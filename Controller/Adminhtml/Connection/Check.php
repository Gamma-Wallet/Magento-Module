<?php
namespace Gamma\Wallet\Controller\Adminhtml\Connection;

use Gamma\Wallet\Model\Gamma;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;

/** "Check again" on the settings page. */
class Check extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Gamma_Wallet::config';

    public function __construct(Context $context, private Gamma $gamma)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $this->gamma->checkConnection();
        $this->messageManager->addSuccessMessage(__('Connection checked.'));

        return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit', ['section' => 'payment']);
    }
}
