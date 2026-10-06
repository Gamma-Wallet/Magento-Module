<?php
namespace Gamma\Wallet\Model\Payment;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Sales\Model\Order;

/**
 * "Use Store Credits with Gamma": the whole order is settled from the store credits the customer holds
 * at the shop. Gamma handles no money, so nothing is authorised or captured here: the order waits
 * (pending payment, status "Awaiting Gamma store credits") until the customer confirms in Gamma Wallet.
 * When it is offered is decided by Observer\MethodAvailability.
 */
class GammaWallet extends AbstractMethod
{
    public const CODE = 'gammawallet';

    protected $_code = self::CODE;
    protected $_isOffline = true;
    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;

    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus($this->getConfigData('order_status') ?: 'gamma_awaiting');
        $stateObject->setIsNotified(false);
        // The confirmation email goes out once the order is settled (Model\Credits::markSettled).
        $this->getInfoInstance()->getOrder()->setCanSendNewEmailFlag(false);

        return $this;
    }
}
