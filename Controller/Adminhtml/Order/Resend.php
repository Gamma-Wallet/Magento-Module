<?php
namespace Gamma\Wallet\Controller\Adminhtml\Order;

use Gamma\Wallet\Model\OrderData;
use Gamma\Wallet\Model\Rewards;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/** "Send the reward QR code to the customer": creates the reward if needed and emails it. */
class Resend extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Gamma_Wallet::order';

    public function __construct(
        Context $context,
        private OrderRepositoryInterface $orders,
        private Rewards $rewards,
        private OrderData $data
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        try {
            $order = $this->orders->get($orderId);
            // A click is a deliberate new try, also after the automatic ones ran out.
            if (!$this->data->get($orderId)['bill_id']) {
                $this->data->save($orderId, ['attempts' => 0]);
            }
            if ($this->rewards->sendRewardEmail($order)) {
                $this->messageManager->addSuccessMessage(__('The reward QR code was emailed to the customer.'));
            } else {
                $this->messageManager->addErrorMessage(__('The reward QR code could not be sent: the order is not eligible for a reward yet, or Gamma could not be reached.'));
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('This order could not be found.'));
        }

        return $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
