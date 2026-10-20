<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Rest {
    public static function routes(): void {
        $base = 'portal-push/v1';
        register_rest_route($base, '/subscriptions', [
            ['methods' => 'GET', 'callback' => [self::class, 'devices'], 'permission_callback' => [self::class, 'allowed']],
            ['methods' => 'POST', 'callback' => [self::class, 'subscribe'], 'permission_callback' => [self::class, 'allowed']],
        ]);
        register_rest_route($base, '/subscriptions/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [self::class, 'revoke'], 'permission_callback' => [self::class, 'allowed'],
        ]);
        register_rest_route($base, '/test', [
            'methods' => 'POST', 'callback' => [self::class, 'test'], 'permission_callback' => [self::class, 'allowed'],
        ]);
        register_rest_route($base, '/people-search', [
            'methods' => 'GET', 'callback' => [self::class, 'search_people'],
            'permission_callback' => static fn() => current_user_can('manage_portal_push'),
        ]);
        register_rest_route($base, '/click/(?P<id>\d+)', [
            'methods' => 'POST', 'callback' => [self::class, 'click'], 'permission_callback' => '__return_true',
        ]);
    }

    public static function allowed(): true|\WP_Error {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return new \WP_Error('ppush_login', 'Entre no portal para continuar.', ['status' => 401]);
        }
        if (!Core::enabled(get_current_user_id())) {
            return new \WP_Error('ppush_disabled', 'O recebimento está desativado para esta pessoa.', ['status' => 403]);
        }
        return true;
    }

    private static function response($data, int $status = 200): \WP_REST_Response {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        return $response;
    }

    public static function devices(): \WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,label,active,created_at,last_seen_at FROM ' . Core::table('subscriptions') . ' WHERE user_id=%d ORDER BY active DESC,id DESC LIMIT 100', get_current_user_id()));
        return self::response(['devices' => $rows]);
    }

    public static function search_people(\WP_REST_Request $request): \WP_REST_Response {
        $search = sanitize_text_field((string)$request->get_param('search'));
        $args = ['number' => 30, 'orderby' => 'display_name', 'order' => 'ASC'];
        if ($search !== '') {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['display_name', 'user_email', 'user_login'];
        }
        $people = [];
        foreach (get_users($args) as $user) {
            $people[] = ['id' => (int)$user->ID, 'name' => $user->display_name . ' — ' . $user->user_email];
        }
        return self::response(['people' => $people]);
    }

    public static function subscribe(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        global $wpdb;
        if (is_wp_error(Transport::keys())) {
            return new \WP_Error('ppush_setup', 'O administrador ainda precisa configurar o envio.', ['status' => 503]);
        }
        $data = $request->get_json_params();
        $sub = $data['subscription'] ?? null;
        $secret = $data['device_secret'] ?? '';
        if (!is_array($sub) || !is_string($secret) || !preg_match('/^[a-zA-Z0-9_-]{43,128}$/D', $secret)) {
            return new \WP_Error('ppush_subscription', 'Dados de inscrição inválidos.', ['status' => 400]);
        }
        $endpoint = $sub['endpoint'] ?? '';
        $p256dh = $sub['keys']['p256dh'] ?? '';
        $auth = $sub['keys']['auth'] ?? '';
        if (!is_string($endpoint) || !is_string($p256dh) || !is_string($auth) || !Transport::valid_endpoint($endpoint)) {
            return new \WP_Error('ppush_endpoint', 'Serviço de push não permitido.', ['status' => 400]);
        }
        $public = Core::b64decode($p256dh);
        $auth_bytes = Core::b64decode($auth);
        if ($public === false || strlen($public) !== 65 || $public[0] !== "\x04" || $auth_bytes === false || strlen($auth_bytes) !== 16) {
            return new \WP_Error('ppush_key', 'Chaves de inscrição inválidas.', ['status' => 400]);
        }
        $user_id = get_current_user_id();
        $table = Core::table('subscriptions');
        $hash = hash('sha256', $endpoint);
        $secret_hash = hash('sha256', $secret);
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE endpoint_hash=%s", $hash));
        $reactivate = !empty($data['reactivate']);
        if ($existing && (!hash_equals($existing->secret_hash, $secret_hash) ||
            ((!(int)$existing->active || (int)$existing->user_id !== $user_id) && !$reactivate))) {
            return new \WP_Error('ppush_device', 'Este dispositivo precisa ser ativado novamente pelo botão do portal.', ['status' => 409]);
        }
        $label = sanitize_text_field(is_string($data['label'] ?? null) ? $data['label'] : 'Navegador');
        $values = [
            'user_id' => $user_id, 'endpoint_hash' => $hash, 'endpoint' => $endpoint,
            'p256dh' => $p256dh, 'auth' => $auth, 'secret_hash' => $secret_hash,
            'label' => mb_substr($label ?: 'Navegador', 0, 120), 'active' => 1,
            'updated_at' => Core::now(), 'last_seen_at' => Core::now(),
        ];
        if ($existing) {
            $ok = $wpdb->update($table, $values, ['id' => $existing->id, 'secret_hash' => $existing->secret_hash]);
            $id = (int)$existing->id;
        } else {
            if ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id=%d AND active=1", $user_id)) >= 50) {
                return new \WP_Error('ppush_limit', 'Desative um dispositivo antes de cadastrar outro (limite de 50 ativos).', ['status' => 429]);
            }
            $values['created_at'] = Core::now();
            $ok = $wpdb->insert($table, $values);
            $id = (int)$wpdb->insert_id;
        }
        if ($ok === false) {
            return new \WP_Error('ppush_database', 'Não foi possível salvar este dispositivo. Tente novamente.', ['status' => 500]);
        }
        self::device_cookie($id . ':' . $secret);
        return self::response(['id' => $id, 'active' => true]);
    }

    public static function revoke(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        global $wpdb;
        $id = (int)$request['id'];
        $row = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . Core::table('subscriptions') . ' WHERE id=%d AND user_id=%d', $id, get_current_user_id()));
        if (!$row) {
            return new \WP_Error('ppush_not_found', 'Dispositivo não encontrado.', ['status' => 404]);
        }
        $wpdb->update(Core::table('subscriptions'), ['active' => 0, 'updated_at' => Core::now()], ['id' => $id, 'user_id' => get_current_user_id()]);
        return self::response(['success' => true]);
    }

    public static function test(): \WP_REST_Response|\WP_Error {
        $user_id = get_current_user_id();
        if (get_transient('ppush_test_' . $user_id)) {
            return new \WP_Error('ppush_wait', 'Aguarde um minuto antes de testar novamente.', ['status' => 429]);
        }
        $id = Campaigns::create([
            'title' => 'Tudo pronto!', 'message' => 'As notificações do portal estão ativadas para você.',
            'url' => get_permalink((int)Core::settings()['portal_page']) ?: home_url('/'),
            'audience_type' => 'people', 'audience_ids' => [$user_id],
        ]);
        if (is_wp_error($id)) {
            return $id;
        }
        set_transient('ppush_test_' . $user_id, 1, 60);
        return self::response(['campaign_id' => $id], 201);
    }

    public static function click(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        global $wpdb;
        $token = $request->get_param('token');
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return new \WP_Error('ppush_token', 'Token inválido.', ['status' => 403]);
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT id,click_hash FROM ' . Core::table('deliveries') . " WHERE id=%d AND status IN ('processing','sent')", (int)$request['id']));
        if (!$row || !hash_equals($row->click_hash, hash('sha256', $token))) {
            return new \WP_Error('ppush_token', 'Token inválido.', ['status' => 403]);
        }
        $wpdb->query($wpdb->prepare('UPDATE ' . Core::table('deliveries') . ' SET clicked_at=%s WHERE id=%d AND clicked_at IS NULL', Core::now(), $row->id));
        return self::response(null, 204);
    }

    private static function cookie_name(): string {
        return 'ppush_device_' . substr(md5(home_url('/')), 0, 12);
    }

    private static function device_cookie(string $value): void {
        if (!headers_sent()) {
            setcookie(self::cookie_name(), $value, [
                'expires' => time() + YEAR_IN_SECONDS,
                'path' => wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/',
                'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
    }

    public static function logout(int $user_id): void {
        global $wpdb;
        $raw = $_COOKIE[self::cookie_name()] ?? '';
        if (!is_string($raw) || !preg_match('/^(\d+):([a-zA-Z0-9_-]{43,128})$/D', $raw, $parts)) {
            return;
        }
        $wpdb->update(Core::table('subscriptions'), ['active' => 0, 'updated_at' => Core::now()], [
            'id' => (int)$parts[1], 'user_id' => $user_id, 'secret_hash' => hash('sha256', $parts[2]),
        ]);
        self::device_cookie('');
    }
}
