<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Updater {
    public const REPOSITORY = 'https://github.com/paulommaeda/push-notifications';
    public const MANIFEST = 'https://raw.githubusercontent.com/paulommaeda/push-notifications/main/updates/portal-push.json';
    private const CACHE_KEY = 'ppush_github_manifest_v1';

    public static function boot(): void {
        add_filter('update_plugins_github.com', [self::class, 'update'], 10, 4);
        add_filter('plugins_api', [self::class, 'information'], 20, 3);
        add_filter('upgrader_pre_download', [self::class, 'download'], 10, 4);
        add_filter('upgrader_source_selection', [self::class, 'source'], 20, 4);
        add_action('upgrader_process_complete', [self::class, 'completed'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(PPUSH_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=portal-push&tab=settings')) . '">Configurações</a>');
            return $links;
        });
    }

    public static function clear(): void {
        delete_site_transient(self::CACHE_KEY);
    }

    public static function metadata(): array|\WP_Error {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return !empty($cached['data']) ? $cached['data'] : new \WP_Error('ppush_updates', $cached['error'] ?? 'Atualização indisponível.');
        }
        $response = wp_safe_remote_get(self::MANIFEST, [
            'timeout' => 8, 'redirection' => 2, 'limit_response_size' => 131072,
            'headers' => ['Accept' => 'application/json'], 'user-agent' => 'Portal-Push/' . PPUSH_VERSION,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::failure('Não foi possível consultar as atualizações no GitHub. Tente novamente mais tarde.');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $validated = self::validate($data);
        if (is_wp_error($validated)) {
            return self::failure($validated->get_error_message());
        }
        set_site_transient(self::CACHE_KEY, ['data' => $validated], 6 * HOUR_IN_SECONDS);
        update_option('ppush_update_status', ['checked_at' => gmdate('Y-m-d H:i:s'), 'version' => $validated['version'], 'error' => ''], false);
        return $validated;
    }

    private static function failure(string $message): \WP_Error {
        set_site_transient(self::CACHE_KEY, ['error' => $message], 15 * MINUTE_IN_SECONDS);
        update_option('ppush_update_status', ['checked_at' => gmdate('Y-m-d H:i:s'), 'version' => '', 'error' => $message], false);
        return new \WP_Error('ppush_updates', $message);
    }

    /** Aceita somente versões estáveis e o pacote correspondente neste repositório. */
    public static function validate($data): array|\WP_Error {
        $error = static fn() => new \WP_Error('ppush_manifest', 'O manifesto de atualização recebido é inválido.');
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['slug'] ?? '') !== 'portal-push') {
            return $error();
        }
        $version = $data['version'] ?? null;
        if (!is_string($version) || !preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}$/D', $version)) {
            return $error();
        }
        $expected = 'https://raw.githubusercontent.com/paulommaeda/push-notifications/main/dist/portal-push-' . $version . '.zip';
        if (($data['download_url'] ?? '') !== $expected || !is_string($data['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['sha256'])) {
            return $error();
        }
        foreach (['requires', 'requires_php', 'tested'] as $key) {
            if (!is_string($data[$key] ?? null) || !preg_match('/^\d{1,3}\.\d{1,3}(?:\.\d{1,3})?$/D', $data[$key])) {
                return $error();
            }
        }
        $sections = [];
        foreach (['description', 'installation', 'changelog'] as $section) {
            $value = $data['sections'][$section] ?? '';
            $sections[$section] = is_string($value) ? wp_kses_post($value) : '';
        }
        return [
            'schema' => 1, 'slug' => 'portal-push', 'version' => $version,
            'download_url' => $expected, 'sha256' => $data['sha256'],
            'requires' => $data['requires'], 'requires_php' => $data['requires_php'], 'tested' => $data['tested'],
            'last_updated' => is_string($data['last_updated'] ?? null) ? sanitize_text_field($data['last_updated']) : '',
            'sections' => $sections,
        ];
    }

    public static function update($update, array $plugin_data, string $plugin_file, array $locales = []) {
        if ($plugin_file !== plugin_basename(PPUSH_FILE) || ($plugin_data['UpdateURI'] ?? '') !== self::REPOSITORY) {
            return $update;
        }
        $data = self::metadata();
        if (is_wp_error($data)) {
            return $update;
        }
        return [
            'id' => self::REPOSITORY, 'slug' => 'portal-push', 'version' => $data['version'],
            'url' => self::REPOSITORY, 'package' => $data['download_url'],
            'requires' => $data['requires'], 'requires_php' => $data['requires_php'], 'tested' => $data['tested'],
        ];
    }

    public static function information($result, string $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== 'portal-push') {
            return $result;
        }
        $data = self::metadata();
        if (is_wp_error($data)) {
            return (object)[
                'name' => 'Portal Push — Pessoas e Perfis', 'slug' => 'portal-push', 'version' => PPUSH_VERSION,
                'homepage' => self::REPOSITORY, 'author' => 'Paulo Maeda',
                'sections' => ['description' => '<p>' . esc_html($data->get_error_message()) . '</p>'],
            ];
        }
        return (object)[
            'name' => 'Portal Push — Pessoas e Perfis', 'slug' => 'portal-push', 'version' => $data['version'],
            'homepage' => self::REPOSITORY, 'author' => 'Paulo Maeda',
            'requires' => $data['requires'], 'requires_php' => $data['requires_php'], 'tested' => $data['tested'],
            'last_updated' => $data['last_updated'], 'download_link' => $data['download_url'],
            'sections' => $data['sections'],
        ];
    }

    /** WordPress chama este filtro antes de baixar. Verifica a integridade do ZIP baixado. */
    public static function download($reply, string $package, $upgrader, array $hook_extra = []) {
        if ($reply !== false && $reply !== null) {
            return $reply;
        }
        $plugin = $hook_extra['plugin'] ?? '';
        if ($plugin !== plugin_basename(PPUSH_FILE)) {
            return $reply;
        }
        $data = self::metadata();
        if (is_wp_error($data)) {
            return $data;
        }
        if ($package !== $data['download_url']) {
            return new \WP_Error('ppush_package_changed', 'A versão disponível mudou. Verifique as atualizações novamente.');
        }
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = download_url($package, 60);
        if (is_wp_error($file)) {
            return $file;
        }
        if (!hash_equals($data['sha256'], (string)hash_file('sha256', $file))) {
            wp_delete_file($file);
            return new \WP_Error('ppush_checksum', 'O pacote recebido falhou na verificação de integridade. Nenhum arquivo foi instalado.');
        }
        return $file;
    }

    /** Mantém o diretório instalado, inclusive se a primeira instalação veio de um ZIP do código. */
    public static function source($source, string $remote_source, $upgrader, array $hook_extra = []) {
        if (is_wp_error($source) || ($hook_extra['plugin'] ?? '') !== plugin_basename(PPUSH_FILE)) {
            return $source;
        }
        global $wp_filesystem;
        if (!$wp_filesystem || !$wp_filesystem->exists(trailingslashit($source) . 'portal-push.php') || !$wp_filesystem->exists(trailingslashit($source) . 'vendor/autoload.php')) {
            return new \WP_Error('ppush_incomplete', 'O ZIP de atualização não contém o plugin completo.');
        }
        $directory = dirname(plugin_basename(PPUSH_FILE));
        if ($directory === '.' || str_contains($directory, '/') || sanitize_file_name($directory) !== $directory) {
            return new \WP_Error('ppush_directory', 'O diretório instalado do plugin não é válido para atualização.');
        }
        if (basename(untrailingslashit($source)) === $directory) {
            return $source;
        }
        $destination = trailingslashit($remote_source) . $directory;
        if (!$wp_filesystem->move(untrailingslashit($source), $destination, false)) {
            return new \WP_Error('ppush_directory', 'Não foi possível preparar o diretório da atualização.');
        }
        return trailingslashit($destination);
    }

    public static function completed($upgrader, array $options): void {
        $plugins = $options['plugins'] ?? (isset($options['plugin']) ? [$options['plugin']] : []);
        if (($options['type'] ?? '') === 'plugin' && in_array(plugin_basename(PPUSH_FILE), $plugins, true)) {
            self::clear();
        }
    }

    public static function auto_enabled(): bool {
        return in_array(plugin_basename(PPUSH_FILE), (array)get_site_option('auto_update_plugins', []), true);
    }

    public static function set_auto(bool $enabled): void {
        $plugins = array_values(array_diff((array)get_site_option('auto_update_plugins', []), [plugin_basename(PPUSH_FILE)]));
        if ($enabled) { $plugins[] = plugin_basename(PPUSH_FILE); }
        update_site_option('auto_update_plugins', $plugins);
    }
}
