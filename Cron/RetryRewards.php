<?php
namespace Gamma\Wallet\Cron;

use Gamma\Wallet\Model\Gamma;
use Gamma\Wallet\Model\Rewards;

class RetryRewards
{
    public function __construct(private Gamma $gamma, private Rewards $rewards)
    {
    }

    public function execute(): void
    {
        if ($this->gamma->token() !== '') {
            $this->rewards->retryFailed();
        }
    }
}
