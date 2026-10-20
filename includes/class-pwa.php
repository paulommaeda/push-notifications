<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Pwa {
    public static function boot(): void {
        // Registrados já no carregamento do plugin, antes da geração de arquivos dos PWAs.
        add_filter('superpwa_sw_template', [self::class, 'inject'], 90);
        add_filter('pwaforwp_sw_js_template', [self::class, 'inject'], 90);
        add_action('admin_init', [self::class, 'refresh_files'], 90);
    }

    public static function provider(): string {
        if (function_exists('pwaforwp_init_plugin')) { return 'PWA for WP'; }
        if (function_exists('superpwa_sw_template')) { return 'SuperPWA'; }
        return '';
    }

    public static function events(): string {
        return str_replace('__PPUSH_VERSION__', PPUSH_VERSION, (string)file_get_contents(PPUSH_DIR . 'assets/push-events.js'));
    }

    public static function inject(string $script): string {
        if (str_contains($script, 'PortalPushWorker')) { return $script; }
        // Apenas eventos push/clique/mensagem; preserva instalação, cache e navegação do PWA.
        return $script . "\n;/* Portal Push integration */\n" . self::events();
    }

    public static function refresh_files(): void {
        if (!current_user_can('manage_options')) { return; }
        $provider = self::provider();
        $version = $provider . ':' . PPUSH_VERSION;
        if (!$provider || get_option('ppush_pwa_worker_version') === $version) { return; }
        if ($provider === 'SuperPWA' && function_exists('superpwa_generate_sw')) {
            // SuperPWA memoriza a geração na requisição; uma regeneração precisa limpar esse estado.
            global $superpwa_sw_generated;
            $superpwa_sw_generated = false;
            if (!superpwa_generate_sw()) { return; }
        } elseif ($provider === 'PWA for WP' && class_exists('PWAFORWP_File_Creation_Init')) {
            $files = new \PWAFORWP_File_Creation_Init();
            if (!$files->pwaforwp_swjs_init()) { return; }
        }
        update_option('ppush_pwa_worker_version', $version, false);
    }
}
