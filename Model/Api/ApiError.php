<?php
namespace Gamma\Wallet\Model\Api;

/** Gamma answered with an error, or could not be reached (status 0). */
class ApiError extends \Exception
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $identifier,
        public readonly ?string $errorName
    ) {
        parent::__construct(sprintf('Gamma answered HTTP %d: %s%s', $status, $errorName ?: 'no details', $identifier ? ' (' . $identifier . ')' : ''));
    }

    /** Worth trying again later: Gamma unreachable, busy or failing. */
    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
