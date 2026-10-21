<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Templates {
    public static function all(): array {
        $items = get_option('ppush_templates', []);
        return is_array($items) ? array_filter($items, 'is_array') : [];
    }

    public static function get(string $key): array|\WP_Error {
        $items = self::all();
        return $items[$key] ?? new \WP_Error('ppush_template_missing', 'Modelo de notificação não encontrado: ' . $key);
    }

    public static function variables(array $template): array {
        preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', implode(' ', [$template['title'] ?? '', $template['message'] ?? '', $template['url'] ?? '']), $matches);
        return array_values(array_unique($matches[1]));
    }

    public static function save(array $data, string $original_key = ''): true|\WP_Error {
        if (!current_user_can('manage_portal_push')) {
            return new \WP_Error('ppush_forbidden', 'Sua conta não pode alterar modelos.');
        }
        foreach (['key', 'name', 'title', 'message', 'url', 'image', 'audience_type'] as $field) {
            if (isset($data[$field]) && !is_string($data[$field])) { return new \WP_Error('ppush_template', 'Campo inválido: ' . $field); }
        }
        $key = $data['key'] ?? '';
        if (!preg_match('/^[a-z][a-z0-9_-]{0,79}$/D', $key) || ($original_key !== '' && $original_key !== $key)) {
            return new \WP_Error('ppush_template_key', 'Use uma chave de até 80 caracteres: letras minúsculas, números, hífen ou sublinhado. Uma chave existente não pode ser alterada.');
        }
        $items = self::all();
        if (($original_key === '' && isset($items[$key])) || ($original_key !== '' && !isset($items[$key]))) {
            return new \WP_Error('ppush_template_key', 'A chave já existe ou o modelo editado não está mais disponível.');
        }
        $name = sanitize_text_field($data['name'] ?? '');
        $title = sanitize_text_field($data['title'] ?? '');
        $message = sanitize_textarea_field($data['message'] ?? '');
        if ($name === '' || Core::length($name) > 120 || $title === '' || Core::length($title) > 80 || $message === '' || Core::length($message) > 300) {
            return new \WP_Error('ppush_template', 'Informe nome (até 120 caracteres), título (até 80) e mensagem (até 300).');
        }
        $url = trim(wp_strip_all_tags($data['url'] ?? '', true)) ?: home_url('/');
        $sample_url = preg_replace('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', 'exemplo', $url);
        if (is_wp_error($checked = Campaigns::portal_url($sample_url))) { return $checked; }
        $type = $data['audience_type'] ?? 'dynamic';
        if (!in_array($type, ['dynamic', 'roles', 'all'], true)) {
            return new \WP_Error('ppush_audience', 'Selecione o público do modelo.');
        }
        $ids = [];
        if ($type === 'roles') {
            $ids = Audience::validate_roles($data['role_slugs'] ?? []);
            if (is_wp_error($ids)) { return $ids; }
        }
        if ($type === 'all' && empty($data['confirm_all'])) {
            return new \WP_Error('ppush_audience', 'Confirme que este modelo poderá enviar para todos os habilitados.');
        }
        $items[$key] = [
            'key' => $key, 'name' => $name, 'title' => $title, 'message' => $message, 'url' => $url,
            'image' => esc_url_raw($data['image'] ?? '', ['https']),
            'audience_type' => $type, 'audience_ids' => $ids,
            'enabled' => !empty($data['enabled']),
            'ttl' => max(60, min(604800, (int)($data['ttl'] ?? Core::settings()['default_ttl']))),
        ];
        if (get_option('ppush_templates', []) === $items) { return true; }
        return update_option('ppush_templates', $items, false) ? true : new \WP_Error('ppush_database', 'Não foi possível salvar o modelo.');
    }

    public static function delete(string $key): true|\WP_Error {
        if (!current_user_can('manage_portal_push')) { return new \WP_Error('ppush_forbidden', 'Sua conta não pode excluir modelos.'); }
        $items = self::all();
        if (!isset($items[$key])) { return new \WP_Error('ppush_template_missing', 'Modelo não encontrado.'); }
        unset($items[$key]);
        return update_option('ppush_templates', $items, false) ? true : new \WP_Error('ppush_database', 'Não foi possível excluir o modelo.');
    }

    private static function render(string $text, array $variables, bool $url = false): string|\WP_Error {
        $missing = [];
        $rendered = preg_replace_callback('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', static function (array $match) use ($variables, $url, &$missing): string {
            if (!array_key_exists($match[1], $variables)) { $missing[] = $match[1]; return ''; }
            $value = (string)$variables[$match[1]];
            return $url ? rawurlencode($value) : $value;
        }, $text);
        return $missing ? new \WP_Error('ppush_variables', 'Informe as variáveis: ' . implode(', ', array_unique($missing)) . '.') : $rendered;
    }

    /** API de servidor: o plugin chamador valida o evento e escolhe os destinatários autorizados. */
    public static function notify(string $key, array $context): int|\WP_Error {
        $template = self::get($key);
        if (is_wp_error($template)) { return $template; }
        if (empty($template['enabled'])) { return new \WP_Error('ppush_template_disabled', 'Este modelo está desativado.'); }
        $variables = $context['variables'] ?? [];
        if (!is_array($variables)) { return new \WP_Error('ppush_variables', 'As variáveis devem ser informadas em um array.'); }
        foreach ($variables as $value) {
            if (!is_scalar($value)) { return new \WP_Error('ppush_variables', 'Cada variável deve conter um valor simples, como texto ou número.'); }
        }
        $has_user = array_key_exists('user_id', $context);
        $has_type = array_key_exists('audience_type', $context);
        $has_ids = array_key_exists('audience_ids', $context);
        if (($has_user && ($has_type || $has_ids)) || ($has_type !== $has_ids)) {
            return new \WP_Error('ppush_audience', 'Informe user_id ou o par audience_type e audience_ids.');
        }
        if ($has_user) {
            $type = 'people'; $ids = [$context['user_id']];
        } elseif ($has_type) {
            $type = $context['audience_type']; $ids = $context['audience_ids'];
        } else {
            $type = $template['audience_type']; $ids = $template['audience_ids'];
        }
        if (!is_array($ids) || !is_string($type) || !in_array($type, ['people', 'roles', 'all'], true)) {
            return new \WP_Error('ppush_audience', 'Informe o usuário ou o público destinatário do evento.');
        }
        if ($type === 'people') {
            foreach ($ids as $id) {
                if ((!is_int($id) && !(is_string($id) && preg_match('/^[0-9]+$/D', $id))) || (int)$id <= 0) {
                    return new \WP_Error('ppush_user', 'Informe IDs válidos de usuários do WordPress.');
                }
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
        } elseif ($type === 'roles') {
            $ids = Audience::validate_roles($ids);
            if (is_wp_error($ids)) { return $ids; }
        } elseif ($ids) {
            return new \WP_Error('ppush_audience', 'O envio geral deve usar audience_ids vazio.');
        }
        if ($type !== 'all' && !$ids) { return new \WP_Error('ppush_audience', 'Selecione pelo menos um destinatário.'); }
        $data = ['audience_type' => $type, 'audience_ids' => $ids, 'image' => $template['image'], 'ttl' => $template['ttl']];
        foreach (['title', 'message', 'url'] as $field) {
            if ($field === 'url' && array_key_exists('url', $context)) {
                if (!is_string($context['url'])) { return new \WP_Error('ppush_url', 'Informe um link válido do portal.'); }
                $data[$field] = $context['url'];
            } else {
                $data[$field] = self::render($template[$field], $variables, $field === 'url');
                if (is_wp_error($data[$field])) { return $data[$field]; }
            }
        }
        if (array_key_exists('event_id', $context)) {
            if (!is_string($context['event_id']) || $context['event_id'] === '' || strlen($context['event_id']) > 191) {
                return new \WP_Error('ppush_event', 'Informe event_id como texto não vazio de até 191 bytes.');
            }
            $canonical = $ids; sort($canonical);
            $audience_hash = hash('sha256', wp_json_encode([$type, $canonical]));
            $data['idempotency_key'] = 'template:' . $key . ':' . $audience_hash . ':' . $context['event_id'];
        }
        if (is_wp_error($keys = Transport::keys())) { return $keys; }
        return Campaigns::create($data);
    }
}
