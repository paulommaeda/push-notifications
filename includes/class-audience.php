<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Audience {
    public static function profiles(): array {
        global $wpdb;
        return $wpdb->get_results('SELECT p.*, (SELECT COUNT(*) FROM ' . Core::table('memberships') . ' m WHERE m.profile_id=p.id) AS members FROM ' . Core::table('profiles') . ' p ORDER BY p.name');
    }

    public static function user_profiles(int $user_id): array {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT profile_id FROM ' . Core::table('memberships') . ' WHERE user_id=%d', $user_id)));
    }

    public static function set_profiles(int $user_id, array $ids): true|\WP_Error {
        global $wpdb;
        if (!get_userdata($user_id)) {
            return new \WP_Error('ppush_user', 'Pessoa não encontrada.');
        }
        $ids = Core::ids($ids);
        $valid = array_map(static fn($p) => (int)$p->id, self::profiles());
        if (array_diff($ids, $valid)) {
            return new \WP_Error('ppush_profile', 'Um dos perfis não existe mais.');
        }
        $existing = self::user_profiles($user_id);
        foreach ($ids as $id) {
            if (in_array($id, $existing, true)) {
                continue;
            }
            if (false === $wpdb->insert(Core::table('memberships'), ['profile_id' => $id, 'user_id' => $user_id], ['%d', '%d'])) {
                return new \WP_Error('ppush_database', 'Não foi possível salvar os perfis.');
            }
        }
        foreach (array_diff($existing, $ids) as $id) {
            $wpdb->delete(Core::table('memberships'), ['user_id' => $user_id, 'profile_id' => $id], ['%d', '%d']);
        }
        return true;
    }

    public static function matches(int $user_id, object $campaign): bool {
        if (!Core::enabled($user_id)) {
            return false;
        }
        $ids = Core::ids(json_decode($campaign->audience_ids, true));
        return match ($campaign->audience_type) {
            'all' => true,
            'people' => in_array($user_id, $ids, true),
            'profiles' => (bool)array_intersect($ids, self::user_profiles($user_id)),
            default => false,
        };
    }

    /** Perfis combinados por OU; DISTINCT evita duplicidade entre perfis. */
    public static function subscriptions(object $campaign): array {
        global $wpdb;
        $ids = Core::ids(json_decode($campaign->audience_ids, true));
        $s = Core::table('subscriptions');
        $m = Core::table('memberships');
        $where = 's.active=1';
        if ($campaign->audience_type !== 'all' && !$ids) {
            return [];
        }
        if ($campaign->audience_type === 'people') {
            $where .= ' AND s.user_id IN (' . implode(',', $ids) . ')';
        } elseif ($campaign->audience_type === 'profiles') {
            $where .= ' AND EXISTS (SELECT 1 FROM ' . $m . ' m WHERE m.user_id=s.user_id AND m.profile_id IN (' . implode(',', $ids) . '))';
        } elseif ($campaign->audience_type !== 'all') {
            return [];
        }
        $rows = $wpdb->get_results("SELECT DISTINCT s.id,s.user_id FROM $s s WHERE $where");
        return array_values(array_filter($rows, static fn($row) => Core::enabled((int)$row->user_id)));
    }
}
