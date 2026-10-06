<?php
namespace Gamma\Wallet\Observer;

use Gamma\Wallet\Model\Gamma;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CheckConnectionOnSave implements ObserverInterface
{
    public function __construct(private Gamma $gamma, private ReinitableConfigInterface $config)
    {
    }

    public function execute(Observer $observer): void
    {
        // The token was just saved: read the settings again before asking Gamma.
        $this->config->reinit();
        $this->gamma->checkConnection();
    }
}
