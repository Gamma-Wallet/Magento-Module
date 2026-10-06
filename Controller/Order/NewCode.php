<?php
namespace Gamma\Wallet\Controller\Order;

use Gamma\Wallet\Model\Api\ApiError;
use Gamma\Wallet\Model\Credits;
use Gamma\Wallet\Model\Gamma;
use Gamma\Wallet\Model\OrderData;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * POST gammawallet/order/newCode: a new store-credit code. Asks Gamma about the current one first, which
 * may have just been settled. The per-order key stands in for Magento's form key, so the page needs no
 * session (it also works for guests and on the order page opened later).
 */
class NewCode extends AbstractAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        OrderRepositoryInterface $orders,
        Gamma $gamma,
        private Credits $credits,
        private OrderData $data,
        private LoggerInterface $logger
    ) {
        parent::__construct($request, $jsonFactory, $orders, $gamma);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $order = $this->order();
        if (!$order || Gamma::methodCode($order) !== Gamma::METHOD) {
            return $this->reply(['error' => 'not_found'], 404);
        }
        try {
            $current = $this->credits->status($order);
        } catch (ApiError $e) {
            return $this->reply(['error' => 'unavailable'], 503);
        }
        if ($current['status'] === 'Paid') {
            return $this->reply(['status' => 'Paid']);
        }
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
            return $this->reply(['error' => 'not_payable'], 409);
        }
        // Never two live codes for one order, or the customer could settle it twice. Gamma still accepts
        // a code a few seconds past its time (clock differences), so wait those out too.
        $row = $this->data->get((int)$order->getId());
        $expires = $row['credit_expires_on'] ? strtotime($row['credit_expires_on']) : 0;
        if ($expires && time() < $expires + 15) {
            return $this->reply(['error' => 'still_valid'], 409);
        }
        try {
            $started = $this->credits->startRequest($order);
        } catch (ApiError $e) {
            $this->logger->warning('Gamma Wallet: new store-credit code for order ' . $order->getIncrementId() . ' failed: ' . $e->getMessage());

            return $this->reply(['error' => 'unavailable'], 503);
        }

        return $this->reply([
            'status' => 'Waiting', 'secondsLeft' => (int)$started['secondsLeft'], 'link' => $started['link'],
            'qr' => 'data:image/png;base64,' . ($started['qrPngBase64'] ?? ''),
        ]);
    }
}
