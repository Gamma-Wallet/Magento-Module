<?php
namespace Gamma\Wallet\Model\Config\Source;

use Gamma\Wallet\Model\Gamma;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Payment\Model\Config;

/**
 * The shop's active payment methods, for "Payment methods that earn no reward". Store credits and
 * zero-total orders are left out: they never earn one anyway.
 */
class PaymentMethods implements OptionSourceInterface
{
    public function __construct(private Config $paymentConfig, private ScopeConfigInterface $scopeConfig)
    {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach (array_keys($this->paymentConfig->getActiveMethods()) as $code) {
            if ($code === Gamma::METHOD || $code === 'free') {
                continue;
            }
            $title = (string)$this->scopeConfig->getValue('payment/' . $code . '/title');
            $options[] = ['value' => $code, 'label' => ($title ?: $code) . (Gamma::isPayLater($code) ? ' — ' . __('paid later') : '')];
        }
        usort($options, fn ($a, $b) => strcasecmp((string)$a['label'], (string)$b['label']));

        return $options;
    }
}
