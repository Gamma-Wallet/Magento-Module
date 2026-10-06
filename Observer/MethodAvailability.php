<?php
namespace Gamma\Wallet\Observer;

use Gamma\Wallet\Model\Gamma;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * payment_method_is_active: "Use Store Credits with Gamma" is offered only when the shop is connected,
 * the business has a Reward service, the cart's currency is the business's and the total is above zero.
 */
class MethodAvailability implements ObserverInterface
{
    public function __construct(private Gamma $gamma)
    {
    }

    public function execute(Observer $observer): void
    {
        $method = $observer->getEvent()->getMethodInstance();
        $result = $observer->getEvent()->getResult();
        if (!$method || $method->getCode() !== Gamma::METHOD || !$result->getData('is_available')) {
            return;
        }
        $quote = $observer->getEvent()->getQuote();
        $available = $this->gamma->creditsEnabled()
            && $this->gamma->rewardServiceActive()
            && (!$quote || ((float)$quote->getGrandTotal() > 0
                && !$quote->getIsMultiShipping()
                && $this->gamma->currencyMatches((string)$quote->getQuoteCurrencyCode())));
        $result->setData('is_available', $available);
    }
}
