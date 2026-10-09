<?php
namespace App\Utility;

/** Short-lived proof bound to the requesting IP and a pre-login accounting watermark. */
class PortalSessionProof
{
    public static function issue(string $ip, string $username, int $afterId, string $key, int $now): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode([
            'ip' => $ip, 'username' => $username, 'after' => $afterId,
            'issued' => $now, 'nonce' => bin2hex(random_bytes(16))
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', $payload, $key);
    }

    public static function verify(string $token, string $ip, string $key, int $now): ?array
    {
        if (strlen($token) > 2048 || $key === '') return null;
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], $key), $parts[1])) return null;
        $data = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true) ?: '', true);
        if (!is_array($data) || ($data['ip'] ?? null) !== $ip ||
            !is_string($data['username'] ?? null) || !is_int($data['after'] ?? null) ||
            !is_int($data['issued'] ?? null) || $data['issued'] > $now || $now - $data['issued'] > 180) return null;
        return $data;
    }
}
