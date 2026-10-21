<?php
/**
 * Plugin Name: Portal Push — Pessoas e Perfis
 * Plugin URI: https://github.com/paulommaeda/push-notifications
 * Description: Push no navegador e no PWA, por pessoa ou perfil dos usuários do WordPress.
 * Version: 0.3.1
 * Author: Paulo Maeda
 * Author URI: https://paulomaeda.com
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * Text Domain: portal-push
 * Update URI: https://github.com/paulommaeda/push-notifications
 */

defined('ABSPATH') || exit;

define('PPUSH_VERSION', '0.3.1');
define('PPUSH_FILE', __FILE__);
define('PPUSH_DIR', plugin_dir_path(__FILE__));
define('PPUSH_URL', plugin_dir_url(__FILE__));

// O atualizador funciona mesmo se as dependências do restante do plugin estiverem incompletas.
require_once PPUSH_DIR . 'includes/class-updater.php';
\PauloMaeda\PortalPush\Updater::boot();
require_once PPUSH_DIR . 'includes/class-pwa.php';
\PauloMaeda\PortalPush\Pwa::boot();

require_once PPUSH_DIR . 'includes/class-installer.php';
register_activation_hook(__FILE__, ['PauloMaeda\\PortalPush\\Installer', 'activate']);
register_deactivation_hook(__FILE__, ['PauloMaeda\\PortalPush\\Installer', 'deactivate']);

add_filter('cron_schedules', static function (array $schedules): array {
    $schedules['ppush_minute'] = ['interval' => 60, 'display' => 'Portal Push: a cada minuto'];
    return $schedules;
});

add_action('plugins_loaded', static function (): void {
    if (!file_exists(PPUSH_DIR . 'vendor/autoload.php')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>Portal Push: pacote incompleto. Instale o ZIP que inclui as dependências.</p></div>';
        });
        return;
    }
    require_once PPUSH_DIR . 'vendor/autoload.php';
    foreach (['core', 'audience', 'campaigns', 'transport', 'queue', 'rest', 'frontend', 'admin', 'privacy'] as $file) {
        require_once PPUSH_DIR . 'includes/class-' . $file . '.php';
    }
    \PauloMaeda\PortalPush\Core::boot();
});

/** API PHP para outros plugins. Não envia diretamente: cria uma campanha na fila. */
function portal_push_send(array $notification) {
    if (!class_exists('PauloMaeda\\PortalPush\\Campaigns')) {
        return new WP_Error('ppush_unavailable', 'Portal Push não está disponível.');
    }
    return \PauloMaeda\PortalPush\Campaigns::create($notification);
}
