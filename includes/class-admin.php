<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Admin {
    public static function boot(): void {
        add_action('admin_menu', static function (): void {
            add_menu_page('Portal Push', 'Portal Push', 'manage_portal_push', 'portal-push', [self::class, 'page'], 'dashicons-bell', 58);
        });
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_ppush_action', [self::class, 'handle']);
    }

    public static function assets(string $hook): void {
        if ($hook !== 'toplevel_page_portal-push') {
            return;
        }
        wp_enqueue_style('ppush-admin', PPUSH_URL . 'assets/admin.css', [], PPUSH_VERSION);
        wp_enqueue_script('ppush-admin', PPUSH_URL . 'assets/admin.js', [], PPUSH_VERSION, true);
        wp_localize_script('ppush-admin', 'PortalPushAdmin', ['api' => rest_url('portal-push/v1/'), 'nonce' => wp_create_nonce('wp_rest')]);
    }

    private static function url(string $tab, array $args = []): string {
        return add_query_arg(array_merge(['page' => 'portal-push', 'tab' => $tab], $args), admin_url('admin.php'));
    }

    private static function form(string $task): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ppush-form">';
        echo '<input type="hidden" name="action" value="ppush_action"><input type="hidden" name="task" value="' . esc_attr($task) . '">';
        wp_nonce_field('ppush_admin', 'ppush_nonce');
    }

    private static function finish(string $tab, string $message, bool $error = false): never {
        set_transient('ppush_notice_' . get_current_user_id(), ['message' => $message, 'error' => $error], 60);
        wp_safe_redirect(self::url($tab));
        exit;
    }

    private static function fail(string $tab, \WP_Error $error): never {
        self::finish($tab, $error->get_error_message(), true);
    }

    public static function handle(): void {
        if (!current_user_can('manage_portal_push')) {
            wp_die('Acesso não permitido.', '', ['response' => 403]);
        }
        check_admin_referer('ppush_admin', 'ppush_nonce');
        global $wpdb;
        $task = sanitize_key(wp_unslash($_POST['task'] ?? ''));
        $data = wp_unslash($_POST);
        switch ($task) {
            case 'profile_save':
                $id = absint($data['id'] ?? 0);
                $name = sanitize_text_field($data['name'] ?? '');
                if (!$name || Core::length($name) > 120) {
                    self::finish('profiles', 'Informe um nome de até 120 caracteres.', true);
                }
                $values = ['name' => $name, 'description' => sanitize_textarea_field($data['description'] ?? '')];
                if ($id) {
                    $ok = $wpdb->update(Core::table('profiles'), $values, ['id' => $id]);
                } else {
                    $slug = sanitize_title($name) ?: 'perfil';
                    $values['slug'] = substr($slug, 0, 140) . '-' . strtolower(wp_generate_password(8, false));
                    $values['created_at'] = Core::now();
                    $ok = $wpdb->insert(Core::table('profiles'), $values);
                }
                self::finish('profiles', $ok === false ? 'Não foi possível salvar o perfil.' : 'Perfil salvo.', $ok === false);
            case 'profile_delete':
                $id = absint($data['id'] ?? 0);
                $wpdb->delete(Core::table('memberships'), ['profile_id' => $id]);
                $wpdb->delete(Core::table('profiles'), ['id' => $id]);
                self::finish('profiles', 'Perfil excluído. As contas das pessoas foram preservadas.');
            case 'person_save':
                $id = absint($data['id'] ?? 0);
                $ids = Core::ids($data['profiles'] ?? []);
                $valid = array_map(static fn($p) => (int)$p->id, Audience::profiles());
                if (array_diff($ids, $valid)) {
                    self::finish('people', 'Um dos perfis selecionados não existe.', true);
                }
                if (!$id) {
                    if (!current_user_can('create_users')) {
                        self::finish('people', 'Sua conta não pode criar usuários.', true);
                    }
                    $name = sanitize_text_field($data['name'] ?? '');
                    $email = sanitize_email($data['email'] ?? '');
                    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
                    if (!$name || !is_email($email) || strlen($password) < 12 || strlen($password) > 4096) {
                        self::finish('people', 'Informe nome, e-mail válido e senha com pelo menos 12 caracteres.', true);
                    }
                    if (email_exists($email)) {
                        self::finish('people', 'Este e-mail já possui conta. Localize a pessoa na lista e edite os perfis.', true);
                    }
                    $login = substr(sanitize_user(explode('@', $email)[0], true) ?: 'pessoa', 0, 40);
                    do { $candidate = $login . '-' . strtolower(wp_generate_password(6, false)); } while (username_exists($candidate));
                    $created = wp_insert_user(['user_login' => $candidate, 'user_email' => $email, 'user_pass' => $password, 'display_name' => $name, 'first_name' => $name, 'role' => 'portal_push_member']);
                    if (is_wp_error($created)) {
                        self::fail('people', $created);
                    }
                    $id = (int)$created;
                }
                $result = Audience::set_profiles($id, $ids);
                if (is_wp_error($result)) {
                    self::fail('people', $result);
                }
                update_user_meta($id, '_ppush_disabled', empty($data['enabled']) ? '1' : '0');
                self::finish('people', 'Pessoa salva. O acesso é feito com o e-mail e a senha da conta.');
            case 'subscription_revoke':
                $wpdb->update(Core::table('subscriptions'), ['active' => 0, 'updated_at' => Core::now()], ['id' => absint($data['id'] ?? 0)]);
                self::finish('people', 'Dispositivo desativado.');
            case 'campaign_create':
                if (is_wp_error(Transport::keys())) {
                    self::finish('compose', 'Gere as chaves de envio em Configurações.', true);
                }
                $type = sanitize_key($data['audience_type'] ?? '');
                if ($type === 'all' && empty($data['confirm_all'])) {
                    self::finish('compose', 'Confirme o envio geral para continuar.', true);
                }
                $args = [
                    'title' => $data['title'] ?? '', 'message' => $data['message'] ?? '',
                    'url' => $data['url'] ?? '', 'image' => $data['image'] ?? '',
                    'audience_type' => $type,
                    'audience_ids' => $type === 'people' ? ($data['person_ids'] ?? []) : ($data['profile_ids'] ?? []),
                    'ttl' => (int)($data['ttl'] ?? 86400),
                ];
                if (!empty($data['scheduled_local'])) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $data['scheduled_local'], wp_timezone());
                    $errors = \DateTimeImmutable::getLastErrors();
                    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->getTimestamp() < time() - 60) {
                        self::finish('compose', 'Informe um agendamento válido no futuro.', true);
                    }
                    $args['scheduled_at'] = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
                $result = Campaigns::create($args);
                if (is_wp_error($result)) {
                    self::fail('compose', $result);
                }
                self::finish('campaigns', 'Campanha #' . $result . ' criada e colocada na fila.');
            case 'campaign_cancel':
                Campaigns::cancel(absint($data['id'] ?? 0));
                self::finish('campaigns', 'Campanha cancelada. Envios já iniciados podem terminar.');
            case 'queue_run':
                Queue::run();
                self::finish('campaigns', 'A fila foi processada. Consulte os resultados abaixo.');
            case 'updates_auto':
                if (!current_user_can('update_plugins')) {
                    self::finish('settings', 'Sua conta não pode alterar as atualizações de plugins.', true);
                }
                Updater::set_auto(!empty($data['auto_updates']));
                self::finish('settings', 'Preferência de atualização automática salva no WordPress.');
            case 'updates_check':
                if (!current_user_can('update_plugins')) {
                    self::finish('settings', 'Sua conta não pode verificar atualizações de plugins.', true);
                }
                Updater::clear();
                $latest = Updater::metadata();
                if (is_wp_error($latest)) { self::fail('settings', $latest); }
                wp_clean_plugins_cache(true);
                wp_update_plugins();
                self::finish('settings', version_compare($latest['version'], PPUSH_VERSION, '>') ? 'Versão ' . $latest['version'] . ' disponível. Abra Plugins para atualizar.' : 'Você já utiliza a versão mais recente.');
            case 'settings_save':
                $contact = sanitize_email($data['contact'] ?? '');
                if (!is_email($contact)) {
                    self::finish('settings', 'Informe um e-mail técnico válido.', true);
                }
                $page = absint($data['portal_page'] ?? 0);
                if ($page && get_post_type($page) !== 'page') {
                    self::finish('settings', 'Selecione uma página válida.', true);
                }
                update_option('ppush_settings', [
                    'contact' => $contact, 'portal_page' => $page,
                    'icon' => esc_url_raw($data['icon'] ?? '', ['https']),
                    'default_ttl' => max(60, min(604800, (int)($data['default_ttl'] ?? 86400))),
                    'retain_on_uninstall' => empty($data['retain_on_uninstall']) ? 0 : 1,
                ], false);
                self::finish('settings', 'Configurações salvas.');
            case 'keys_generate':
                $result = Transport::generate_keys();
                if (is_wp_error($result)) {
                    self::fail('settings', $result);
                }
                self::finish('settings', 'Chaves de envio prontas.');
            case 'page_create':
                if (!current_user_can('publish_pages')) {
                    self::finish('settings', 'Sua conta não pode publicar páginas.', true);
                }
                $settings = Core::settings();
                if ($settings['portal_page'] && get_post_status((int)$settings['portal_page']) === 'publish') {
                    self::finish('settings', 'Já existe uma página vinculada.');
                }
                $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Minhas notificações', 'post_content' => '[portal_push]'], true);
                if (is_wp_error($id)) {
                    self::fail('settings', $id);
                }
                $settings['portal_page'] = $id;
                update_option('ppush_settings', $settings, false);
                self::finish('settings', 'Página de notificações criada.');
            default:
                self::finish('dashboard', 'Operação desconhecida.', true);
        }
    }

    public static function page(): void {
        if (!current_user_can('manage_portal_push')) {
            return;
        }
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'dashboard'));
        $tabs = ['dashboard' => 'Visão geral', 'people' => 'Pessoas', 'profiles' => 'Perfis', 'compose' => 'Nova notificação', 'campaigns' => 'Campanhas', 'settings' => 'Configurações'];
        if (!isset($tabs[$tab])) {
            $tab = 'dashboard';
        }
        echo '<div class="wrap ppush-admin"><div class="ppush-header"><div><span class="ppush-kicker">COMUNICAÇÃO DO PORTAL</span><h1>Portal Push <small>' . esc_html(PPUSH_VERSION) . '</small></h1><p>A mensagem certa para cada pessoa.</p></div><a class="button button-primary" href="' . esc_url(self::url('compose')) . '">Criar notificação</a></div>';
        $notice = get_transient('ppush_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('ppush_notice_' . get_current_user_id());
            echo '<div class="notice ' . ($notice['error'] ? 'notice-error' : 'notice-success') . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
        }
        echo '<nav class="ppush-nav" aria-label="Seções">';
        foreach ($tabs as $key => $label) {
            echo '<a ' . ($tab === $key ? 'aria-current="page"' : '') . ' href="' . esc_url(self::url($key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        match ($tab) {
            'people' => self::people(), 'profiles' => self::profiles(), 'compose' => self::compose(),
            'campaigns' => self::campaigns(), 'settings' => self::settings(), default => self::dashboard(),
        };
        echo '</div>';
    }

    private static function dashboard(): void {
        global $wpdb;
        $counts = [
            'Pessoas no WordPress' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
            'Perfis do portal' => (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . Core::table('profiles')),
            'Dispositivos ativos' => (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . Core::table('subscriptions') . ' WHERE active=1'),
            'Campanhas' => (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . Core::table('campaigns')),
        ];
        echo '<div class="ppush-metrics">';
        foreach ($counts as $label => $count) {
            echo '<div><span>' . esc_html($label) . '</span><strong>' . esc_html(number_format_i18n($count)) . '</strong></div>';
        }
        echo '</div><div class="ppush-panel"><h2>Prepare seu portal</h2><ol><li>Gere as chaves em <a href="' . esc_url(self::url('settings')) . '">Configurações</a>.</li><li>Crie os perfis e vincule as pessoas.</li><li>Adicione <code>[portal_push]</code> a uma página ou crie a página nas configurações.</li><li>Peça que cada pessoa entre e ative os avisos no próprio dispositivo.</li><li>Crie uma campanha para uma pessoa, vários perfis ou todos.</li></ol><p>Os perfis servem para organizar os destinatários. As permissões de acesso continuam sendo controladas pelo WordPress.</p></div>';
        echo '<div class="ppush-panel"><h2>Estado do envio</h2><p>Chaves de envio: <strong>' . (is_wp_error(Transport::keys()) ? 'Configuração pendente' : 'Prontas') . '</strong></p><p>HTTPS: <strong>' . (is_ssl() ? 'Ativo' : 'Necessário para os dispositivos de produção') . '</strong></p><p>Última execução da fila: ' . esc_html(self::date(get_option('ppush_queue_last_run', ''))) . '</p><p>O agendamento acompanha o fuso do WordPress: <strong>' . esc_html(wp_timezone_string()) . '</strong>.</p></div>';
    }

    private static function profiles(): void {
        global $wpdb;
        $edit = absint($_GET['edit'] ?? 0);
        $profile = $edit ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Core::table('profiles') . ' WHERE id=%d', $edit)) : null;
        echo '<div class="ppush-columns"><section class="ppush-panel"><h2>' . ($profile ? 'Editar perfil' : 'Novo perfil') . '</h2>';
        self::form('profile_save');
        echo '<input type="hidden" name="id" value="' . esc_attr($profile->id ?? 0) . '"><label>Nome<input name="name" required maxlength="120" value="' . esc_attr($profile->name ?? '') . '" placeholder="Ex.: Equipe, Clientes, Gestores"></label><label>Descrição<textarea name="description" rows="3">' . esc_textarea($profile->description ?? '') . '</textarea></label><button class="button button-primary">Salvar perfil</button></form><p class="description">Uma pessoa pode participar de vários perfis.</p></section><section class="ppush-panel"><h2>Perfis cadastrados</h2>';
        foreach (Audience::profiles() as $row) {
            echo '<div class="ppush-row"><div><strong>' . esc_html($row->name) . '</strong><p>' . esc_html($row->description) . '</p><span class="description">' . esc_html($row->members) . ' pessoas</span></div><div><a class="button" href="' . esc_url(self::url('profiles', ['edit' => $row->id])) . '">Editar</a> ';
            self::form('profile_delete');
            echo '<input type="hidden" name="id" value="' . esc_attr($row->id) . '"><button class="button ppush-danger" data-confirm="Excluir este perfil? As pessoas continuarão cadastradas.">Excluir</button></form></div></div>';
        }
        echo '</section></div>';
    }

    private static function people(): void {
        global $wpdb;
        $edit = absint($_GET['edit'] ?? 0);
        $person = $edit ? get_userdata($edit) : false;
        echo '<section class="ppush-panel"><h2>' . ($person ? 'Perfis de ' . esc_html($person->display_name) : 'Cadastrar pessoa') . '</h2>';
        if ($person || current_user_can('create_users')) {
            self::form('person_save');
            echo '<input type="hidden" name="id" value="' . esc_attr($person->ID ?? 0) . '">';
            if (!$person) {
                echo '<div class="ppush-fields"><label>Nome<input name="name" required maxlength="200" autocomplete="name"></label><label>E-mail<input name="email" type="email" required autocomplete="off"></label><label>Senha inicial<input name="password" type="password" required minlength="12" autocomplete="new-password"><small>Mínimo de 12 caracteres.</small></label></div>';
            } else {
                echo '<p>' . esc_html($person->user_email) . '</p><p class="description">Nome, e-mail e senha podem ser alterados no cadastro de usuários do WordPress.</p>';
            }
            $selected = $person ? Audience::user_profiles($person->ID) : [];
            echo '<fieldset><legend>Perfis</legend><div class="ppush-checkboxes">';
            foreach (Audience::profiles() as $profile) {
                echo '<label><input type="checkbox" name="profiles[]" value="' . esc_attr($profile->id) . '" ' . checked(in_array((int)$profile->id, $selected, true), true, false) . '> ' . esc_html($profile->name) . '</label>';
            }
            echo '</div></fieldset><label class="ppush-inline"><input type="checkbox" name="enabled" value="1" ' . checked(!$person || get_user_meta($person->ID, '_ppush_disabled', true) !== '1', true, false) . '> Recebimento habilitado para esta pessoa</label><button class="button button-primary">Salvar pessoa</button></form><p class="description">O cadastro não dispara e-mails. A pessoa precisa entrar no portal e autorizar os avisos no navegador.</p>';
        }
        if ($person) {
            echo '<h3>Dispositivos desta pessoa</h3>';
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id,label,active,last_seen_at FROM ' . Core::table('subscriptions') . ' WHERE user_id=%d ORDER BY id DESC', $person->ID));
            foreach ($rows as $row) {
                echo '<div class="ppush-row"><span>' . esc_html($row->label) . ' · ' . ($row->active ? 'Ativo' : 'Pausado') . ' · ' . esc_html(self::date($row->last_seen_at)) . '</span>';
                if ($row->active) {
                    self::form('subscription_revoke');
                    echo '<input type="hidden" name="id" value="' . esc_attr($row->id) . '"><button class="button">Desativar</button></form>';
                }
                echo '</div>';
            }
            if (!$rows) {
                echo '<p>Nenhum dispositivo cadastrado.</p>';
            }
        }
        echo '</section><section class="ppush-panel"><h2>Pessoas</h2><p>As contas existentes do WordPress já aparecem nesta lista.</p><form method="get" class="ppush-search"><input type="hidden" name="page" value="portal-push"><input type="hidden" name="tab" value="people"><input type="search" name="s" aria-label="Buscar pessoa" placeholder="Nome, e-mail ou usuário" value="' . esc_attr(wp_unslash($_GET['s'] ?? '')) . '"><button class="button">Buscar</button></form>';
        $page = max(1, absint($_GET['paged'] ?? 1));
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $args = ['number' => 20, 'paged' => $page, 'orderby' => 'display_name', 'order' => 'ASC'];
        if ($search) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['display_name', 'user_email', 'user_login'];
        }
        $query = new \WP_User_Query($args);
        $profiles = [];
        foreach (Audience::profiles() as $profile) { $profiles[(int)$profile->id] = $profile->name; }
        echo '<div class="ppush-table-scroll"><table class="widefat striped"><thead><tr><th>Pessoa</th><th>Perfis</th><th>Dispositivos ativos</th><th>Recebimento</th><th></th></tr></thead><tbody>';
        foreach ($query->get_results() as $user) {
            $names = array_intersect_key($profiles, array_flip(Audience::user_profiles($user->ID)));
            $devices = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Core::table('subscriptions') . ' WHERE user_id=%d AND active=1', $user->ID));
            echo '<tr><td><strong>' . esc_html($user->display_name) . '</strong><br>' . esc_html($user->user_email) . '</td><td>' . esc_html(implode(', ', $names) ?: 'Sem perfil') . '</td><td>' . esc_html($devices) . '</td><td>' . (Core::enabled($user->ID) ? 'Habilitado' : 'Desativado') . '</td><td><a href="' . esc_url(self::url('people', ['edit' => $user->ID])) . '">Editar perfis</a></td></tr>';
        }
        echo '</tbody></table></div><div class="ppush-pagination">';
        echo wp_kses_post(paginate_links(['base' => add_query_arg('paged', '%#%', self::url('people', $search ? ['s' => $search] : [])), 'format' => '', 'current' => $page, 'total' => (int)ceil($query->get_total() / 20)]) ?: '');
        echo '</div></section>';
    }

    private static function compose(): void {
        echo '<section class="ppush-panel ppush-compose"><h2>Nova notificação</h2>';
        self::form('campaign_create');
        echo '<label>Enviar para<select name="audience_type" data-audience><option value="people">Pessoas específicas</option><option value="profiles">Um ou mais perfis</option><option value="all">Todas as pessoas habilitadas</option></select></label>';
        echo '<div data-audience-people><label>Buscar pessoas<input type="search" data-people-search placeholder="Digite nome ou e-mail" autocomplete="off"></label><label>Pessoas selecionadas<select name="person_ids[]" multiple size="6" data-people-select required>';
        foreach (get_users(['number' => 30, 'orderby' => 'display_name', 'order' => 'ASC']) as $user) {
            echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->display_name . ' — ' . $user->user_email) . '</option>';
        }
        echo '</select></label><small>Use Ctrl ou Command para selecionar várias pessoas. A busca preserva as opções selecionadas.</small><span data-people-status role="status"></span></div>';
        echo '<fieldset data-audience-profiles hidden><legend>Perfis destinatários</legend><div class="ppush-checkboxes">';
        foreach (Audience::profiles() as $profile) {
            echo '<label><input type="checkbox" name="profile_ids[]" value="' . esc_attr($profile->id) . '"> ' . esc_html($profile->name) . ' (' . esc_html($profile->members) . ')</label>';
        }
        echo '</div><p class="description">Recebem as pessoas de qualquer perfil selecionado. Um mesmo dispositivo recebe uma vez por campanha.</p></fieldset>';
        echo '<label data-audience-all hidden class="ppush-inline"><input type="checkbox" name="confirm_all" value="1"> Confirmo o envio para todas as pessoas habilitadas com dispositivos ativos.</label>';
        echo '<label>Título<input name="title" maxlength="80" required placeholder="Ex.: Uma novidade para sua equipe"></label><label>Mensagem<textarea name="message" rows="4" maxlength="300" required placeholder="Escreva o aviso que aparecerá na notificação"></textarea></label><label>Link no portal<input type="url" name="url" value="' . esc_attr(home_url('/')) . '" required><small>O destino deve pertencer a este portal. As permissões da página continuam valendo.</small></label><label>Imagem opcional<input name="image" type="url" placeholder="https://..."></label><div class="ppush-fields"><label>Agendar (opcional)<input type="datetime-local" name="scheduled_local"><small>Fuso: ' . esc_html(wp_timezone_string()) . '. Vazio = próxima execução da fila.</small></label><label>Validade para dispositivos offline<select name="ttl"><option value="3600">1 hora</option><option value="86400" selected>24 horas</option><option value="604800">7 dias</option></select></label></div><button class="button button-primary button-hero">Criar campanha</button></form></section>';
    }

    private static function campaigns(): void {
        global $wpdb;
        echo '<section class="ppush-panel"><div class="ppush-row"><div><h2>Campanhas</h2><p>“Aceitas” indica recebimento pelo serviço de push. Isso não comprova leitura.</p></div>';
        self::form('queue_run'); echo '<button class="button">Processar fila agora</button></form></div>';
        $page = max(1, absint($_GET['paged'] ?? 1));
        $total = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . Core::table('campaigns'));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Core::table('campaigns') . ' ORDER BY id DESC LIMIT 20 OFFSET %d', ($page - 1) * 20));
        echo '<div class="ppush-table-scroll"><table class="widefat striped"><thead><tr><th>Campanha</th><th>Público</th><th>Status</th><th>Agendamento</th><th>Dispositivos</th><th>Aceitas</th><th>Falhas</th><th>Cliques</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $stats = Campaigns::stats((int)$row->id);
            echo '<tr><td><a href="' . esc_url(self::url('campaigns', ['detail' => $row->id])) . '"><strong>#' . esc_html($row->id) . ' ' . esc_html($row->title) . '</strong></a></td><td>' . esc_html(['all' => 'Todos', 'people' => 'Pessoas', 'profiles' => 'Perfis'][$row->audience_type] ?? '') . '</td><td>' . esc_html(self::status($row->status)) . '</td><td>' . esc_html(self::date($row->scheduled_at)) . '</td><td>' . esc_html($row->total) . '</td><td>' . esc_html($stats['sent']) . '</td><td>' . esc_html($stats['failed']) . '</td><td>' . esc_html($stats['clicked']) . '</td><td>';
            if (in_array($row->status, ['scheduled', 'queued'], true)) {
                self::form('campaign_cancel');
                echo '<input type="hidden" name="id" value="' . esc_attr($row->id) . '"><button class="button" data-confirm="Cancelar os envios pendentes desta campanha?">Cancelar</button></form>';
            }
            echo '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="9">Nenhuma campanha criada ainda.</td></tr>'; }
        echo '</tbody></table></div><div class="ppush-pagination">' . wp_kses_post(paginate_links(['base' => add_query_arg('paged', '%#%', self::url('campaigns')), 'format' => '', 'current' => $page, 'total' => (int)ceil($total / 20)]) ?: '') . '</div></section>';
        $id = absint($_GET['detail'] ?? 0);
        if ($id) {
            $campaign = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Core::table('campaigns') . ' WHERE id=%d', $id));
            if (!$campaign) { return; }
            if ($campaign->last_error) {
                echo '<div class="notice notice-error"><p>' . esc_html($campaign->last_error) . '</p></div>';
            }
            echo '<section class="ppush-panel"><h2>Campanha #' . esc_html($id) . ' — ' . esc_html($campaign->title) . '</h2><p>' . nl2br(esc_html($campaign->message)) . '</p><p><a href="' . esc_url($campaign->url) . '">' . esc_html($campaign->url) . '</a></p><p class="description">Até 100 registros mais recentes por dispositivo.</p><div class="ppush-table-scroll"><table class="widefat striped"><thead><tr><th>Pessoa</th><th>Dispositivo</th><th>Situação</th><th>Tentativas</th><th>Detalhes</th></tr></thead><tbody>';
            $deliveries = $wpdb->get_results($wpdb->prepare('SELECT d.*,s.label FROM ' . Core::table('deliveries') . ' d LEFT JOIN ' . Core::table('subscriptions') . ' s ON s.id=d.subscription_id WHERE d.campaign_id=%d ORDER BY d.id DESC LIMIT 100', $id));
            foreach ($deliveries as $delivery) {
                $person = get_userdata((int)$delivery->user_id);
                echo '<tr><td>' . esc_html($person ? $person->display_name : 'Conta removida') . '</td><td>' . esc_html($delivery->label ?? 'Removido') . '</td><td>' . esc_html(self::status($delivery->status)) . '</td><td>' . esc_html($delivery->attempts) . '</td><td>' . esc_html($delivery->error_message) . '</td></tr>';
            }
            echo '</tbody></table></div></section>';
        }
    }

    private static function settings(): void {
        $settings = Core::settings();
        $keys = Transport::keys();
        echo '<div class="ppush-columns"><section class="ppush-panel"><h2>Configurações do portal</h2>';
        self::form('settings_save');
        echo '<label>E-mail técnico<input type="email" required name="contact" value="' . esc_attr($settings['contact']) . '"><small>Identifica o responsável pelo envio. Não dispara mensagens de e-mail.</small></label><label>Página de notificações';
        wp_dropdown_pages(['name' => 'portal_page', 'selected' => $settings['portal_page'], 'show_option_none' => 'Selecione uma página', 'option_none_value' => 0]);
        echo '</label><p class="description">Insira <code>[portal_push]</code> no conteúdo da página escolhida.</p><label>URL do ícone (opcional)<input type="url" name="icon" value="' . esc_attr($settings['icon']) . '"><small>Sem URL, utiliza o ícone do site.</small></label><label>Validade padrão (segundos)<input type="number" name="default_ttl" min="60" max="604800" value="' . esc_attr($settings['default_ttl']) . '"></label><label class="ppush-inline"><input type="checkbox" name="retain_on_uninstall" value="1" ' . checked((bool)$settings['retain_on_uninstall'], true, false) . '> Preservar os dados ao excluir o plugin</label><button class="button button-primary">Salvar configurações</button></form></section><section class="ppush-panel"><h2>Chaves de envio</h2>';
        if (is_wp_error($keys)) {
            echo '<p>Gere as chaves uma vez para habilitar a inscrição dos dispositivos.</p>';
            self::form('keys_generate'); echo '<button class="button button-primary">Gerar chaves no servidor</button></form>';
        } else {
            echo '<p class="ppush-good">Chaves prontas. O envio é processado neste servidor.</p><p>Chave pública:</p><code class="ppush-key">' . esc_html($keys['publicKey']) . '</code><p class="description">A chave privada é mantida no servidor e não aparece nesta tela.</p>';
        }
        echo '<hr><h2>Página para as pessoas</h2>';
        if ($settings['portal_page'] && get_post_status((int)$settings['portal_page']) === 'publish') {
            echo '<p><a class="button" href="' . esc_url(get_permalink((int)$settings['portal_page'])) . '">Abrir página de notificações</a></p>';
        } else {
            self::form('page_create'); echo '<button class="button">Criar página de notificações</button></form>';
        }
        echo '<hr><h2>Agendamento confiável</h2><p>A fila roda a cada minuto pelo agendador do WordPress. Em portais com poucas visitas, configure uma execução de <code>wp-cron.php</code> a cada minuto na hospedagem.</p><p>Os horários usam o fuso definido em Configurações → Geral.</p></section></div>';
        echo '<section class="ppush-panel"><h2>Atualizações pelo WordPress</h2><p>Versão instalada: <strong>' . esc_html(PPUSH_VERSION) . '</strong>. Fonte: <a href="' . esc_url(Updater::REPOSITORY) . '">GitHub — paulommaeda/push-notifications</a>.</p>';
        $status = get_option('ppush_update_status', []);
        if (!empty($status['version'])) {
            echo '<p>Versão publicada: <strong>' . esc_html($status['version']) . '</strong>.</p>';
        }
        if (!empty($status['checked_at'])) {
            echo '<p>Última consulta: ' . esc_html(self::date($status['checked_at'])) . '.</p>';
        }
        if (!empty($status['error'])) {
            echo '<p>' . esc_html($status['error']) . '</p>';
        }
        if (current_user_can('update_plugins')) {
            self::form('updates_auto');
            echo '<label class="ppush-inline"><input type="checkbox" name="auto_updates" value="1" ' . checked(Updater::auto_enabled(), true, false) . '> Instalar automaticamente as próximas versões deste plugin</label><button class="button button-primary">Salvar preferência</button></form><p class="description">Usa a preferência nativa do WordPress. Também pode ser alterada na tela Plugins. As regras de atualização e de arquivos da hospedagem continuam sendo respeitadas.</p>';
            self::form('updates_check');
            echo '<button class="button">Verificar atualizações agora</button></form> <a class="button" href="' . esc_url(admin_url('plugins.php')) . '">Abrir Plugins</a>';
        }
        echo '</section>';
    }

    private static function date(string $value): string {
        return $value ? wp_date('d/m/Y H:i', strtotime($value . ' UTC')) : 'Ainda não executada';
    }

    private static function status(string $status): string {
        return ['scheduled' => 'Agendada', 'queued' => 'Na fila', 'completed' => 'Concluída', 'partial' => 'Concluída com falhas', 'cancelled' => 'Cancelada', 'pending' => 'Pendente', 'processing' => 'Processando', 'sent' => 'Aceita pelo serviço', 'failed' => 'Falhou', 'skipped' => 'Ignorada'][$status] ?? $status;
    }
}
