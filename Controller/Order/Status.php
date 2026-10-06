<?php
namespace Gamma\Wallet\Controller\Order;

use Gamma\Wallet\Model\Api\ApiError;
use Gamma\Wallet\Model\Credits;
use Gamma\Wallet\Model\Gamma;
use Gamma\Wallet\Model\Rewards;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;

/** GET gammawallet/order/status: Claimed / Waiting for a reward; Paid / Waiting / Expired for store credits. */
class Status extends AbstractAction implements HttpGetActionInterface
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        OrderRepositoryInterface $orders,
        Gamma $gamma,
        private Credits $credits,
        private Rewards $rewards
    ) {
        parent::__construct($request, $jsonFactory, $orders, $gamma);
    }

    public function execute()
    {
        $order = $this->order();
        if (!$order) {
            return $this->reply(['error' => 'not_found'], 404);
        }
        if (Gamma::methodCode($order) === Gamma::METHOD) {
            try {
                $status = $this->credits->status($order);
            } catch (ApiError $e) {
                return $this->reply(['kind' => 'credit', 'status' => 'Unknown'], 503);
            }

            return $this->reply(['kind' => 'credit'] + $status);
        }

        return $this->reply(['kind' => 'reward', 'status' => $this->rewards->refreshStatus($order)]);
    }
}
