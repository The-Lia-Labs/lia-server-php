<?php
declare(strict_types=1);
namespace LiaLabs\Server;
final class LiaClient {
    private string $key;
    private string $baseUrl;
    public readonly string $mode;
    private array $operations;
    public function __construct(#[\SensitiveParameter] string $key, string $baseUrl = 'https://api.lialabs.org/api/apps/v1', private float $timeout = 15.0, private int $maxRetries = 2) {
        if (!preg_match('/^lia_(test|live)_[A-Za-z0-9_-]+$/D', $key)) throw new LiaError('invalid_server_key');
        $url = parse_url($baseUrl);
        if (!$url || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !isset($url['host']) || !(($url['scheme'] ?? '') === 'https' || (($url['scheme'] ?? '') === 'http' && in_array($url['host'], ['localhost','127.0.0.1','[::1]'], true)))) throw new LiaError('invalid_base_url');
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 120 || $maxRetries < 0 || $maxRetries > 3) throw new LiaError('invalid_client_options');
        $this->key = $key; $this->mode = str_starts_with($key, 'lia_test_') ? 'test' : 'live'; $this->baseUrl = rtrim($baseUrl, '/');
        $this->operations = json_decode(file_get_contents(__DIR__.'/operations.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    public function __debugInfo(): array { return ['mode' => $this->mode]; }
    public function __serialize(): array { return ['mode'=>$this->mode]; }
    public function __unserialize(array $data): void { throw new LiaError('client_not_serializable'); }
    private static function safe(mixed $value): ?string { return is_string($value) && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $value) ? $value : null; }
    /** Named operations only: a caller cannot use a URL to exfiltrate the server key. */
    public function call(string $operation, array $params = [], ?string $idempotencyKey = null): array|string {
        $spec = $this->operations[$operation] ?? null;
        if (!$spec) throw new LiaError('unknown_operation');
        foreach ($spec['required'] as $field) if (!array_key_exists($field, $params)) throw new LiaError('missing_parameter');
        $path = preg_replace_callback('/\{(\w+)\}/', function(array $m) use ($params) { $value = $params[$m[1]] ?? null; if (!self::safe($value) || $value === '.' || $value === '..') throw new LiaError('invalid_path_parameter'); return rawurlencode($value); }, $spec['path']);
        $query = array_intersect_key($params, array_flip($spec['query']));
        $url = $this->baseUrl.$path.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        if ($idempotencyKey !== null && !self::safe($idempotencyKey)) throw new LiaError('invalid_idempotency_key');
        $headers = ['Authorization: Bearer '.$this->key, 'Accept: '.($spec['text'] ? 'text/csv' : 'application/json'), 'User-Agent: lia-labs-server-php/0.1.0'];
        if ($spec['method'] !== 'GET' && $operation !== 'verifySession') $headers[] = 'Idempotency-Key: '.($idempotencyKey ?? bin2hex(random_bytes(16)));
        $body = null;
        if ($spec['method'] === 'POST') { $headers[] = 'Content-Type: application/json'; $body = json_encode((object)array_intersect_key($params, array_flip($spec['body'])), JSON_THROW_ON_ERROR); }
        $deadline = microtime(true) + $this->timeout;
        for ($attempt = 0; ; $attempt++) {
            $handle = curl_init($url); $responseHeaders = [];
            curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $spec['method'], CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => max(1,(int)(($deadline-microtime(true))*1000)), CURLOPT_CONNECTTIMEOUT_MS => max(1,(int)(($deadline-microtime(true))*1000)), CURLOPT_PROTOCOLS => CURLPROTO_HTTP|CURLPROTO_HTTPS, CURLOPT_HEADERFUNCTION => function($ch, string $line) use (&$responseHeaders): int { $parts = explode(':', $line, 2); if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]); return strlen($line); }]);
            if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            $raw = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE); $networkFailure = $raw === false; unset($handle);
            $value = $networkFailure ? null : json_decode($raw, true);
            if (!$networkFailure && $status >= 200 && $status < 300) {
                if ($spec['text']) return $raw;
                if (!is_array($value)) throw new LiaError('invalid_response', $status);
                return $value;
            }
            $retryAfter = $responseHeaders['retry-after'] ?? null;
            if ($retryAfter !== null) $retryAfter = is_numeric($retryAfter) ? max(0,(float)$retryAfter) : (($at = strtotime($retryAfter)) !== false ? max(0,$at-time()) : null);
            $error = new LiaError($networkFailure ? 'network_error' : (self::safe($value['error'] ?? null) ?? 'http_error'), $status, self::safe($responseHeaders['x-lia-request-id'] ?? null), $retryAfter);
            $delay = $error->retryAfter ?? (.1 * 2**$attempt + random_int(0,100)/1000);
            if (!$error->retryable() || $attempt >= $this->maxRetries || microtime(true) + $delay >= $deadline) throw $error;
            usleep((int)($delay*1000000));
        }
    }
    public function requireSession(#[\SensitiveParameter] string $session): array {
        $result = $this->verifySession(['session' => $session]);
        if (($result['valid'] ?? null) !== true || !preg_match('/^p_\w{8,40}$/D', (string)($result['playerId'] ?? '')) || ($result['mode'] ?? null) !== $this->mode || !is_int($result['exp'] ?? null) || $result['exp'] <= (int)(microtime(true)*1000)) throw new LiaError('invalid_session', 401);
        return $result;
    }
    public function verifySession(array $params = [], ?string $idempotencyKey = null): array { return $this->call('verifySession', $params, $idempotencyKey); }
    public function listProducts(array $params = [], ?string $idempotencyKey = null): array { return $this->call('listProducts', $params, $idempotencyKey); }
    public function getCharge(array $params = [], ?string $idempotencyKey = null): array { return $this->call('getCharge', $params, $idempotencyKey); }
    public function deliverCharge(array $params = [], ?string $idempotencyKey = null): array { return $this->call('deliverCharge', $params, $idempotencyKey); }
    public function refundCharge(array $params = [], ?string $idempotencyKey = null): array { return $this->call('refundCharge', $params, $idempotencyKey); }
    public function payReward(array $params = [], ?string $idempotencyKey = null): array { return $this->call('payReward', $params, $idempotencyKey); }
    public function getPool(array $params = [], ?string $idempotencyKey = null): array { return $this->call('getPool', $params, $idempotencyKey); }
    public function sendNotification(array $params = [], ?string $idempotencyKey = null): array { return $this->call('sendNotification', $params, $idempotencyKey); }
    public function verifyAd(array $params = [], ?string $idempotencyKey = null): array { return $this->call('verifyAd', $params, $idempotencyKey); }
    public function listTables(array $params = [], ?string $idempotencyKey = null): array { return $this->call('listTables', $params, $idempotencyKey); }
    public function saveTable(array $params = [], ?string $idempotencyKey = null): array { return $this->call('saveTable', $params, $idempotencyKey); }
    public function sitAtTable(array $params = [], ?string $idempotencyKey = null): array { return $this->call('sitAtTable', $params, $idempotencyKey); }
    public function removeTable(array $params = [], ?string $idempotencyKey = null): array { return $this->call('removeTable', $params, $idempotencyKey); }
    public function listPendingCharges(array $params = [], ?string $idempotencyKey = null): array { return $this->call('listPendingCharges', $params, $idempotencyKey); }
    public function listLeaderboards(array $params = [], ?string $idempotencyKey = null): array { return $this->call('listLeaderboards', $params, $idempotencyKey); }
    public function saveLeaderboard(array $params = [], ?string $idempotencyKey = null): array { return $this->call('saveLeaderboard', $params, $idempotencyKey); }
    public function getLeaderboard(array $params = [], ?string $idempotencyKey = null): array { return $this->call('getLeaderboard', $params, $idempotencyKey); }
    public function submitScore(array $params = [], ?string $idempotencyKey = null): array { return $this->call('submitScore', $params, $idempotencyKey); }
    public function moderateScore(array $params = [], ?string $idempotencyKey = null): array { return $this->call('moderateScore', $params, $idempotencyKey); }
    public function getPlayerStorage(array $params = [], ?string $idempotencyKey = null): array { return $this->call('getPlayerStorage', $params, $idempotencyKey); }
    public function setPlayerStorage(array $params = [], ?string $idempotencyKey = null): array { return $this->call('setPlayerStorage', $params, $idempotencyKey); }
    public function listWebhookEvents(array $params = [], ?string $idempotencyKey = null): array { return $this->call('listWebhookEvents', $params, $idempotencyKey); }
    public function replayWebhookEvent(array $params = [], ?string $idempotencyKey = null): array { return $this->call('replayWebhookEvent', $params, $idempotencyKey); }
    public function createAdChallenge(array $params = [], ?string $idempotencyKey = null): array { return $this->call('createAdChallenge', $params, $idempotencyKey); }
    public function claimAdChallenge(array $params = [], ?string $idempotencyKey = null): array { return $this->call('claimAdChallenge', $params, $idempotencyKey); }
    public function getAnalytics(array $params = [], ?string $idempotencyKey = null): array { return $this->call('getAnalytics', $params, $idempotencyKey); }
    public function exportAnalytics(array $params = [], ?string $idempotencyKey = null): string { return $this->call('exportAnalytics', $params, $idempotencyKey); }
}
