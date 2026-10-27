<?php

/** Small bridge between GLPI's durable inboxes and the independent SSE process. */
final class PluginNotificationbellRealtime
{
    public static function config(): array
    {
        $path = defined('GLPI_CONFIG_DIR') ? GLPI_CONFIG_DIR . '/notificationbell.php' : '';
        $config = $path !== '' && is_file($path) ? require $path : [];
        return is_array($config) ? $config : [];
    }

    /** Only a random revision is shared with the relay, never ticket text or sessions. */
    public static function changed(string $channel): void
    {
        $config = self::config();
        $directory = $config['state_dir'] ?? '';
        if ($directory === '' || !preg_match('/^[gu]_[1-9][0-9]*$/D', $channel)) {
            return;
        }
        try {
            if (!is_dir($directory) || !is_writable($directory)) {
                throw new RuntimeException('Revision directory unavailable');
            }
            if (file_put_contents($directory . '/' . $channel, bin2hex(random_bytes(16)), LOCK_EX) === false) {
                throw new RuntimeException('Revision write failed');
            }
        } catch (Throwable) {
            static $reported = false;
            if (!$reported) {
                error_log('notificationbell: realtime revision unavailable; inbox data retained.');
                $reported = true;
            }
        }
    }

    /** Short-lived, signed channel capabilities. Feed requests still recheck GLPI rights. */
    public static function connection(int $userId, array $groupIds): ?array
    {
        $config = self::config();
        $secret = (string) ($config['secret'] ?? '');
        $url = (string) ($config['url'] ?? '');
        if (strlen($secret) < 32 || str_starts_with($secret, 'REPLACE_') || $url === '') {
            return null;
        }
        $expires = time() + 120;
        $payload = self::base64url(json_encode([
            'exp' => $expires,
            'channels' => array_merge(['u_' . $userId], array_map(static fn(int $id): string => 'g_' . $id, $groupIds)),
        ], JSON_THROW_ON_ERROR));
        return [
            'url' => $url,
            'token' => $payload . '.' . self::base64url(hash_hmac('sha256', $payload, $secret, true)),
            'expires' => $expires,
            'ttl' => 120,
        ];
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
