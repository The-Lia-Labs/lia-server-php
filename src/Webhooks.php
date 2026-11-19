<?php
declare(strict_types=1);
namespace LiaLabs\Server;
final class Webhooks {
    /** Verify raw request bytes. `now` is Unix seconds. */
    public static function verify(#[\SensitiveParameter] string $secret, string $raw, string $signature, ?int $now = null, int $tolerance = 300): bool {
        if ($secret === '' || $tolerance < 1 || $tolerance > 300 || strlen($raw) > 1048576 || !preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/D', $signature, $m) || abs(($now ?? time()) - (int)$m[1]) > $tolerance) return false;
        return hash_equals(hash_hmac('sha256', $m[1].'.'.$raw, $secret), $m[2]);
    }
    public static function constructEvent(#[\SensitiveParameter] string $secret, string $raw, string $signature, ?int $now = null): array {
        if (!self::verify($secret, $raw, $signature, $now)) throw new LiaError('invalid_webhook', 401);
        try { $event = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new LiaError('invalid_webhook', 401); }
        if (!is_array($event) || !is_string($event['type'] ?? null) || !is_string($event['app'] ?? null) || !is_numeric($event['at'] ?? null)) throw new LiaError('invalid_webhook', 401);
        return $event;
    }
}
