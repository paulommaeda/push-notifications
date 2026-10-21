<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

$settings = get_option('ppush_settings', []);
if (!empty($settings['retain_on_uninstall']) || !array_key_exists('retain_on_uninstall', $settings)) {
    return;
}
global $wpdb;
foreach (['deliveries', 'campaigns', 'memberships', 'subscriptions', 'profiles'] as $name) {
    $table = $wpdb->prefix . 'ppush_' . $name;
    $wpdb->query("DROP TABLE IF EXISTS `$table`");
}
delete_site_transient('ppush_github_manifest_v1');
$auto = (array)get_site_option('auto_update_plugins', []);
update_site_option('auto_update_plugins', array_values(array_diff($auto, [plugin_basename(__DIR__ . '/portal-push.php')])));
foreach (['ppush_settings', 'ppush_vapid', 'ppush_db_version', 'ppush_queue_lock', 'ppush_queue_last_run', 'ppush_update_status', 'ppush_pwa_worker_version', 'ppush_pwa_worker_revision', 'ppush_legacy_campaigns_cancelled'] as $option) {
    delete_option($option);
}
delete_option('ppush_templates');
delete_metadata('user', 0, '_ppush_disabled', '', true);
$role = get_role('administrator');
if ($role) { $role->remove_cap('manage_portal_push'); }
// As contas das pessoas e seu papel de acesso são preservados.
