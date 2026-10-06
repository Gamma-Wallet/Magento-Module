<?php
namespace Gamma\Wallet\Block;

use Gamma\Wallet\Model\Gamma;
use Gamma\Wallet\Model\OrderData;
use Gamma\Wallet\Model\Rewards;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * The reward QR code in the order confirmation email (layout handle sales_email_order_items). The email
 * hands every block of that handle its order_id (or, in older versions, the order itself).
 */
class Email extends Template
{
    protected $_template = 'Gamma_Wallet::email/reward_block.phtml';

    public function __construct(
        Template\Context $context,
        private OrderRepositoryInterface $orders,
        private Gamma $gamma,
        private OrderData $orderData,
        private Rewards $rewards,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    private function order(): ?Order
    {
        $order = $this->getData('order');
        if (!$order instanceof Order && $this->getData('order_id')) {
            try {
                $order = $this->orders->get((int)$this->getData('order_id'));
            } catch (\Exception $e) {
                return null;
            }
        }

        return $order instanceof Order && $order->getId() ? $order : null;
    }

    /** The QR code and link, when this order has a reward and the shop wants it in the email. */
    public function getReward(): ?array
    {
        $order = $this->order();
        if (!$order || !$this->gamma->rewardInEmail() || Gamma::methodCode($order) === Gamma::METHOD) {
            return null;
        }
        // Normally created a moment ago, when the paid order was saved; a short last try otherwise.
        if (!$this->orderData->get((int)$order->getId())['bill_id'] && !$this->rewards->ensureBill($order, 10)) {
            return null;
        }
        $row = $this->orderData->get((int)$order->getId());

        return ['qr' => $row['qr_url'], 'link' => $row['link']];
    }
}
