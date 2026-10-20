<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Installer {
    public static function activate(bool $network_wide = false): void {
        if ($network_wide) {
            wp_die('Ative o Portal Push individualmente em cada site da rede.');
        }
        foreach (['openssl', 'curl', 'mbstring'] as $extension) {
            if (!extension_loaded($extension)) {
                wp_die('Portal Push requer a extensão PHP ' . esc_html($extension) . '.');
            }
        }
        self::schema();
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_portal_push');
        }
        if (!wp_next_scheduled('ppush_process_queue')) {
            wp_schedule_event(time() + 60, 'ppush_minute', 'ppush_process_queue');
        }
        update_option('ppush_db_version', PPUSH_VERSION, false);
    }

    public static function schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'ppush_';
        $tables = [];
        $tables[] = "CREATE TABLE {$p}profiles (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL,
            slug varchar(160) NOT NULL,
            description text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset;";
        $tables[] = "CREATE TABLE {$p}memberships (
            profile_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (profile_id,user_id),
            KEY user_id (user_id)
        ) $charset;";
        $tables[] = "CREATE TABLE {$p}subscriptions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            endpoint_hash char(64) NOT NULL,
            endpoint text NOT NULL,
            p256dh varchar(100) NOT NULL,
            auth varchar(64) NOT NULL,
            secret_hash char(64) NOT NULL,
            label varchar(120) NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY endpoint_hash (endpoint_hash),
            KEY user_active (user_id,active)
        ) $charset;";
        $tables[] = "CREATE TABLE {$p}campaigns (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            creator_id bigint(20) unsigned NOT NULL,
            title varchar(180) NOT NULL,
            message text NOT NULL,
            url text NOT NULL,
            image text NOT NULL,
            audience_type varchar(20) NOT NULL,
            audience_ids longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'scheduled',
            scheduled_at datetime NOT NULL,
            created_at datetime NOT NULL,
            finished_at datetime DEFAULT NULL,
            ttl int(11) unsigned NOT NULL DEFAULT 86400,
            total int(11) unsigned NOT NULL DEFAULT 0,
            last_error text NOT NULL,
            PRIMARY KEY  (id),
            KEY status_schedule (status,scheduled_at)
        ) $charset;";
        $tables[] = "CREATE TABLE {$p}deliveries (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            subscription_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            next_attempt_at datetime NOT NULL,
            locked_at datetime DEFAULT NULL,
            error_code varchar(40) NOT NULL DEFAULT '',
            error_message text NOT NULL,
            sent_at datetime DEFAULT NULL,
            clicked_at datetime DEFAULT NULL,
            click_hash char(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY campaign_subscription (campaign_id,subscription_id),
            KEY status_attempt (status,next_attempt_at),
            KEY campaign_status (campaign_id,status),
            KEY user_id (user_id)
        ) $charset;";
        foreach ($tables as $sql) {
            dbDelta($sql);
        }
        self::migrate_legacy_audiences();
    }

    public static function migrate_legacy_audiences(): void {
        global $wpdb;
        $campaigns = $wpdb->prefix . 'ppush_campaigns';
        $deliveries = $wpdb->prefix . 'ppush_deliveries';
        $wpdb->query("UPDATE $deliveries SET status='cancelled' WHERE status='pending' AND campaign_id IN (SELECT id FROM $campaigns WHERE audience_type='profiles')");
        $count = $wpdb->query($wpdb->prepare("UPDATE $campaigns SET status='cancelled',finished_at=%s,last_error=%s WHERE audience_type='profiles' AND status IN ('scheduled','queued')", gmdate('Y-m-d H:i:s'), 'Campanha anterior à versão 0.3.0 cancelada: selecione os perfis do WordPress e crie um novo envio.'));
        if ($count) { update_option('ppush_legacy_campaigns_cancelled', (int)get_option('ppush_legacy_campaigns_cancelled', 0) + $count, false); }
        // Tabelas antigas e o papel portal_push_member já existente são preservados.
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('ppush_process_queue');
        wp_clear_scheduled_hook('ppush_kick_queue');
        delete_option('ppush_queue_lock');
    }
}
