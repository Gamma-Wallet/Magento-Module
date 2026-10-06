<?php
namespace Gamma\Wallet\Block\Adminhtml\Order;

use Gamma\Wallet\Model\Credits;
use Gamma\Wallet\Model\Gamma;
use Gamma\Wallet\Model\OrderData;
use Gamma\Wallet\Model\Rewards;
use Magento\Backend\Block\Template;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;

/** The Gamma Wallet box on the order page in the admin: where the reward or the store credits stand. */
class Box extends Template
{
    protected $_template = 'Gamma_Wallet::order/box.phtml';

    public function __construct(
        Template\Context $context,
        private Registry $registry,
        private Gamma $gamma,
        private OrderData $orderData,
        private Rewards $rewards,
        private Credits $credits,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getOrder(): ?Order
    {
        $order = $this->registry->registry('current_order');

        return $order instanceof Order && $order->getId() ? $order : null;
    }

    /** ['text' => …, 'detail' => …, 'qr' => …, 'resend' => bool] or null when Gamma is not set up. */
    public function getState(): ?array
    {
        $order = $this->getOrder();
        if (!$order || $this->gamma->token() === '') {
            return null;
        }
        $row = $this->orderData->get((int)$order->getId());
        $method = Gamma::methodCode($order);
        if ($method === Gamma::METHOD) {
            if ($row['settled_request_id']) {
                return ['text' => __('Settled with store credits through Gamma Wallet.'),
                    'detail' => __('Request %1', $row['settled_request_id']), 'resend' => false];
            }

            return ['text' => $order->getState() === Order::STATE_PENDING_PAYMENT
                ? __('Waiting for the customer to settle it with store credits.')
                : __('No reward is given for an order settled with store credits.'), 'resend' => false];
        }
        if ($row['bill_id']) {
            $claimed = $row['bill_status'] === 'Claimed' || $this->rewards->refreshStatus($order) === 'Claimed';

            return ['text' => $claimed ? __('Reward collected') : __('Waiting for the customer to collect the reward'),
                'detail' => __('Bill %1', $row['code'] ?: $row['bill_id']), 'qr' => $claimed ? null : $row['qr_url'], 'resend' => !$claimed];
        }
        if ($row['error']) {
            return ['text' => $row['error'], 'error' => true, 'resend' => true];
        }
        if (!$this->gamma->rewardServiceActive()) {
            return ['text' => __('No reward: your business has no Reward service active in Gamma.'), 'resend' => false];
        }
        if (!$this->gamma->methodEarnsReward($method)) {
            return ['text' => __('This order earns no reward (its payment method does not earn one).'), 'resend' => false];
        }
        if (!Rewards::isPaid($order)) {
            return ['text' => Gamma::isPayLater($method)
                ? __('Paid later: when you create the invoice, the reward QR code is created and the customer receives it in an email of its own.')
                : __('Not sent to Gamma Wallet yet. It is sent when the invoice is paid.'), 'resend' => false];
        }

        return ['text' => __('Not sent to Gamma Wallet yet.'), 'resend' => true];
    }

    public function getResendUrl(): string
    {
        return $this->getUrl('gammawallet/order/resend', ['order_id' => $this->getOrder()->getId()]);
    }

    public function getLogoUrl(): string
    {
        return $this->getViewFileUrl('Gamma_Wallet::images/gamma-mark-20.png');
    }
}
