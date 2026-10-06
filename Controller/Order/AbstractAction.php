<?php
namespace Gamma\Wallet\Controller\Order;

use Gamma\Wallet\Model\Gamma;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Asked by the customer's page every 5 seconds (never Gamma itself). The order is named by id and a
 * key only this shop's server can make, so nobody can look at someone else's order.
 */
abstract class AbstractAction
{
    public function __construct(
        protected RequestInterface $request,
        protected JsonFactory $jsonFactory,
        protected OrderRepositoryInterface $orders,
        protected Gamma $gamma
    ) {
    }

    protected function order(): ?Order
    {
        $orderId = (int)$this->request->getParam('order_id');
        $key = (string)$this->request->getParam('key');
        if (!$orderId || $key === '' || !hash_equals($this->gamma->orderKey($orderId), $key)) {
            return null;
        }
        try {
            $order = $this->orders->get($orderId);
        } catch (\Exception $e) {
            return null;
        }

        return $order instanceof Order ? $order : null;
    }

    protected function reply(array $data, int $status = 200): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Cache-Control', 'no-store', true);

        return $result->setData($data);
    }
}
