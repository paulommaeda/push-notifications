<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Campaigns {
    /** IDs vazios nunca significam todos. */
    public static function create(array $data): int|\WP_Error {
        global $wpdb;
        $title = sanitize_text_field($data['title'] ?? '');
        $message = sanitize_textarea_field($data['message'] ?? '');
        $type = sanitize_key($data['audience_type'] ?? '');
        $ids = $type === 'roles' ? Audience::validate_roles($data['audience_ids'] ?? []) : Core::ids($data['audience_ids'] ?? []);
        if ($title === '' || $message === '' || Core::length($title) > 80 || Core::length($message) > 300) {
            return new \WP_Error('ppush_message', 'Informe título (até 80 caracteres) e mensagem (até 300).');
        }
        if (is_wp_error($ids)) { return $ids; }
        if (!in_array($type, ['people', 'roles', 'all'], true) || ($type !== 'all' && !$ids)) {
            return new \WP_Error('ppush_audience', 'Selecione pelo menos uma pessoa ou um perfil do WordPress.');
        }
        if (count($ids) > 2000) {
            return new \WP_Error('ppush_audience', 'Selecione até 2.000 IDs por campanha, ou utilize perfis.');
        }
        if ($type === 'people') {
            foreach ($ids as $id) {
                if (!get_userdata($id)) {
                    return new \WP_Error('ppush_user', 'Uma das pessoas selecionadas não existe.');
                }
            }
        }
        $url = self::portal_url($data['url'] ?? home_url('/'));
        if (is_wp_error($url)) {
            return $url;
        }
        $image = esc_url_raw($data['image'] ?? '', ['https']);
        $sample = [
            'portalPush' => 1,
            'title' => $title, 'body' => $message, 'url' => $url, 'image' => $image,
            'icon' => Core::settings()['icon'] ?: get_site_icon_url(192),
            'tag' => 'portal-push-999999999999', 'campaign' => 999999999999,
            'clickUrl' => rest_url('portal-push/v1/click/999999999999'), 'clickToken' => str_repeat('f', 64),
        ];
        if (strlen(wp_json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 3500) {
            return new \WP_Error('ppush_size', 'A notificação excede o limite do Web Push. Reduza a mensagem ou os links.');
        }
        $scheduled = isset($data['scheduled_at']) ? strtotime($data['scheduled_at'] . ' UTC') : time();
        if ($scheduled === false) {
            return new \WP_Error('ppush_date', 'Data inválida.');
        }
        $ttl = max(60, min(604800, (int)($data['ttl'] ?? Core::settings()['default_ttl'])));
        $created = $wpdb->insert(Core::table('campaigns'), [
            'creator_id' => get_current_user_id(),
            'title' => $title, 'message' => $message, 'url' => $url, 'image' => $image,
            'audience_type' => $type, 'audience_ids' => wp_json_encode($type === 'all' ? [] : $ids),
            'status' => 'scheduled', 'scheduled_at' => gmdate('Y-m-d H:i:s', max(time(), $scheduled)),
            'created_at' => Core::now(), 'ttl' => $ttl, 'last_error' => '',
        ]);
        if ($created === false) {
            return new \WP_Error('ppush_database', 'Não foi possível criar a campanha.');
        }
        $id = (int)$wpdb->insert_id;
        self::kick();
        do_action('portal_push_campaign_created', $id);
        return $id;
    }

    public static function portal_url(string $url): string|\WP_Error {
        if ($url === '') {
            $url = home_url('/');
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $url = home_url($url);
        }
        $url = esc_url_raw($url, ['https', 'http']);
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!$parts || empty($parts['host']) || strtolower($parts['host']) !== strtolower($home['host']) ||
            !empty($parts['user']) || !empty($parts['pass']) ||
            ($parts['port'] ?? null) !== ($home['port'] ?? null) ||
            ($parts['scheme'] ?? '') !== ($home['scheme'] ?? '')) {
            return new \WP_Error('ppush_url', 'O link deve pertencer ao próprio portal.');
        }
        return $url;
    }

    public static function cancel(int $id): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE " . Core::table('campaigns') . " SET status='cancelled',finished_at=%s WHERE id=%d AND status IN ('scheduled','queued')", Core::now(), $id));
        $wpdb->query($wpdb->prepare("UPDATE " . Core::table('deliveries') . " SET status='cancelled' WHERE campaign_id=%d AND status='pending'", $id));
    }

    public static function kick(): void {
        if (!wp_next_scheduled('ppush_kick_queue')) {
            wp_schedule_single_event(time() + 1, 'ppush_kick_queue');
        }
    }

    public static function stats(int $id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT status,COUNT(*) AS n FROM ' . Core::table('deliveries') . ' WHERE campaign_id=%d GROUP BY status', $id));
        $out = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0, 'clicked' => 0];
        foreach ($rows as $row) {
            $out[$row->status] = (int)$row->n;
        }
        $out['clicked'] = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Core::table('deliveries') . ' WHERE campaign_id=%d AND clicked_at IS NOT NULL', $id));
        return $out;
    }
}
