<?php
namespace Gamma\Wallet\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\View\Asset\Repository;

/** What the checkout's payment step shows under "Use Store Credits with Gamma". */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(private Repository $assets)
    {
    }

    public function getConfig(): array
    {
        return ['payment' => ['gammawallet' => [
            'instructions' => (string)__('After you place the order, scan the QR code with the Gamma Wallet app. The whole order is settled with the store credits you hold at our shop.'),
            'logo' => $this->assets->getUrl('Gamma_Wallet::images/gamma-mark-20.png'),
        ]]];
    }
}
