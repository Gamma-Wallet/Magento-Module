<?php
namespace Gamma\Wallet\Model;

use Gamma\Wallet\Model\Api\ApiError;
use Gamma\Wallet\Model\Api\Client;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/** Rewards for paid orders: the bill sent to Gamma, its status, and the reward email. */
class Rewards
{
    /** No more automatic attempts after this many failures; the shop can still send the reward by hand. */
    public const MAX_ATTEMPTS = 6;

    public function __construct(
        private Gamma $gamma,
        private OrderData $data,
        private OrderRepositoryInterface $orders,
        private TransportBuilder $transportBuilder,
        private StateInterface $inlineTranslation,
        private ScopeConfigInterface $scopeConfig,
        private LoggerInterface $logger
    ) {
    }

    /** The money is in: the order's invoices are paid in full (a first partial invoice is not enough). */
    public static function isPaid(Order $order): bool
    {
        return (float)$order->getTotalPaid() > 0 && (float)$order->getTotalPaid() >= (float)$order->getGrandTotal() - 0.005;
    }

    /** Placed since the module was installed: older orders never earn a reward. */
    public function placedSinceInstall(Order $order): bool
    {
        $installedOn = $this->gamma->installedOn();
        // Magento keeps created_at in UTC.
        $createdOn = strtotime((string)$order->getCreatedAt() . ' UTC');

        return $installedOn > 0 && $createdOn !== false && $createdOn >= $installedOn;
    }

    /** True when this order can ever earn a reward, now or once its invoice is paid. */
    public function mayEarn(Order $order): bool
    {
        return $this->gamma->rewardsEnabled() && $this->gamma->rewardServiceActive() && $this->gamma->methodEarnsReward(Gamma::methodCode($order));
    }

    /**
     * True when this order should have a reward QR code now: rewards are on, the business has a Reward
     * service, the payment method earns one, the currencies match, and the money is in. Orders settled
     * with store credits never earn one.
     */
    public function qualifies(Order $order): bool
    {
        return self::isPaid($order)
            && (float)$order->getGrandTotal() > 0
            && $this->placedSinceInstall($order)
            && $this->mayEarn($order)
            && $this->gamma->currencyMatches((string)$order->getOrderCurrencyCode());
    }

    /**
     * Declares the order to Gamma once. Returns true when the order has a bill afterwards. Safe to call
     * any number of times: Gamma returns the same bill for the same reference.
     */
    public function ensureBill(Order $order, int $timeout = 20): bool
    {
        $orderId = (int)$order->getId();
        if (!$orderId) {
            return false;
        }
        $row = $this->data->get($orderId);
        if ($row['bill_id']) {
            return true;
        }
        if (!$this->qualifies($order) || (int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }
        $api = $this->gamma->api();
        if (!$api) {
            return false;
        }
        try {
            $bill = $api->createBill([
                'reference'     => $this->gamma->reference($order),
                'total'         => round((float)$order->getGrandTotal(), 2),
                'currencyCode'  => $order->getOrderCurrencyCode(),
                // Magento keeps created_at in UTC.
                'issuedOn'      => gmdate(DATE_ATOM, strtotime($order->getCreatedAt() . ' UTC') ?: time()),
                'platform'      => 'magento',
                'pluginVersion' => Client::VERSION,
            ], $timeout);
        } catch (ApiError $e) {
            $this->data->save($orderId, ['error' => substr(Gamma::explain($e), 0, 250), 'attempts' => (int)$row['attempts'] + 1]);
            $this->logger->warning('Gamma Wallet: bill for order ' . $order->getIncrementId() . ' failed: ' . $e->getMessage());

            return false;
        }
        $this->data->save($orderId, [
            'bill_id' => $bill['billId'], 'code' => $bill['code'], 'link' => $bill['link'], 'qr_url' => $bill['qrImageUrl'],
            'bill_status' => $bill['status'], 'claimed_on' => $bill['claimedOn'] ?? null, 'error' => null,
        ]);

        return true;
    }

    /** Asks Gamma whether the reward was collected. "Waiting" or "Claimed". */
    public function refreshStatus(Order $order, int $timeout = 20): string
    {
        $orderId = (int)$order->getId();
        $row = $this->data->get($orderId);
        $api = $this->gamma->api();
        if ($row['bill_status'] === 'Claimed' || !$row['bill_id'] || !$api) {
            return (string)$row['bill_status'];
        }
        try {
            $bill = $api->getBill($row['bill_id'], $timeout);
        } catch (ApiError $e) {
            return (string)$row['bill_status'];
        }
        if ($bill['status'] !== $row['bill_status']) {
            $this->data->save($orderId, ['bill_status' => $bill['status'], 'claimed_on' => $bill['claimedOn'] ?? null]);
        }

        return (string)$bill['status'];
    }

    /** The reward email of its own: for orders paid after the confirmation email, and for sending the QR code again by hand. */
    public function sendRewardEmail(Order $order): bool
    {
        if (!$this->ensureBill($order)) {
            return false;
        }
        $row = $this->data->get((int)$order->getId());
        $storeId = (int)$order->getStoreId();
        $this->inlineTranslation->suspend();
        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier('gamma_wallet_reward')
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'order_increment_id' => $order->getIncrementId(),
                    'customer_name' => $order->getCustomerFirstname() ?: $order->getBillingAddress()?->getFirstname(),
                    'store_name' => $order->getStore()->getFrontendName(),
                    'qr' => $row['qr_url'],
                    'link' => $row['link'],
                ])
                ->setFromByScope($this->scopeConfig->getValue('sales_email/order/identity', ScopeInterface::SCOPE_STORE, $storeId) ?: 'sales', $storeId)
                ->addTo((string)$order->getCustomerEmail(), (string)$order->getCustomerName())
                ->getTransport();
            $transport->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->warning('Gamma Wallet: reward email for order ' . $order->getIncrementId() . ' failed: ' . $e->getMessage());

            return false;
        } finally {
            $this->inlineTranslation->resume();
        }
        $this->data->save((int)$order->getId(), ['emailed' => time()]);

        return true;
    }

    /** Cron: orders whose reward failed for a passing reason get another try (6 at most). */
    public function retryFailed(): void
    {
        foreach ($this->data->retryable(self::MAX_ATTEMPTS) as $orderId) {
            try {
                $order = $this->orders->get((int)$orderId);
                if ($this->ensureBill($order) && $order->getEmailSent() && !(int)$this->data->get((int)$orderId)['emailed']) {
                    $this->sendRewardEmail($order);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Gamma Wallet: retry for order ' . $orderId . ' failed: ' . $e->getMessage());
            }
        }
    }
}
