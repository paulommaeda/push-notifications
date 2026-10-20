<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

final class Queue {
    private static function lock(): string|false {
        global $wpdb;
        $token = (time() + 90) . ':' . wp_generate_uuid4();
        if (add_option('ppush_queue_lock', $token, '', false)) {
            return $token;
        }
        $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'ppush_queue_lock'));
        if ($old && (int)explode(':', $old, 2)[0] < time()) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", 'ppush_queue_lock', $old));
            wp_cache_delete('ppush_queue_lock', 'options');
            wp_cache_delete('notoptions', 'options');
            if (add_option('ppush_queue_lock', $token, '', false)) {
                return $token;
            }
        }
        return false;
    }

    public static function run(): void {
        global $wpdb;
        $token = self::lock();
        if (!$token) {
            return;
        }
        try {
            if (is_wp_error(Transport::keys())) {
                return;
            }
            $c = Core::table('campaigns');
            $d = Core::table('deliveries');
            $wpdb->query($wpdb->prepare("UPDATE $d SET status='pending',locked_at=NULL WHERE status='processing' AND locked_at<%s AND attempts<3", gmdate('Y-m-d H:i:s', time() - 300)));
            $wpdb->query($wpdb->prepare("UPDATE $d SET status='failed',error_code='interrupted',error_message='Processamento interrompido após três tentativas.' WHERE status='processing' AND locked_at<%s AND attempts>=3", gmdate('Y-m-d H:i:s', time() - 300)));
            $campaigns = $wpdb->get_results($wpdb->prepare("SELECT * FROM $c WHERE status='scheduled' AND scheduled_at<=%s ORDER BY id LIMIT 5", Core::now()));
            foreach ($campaigns as $campaign) {
                $expansion_failed = false;
                foreach (Audience::subscriptions($campaign) as $subscription) {
                    $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $d (campaign_id,subscription_id,user_id,status,attempts,next_attempt_at,error_message) VALUES (%d,%d,%d,'pending',0,%s,'')", $campaign->id, $subscription->id, $subscription->user_id, Core::now()));
                    if ($inserted === false) {
                        $expansion_failed = true;
                        break;
                    }
                }
                if ($expansion_failed) {
                    $wpdb->update($c, ['last_error' => 'A montagem do público foi interrompida por uma falha no banco. A fila tentará novamente.'], ['id' => $campaign->id]);
                    continue;
                }
                $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $d WHERE campaign_id=%d", $campaign->id));
                $wpdb->update($c, ['total' => $total, 'status' => $total ? 'queued' : 'completed', 'finished_at' => $total ? null : Core::now(), 'last_error' => ''], ['id' => $campaign->id, 'status' => 'scheduled']);
            }
            $started = microtime(true);
            for ($i = 0; $i < 100 && microtime(true) - $started < 20; $i++) {
                $delivery = $wpdb->get_row($wpdb->prepare("SELECT d.* FROM $d d JOIN $c c ON c.id=d.campaign_id WHERE d.status='pending' AND d.next_attempt_at<=%s AND c.status='queued' ORDER BY d.id LIMIT 1", Core::now()));
                if (!$delivery) {
                    break;
                }
                $claimed = $wpdb->update($d, ['status' => 'processing', 'attempts' => (int)$delivery->attempts + 1, 'locked_at' => Core::now()], ['id' => $delivery->id, 'status' => 'pending']);
                if ($claimed) {
                    self::deliver($delivery);
                }
            }
            foreach ($wpdb->get_results("SELECT * FROM $c WHERE status='queued'") as $campaign) {
                $stats = Campaigns::stats((int)$campaign->id);
                if (!$stats['pending'] && !$stats['processing']) {
                    $status = $stats['failed'] ? ($stats['sent'] ? 'partial' : 'failed') : 'completed';
                    $wpdb->update($c, ['status' => $status, 'finished_at' => Core::now()], ['id' => $campaign->id, 'status' => 'queued']);
                }
            }
            update_option('ppush_queue_last_run', Core::now(), false);
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", 'ppush_queue_lock', $token));
            wp_cache_delete('ppush_queue_lock', 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    private static function deliver(object $delivery): void {
        global $wpdb;
        $d = Core::table('deliveries');
        $campaign = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Core::table('campaigns') . ' WHERE id=%d', $delivery->campaign_id));
        $subscription = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Core::table('subscriptions') . ' WHERE id=%d', $delivery->subscription_id));
        if (!$campaign || $campaign->status !== 'queued' || !$subscription || !$subscription->active ||
            (int)$subscription->user_id !== (int)$delivery->user_id || !Audience::matches((int)$delivery->user_id, $campaign)) {
            $wpdb->update($d, ['status' => 'skipped', 'locked_at' => null, 'error_message' => 'Pessoa, perfil ou dispositivo deixou de ser elegível.'], ['id' => $delivery->id]);
            return;
        }
        $click_token = hash_hmac('sha256', 'click:' . $delivery->id . ':' . $campaign->id, wp_salt('auth'));
        $wpdb->update($d, ['click_hash' => hash('sha256', $click_token)], ['id' => $delivery->id]);
        $settings = Core::settings();
        $payload = [
            'portalPush' => 1,
            'title' => $campaign->title, 'body' => $campaign->message, 'url' => $campaign->url,
            'icon' => $settings['icon'] ?: get_site_icon_url(192), 'image' => $campaign->image,
            'tag' => 'portal-push-' . $campaign->id, 'campaign' => (int)$campaign->id,
            'clickUrl' => rest_url('portal-push/v1/click/' . $delivery->id), 'clickToken' => $click_token,
        ];
        $result = Transport::send($subscription, $payload, (int)$campaign->ttl);
        $attempts = (int)$delivery->attempts + 1;
        $values = ['locked_at' => null, 'error_code' => (string)($result['code'] ?? ''), 'error_message' => $result['error'] ?? ''];
        if (!empty($result['success'])) {
            $values['status'] = 'sent';
            $values['sent_at'] = Core::now();
        } elseif (!empty($result['retry']) && $attempts < 3) {
            $values['status'] = 'pending';
            $values['next_attempt_at'] = gmdate('Y-m-d H:i:s', time() + max(60 * (2 ** ($attempts - 1)), (int)($result['retry_after'] ?? 0)));
        } else {
            $values['status'] = 'failed';
        }
        if (!empty($result['expired'])) {
            $wpdb->update(Core::table('subscriptions'), ['active' => 0, 'updated_at' => Core::now()], ['id' => $subscription->id]);
        }
        $wpdb->update($d, $values, ['id' => $delivery->id]);
        do_action('portal_push_delivery_processed', (int)$delivery->id, $values['status']);
    }
}
