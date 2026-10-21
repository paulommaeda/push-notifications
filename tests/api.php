<?php
/** Somente em WordPress descartável: wp eval-file tests/api.php */
use PauloMaeda\PortalPush\{Core, Templates, Campaigns};
if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_parse_url(home_url('/'), PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
    exit('Execute por WP-CLI somente em uma instalação descartável em localhost.');
}
global $wpdb;
$checks = []; $created = []; $errors = []; $users = [];
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    $checks[] = $name;
};
$on_create = static function (int $id) use (&$created): void { $created[] = $id; };
$on_error = static function ($error, $key) use (&$errors): void { $errors[] = [$error, $key]; };
add_action('portal_push_campaign_created', $on_create);
add_action('portal_push_integration_error', $on_error, 10, 2);
$old_templates = get_option('ppush_templates', []);
$suffix = substr(wp_generate_uuid4(), 0, 8);
$key = 'teste_api_' . $suffix;
$count = static fn() => (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . Core::table('campaigns'));
$campaign = static fn($id) => $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Core::table('campaigns') . ' WHERE id=%d', $id));
wp_set_current_user(1);
try {
    foreach (['a', 'b'] as $person) {
        $id = wp_insert_user(['user_login' => 'api_' . $suffix . $person, 'user_email' => 'api_' . $suffix . $person . '@example.test', 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        $users[] = $id;
    }
    [$a, $b] = $users;
    $model = ['key' => $key, 'name' => 'Teste da API', 'title' => 'Concluída: {tarefa}', 'message' => 'Olá {nome}, a tarefa {tarefa} foi concluída.', 'url' => home_url('/tarefas/?id={tarefa_id}'), 'audience_type' => 'dynamic', 'enabled' => 1];
    $check(Templates::save($model) === true, 'Administrador cadastra modelo com variáveis');
    $check(Templates::variables(Templates::get($key)) === ['tarefa', 'nome', 'tarefa_id'], 'Modelo informa as variáveis necessárias');
    $check(is_wp_error(Templates::save($model)), 'Chave duplicada não sobrescreve outro modelo');
    $check(is_wp_error(Templates::save(array_replace($model, ['key' => '../invalid']))), 'Chave inválida é rejeitada');
    $check(is_wp_error(Templates::save(array_replace($model, ['key' => $key . '_evil', 'url' => 'https://{host}/']))), 'Variável não pode controlar o domínio do link');
    $encoded = array_replace($model, ['key' => $key . '_encoded', 'url' => home_url('/not%C3%ADcias/?id={tarefa_id}')]);
    $check(Templates::save($encoded) === true && Templates::get($encoded['key'])['url'] === $encoded['url'], 'Modelo preserva os caracteres codificados de um permalink');
    $context = ['user_id' => $a, 'variables' => ['nome' => 'Ana', 'tarefa' => 'Contrato', 'tarefa_id' => '8&outra=9'], 'event_id' => 'tarefa:8:concluida'];
    $before = $count();
    $cid = portal_push_notify($key, $context);
    $check(is_int($cid) && $count() === $before + 1, 'Função pública cria uma campanha na fila');
    $row = $campaign($cid);
    $check($row->title === 'Concluída: Contrato' && $row->message === 'Olá Ana, a tarefa Contrato foi concluída.', 'Título e mensagem recebem as variáveis do evento');
    parse_str(wp_parse_url($row->url, PHP_URL_QUERY), $query);
    $check($query === ['id' => '8&outra=9'], 'Variável no link é codificada sem injetar outros parâmetros');
    $check($row->audience_type === 'people' && json_decode($row->audience_ids, true) === [$a], 'Destinatário é o ID da conta WordPress');
    $check(portal_push_notify($key, $context) === $cid && $count() === $before + 1, 'Repetir event_id reutiliza a campanha do mesmo modelo e público');
    $check(portal_push_notify($key, array_replace($context, ['user_id' => (string)$a])) === $cid, 'ID numérico recebido como texto identifica a mesma conta e o mesmo evento');
    $other = portal_push_notify($key, array_replace($context, ['user_id' => $b]));
    $check(is_int($other) && $other !== $cid, 'Outro destinatário recebe sua própria campanha para o mesmo evento');
    $batch = array_diff_key($context, ['user_id' => 1]); $batch['audience_type'] = 'people'; $batch['audience_ids'] = [$a, $b];
    $batch_id = portal_push_notify($key, $batch); $batch['audience_ids'] = [$b, $a];
    $check(is_int($batch_id) && portal_push_notify($key, $batch) === $batch_id, 'Ordem dos destinatários não duplica o evento');
    $before = $count();
    foreach ([
        ['user_id' => 0], ['user_id' => -1], ['user_id' => 999999999],
        ['user_id' => false], ['user_id' => 1.5],
        ['audience_type' => 'people', 'audience_ids' => []],
        ['audience_type' => 'roles', 'audience_ids' => ['nao_existe']],
        ['audience_type' => 'all', 'audience_ids' => [$a]],
    ] as $invalid) {
        $args = array_merge(array_diff_key($context, ['user_id' => 1]), $invalid);
        $check(is_wp_error(portal_push_notify($key, $args)), 'Público inválido rejeitado: ' . wp_json_encode($invalid));
    }
    $check($count() === $before, 'Públicos inválidos não criam campanhas');
    $check(is_wp_error(portal_push_notify($key, ['variables' => $context['variables']])), 'Modelo dinâmico não envia sem destinatário');
    $check(is_wp_error(portal_push_notify($key, array_merge($context, ['audience_type' => 'all', 'audience_ids' => []]))), 'user_id não pode ser combinado com outro público');
    $check(is_wp_error(portal_push_notify($key, array_replace($context, ['variables' => ['nome' => 'Ana']]))), 'Variável ausente é informada como erro');
    $check(is_wp_error(portal_push_notify($key, array_replace($context, ['variables' => ['nome' => ['Ana']]]))), 'Variável composta é rejeitada');
    $long = $context; $long['variables']['tarefa'] = str_repeat('x', 301); unset($long['event_id']);
    $check(is_wp_error(portal_push_notify($key, $long)), 'Expansão acima dos limites da notificação é rejeitada');
    $check(is_wp_error(portal_push_notify($key, array_replace($context, ['url' => 'https://attacker.test/']))), 'Integração não pode apontar o clique para outro domínio');
    $override = $context; $override['url'] = home_url('/outro/'); unset($override['variables']['tarefa_id'], $override['event_id']);
    $override_id = portal_push_notify($key, $override);
    $check(is_int($override_id) && $campaign($override_id)->url === home_url('/outro/'), 'Plugin pode informar outro link do próprio portal');
    $check(is_wp_error(portal_push_notify($key, array_replace($context, ['event_id' => '']))), 'Chave de evento vazia é rejeitada');
    $no_event = $context; unset($no_event['event_id']);
    $check(portal_push_notify($key, $no_event) !== portal_push_notify($key, $no_event), 'Eventos sem chave continuam gerando campanhas independentes');
    $roles = array_replace($model, ['key' => $key . '_roles', 'audience_type' => 'roles', 'role_slugs' => ['editor']]);
    $check(Templates::save($roles) === true, 'Modelo pode ter perfis do WordPress como público padrão');
    $role_id = portal_push_notify($roles['key'], ['variables' => $context['variables']]);
    $check(is_int($role_id) && $campaign($role_id)->audience_type === 'roles' && json_decode($campaign($role_id)->audience_ids, true) === ['editor'], 'Evento usa os papéis pré-configurados quando não informa outro público');
    $all = array_replace($model, ['key' => $key . '_all', 'audience_type' => 'all']);
    $check(is_wp_error(Templates::save($all)), 'Modelo geral exige confirmação administrativa');
    $all['confirm_all'] = 1;
    $check(Templates::save($all) === true && is_int(portal_push_notify($all['key'], ['variables' => $context['variables']])), 'Público geral pré-configurado pode ser disparado conscientemente');
    $paused = array_replace($model, ['enabled' => 0]);
    $check(Templates::save($paused, $key) === true && is_wp_error(portal_push_notify($key, $context)), 'Modelo pausado bloqueia novos disparos');
    Templates::save($model, $key);
    $before = $count();
    $action = array_replace($context, ['event_id' => 'hook:1']);
    do_action('portal_push_notify', $key, $action); do_action('portal_push_notify', $key, $action);
    $check($count() === $before + 1, 'Gancho público dispara o modelo e respeita a chave do evento');
    $before_errors = count($errors);
    do_action('portal_push_notify', 'nao_existe', $context);
    $check(count($errors) === $before_errors + 1 && is_wp_error(end($errors)[0]), 'Falha pelo gancho publica WP_Error para o integrador acompanhar');
    wp_set_current_user($a);
    $check(is_wp_error(Templates::save(array_replace($model, ['key' => $key . '_no']))) && is_wp_error(Templates::delete($key)), 'Usuário comum não pode gerenciar modelos');
    $check(is_int(portal_push_notify($key, array_replace($context, ['event_id' => 'servidor:1']))), 'Código confiável do plugin pode disparar eventos sem sessão de administrador');
    wp_set_current_user(1);
    $before = $count();
    $missed = false;
    $race = static function (string $sql) use (&$missed, $wpdb): string {
        if (!$missed && str_starts_with($sql, 'SELECT id FROM ' . Core::table('campaigns') . ' WHERE idempotency_hash=')) {
            $missed = true; return $sql . ' AND 1=0';
        }
        return $sql;
    };
    add_filter('query', $race);
    try { $raced = portal_push_notify($key, $context); }
    finally { remove_filter('query', $race); }
    $check($missed && $raced === $cid && $count() === $before, 'Índice único impede duplicidade mesmo quando a consulta inicial não enxerga a campanha existente');
    $legacy = ['title' => 'API antiga', 'message' => 'Continua disponível.', 'url' => home_url('/'), 'audience_type' => 'people', 'audience_ids' => [$a]];
    $legacy1 = portal_push_send($legacy); $legacy2 = portal_push_send($legacy);
    $check(is_int($legacy1) && is_int($legacy2) && $legacy1 !== $legacy2, 'API portal_push_send permanece compatível e aceita várias campanhas sem chave');
    $legacy['idempotency_key'] = 'teste:' . $suffix;
    $legacy3 = portal_push_send($legacy);
    $check(is_int($legacy3) && portal_push_send($legacy) === $legacy3, 'API direta também aceita chave para impedir repetição do evento');
    $check(Templates::delete($key) === true && is_wp_error(portal_push_notify($key, $context)) && $campaign($cid)->title === 'Concluída: Contrato', 'Excluir modelo preserva o conteúdo das campanhas já criadas');
    echo wp_json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} finally {
    remove_action('portal_push_campaign_created', $on_create);
    remove_action('portal_push_integration_error', $on_error, 10);
    foreach (array_unique($created) as $id) {
        $wpdb->delete(Core::table('deliveries'), ['campaign_id' => $id]);
        $wpdb->delete(Core::table('campaigns'), ['id' => $id]);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $id) { wp_delete_user($id); }
    update_option('ppush_templates', $old_templates, false);
}
