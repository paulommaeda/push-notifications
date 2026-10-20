<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Frontend {
    public static function boot(): void {
        add_action('init', [self::class, 'special_files'], 0);
        add_action('template_redirect', [self::class, 'no_cache']);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_head', [self::class, 'manifest']);
        add_action('wp_footer', [self::class, 'prompt']);
        add_shortcode('portal_push', [self::class, 'shortcode']);
    }

    public static function is_portal(): bool {
        global $post;
        return is_singular() && ($post && (has_shortcode($post->post_content, 'portal_push') || (int)$post->ID === (int)Core::settings()['portal_page']));
    }

    public static function no_cache(): void {
        if (self::is_portal() || (is_user_logged_in() && Core::settings()['site_prompt'])) {
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            nocache_headers();
        }
    }

    public static function assets(): void {
        if (!self::is_portal() && !(is_user_logged_in() && Core::settings()['site_prompt'])) {
            return;
        }
        wp_enqueue_style('portal-push', PPUSH_URL . 'assets/portal.css', [], PPUSH_VERSION);
        if (!is_user_logged_in() || !Core::enabled(get_current_user_id())) {
            return;
        }
        wp_enqueue_script('portal-push', PPUSH_URL . 'assets/portal.js', [], PPUSH_VERSION, true);
        $keys = Transport::keys();
        wp_localize_script('portal-push', 'PortalPush', [
            'api' => rest_url('portal-push/v1/'), 'nonce' => wp_create_nonce('wp_rest'),
            'publicKey' => is_wp_error($keys) ? '' : $keys['publicKey'],
            'worker' => add_query_arg('portal_push_worker', '1', home_url('/')),
            'scope' => home_url('/'), 'pwa' => Pwa::provider(), 'storage' => 'ppush-' . substr(md5(home_url('/')), 0, 12),
        ]);
    }

    public static function manifest(): void {
        if (!Pwa::provider() && (self::is_portal() || Core::settings()['site_prompt']) && !apply_filters('portal_push_disable_manifest', false)) {
            echo '<link rel="manifest" href="' . esc_url(add_query_arg('portal_push_manifest', '1', home_url('/'))) . '">';
        }
    }

    public static function special_files(): void {
        if (isset($_GET['portal_push_worker']) && $_GET['portal_push_worker'] === '1') {
            nocache_headers();
            header('Content-Type: application/javascript; charset=UTF-8');
            header('Service-Worker-Allowed: ' . (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/'));
            header('X-Content-Type-Options: nosniff');
            readfile(PPUSH_DIR . 'assets/worker.js');
            echo Pwa::events();
            exit;
        }
        if (isset($_GET['portal_push_manifest']) && $_GET['portal_push_manifest'] === '1') {
            $settings = Core::settings();
            $icons = [];
            foreach ([192, 512] as $size) {
                $url = get_site_icon_url($size);
                if ($url) {
                    $icons[] = ['src' => $url, 'sizes' => $size . 'x' . $size];
                }
            }
            nocache_headers();
            header('Content-Type: application/manifest+json; charset=UTF-8');
            echo wp_json_encode([
                'id' => home_url('/'), 'name' => get_bloginfo('name'), 'short_name' => mb_substr(get_bloginfo('name'), 0, 20),
                'start_url' => get_permalink((int)$settings['portal_page']) ?: home_url('/'),
                'scope' => home_url('/'), 'pwa' => Pwa::provider(), 'display' => 'standalone',
                'background_color' => '#f4f6fb', 'theme_color' => '#1e3a8a', 'icons' => $icons,
            ]);
            exit;
        }
    }

    public static function prompt(): void {
        if (self::is_portal() || !is_user_logged_in() || !Core::enabled(get_current_user_id()) || !Core::settings()['site_prompt']) { return; }
        echo '<aside class="ppush-card ppush-prompt" data-ppush data-ppush-prompt hidden aria-label="Notificações do portal"><h2>Receber avisos do portal</h2><p>Ative para receber notificações no navegador ou no aplicativo instalado neste dispositivo.</p><p class="ppush-status" role="status" aria-live="polite">Verificando este dispositivo…</p><div class="ppush-actions"><button type="button" data-ppush-enable disabled>Ativar notificações</button><button type="button" class="ppush-secondary" data-ppush-dismiss>Agora não</button></div></aside>';
    }

    public static function shortcode(): string {
        ob_start();
        echo '<section class="ppush-card" data-ppush><div class="ppush-eyebrow">SEU PORTAL</div><h2>Configurar notificações push</h2>';
        if (!is_user_logged_in()) {
            echo '<p>Entre na sua conta para ativar as notificações neste dispositivo.</p>';
            wp_login_form(['redirect' => get_permalink(), 'label_username' => 'E-mail ou usuário', 'label_password' => 'Senha', 'label_log_in' => 'Entrar no portal']);
        } elseif (!Core::enabled(get_current_user_id())) {
            echo '<p>O recebimento está desativado para sua conta. Fale com a administração do portal.</p>';
        } else {
            $user = wp_get_current_user();
            echo '<p>Olá, <strong>' . esc_html($user->display_name) . '</strong>. Autorize os avisos destinados a você e aos seus perfis do WordPress. Eles aparecem nas notificações do navegador ou do celular, mesmo com esta página fechada.</p>';
            $roles = Audience::user_roles($user->ID);
            echo '<div class="ppush-tags" aria-label="Seus perfis no WordPress">';
            foreach (Audience::roles() as $slug => $name) {
                if (in_array($slug, $roles, true)) { echo '<span>' . esc_html($name) . '</span>'; }
            }
            if (!$roles) { echo '<span>Você recebe avisos individuais e gerais</span>'; }
            echo '</div><p class="ppush-status" role="status" aria-live="polite">Verificando este dispositivo…</p>';
            echo '<div class="ppush-actions"><button type="button" data-ppush-enable disabled>Ativar neste dispositivo</button><button type="button" class="ppush-secondary" data-ppush-test disabled>Enviar teste para mim</button></div>';
            echo '<h3>Meus dispositivos</h3><div data-ppush-devices></div><p class="ppush-footnote">Você pode desativar os avisos a qualquer momento. Ao sair do portal, o recebimento neste navegador será pausado.</p>';
            echo '<noscript>Ative o JavaScript para gerenciar as notificações.</noscript>';
        }
        echo '</section>';
        return (string)ob_get_clean();
    }
}
