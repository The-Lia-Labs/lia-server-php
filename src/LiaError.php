<?php
declare(strict_types=1);
namespace LiaLabs\Server;
final class LiaError extends \RuntimeException {
    public function __construct(public readonly string $errorCode, public readonly int $status = 0, public readonly ?string $requestId = null, public readonly ?float $retryAfter = null) { parent::__construct($errorCode); }
    public function retryable(): bool { return in_array($this->status, [0,408,425,429,500,502,503,504], true) || $this->errorCode === 'idempotency_key_in_use'; }
}
