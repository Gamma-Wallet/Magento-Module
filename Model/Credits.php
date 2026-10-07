<?php
namespace Gamma\Wallet\Model;

use Gamma\Wallet\Model\Api\ApiError;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

/** "Use Store Credits with Gamma": the request shown as a QR code, its status, and settling the order. */
class Credits
{
    /** How long after a code was shown cron keeps asking Gamma whether it was settled. */
    public const RECONCILE_HOURS = 6;

    public function __construct(
        private Gamma $gamma,
        private OrderData $data,
        private InvoiceService $invoiceService,
        private TransactionFactory $transactionFactory,
        private OrderSender $orderSender,
        private OrderRepositoryInterface $orders,
        private NotifierInterface $notifier,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Asks Gamma for a new store-credit request for the whole order, and keeps it. Returns null when
     * another request is starting one or the current one is still live (never two codes for one order).
     * $firstOnly: only if the order never had a code (the first view of the order).
     */
    public function startRequest(Order $order, bool $firstOnly = false): ?array
    {
        $api = $this->gamma->api();
        if (!$api) {
            throw new ApiError(401, '0392', 'IntegrationTokenMissing');
        }
        $orderId = (int)$order->getId();
        $previous = $this->data->claimCodeSlot($orderId, $firstOnly);
        if ($previous === null) {
            return null;
        }
        try {
            $request = $api->startCredit([
                'reference'    => $this->gamma->reference($order),
                'total'        => round((float)$order->getGrandTotal(), 2),
                'currencyCode' => $order->getOrderCurrencyCode(),
            ]);
        } catch (ApiError $e) {
            $this->data->releaseCodeSlot($orderId, $previous);
            throw $e;
        }
        $this->data->save($orderId, [
            'credit_request' => $request['creditRequest'], 'credit_request_id' => $request['requestId'],
            'credit_expires_on' => $request['expiresOn'], 'credit_link' => $request['link'], 'credit_qr' => $request['qrPngBase64'] ?? '',
        ]);

        return $request;
    }

    public function isSettled(Order $order): bool
    {
        return (bool)$this->data->get((int)$order->getId())['settled_request_id'];
    }

    /** Paid (settled now or before), Waiting with the seconds left, or Expired. */
    public function status(Order $order): array
    {
        if ($this->isSettled($order)) {
            return ['status' => 'Paid'];
        }
        $row = $this->data->get((int)$order->getId());
        $api = $this->gamma->api();
        if (!$row['credit_request'] || !$api) {
            return ['status' => 'Expired'];
        }
        $checked = $api->checkCredit($row['credit_request']);
        if ($checked['status'] === 'Paid') {
            $this->markSettled($order, $checked);

            return ['status' => 'Paid'];
        }

        return ['status' => $checked['status'], 'secondsLeft' => (int)$checked['secondsLeft']];
    }

    /**
     * Cron: orders whose customer may have confirmed in the app after leaving the page. Credit/Check
     * answers for a request long after its code expired, so a settled order is found even then.
     */
    public function reconcile(): void
    {
        foreach ($this->data->awaitingSettlement(self::RECONCILE_HOURS) as $orderId) {
            try {
                $order = $this->orders->get((int)$orderId);
                if ($order instanceof Order && Gamma::methodCode($order) === Gamma::METHOD) {
                    $this->status($order);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Gamma Wallet: checking the store credits of order ' . $orderId . ' failed: ' . $e->getMessage());
            }
        }
    }

    /** Gamma's answer is about this order: same reference, total and currency. */
    private function matches(Order $order, array $checked): bool
    {
        if (isset($checked['reference']) && (string)$checked['reference'] !== $this->gamma->reference($order)) {
            return false;
        }
        if (isset($checked['currencyCode']) && strcasecmp((string)$checked['currencyCode'], (string)$order->getOrderCurrencyCode()) !== 0) {
            return false;
        }

        return !isset($checked['total']) || abs((float)$checked['total'] - round((float)$order->getGrandTotal(), 2)) < 0.005;
    }

    /**
     * Records a settled request once: the order gets its invoice (no money changes hands, so it is
     * captured offline), moves to Processing with a note, and the order confirmation email goes out.
     * When the order no longer waits for payment (cancelled meanwhile, or changed by hand) or the invoice
     * fails, the shop is told loudly: the customer has used their credits.
     */
    public function markSettled(Order $order, array $checked): void
    {
        if (!$this->matches($order, $checked)) {
            $this->logger->error('Gamma Wallet: the settled request ' . ($checked['requestId'] ?? '?') . ' does not match order '
                . $order->getIncrementId() . ' (reference, total or currency); the order was not changed.');

            return;
        }
        $requestId = (string)($checked['requestId'] ?? '') ?: 'settled';
        if (!$this->data->claimSettlement((int)$order->getId(), $requestId)) {
            return;
        }
        $note = (string)__("Settled with the customer's store credits through Gamma Wallet (request %1).", $requestId);
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT || !$order->canInvoice()) {
            $this->alert($order, $note . ' ' . __('The order was no longer waiting for payment (state: %1), so it was not changed: check it.', (string)$order->getState()));

            return;
        }
        try {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
            $invoice->register();
            // Magento moves only a "new" order to Processing by itself; this one waited in pending payment.
            $order->setState(Order::STATE_PROCESSING)
                ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
            $order->addCommentToStatusHistory($note, false, true);
            $this->transactionFactory->create()->addObject($invoice)->addObject($order)->save();
        } catch (\Throwable $e) {
            $this->logger->error('Gamma Wallet: settling order ' . $order->getIncrementId() . ' failed: ' . $e->getMessage());
            // The order in memory holds the half-made invoice's totals: note it on a fresh copy instead.
            try {
                $order = $this->orders->get((int)$order->getId());
            } catch (\Throwable $ignored) {
                return;
            }
            $this->alert($order, $note . ' ' . __('The invoice could not be created: please create it by hand.'));

            return;
        }
        try {
            // The confirmation email was held back while the order waited for the credits.
            $order->setCanSendNewEmailFlag(true);
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order, true);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Gamma Wallet: order email for ' . $order->getIncrementId() . ' failed: ' . $e->getMessage());
        }
    }

    /** A note on the order, an admin notification and a log line: something needs the shop's attention. */
    private function alert(Order $order, string $message): void
    {
        $this->logger->error('Gamma Wallet: order ' . $order->getIncrementId() . ': ' . $message);
        try {
            $order->addCommentToStatusHistory($message, false, false);
            $this->orders->save($order);
            $this->notifier->addMajor((string)__('Gamma Wallet: check order %1', $order->getIncrementId()), $message);
        } catch (\Throwable $e) {
            $this->logger->error('Gamma Wallet: could not note this on order ' . $order->getIncrementId() . ': ' . $e->getMessage());
        }
    }
}
