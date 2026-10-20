<?php
/** Somente em WordPress descartável: wp eval-file tests/roles.php */
use PauloMaeda\PortalPush\{Audience, Campaigns, Core, Installer, Privacy};

if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_parse_url(home_url('/'), PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
    exit('Execute por WP-CLI somente em uma instalação descartável em localhost.');
}
global $wpdb;
$checks = [];
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    $checks[] = $name;
};
$suffix = substr(wp_generate_uuid4(), 0, 8);
$custom = 'ppush_test_' . $suffix;
add_role($custom, 'Perfil personalizado de teste', ['read' => true]);
$id = wp_insert_user(['user_login' => 'ppush_' . $suffix, 'user_pass' => wp_generate_password(32), 'user_email' => $suffix . '@example.test', 'role' => 'subscriber']);
if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
$campaigns = [];
$legacy_notice = get_option('ppush_legacy_campaigns_cancelled', 0);
try {
    $check(isset(Audience::roles()['subscriber'], Audience::roles()['editor'], Audience::roles()[$custom]), 'Papéis padrão e personalizados aparecem diretamente do WordPress');
    $user = new WP_User($id); $user->add_role($custom);
    $check(in_array($custom, Audience::user_roles($id), true) && in_array('subscriber', Audience::user_roles($id), true), 'Vários papéis da conta são reconhecidos sem cadastro de grupos');
    $base = ['title' => 'Teste de papéis', 'message' => 'Mensagem local', 'url' => home_url('/'), 'audience_type' => 'roles', 'audience_ids' => [$custom, 'subscriber']];
    $native_roles = wp_roles()->roles;
    $cid = Campaigns::create($base); $campaigns[] = $cid;
    $check(is_int($cid), 'Campanha aceita identificadores de papéis do WordPress');
    $campaign = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Core::table('campaigns') . ' WHERE id=%d', $cid));
    $check(Audience::matches($id, $campaign), 'Usuário com um dos papéis selecionados pertence ao público');
    (new WP_User($id))->set_role('editor');
    $check(!Audience::matches($id, $campaign), 'Mudança de papel no WordPress retira o destinatário imediatamente');
    foreach ([[], [1], ['1'], ['nao_existe'], ['subscriber', 'nao_existe'], [['subscriber']]] as $invalid) {
        $check(is_wp_error(Campaigns::create(array_replace($base, ['audience_ids' => $invalid]))), 'Público inválido não cria campanha nem vira envio geral: ' . wp_json_encode($invalid));
    }
    $check(is_wp_error(Campaigns::create(array_replace($base, ['audience_type' => 'profiles', 'audience_ids' => [1]]))), 'Antigos IDs numéricos de grupos são rejeitados pela API');
    $check(wp_roles()->roles === $native_roles && get_userdata($id)->roles === ['editor'], 'Segmentar campanhas não altera papéis nem capacidades');
    $keys = get_option('ppush_vapid');
    $legacy = Campaigns::create(array_replace($base, ['audience_type' => 'people', 'audience_ids' => [$id]])); $campaigns[] = $legacy;
    $wpdb->update(Core::table('campaigns'), ['audience_type' => 'profiles', 'audience_ids' => '[1]'], ['id' => $legacy]);
    $wpdb->insert(Core::table('deliveries'), ['campaign_id' => $legacy, 'subscription_id' => 99999999, 'user_id' => $id, 'status' => 'pending', 'next_attempt_at' => Core::now(), 'error_message' => '']);
    Installer::migrate_legacy_audiences();
    $check($wpdb->get_var($wpdb->prepare('SELECT status FROM ' . Core::table('campaigns') . ' WHERE id=%d', $legacy)) === 'cancelled', 'Migração cancela campanhas pendentes dos antigos grupos');
    $check($wpdb->get_var($wpdb->prepare('SELECT status FROM ' . Core::table('deliveries') . ' WHERE campaign_id=%d', $legacy)) === 'cancelled', 'Migração cancela as entregas antigas pendentes');
    $check(get_option('ppush_vapid') === $keys && get_userdata($id)->roles === ['editor'], 'Migração preserva chaves e papéis de acesso');
    Privacy::erase(get_userdata($id)->user_email);
    $check(get_userdata($id)->roles === ['editor'] && !Core::enabled($id), 'Apagar dados de push preserva o papel da conta e desativa o recebimento');
    echo wp_json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} finally {
    foreach ($campaigns as $cid) {
        $wpdb->delete(Core::table('deliveries'), ['campaign_id' => $cid]);
        $wpdb->delete(Core::table('campaigns'), ['id' => $cid]);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($id); remove_role($custom);
    update_option('ppush_legacy_campaigns_cancelled', $legacy_notice, false);
}
