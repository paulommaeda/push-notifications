<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Core {
    public static function boot(): void {
        if (get_option('ppush_db_version') !== PPUSH_VERSION) {
            Installer::schema();
            update_option('ppush_db_version', PPUSH_VERSION, false);
        }
        add_action('rest_api_init', [Rest::class, 'routes']);
        add_action('ppush_process_queue', [Queue::class, 'run']);
        add_action('ppush_kick_queue', [Queue::class, 'run']);
        add_action('deleted_user', [Privacy::class, 'delete_user']);
        add_action('wp_logout', [Rest::class, 'logout'], 10, 1);
        Frontend::boot();
        Admin::boot();
        Privacy::boot();
    }

    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'ppush_' . $name;
    }

    public static function settings(): array {
        return wp_parse_args(get_option('ppush_settings', []), [
            'contact' => get_option('admin_email'),
            'portal_page' => 0,
            'icon' => '',
            'default_ttl' => 86400,
            'retain_on_uninstall' => 1,
            'site_prompt' => 1,
        ]);
    }

    public static function enabled(int $user_id): bool {
        $user = get_userdata($user_id);
        return $user && user_can($user, 'read') && get_user_meta($user_id, '_ppush_disabled', true) !== '1';
    }

    public static function now(): string {
        return gmdate('Y-m-d H:i:s');
    }

    public static function ids($values): array {
        return array_values(array_unique(array_filter(array_map('absint', is_array($values) ? $values : []))));
    }

    public static function length(string $value): int {
        return mb_strlen($value, 'UTF-8');
    }

    public static function b64decode(string $value): string|false {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            return false;
        }
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
