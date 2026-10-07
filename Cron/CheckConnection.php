<?php
namespace Gamma\Wallet\Cron;

use Gamma\Wallet\Model\Gamma;

/** Keeps the connection check (Reward service, currency, token expiry) fresh, so no page waits for Gamma. */
class CheckConnection
{
    public function __construct(private Gamma $gamma)
    {
    }

    public function execute(): void
    {
        $this->gamma->refreshIfStale(20);
    }
}
