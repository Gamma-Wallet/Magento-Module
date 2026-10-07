<?php
namespace Gamma\Wallet\Cron;

use Gamma\Wallet\Model\Credits;
use Gamma\Wallet\Model\Gamma;

/**
 * A customer who confirms in Gamma Wallet and closes the page before it asks again must still get their
 * order: every few minutes, store-credit orders still waiting are checked with Gamma.
 */
class ReconcileCredits
{
    public function __construct(private Gamma $gamma, private Credits $credits)
    {
    }

    public function execute(): void
    {
        if ($this->gamma->token() !== '') {
            $this->credits->reconcile();
        }
    }
}
