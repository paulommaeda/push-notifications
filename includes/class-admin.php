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
            case 'person_save':
                $id = absint($data['id'] ?? 0);
                if (!$id || !get_userdata($id)) { self::finish('people', 'Selecione uma conta existente do WordPress.', true); }
                update_user_meta($id, '_ppush_disabled', empty($data['enabled']) ? '1' : '0');
                self::finish('people', 'Preferência de recebimento salva.');
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
                    'audience_ids' => $type === 'people' ? ($data['person_ids'] ?? []) : ($data['role_slugs'] ?? []),
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
                    'site_prompt' => empty($data['site_prompt']) ? 0 : 1,
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
                $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Configurar notificações', 'post_content' => '[portal_push]'], true);
                if (is_wp_error($id)) {
                    self::fail('settings', $id);
                }
                $settings['portal_page'] = $id;
                update_option('ppush_settings', $settings, false);
                self::finish('settings', 'Página de configuração dos dispositivos criada.');
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
            'Perfis do WordPress' => count(Audience::roles()),
            'Dispositivos ativos' => (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . Core::table('subscriptions') . ' WHERE active=1'),
            'Campanhas' => (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . Core::table('campaigns')),
        ];
        echo '<div class="ppush-metrics">';
        foreach ($counts as $label => $count) {
            echo '<div><span>' . esc_html($label) . '</span><strong>' . esc_html(number_format_i18n($count)) . '</strong></div>';
        }
        echo '</div><div class="ppush-panel"><h2>Push no navegador e no celular</h2><p>O aviso aparece nas notificações do navegador ou do sistema, inclusive no PWA instalado. Ao tocar nele, a pessoa abre o link escolhido no portal.</p><ol><li>Gere as chaves em <a href="' . esc_url(self::url('settings')) . '">Configurações</a>.</li><li>Use as contas e os perfis já cadastrados em Usuários no WordPress.</li><li>Cada pessoa entra no portal e clica em Ativar notificações no próprio dispositivo.</li><li>Crie uma campanha para pessoas específicas, perfis do WordPress ou todos.</li></ol><p>O botão de ativação aparece nas páginas do portal para usuários conectados. A página opcional com <code>[portal_push]</code> permite gerenciar dispositivos; ela não é uma caixa de mensagens.</p></div>';
        if (get_option('ppush_legacy_campaigns_cancelled')) {
            echo '<div class="notice notice-warning"><p>Campanhas pendentes dos grupos próprios da versão anterior foram canceladas. Crie novos envios selecionando os perfis do WordPress. As contas, as chaves e os dispositivos foram preservados.</p></div>';
        }
        echo '<div class="ppush-panel"><h2>Estado do envio</h2><p>Chaves de envio: <strong>' . (is_wp_error(Transport::keys()) ? 'Configuração pendente' : 'Prontas') . '</strong></p><p>HTTPS: <strong>' . (is_ssl() ? 'Ativo' : 'Necessário para os dispositivos de produção') . '</strong></p><p>Última execução da fila: ' . esc_html(self::date(get_option('ppush_queue_last_run', ''))) . '</p><p>O agendamento acompanha o fuso do WordPress: <strong>' . esc_html(wp_timezone_string()) . '</strong>.</p></div>';
    }

    private static function profiles(): void {
        $counts = count_users()['avail_roles'] ?? [];
        echo '<section class="ppush-panel"><h2>Perfis dos usuários do WordPress</h2><p>Esta lista usa os mesmos papéis de Usuários → Todos os usuários, incluindo os personalizados registrados por outros plugins. O cadastro e a alteração dos papéis são feitos pelo WordPress.</p>';
        if (current_user_can('list_users')) {
            echo '<p><a class="button" href="' . esc_url(admin_url('users.php')) . '">Gerenciar usuários no WordPress</a></p>';
        }
        echo '<table class="widefat striped"><thead><tr><th>Perfil</th><th>Identificador</th><th>Usuários</th></tr></thead><tbody>';
        foreach (Audience::roles() as $slug => $name) {
            echo '<tr><td>' . esc_html($name) . '</td><td><code>' . esc_html($slug) . '</code></td><td>' . esc_html($counts[$slug] ?? 0) . '</td></tr>';
        }
        echo '</tbody></table><p>Para enviar um aviso, selecione estes perfis em Nova notificação. Selecionar destinatários não altera permissões de acesso.</p></section>';
    }

    private static function people(): void {
        global $wpdb;
        $edit = absint($_GET['edit'] ?? 0);
        $person = $edit ? get_userdata($edit) : false;
        $roles = Audience::roles();
        if ($person) {
            echo '<section class="ppush-panel"><h2>Notificações de ' . esc_html($person->display_name) . '</h2><p>' . esc_html($person->user_email) . '</p><p>Perfis no WordPress: <strong>' . esc_html(implode(', ', array_intersect_key($roles, array_flip(Audience::user_roles($person->ID)))) ?: 'Sem perfil') . '</strong>.</p>';
            if (current_user_can('edit_user', $person->ID)) {
                echo '<p><a class="button" href="' . esc_url(get_edit_user_link($person->ID)) . '">Editar usuário no WordPress</a></p>';
            }
            self::form('person_save');
            echo '<input type="hidden" name="id" value="' . esc_attr($person->ID) . '"><label class="ppush-inline"><input type="checkbox" name="enabled" value="1" ' . checked(get_user_meta($person->ID, '_ppush_disabled', true) !== '1', true, false) . '> Recebimento habilitado para esta pessoa</label><button class="button button-primary">Salvar recebimento</button></form><h3>Dispositivos desta pessoa</h3>';
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id,label,active,last_seen_at FROM ' . Core::table('subscriptions') . ' WHERE user_id=%d ORDER BY id DESC', $person->ID));
            foreach ($rows as $row) {
                echo '<div class="ppush-row"><span>' . esc_html($row->label) . ' · ' . ($row->active ? 'Ativo' : 'Pausado') . ' · ' . esc_html(self::date($row->last_seen_at)) . '</span>';
                if ($row->active) {
                    self::form('subscription_revoke');
                    echo '<input type="hidden" name="id" value="' . esc_attr($row->id) . '"><button class="button">Desativar</button></form>';
                }
                echo '</div>';
            }
            if (!$rows) { echo '<p>Nenhum dispositivo cadastrado.</p>'; }
            echo '</section>';
        }
        echo '<section class="ppush-panel"><h2>Usuários do WordPress</h2><p>As contas e seus perfis são lidos diretamente do WordPress. Cada pessoa precisa autorizar o push no navegador ou no PWA do próprio dispositivo.</p>';
        if (current_user_can('create_users')) {
            echo '<p><a class="button" href="' . esc_url(admin_url('user-new.php')) . '">Cadastrar usuário no WordPress</a></p>';
        }
        echo '<form method="get" class="ppush-search"><input type="hidden" name="page" value="portal-push"><input type="hidden" name="tab" value="people"><input type="search" name="s" aria-label="Buscar pessoa" placeholder="Nome, e-mail ou usuário" value="' . esc_attr(wp_unslash($_GET['s'] ?? '')) . '"><button class="button">Buscar</button></form>';
        $page = max(1, absint($_GET['paged'] ?? 1));
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $args = ['number' => 20, 'paged' => $page, 'orderby' => 'display_name', 'order' => 'ASC'];
        if ($search) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['display_name', 'user_email', 'user_login'];
        }
        $query = new \WP_User_Query($args);
        echo '<div class="ppush-table-scroll"><table class="widefat striped"><thead><tr><th>Pessoa</th><th>Perfis do WordPress</th><th>Dispositivos ativos</th><th>Recebimento</th><th></th></tr></thead><tbody>';
        foreach ($query->get_results() as $user) {
            $names = array_intersect_key($roles, array_flip(Audience::user_roles($user->ID)));
            $devices = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Core::table('subscriptions') . ' WHERE user_id=%d AND active=1', $user->ID));
            echo '<tr><td><strong>' . esc_html($user->display_name) . '</strong><br>' . esc_html($user->user_email) . '</td><td>' . esc_html(implode(', ', $names) ?: 'Sem perfil') . '</td><td>' . esc_html($devices) . '</td><td>' . (Core::enabled($user->ID) ? 'Habilitado' : 'Desativado') . '</td><td><a href="' . esc_url(self::url('people', ['edit' => $user->ID])) . '">Gerenciar notificações</a></td></tr>';
        }
        echo '</tbody></table></div><div class="ppush-pagination">';
        echo wp_kses_post(paginate_links(['base' => add_query_arg('paged', '%#%', self::url('people', $search ? ['s' => $search] : [])), 'format' => '', 'current' => $page, 'total' => (int)ceil($query->get_total() / 20)]) ?: '');
        echo '</div></section>';
    }

    private static function compose(): void {
        echo '<section class="ppush-panel ppush-compose"><h2>Nova notificação</h2>';
        self::form('campaign_create');
        echo '<label>Enviar para<select name="audience_type" data-audience><option value="people">Pessoas específicas</option><option value="roles">Perfis do WordPress</option><option value="all">Todas as pessoas habilitadas</option></select></label>';
        echo '<div data-audience-people><label>Buscar pessoas<input type="search" data-people-search placeholder="Digite nome ou e-mail" autocomplete="off"></label><label>Pessoas selecionadas<select name="person_ids[]" multiple size="6" data-people-select required>';
        foreach (get_users(['number' => 30, 'orderby' => 'display_name', 'order' => 'ASC']) as $user) {
            echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->display_name . ' — ' . $user->user_email) . '</option>';
        }
        echo '</select></label><small>Use Ctrl ou Command para selecionar várias pessoas. A busca preserva as opções selecionadas.</small><span data-people-status role="status"></span></div>';
        echo '<fieldset data-audience-roles hidden><legend>Perfis dos usuários do WordPress</legend><div class="ppush-checkboxes">';
        foreach (Audience::roles() as $slug => $name) {
            echo '<label><input type="checkbox" name="role_slugs[]" value="' . esc_attr($slug) . '"> ' . esc_html($name) . '</label>';
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
            echo '<tr><td><a href="' . esc_url(self::url('campaigns', ['detail' => $row->id])) . '"><strong>#' . esc_html($row->id) . ' ' . esc_html($row->title) . '</strong></a></td><td>' . esc_html(['all' => 'Todos', 'people' => 'Pessoas', 'roles' => 'Perfis do WordPress', 'profiles' => 'Grupos antigos'][$row->audience_type] ?? '') . '</td><td>' . esc_html(self::status($row->status)) . '</td><td>' . esc_html(self::date($row->scheduled_at)) . '</td><td>' . esc_html($row->total) . '</td><td>' . esc_html($stats['sent']) . '</td><td>' . esc_html($stats['failed']) . '</td><td>' . esc_html($stats['clicked']) . '</td><td>';
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
        echo '<label>E-mail técnico<input type="email" required name="contact" value="' . esc_attr($settings['contact']) . '"><small>Identifica o responsável pelo envio. Não dispara mensagens de e-mail.</small></label><label>Página de dispositivos (opcional)';
        wp_dropdown_pages(['name' => 'portal_page', 'selected' => $settings['portal_page'], 'show_option_none' => 'Selecione uma página', 'option_none_value' => 0]);
        echo '</label><p class="description">Insira <code>[portal_push]</code> para gerenciar dispositivos. O push é exibido pelo navegador ou pelo celular, mesmo sem esta página aberta.</p><label class="ppush-inline"><input type="checkbox" name="site_prompt" value="1" ' . checked((bool)$settings['site_prompt'], true, false) . '> Exibir botão de ativação nas páginas para usuários conectados</label><label>URL do ícone (opcional)<input type="url" name="icon" value="' . esc_attr($settings['icon']) . '"><small>Sem URL, utiliza o ícone do site.</small></label><label>Validade padrão (segundos)<input type="number" name="default_ttl" min="60" max="604800" value="' . esc_attr($settings['default_ttl']) . '"></label><label class="ppush-inline"><input type="checkbox" name="retain_on_uninstall" value="1" ' . checked((bool)$settings['retain_on_uninstall'], true, false) . '> Preservar os dados ao excluir o plugin</label><button class="button button-primary">Salvar configurações</button></form></section><section class="ppush-panel"><h2>Chaves de envio</h2>';
        if (is_wp_error($keys)) {
            echo '<p>Gere as chaves uma vez para habilitar a inscrição dos dispositivos.</p>';
            self::form('keys_generate'); echo '<button class="button button-primary">Gerar chaves no servidor</button></form>';
        } else {
            echo '<p class="ppush-good">Chaves prontas. O envio é processado neste servidor.</p><p>Chave pública:</p><code class="ppush-key">' . esc_html($keys['publicKey']) . '</code><p class="description">A chave privada é mantida no servidor e não aparece nesta tela.</p>';
        }
        echo '<hr><h2>Gerenciamento opcional de dispositivos</h2>';
        echo '<p>PWA: <strong>' . esc_html(Pwa::provider() ?: 'Worker de notificações do Portal Push') . '</strong>. ' . (Pwa::provider() ? 'A integração é acrescentada ao worker existente. Depois de atualizar, feche e reabra o aplicativo para ativar a nova versão.' : 'Se utilizar outro plugin de PWA, integre os eventos de push ao worker dele conforme o guia do plugin.') . '</p>';
        if ($settings['portal_page'] && get_post_status((int)$settings['portal_page']) === 'publish') {
            echo '<p><a class="button" href="' . esc_url(get_permalink((int)$settings['portal_page'])) . '">Abrir página de dispositivos</a></p>';
        } else {
            self::form('page_create'); echo '<button class="button">Criar página de dispositivos</button></form>';
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
