<?php
namespace PauloMaeda\PortalPush;

defined('ABSPATH') || exit;

use PortalPushVendor\Minishlink\WebPush\Subscription;
use PortalPushVendor\Minishlink\WebPush\VAPID;
use PortalPushVendor\Minishlink\WebPush\WebPush;

final class Transport {
    public static function keys(): array|\WP_Error {
        $keys = get_option('ppush_vapid', []);
        if (!empty($keys['publicKey']) && !empty($keys['privateKey'])) {
            return $keys;
        }
        return new \WP_Error('ppush_keys', 'Gere as chaves de envio em Configurações.');
    }

    public static function generate_keys(): array|\WP_Error {
        $existing = get_option('ppush_vapid', []);
        if (!empty($existing['publicKey'])) {
            return $existing;
        }
        try {
            $keys = VAPID::createVapidKeys();
            add_option('ppush_vapid', $keys, '', false);
            return get_option('ppush_vapid');
        } catch (\Throwable $error) {
            return new \WP_Error('ppush_crypto', 'Não foi possível gerar as chaves. Verifique OpenSSL no servidor.');
        }
    }

    /** Endpoints apenas dos provedores de navegadores; evita SSRF. */
    public static function valid_endpoint(string $url): bool {
        $parts = wp_parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !empty($parts['user']) || !empty($parts['pass']) ||
            !empty($parts['fragment']) || (isset($parts['port']) && (int)$parts['port'] !== 443) || strlen($url) > 2048) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        if (in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'], true)) {
            return true;
        }
        foreach (['.push.services.mozilla.com', '.push.apple.com', '.notify.windows.com', '.push.windows.net'] as $suffix) {
            if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                return true;
            }
        }
        return false;
    }

    public static function send(object $subscription, array $payload, int $ttl): array {
        $keys = self::keys();
        if (is_wp_error($keys)) {
            return ['success' => false, 'code' => 'keys', 'retry' => false, 'error' => $keys->get_error_message()];
        }
        if (!self::valid_endpoint($subscription->endpoint)) {
            return ['success' => false, 'code' => 'endpoint', 'retry' => false, 'error' => 'Endpoint não permitido.'];
        }
        try {
            $http = apply_filters('portal_push_http_options', ['connect_timeout' => 3, 'allow_redirects' => false]);
            $http['allow_redirects'] = false;
            $push = new WebPush(['VAPID' => [
                'subject' => 'mailto:' . Core::settings()['contact'],
                'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey'],
            ]], ['TTL' => $ttl, 'urgency' => 'normal'], 6, $http, new \PortalPushVendor\Psr\Log\NullLogger());
            $report = $push->sendOneNotification(Subscription::create([
                'endpoint' => $subscription->endpoint, 'publicKey' => $subscription->p256dh,
                'authToken' => $subscription->auth, 'contentEncoding' => 'aes128gcm',
            ]), wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $response = $report->getResponse();
            $code = $response ? $response->getStatusCode() : 0;
            $retry_after = $response ? $response->getHeaderLine('Retry-After') : '';
            $wait = ctype_digit($retry_after) ? (int)$retry_after : 0;
            if (!$wait && $retry_after) {
                $wait = max(0, (int)strtotime($retry_after) - time());
            }
            return [
                'success' => $report->isSuccess(), 'code' => (string)$code,
                'expired' => $report->isSubscriptionExpired(),
                'retry' => $code === 0 || $code === 408 || $code === 429 || $code >= 500,
                'retry_after' => min(3600, max(0, $wait)),
                'error' => $report->isSuccess() ? '' : ($code ? 'Serviço de push retornou HTTP ' . $code . '.' : 'Falha de conexão com o serviço de push.'),
            ];
        } catch (\Throwable $error) {
            return ['success' => false, 'code' => 'transport', 'retry' => false, 'error' => 'Falha no preparo criptográfico ou na configuração do envio.'];
        }
    }
}
