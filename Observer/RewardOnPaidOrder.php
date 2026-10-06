<?php
namespace Gamma\Wallet\Observer;

use Gamma\Wallet\Model\Gamma;
use Gamma\Wallet\Model\OrderData;
use Gamma\Wallet\Model\Rewards;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * sales_order_save_after: an order whose money is in gets its reward. When the order confirmation email
 * has already gone out without it (paid later, or captured later by the shop), the customer gets the
 * reward in an email of its own, once.
 */
class RewardOnPaidOrder implements ObserverInterface
{
    /** Orders handled in this request: an order is saved several times while it is placed. */
    private array $done = [];

    public function __construct(
        private Rewards $rewards,
        private OrderData $data,
        private LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof Order || !$order->getId() || isset($this->done[$order->getId()])) {
            return;
        }
        if (!Rewards::isPaid($order) || Gamma::methodCode($order) === Gamma::METHOD) {
            return;
        }
        $this->done[$order->getId()] = true;
        try {
            $hadBill = (bool)$this->data->get((int)$order->getId())['bill_id'];
            // Short timeout: this runs while the order is being saved; a failure is retried by cron.
            if ($hadBill || !$this->rewards->ensureBill($order, 10)) {
                return;
            }
            if ($order->getEmailSent()) {
                $this->rewards->sendRewardEmail($order);
            }
        } catch (\Throwable $e) {
            // Never let a reward problem stop the order.
            $this->logger->warning('Gamma Wallet: reward step for order ' . $order->getIncrementId() . ' failed: ' . $e->getMessage());
        }
    }
}
