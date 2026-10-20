<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Audience {
    /** Os perfis são os papéis registrados no próprio WordPress, inclusive os personalizados. */
    public static function roles(): array {
        $roles = [];
        foreach (wp_roles()->roles as $slug => $role) {
            $roles[$slug] = translate_user_role($role['name']);
        }
        return $roles;
    }

    public static function user_roles(int $user_id): array {
        $user = get_userdata($user_id);
        return $user ? array_values(array_intersect($user->roles, array_keys(self::roles()))) : [];
    }

    public static function validate_roles($values): array|\WP_Error {
        if (!is_array($values) || !$values) {
            return new \WP_Error('ppush_role', 'Selecione pelo menos um perfil do WordPress.');
        }
        $valid = self::roles();
        foreach ($values as $value) {
            if (!is_string($value) || !isset($valid[$value])) {
                return new \WP_Error('ppush_role', 'Um dos perfis selecionados não existe no WordPress.');
            }
        }
        return array_values(array_unique($values));
    }

    public static function matches(int $user_id, object $campaign): bool {
        if (!Core::enabled($user_id)) {
            return false;
        }
        $values = json_decode($campaign->audience_ids, true);
        if ($campaign->audience_type === 'all') { return true; }
        if ($campaign->audience_type === 'people') {
            return in_array($user_id, Core::ids($values), true);
        }
        if ($campaign->audience_type === 'roles') {
            $roles = self::validate_roles($values);
            return !is_wp_error($roles) && (bool)array_intersect($roles, self::user_roles($user_id));
        }
        // Grupos antigos não são convertidos em papéis nem em envio geral.
        return false;
    }

    /** Papéis combinados por OU; cada inscrição aparece uma única vez. */
    public static function subscriptions(object $campaign): array {
        global $wpdb;
        $where = 'active=1';
        if ($campaign->audience_type === 'people') {
            $ids = Core::ids(json_decode($campaign->audience_ids, true));
            if (!$ids) { return []; }
            $where .= ' AND user_id IN (' . implode(',', $ids) . ')';
        } elseif ($campaign->audience_type === 'roles') {
            if (is_wp_error(self::validate_roles(json_decode($campaign->audience_ids, true)))) { return []; }
        } elseif ($campaign->audience_type !== 'all') {
            return [];
        }
        $rows = $wpdb->get_results('SELECT id,user_id FROM ' . Core::table('subscriptions') . ' WHERE ' . $where);
        cache_users(array_values(array_unique(array_map(static fn($row) => (int)$row->user_id, $rows))));
        return array_values(array_filter($rows, static fn($row) => self::matches((int)$row->user_id, $campaign)));
    }
}
