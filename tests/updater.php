<?php
/** Somente em WordPress descartável em localhost: wp eval-file tests/updater.php */
use PauloMaeda\PortalPush\{Updater, Core};

if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_parse_url(home_url('/'), PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
    exit('Este teste altera a instalação e só pode ser executado por WP-CLI em localhost.');
}
global $wpdb, $ppush_update_checks;
$ppush_update_checks = [];
function ucheck(bool $ok, string $label): void {
    global $ppush_update_checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $ppush_update_checks[] = $label;
}

$root = dirname(__DIR__);
$stable = json_decode(file_get_contents($root . '/updates/portal-push.json'), true);
$stable_file = $root . '/dist/portal-push-' . $stable['version'] . '.zip';
$future = $stable;
$parts = array_map('intval', explode('.', $stable['version']));
$parts[2]++;
$future['version'] = implode('.', $parts);
$future['download_url'] = 'https://raw.githubusercontent.com/paulommaeda/push-notifications/main/dist/portal-push-' . $future['version'] . '.zip';
$future_file = wp_tempnam('portal-push-future.zip');
$input = new ZipArchive(); $input->open($stable_file);
$output = new ZipArchive(); $output->open($future_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
for ($i = 0; $i < $input->numFiles; $i++) {
    $name = $input->getNameIndex($i); $content = $input->getFromIndex($i);
    if ($name === 'portal-push/portal-push.php') { $content = str_replace($stable['version'], $future['version'], $content); }
    $output->addFromString($name, $content);
}
$output->close(); $input->close();
$future['sha256'] = hash_file('sha256', $future_file);
$mode = 'ok'; $metadata = $future; $requests = 0; $last_download = '';
$http = static function ($pre, array $args, string $url) use (&$mode, &$metadata, &$requests, &$last_download, $future_file) {
    if ($url === Updater::MANIFEST) {
        $requests++;
        if ($mode === 'unavailable') { return new WP_Error('offline', 'Simulação de indisponibilidade.'); }
        return ['headers' => [], 'body' => $mode === 'json_invalid' ? '{bad' : wp_json_encode($metadata), 'response' => ['code' => 200, 'message' => 'OK']];
    }
    if ($url === $metadata['download_url']) {
        $last_download = $args['filename'];
        copy($future_file, $last_download);
        if ($mode === 'corrupted') { file_put_contents($last_download, 'corrompido', FILE_APPEND); }
        return ['headers' => [], 'body' => '', 'filename' => $last_download, 'response' => ['code' => 200, 'message' => 'OK']];
    }
    if (str_contains($url, 'api.wordpress.org/plugins/update-check')) {
        return ['headers' => [], 'body' => wp_json_encode(['plugins' => [], 'no_update' => [], 'translations' => []]), 'response' => ['code' => 200, 'message' => 'OK']];
    }
    if (str_contains($url, 'api.wordpress.org/core/version-check')) {
        return ['headers' => [], 'body' => wp_json_encode(['offers' => [], 'translations' => []]), 'response' => ['code' => 200, 'message' => 'OK']];
    }
    if (str_contains($url, 'api.wordpress.org/themes/update-check')) {
        return ['headers' => [], 'body' => wp_json_encode(['themes' => [], 'no_update' => [], 'translations' => []]), 'response' => ['code' => 200, 'message' => 'OK']];
    }
    return new WP_Error('ppush_test_network', 'Rede externa bloqueada durante o teste: ' . $url);
};
add_filter('pre_http_request', $http, 10, 3);
wp_set_current_user(1);
$old_auto = get_site_option('auto_update_plugins', []);
try {
    ucheck(!is_wp_error(Updater::validate($stable)), 'Manifesto gerado pelo empacotador é válido');
    foreach ([
        ['slug', 'outro-plugin'], ['schema', 99], ['version', '0.3.0-beta'],
        ['version', '../evil'], ['download_url', 'https://attacker.test/plugin.zip'],
        ['download_url', str_replace('/paulommaeda/', '/outro/', $future['download_url'])],
        ['sha256', 'invalido'], ['sha256', []], ['requires_php', []], ['requires', '<script>'],
    ] as [$field, $value]) {
        $bad = $future; $bad[$field] = $value;
        ucheck(is_wp_error(Updater::validate($bad)), 'Manifesto inválido é rejeitado: ' . $field);
    }
    $safe = $future; $safe['sections']['description'] = '<script>alert(1)</script><a href="javascript:alert(1)">link</a>';
    $safe = Updater::validate($safe);
    ucheck(!str_contains($safe['sections']['description'], '<script') && !str_contains($safe['sections']['description'], 'javascript:'), 'Detalhes da atualização têm HTML perigoso removido');
    Updater::clear(); $first = Updater::metadata(); $second = Updater::metadata();
    ucheck(!is_wp_error($first) && $first === $second && $requests === 1, 'Cache evita consultas repetidas ao GitHub');
    $untouched = ['other' => true];
    ucheck(Updater::update($untouched, ['UpdateURI' => Updater::REPOSITORY], 'another/another.php') === $untouched, 'Atualizador não interfere em outro plugin GitHub');
    ucheck(Updater::update($untouched, ['UpdateURI' => 'https://github.com/other/repo'], plugin_basename(PPUSH_FILE)) === $untouched, 'Update URI incorreto não é atendido');
    $update = Updater::update(false, ['UpdateURI' => Updater::REPOSITORY], plugin_basename(PPUSH_FILE));
    ucheck($update['version'] === $future['version'] && $update['package'] === $future['download_url'], 'Filtro nativo informa a nova versão e o ZIP correto');
    $info = Updater::information(false, 'plugin_information', (object)['slug' => 'portal-push']);
    ucheck($info->version === $future['version'] && $info->download_link === $future['download_url'], 'Modal de detalhes usa os metadados do próprio plugin');
    ucheck(Updater::information($untouched, 'query_plugins', (object)['slug' => 'portal-push']) === $untouched, 'Consulta de catálogo permanece intacta');
    ucheck(Updater::information($untouched, 'plugin_information', (object)['slug' => 'outro']) === $untouched, 'Modal de outros plugins permanece intacto');

    $mode = 'unavailable'; Updater::clear(); $before = $requests;
    ucheck(is_wp_error(Updater::metadata()) && is_wp_error(Updater::metadata()) && $requests === $before + 1, 'Falha de rede é armazenada para evitar excesso de requisições');
    ucheck(Updater::update(false, ['UpdateURI' => Updater::REPOSITORY], plugin_basename(PPUSH_FILE)) === false, 'Indisponibilidade não anuncia um pacote inválido');
    $mode = 'json_invalid'; Updater::clear();
    ucheck(is_wp_error(Updater::metadata()), 'JSON malformado é rejeitado');
    $mode = 'ok'; Updater::clear();
    update_site_option('auto_update_plugins', ['another/another.php']);
    Updater::set_auto(true);
    ucheck(Updater::auto_enabled() && in_array('another/another.php', get_site_option('auto_update_plugins'), true), 'Ativação automática preserva a preferência dos outros plugins');
    Updater::set_auto(false);
    ucheck(!Updater::auto_enabled() && get_site_option('auto_update_plugins') === ['another/another.php'], 'Desativação automática preserva os outros plugins');

    $file = Updater::download(false, $future['download_url'], null, ['plugin' => plugin_basename(PPUSH_FILE)]);
    ucheck(is_string($file) && is_file($file) && hash_file('sha256', $file) === $future['sha256'], 'Download correto passa na verificação SHA-256');
    wp_delete_file($file);
    $mode = 'corrupted';
    $bad = Updater::download(false, $future['download_url'], null, ['plugin' => plugin_basename(PPUSH_FILE)]);
    ucheck(is_wp_error($bad) && $bad->get_error_code() === 'ppush_checksum' && !file_exists($last_download), 'ZIP adulterado é bloqueado e removido antes da instalação');
    $mode = 'ok';
    ucheck(is_wp_error(Updater::download(false, 'https://attacker.test/plugin.zip', null, ['plugin' => plugin_basename(PPUSH_FILE)])), 'Pacote diferente do manifesto é bloqueado');
    ucheck(Updater::download(false, 'https://example.test/other.zip', null, ['plugin' => 'other/plugin.php']) === false, 'Download de outro plugin permanece intacto');
    $existing_error = new WP_Error('another', 'Outro filtro recusou');
    ucheck(Updater::download($existing_error, $future['download_url'], null, ['plugin' => plugin_basename(PPUSH_FILE)]) === $existing_error, 'Recusa de outro filtro é preservada');

    require_once ABSPATH . 'wp-admin/includes/file.php';
    if (!defined('FS_METHOD')) { define('FS_METHOD', 'direct'); }
    WP_Filesystem();
    $temporary = get_temp_dir() . 'ppush-update-test-' . wp_generate_uuid4() . '/';
    wp_mkdir_p($temporary . 'push-notifications-main/vendor');
    file_put_contents($temporary . 'push-notifications-main/portal-push.php', '<?php');
    file_put_contents($temporary . 'push-notifications-main/vendor/autoload.php', '<?php');
    $prepared = Updater::source($temporary . 'push-notifications-main/', $temporary, null, ['plugin' => plugin_basename(PPUSH_FILE)]);
    ucheck(is_string($prepared) && is_file($prepared . 'portal-push.php') && basename(untrailingslashit($prepared)) === dirname(plugin_basename(PPUSH_FILE)), 'Pasta extraída é normalizada para o diretório instalado');
    wp_delete_file($prepared . 'vendor/autoload.php');
    ucheck(is_wp_error(Updater::source($prepared, $temporary, null, ['plugin' => plugin_basename(PPUSH_FILE)])), 'Pacote sem dependências é rejeitado');
    global $wp_filesystem;
    $wp_filesystem->delete($temporary, true);

    $snapshot = ['keys' => get_option('ppush_vapid'), 'roles' => wp_roles()->roles, 'devices' => $wpdb->get_var('SELECT COUNT(*) FROM ' . Core::table('subscriptions'))];
    Updater::clear(); delete_site_transient('update_plugins'); wp_clean_plugins_cache(true); wp_update_plugins();
    $native = get_site_transient('update_plugins');
    ucheck(isset($native->response[plugin_basename(PPUSH_FILE)]) && $native->response[plugin_basename(PPUSH_FILE)]->new_version === $future['version'], 'WordPress detecta a nova versão pelo mecanismo nativo');
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    // O modo automático mantém o plugin ativo; o modo manual usa uma etapa de reativação pelo painel.
    if (!defined('DOING_CRON')) { define('DOING_CRON', true); }
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->upgrade(plugin_basename(PPUSH_FILE), ['clear_update_cache' => false]);
    if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
    if ($result !== true) { throw new RuntimeException('Upgrade retornou ' . var_export($result, true) . '; ' . wp_json_encode($upgrader->skin->get_errors())); }
    $headers = get_file_data(PPUSH_FILE, ['Version' => 'Version']);
    ucheck($headers['Version'] === $future['version'], 'Atualização real pelo Plugin_Upgrader instala a nova versão');
    ucheck(is_plugin_active(plugin_basename(PPUSH_FILE)), 'Plugin continua ativo após a atualização');
    ucheck($snapshot['keys'] === get_option('ppush_vapid'), 'Atualização preserva as chaves de envio');
    ucheck($snapshot['roles'] === wp_roles()->roles, 'Atualização preserva os papéis do WordPress');
    ucheck($snapshot['devices'] === $wpdb->get_var('SELECT COUNT(*) FROM ' . Core::table('subscriptions')), 'Atualização preserva os dispositivos');
    $report = ['passed' => count($ppush_update_checks), 'checks' => $ppush_update_checks];
    file_put_contents(get_temp_dir() . 'portal-push-updater-report.json', wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} finally {
    remove_filter('pre_http_request', $http, 10);
    update_site_option('auto_update_plugins', $old_auto);
    Updater::clear();
    wp_delete_file($future_file);
}
