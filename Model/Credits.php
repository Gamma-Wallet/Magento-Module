<?php
namespace Gamma\Wallet\Model;

use Gamma\Wallet\Model\Api\ApiError;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

/** "Use Store Credits with Gamma": the request shown as a QR code, its status, and settling the order. */
class Credits
{
    public function __construct(
        private Gamma $gamma,
        private OrderData $data,
        private InvoiceService $invoiceService,
        private TransactionFactory $transactionFactory,
        private OrderSender $orderSender,
        private LoggerInterface $logger
    ) {
    }

    /** Asks Gamma for a new store-credit request for the whole order, and keeps it. */
    public function startRequest(Order $order): array
    {
        $api = $this->gamma->api();
        if (!$api) {
            throw new ApiError(401, '0392', 'IntegrationTokenMissing');
        }
        $request = $api->startCredit([
            'reference'    => $this->gamma->reference($order),
            'total'        => round((float)$order->getGrandTotal(), 2),
            'currencyCode' => $order->getOrderCurrencyCode(),
        ]);
        $this->data->save((int)$order->getId(), [
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
     * Records a settled request once: the order gets its invoice (no money changes hands, so it is
     * captured offline), moves to Processing with a note, and the order confirmation email goes out.
     */
    public function markSettled(Order $order, array $checked): void
    {
        $requestId = (string)($checked['requestId'] ?? '') ?: 'settled';
        if (!$this->data->claimSettlement((int)$order->getId(), $requestId)) {
            return;
        }
        $note = (string)__("Settled with the customer's store credits through Gamma Wallet (request %1).", $requestId);
        try {
            if ($order->canInvoice()) {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
                $invoice->register();
                // Magento moves only a "new" order to Processing by itself; this one waited in pending payment.
                $order->setState(Order::STATE_PROCESSING)
                    ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
                $order->addCommentToStatusHistory($note, false, true);
                $this->transactionFactory->create()->addObject($invoice)->addObject($order)->save();
            } else {
                $order->addCommentToStatusHistory($note, false, true);
                $order->save();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Gamma Wallet: settling order ' . $order->getIncrementId() . ' failed: ' . $e->getMessage());
            $order->addCommentToStatusHistory($note . ' ' . __('The invoice could not be created: please create it by hand.'), false, false);
            $order->save();
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
}
