<?php
/** Somente em WordPress descartável com SuperPWA: wp eval-file tests/superpwa.php */
use PauloMaeda\PortalPush\{Core, Pwa};

if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_parse_url(home_url('/'), PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
    exit('Execute por WP-CLI somente em uma instalação descartável em localhost.');
}
if (!function_exists('superpwa_sw_template')) { exit('Ative o SuperPWA na instalação descartável antes deste teste.'); }
$checks = [];
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $checks[] = $label;
};
add_filter('pre_http_request', static fn() => new WP_Error('ppush_test_network', 'Rede desativada durante o teste.'));
global $wpdb;
wp_set_current_user(1);
$path = superpwa_sw('abs');
$settings = get_option('superpwa_settings', []);
$original = is_file($path) ? file_get_contents($path) : null;
$version = get_option('ppush_pwa_worker_version', '');
$revision = get_option('ppush_pwa_worker_revision', 0);
$keys = get_option('ppush_vapid');
$roles = wp_roles()->roles;
$devices = $wpdb->get_var('SELECT COUNT(*) FROM ' . Core::table('subscriptions'));
try {
    $check(Pwa::provider() === 'SuperPWA', 'Detecta o SuperPWA instalado');
    $check(Pwa::repair() === true && Pwa::status()['ready'], 'Reparo gera e verifica o módulo atual no arquivo real do SuperPWA');
    $script = file_get_contents($path);
    $check(str_contains($script, 'fetch') && str_contains($script, 'PORTAL_PUSH_ACTIVATE'), 'Arquivo mantém navegação do PWA e inclui atualização por solicitação');
    $check(Pwa::inject($script) === $script, 'Minificação do SuperPWA não duplica a integração');
    $url = Pwa::superpwa_url(['url' => '/superpwa-sw.js?123&custom=value', 'other' => 'kept']);
    $check(str_contains($url['url'], 'portal_push_version=') && str_contains($url['url'], 'custom=value') && $url['other'] === 'kept', 'URL identifica revisão sem remover parâmetros do SuperPWA');
    $previous_revision = (int)get_option('ppush_pwa_worker_revision');
    file_put_contents($path, "'use strict'; /* componente antigo */");
    $current = superpwa_get_settings(); $current['is_static_sw'] = 1; update_option('superpwa_settings', $current);
    update_option('ppush_pwa_worker_version', 'SuperPWA:' . PPUSH_VERSION);
    $check(!Pwa::status()['ready'], 'Arquivo antigo é detectado mesmo com a versão já registrada');
    Pwa::refresh_files();
    $check(Pwa::status()['ready'] && (int)get_option('ppush_pwa_worker_revision') > $previous_revision, 'Atualização automática repara arquivo antigo e renova a URL de registro');
    unlink($path);
    $check(!Pwa::status()['ready'], 'Arquivo estático ausente é informado como pendente');
    Pwa::refresh_files();
    $check(is_file($path) && Pwa::status()['ready'], 'Arquivo estático removido é regenerado mesmo com a versão registrada');
    $current = superpwa_get_settings(); $current['is_static_sw'] = 0; update_option('superpwa_settings', $current);
    $check(Pwa::status()['ready'] && Pwa::status()['mode'] === 'gerado pelo WordPress', 'Template dinâmico do SuperPWA também é verificado');
    update_option('superpwa_settings', array_replace($current, ['is_static_sw' => 1]));
    if (Pwa::repair() !== true || !is_file($path)) { throw new RuntimeException('Não foi possível preparar o arquivo para verificar a autorização.'); }
    $before = file_get_contents($path); $before_revision = get_option('ppush_pwa_worker_revision');
    wp_set_current_user(0);
    $check(is_wp_error(Pwa::repair()) && file_get_contents($path) === $before && get_option('ppush_pwa_worker_revision') === $before_revision, 'Visitante não pode regenerar arquivos nem alterar a revisão');
    wp_set_current_user(1);
    $check($keys === get_option('ppush_vapid') && $roles === wp_roles()->roles && $devices === $wpdb->get_var('SELECT COUNT(*) FROM ' . Core::table('subscriptions')), 'Reparos preservam chaves, papéis e inscrições dos dispositivos');
    echo wp_json_encode(['passed' => count($checks), 'checks' => $checks, 'superpwa' => SUPERPWA_VERSION], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} finally {
    update_option('superpwa_settings', $settings);
    update_option('ppush_pwa_worker_version', $version);
    update_option('ppush_pwa_worker_revision', $revision);
    if ($original !== null) { file_put_contents($path, $original); }
    elseif (is_file($path)) { unlink($path); }
}
