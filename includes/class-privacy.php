<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Privacy {
    public static function boot(): void {
        add_filter('wp_privacy_personal_data_exporters', static function (array $exporters): array {
            $exporters['portal-push'] = ['exporter_friendly_name' => 'Portal Push', 'callback' => [self::class, 'export']];
            return $exporters;
        });
        add_filter('wp_privacy_personal_data_erasers', static function (array $erasers): array {
            $erasers['portal-push'] = ['eraser_friendly_name' => 'Portal Push', 'callback' => [self::class, 'erase']];
            return $erasers;
        });
        add_action('admin_init', static function (): void {
            if (function_exists('wp_add_privacy_policy_content')) {
                wp_add_privacy_policy_content('Portal Push', '<p>As notificações do portal são opcionais e exigem autorização no dispositivo. Armazenamos o vínculo com a conta, os perfis atribuídos pela administração, os dados técnicos da inscrição push, uma descrição do dispositivo e o histórico de disparos e cliques. Os envios são processados pela hospedagem do portal e encaminhados ao serviço de push do navegador (Google, Mozilla, Apple ou Microsoft). Não coletamos a localização nem armazenamos IPs neste plugin. A pessoa pode pausar cada dispositivo na página de notificações. Ajuste este texto às práticas e ao prazo de retenção da sua instituição.</p>');
            }
        });
    }

    public static function delete_user(int $id): void {
        global $wpdb;
        $wpdb->delete(Core::table('memberships'), ['user_id' => $id]);
        $wpdb->delete(Core::table('subscriptions'), ['user_id' => $id]);
        $wpdb->delete(Core::table('deliveries'), ['user_id' => $id]);
        delete_user_meta($id, '_ppush_disabled');
        delete_transient('ppush_test_' . $id);
    }

    public static function export(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }
        $batch = 100;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,label,active,created_at,last_seen_at FROM ' . Core::table('subscriptions') . ' WHERE user_id=%d ORDER BY id LIMIT %d OFFSET %d', $user->ID, $batch, max(0, $page - 1) * $batch));
        $data = [];
        if ($page === 1) {
            $names = [];
            foreach (Audience::profiles() as $profile) {
                if (in_array((int)$profile->id, Audience::user_profiles($user->ID), true)) { $names[] = $profile->name; }
            }
            $data[] = [
                'group_id' => 'portal-push', 'group_label' => 'Portal Push', 'item_id' => 'ppush-account-' . $user->ID,
                'data' => [
                    ['name' => 'Perfis', 'value' => implode(', ', $names)],
                    ['name' => 'Recebimento', 'value' => Core::enabled($user->ID) ? 'Habilitado' : 'Desativado'],
                ],
            ];
        }
        foreach ($rows as $row) {
            $data[] = [
                'group_id' => 'portal-push', 'group_label' => 'Portal Push', 'item_id' => 'ppush-device-' . $row->id,
                'data' => [
                    ['name' => 'Dispositivo', 'value' => $row->label],
                    ['name' => 'Ativo', 'value' => $row->active ? 'Sim' : 'Não'],
                    ['name' => 'Cadastro (UTC)', 'value' => $row->created_at],
                    ['name' => 'Última atualização (UTC)', 'value' => $row->last_seen_at],
                ],
            ];
        }
        // A segunda etapa exporta o histórico sem expor endpoints ou chaves técnicas.
        $deliveries = $wpdb->get_results($wpdb->prepare('SELECT d.id,d.status,d.sent_at,d.clicked_at,c.title FROM ' . Core::table('deliveries') . ' d LEFT JOIN ' . Core::table('campaigns') . ' c ON c.id=d.campaign_id WHERE d.user_id=%d ORDER BY d.id LIMIT %d OFFSET %d', $user->ID, $batch, max(0, $page - 1) * $batch));
        foreach ($deliveries as $delivery) {
            $data[] = [
                'group_id' => 'portal-push', 'group_label' => 'Portal Push', 'item_id' => 'ppush-delivery-' . $delivery->id,
                'data' => [
                    ['name' => 'Notificação', 'value' => $delivery->title ?? 'Removida'],
                    ['name' => 'Situação', 'value' => $delivery->status],
                    ['name' => 'Envio (UTC)', 'value' => $delivery->sent_at ?? ''],
                    ['name' => 'Clique (UTC)', 'value' => $delivery->clicked_at ?? ''],
                ],
            ];
        }
        return ['data' => $data, 'done' => count($rows) < $batch && count($deliveries) < $batch];
    }

    public static function erase(string $email, int $page = 1): array {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        self::delete_user($user->ID);
        update_user_meta($user->ID, '_ppush_disabled', '1');
        return ['items_removed' => true, 'items_retained' => false, 'messages' => ['Dados do Portal Push apagados. A conta WordPress foi preservada e o recebimento desativado.'], 'done' => true];
    }
}
