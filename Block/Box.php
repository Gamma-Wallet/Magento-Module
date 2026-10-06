<?php
namespace Gamma\Wallet\Block;

use Gamma\Wallet\Model\Api\Client;
use Gamma\Wallet\Model\Credits;
use Gamma\Wallet\Model\Gamma;
use Gamma\Wallet\Model\OrderData;
use Gamma\Wallet\Model\Rewards;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Model\Order;

/**
 * The Gamma Wallet box on the order success page and the customer's order page: the reward QR code,
 * the store-credit QR code with its countdown, or a short note. Never cached: it is built per order.
 */
class Box extends Template
{
    protected $_template = 'Gamma_Wallet::box.phtml';
    private ?array $box = null;

    public function __construct(
        Template\Context $context,
        private CheckoutSession $checkoutSession,
        private Registry $registry,
        private Gamma $gamma,
        private OrderData $orderData,
        private Rewards $rewards,
        private Credits $credits,
        private PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setData('cache_lifetime', null);
    }

    private function order(): ?Order
    {
        // On the success page: the order just placed. On the order pages: the order being shown.
        $order = $this->getData('source') === 'success'
            ? $this->checkoutSession->getLastRealOrder()
            : $this->registry->registry('current_order');

        return $order instanceof Order && $order->getId() ? $order : null;
    }

    /** What the template shows, or null for nothing at all. */
    public function getBox(): ?array
    {
        if ($this->box !== null) {
            return $this->box ?: null;
        }
        $this->box = [];
        $order = $this->order();
        if (!$order || $this->gamma->token() === '') {
            return null;
        }
        $orderId = (int)$order->getId();
        $key = $this->gamma->orderKey($orderId);
        $box = [
            'status_url' => $this->getUrl('gammawallet/order/status', ['_query' => ['order_id' => $orderId, 'key' => $key], '_secure' => true]),
            'new_code_url' => $this->getUrl('gammawallet/order/newCode', ['_query' => ['order_id' => $orderId, 'key' => $key], '_secure' => true]),
        ];
        if (Gamma::methodCode($order) === Gamma::METHOD) {
            if ($this->credits->isSettled($order)) {
                $box += ['kind' => 'note', 'note' => __('Your order is settled with your store credits through Gamma Wallet.')];
            } elseif ($order->getState() === Order::STATE_PENDING_PAYMENT) {
                $row = $this->orderData->get($orderId);
                if (!$row['credit_request']) {
                    // The first time the customer sees the order: ask Gamma for the code now, so its
                    // 60 seconds start when it is on the screen. Later codes come from "Show a new code".
                    try {
                        $this->credits->startRequest($order);
                    } catch (\Gamma\Wallet\Model\Api\ApiError $e) {
                        $this->_logger->warning('Gamma Wallet: store-credit request for order ' . $order->getIncrementId() . ' failed: ' . $e->getMessage());
                    }
                    $row = $this->orderData->get($orderId);
                }
                $expires = $row['credit_expires_on'] ? strtotime($row['credit_expires_on']) : 0;
                $box += [
                    'kind' => 'credit',
                    'seconds' => $expires ? max(0, $expires - time()) : 0,
                    'qr' => $row['credit_qr'] ? 'data:image/png;base64,' . $row['credit_qr'] : '',
                    'link' => (string)$row['credit_link'],
                    'total' => $this->priceCurrency->format((float)$order->getGrandTotal(), false, 2, null, $order->getOrderCurrencyCode()),
                ];
            } else {
                return null;
            }
        } elseif ($this->rewards->ensureBill($order)) {
            $row = $this->orderData->get($orderId);
            $claimed = $row['bill_status'] === 'Claimed' || $this->rewards->refreshStatus($order) === 'Claimed';
            $box += ['kind' => 'reward', 'claimed' => $claimed, 'qr' => $row['qr_url'], 'link' => $row['link']];
        } elseif (!Rewards::isPaid($order) && $this->rewards->mayEarn($order) && !$order->isCanceled()) {
            $box += ['kind' => 'note', 'note' => Gamma::isPayLater(Gamma::methodCode($order))
                ? __('This order earns a Gamma Wallet reward. Once your payment is received, we will email you a QR code to collect it.')
                : __('As soon as your payment is confirmed, you will receive a QR code by email to collect your reward with Gamma Wallet.')];
        } else {
            return null;
        }
        $this->box = $box;

        return $box;
    }

    public function getTexts(): string
    {
        return (string)json_encode([
            'secondsLeft' => __('%1 s left', '%d')->render(),
            'timeLeft' => __('%1 left', '%s')->render(),
            'expired' => __('This code has expired.')->render(),
            'settled' => __('Done! Your order is settled with your store credits.')->render(),
            'claimed' => __('Reward collected. Thank you!')->render(),
            'unavailable' => __('Gamma cannot be reached right now. Please try again in a moment.')->render(),
        ]);
    }

    public function getVersion(): string
    {
        return Client::VERSION;
    }
}
