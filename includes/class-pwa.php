<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Pwa {
    public static function boot(): void {
        // Registrados já no carregamento do plugin, antes da geração de arquivos dos PWAs.
        add_filter('superpwa_sw_template', [self::class, 'inject'], 90);
        add_filter('pwaforwp_sw_js_template', [self::class, 'inject'], 90);
        add_filter('superpwa_sw_localize_data', [self::class, 'superpwa_url'], 90);
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

    public static function superpwa_url(array $data): array {
        if (!empty($data['url']) && is_string($data['url'])) {
            $data['url'] = add_query_arg('portal_push_version', PPUSH_VERSION . '-' . (int)get_option('ppush_pwa_worker_revision', 0), $data['url']);
        }
        return $data;
    }

    private static function has_module(string $script): bool {
        return str_contains($script, 'PORTAL_PUSH_READY') && str_contains($script, 'PORTAL_PUSH_ACTIVATE') &&
            (bool)preg_match('/PortalPushWorker\\s*=\\s*([\'"])' . preg_quote(PPUSH_VERSION, '/') . '\\1/', $script);
    }

    /** Verifica o arquivo/template local; não afirma que o celular ou a CDN já o atualizou. */
    public static function status(): array {
        $provider = self::provider();
        $status = ['provider' => $provider, 'ready' => false, 'mode' => ''];
        if ($provider === 'SuperPWA') {
            $settings = superpwa_get_settings();
            if (!empty($settings['is_static_sw'])) {
                $path = superpwa_sw('abs');
                $status['mode'] = 'arquivo estático';
                $script = is_readable($path) ? (string)file_get_contents($path) : '';
            } else {
                $status['mode'] = 'gerado pelo WordPress';
                $script = superpwa_sw_template();
            }
        } elseif ($provider === 'PWA for WP' && class_exists('PWAFORWP_File_Creation_Init')) {
            $files = new \PWAFORWP_File_Creation_Init();
            if (is_file($files->swjs_init)) {
                $status['mode'] = 'arquivo estático';
                $script = is_readable($files->swjs_init) ? (string)file_get_contents($files->swjs_init) : '';
            } else {
                $status['mode'] = 'gerado pelo WordPress';
                $script = $files->fileCreation->pwaforwp_swjs();
            }
        } else {
            return $status;
        }
        $status['ready'] = self::has_module($script);
        return $status;
    }

    public static function refresh_files(): void {
        if (!current_user_can('manage_options')) { return; }
        $provider = self::provider();
        $version = $provider . ':' . PPUSH_VERSION;
        if (!$provider || (get_option('ppush_pwa_worker_version') === $version && self::status()['ready'])) { return; }
        self::repair();
    }

    public static function repair(): true|\WP_Error {
        if (!current_user_can('manage_options')) {
            return new \WP_Error('ppush_pwa_forbidden', 'Sua conta não pode reparar a integração do PWA.');
        }
        $provider = self::provider();
        if (!$provider) {
            return new \WP_Error('ppush_pwa_missing', 'Nenhum PWA compatível foi detectado.');
        }
        if ($provider === 'SuperPWA' && function_exists('superpwa_generate_sw')) {
            // SuperPWA memoriza a geração na requisição; uma regeneração precisa limpar esse estado.
            global $superpwa_sw_generated;
            $superpwa_sw_generated = false;
            if (!superpwa_generate_sw()) {
                return new \WP_Error('ppush_pwa_write', 'Não foi possível gerar o arquivo do SuperPWA. Confira as permissões dos arquivos na hospedagem e salve as configurações do SuperPWA.');
            }
        } elseif ($provider === 'PWA for WP' && class_exists('PWAFORWP_File_Creation_Init')) {
            $files = new \PWAFORWP_File_Creation_Init();
            if (!$files->pwaforwp_swjs_init()) {
                return new \WP_Error('ppush_pwa_write', 'Não foi possível gerar o arquivo do PWA. Confira as permissões dos arquivos na hospedagem.');
            }
        }
        if (!self::status()['ready']) {
            delete_option('ppush_pwa_worker_version');
            return new \WP_Error('ppush_pwa_module', 'O arquivo do PWA ainda não contém a integração atual. Salve as configurações do PWA e tente reparar novamente.');
        }
        update_option('ppush_pwa_worker_version', $provider . ':' . PPUSH_VERSION, false);
        update_option('ppush_pwa_worker_revision', (int)get_option('ppush_pwa_worker_revision', 0) + 1, false);
        return true;
    }
}
