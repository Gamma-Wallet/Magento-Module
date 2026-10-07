<?php
namespace Gamma\Wallet\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * bin/magento module:uninstall Gamma_Wallet: removes the settings (the integration token too) and what
 * the module kept in the flag table. The order status and past orders' Gamma data stay, as past orders
 * use them.
 */
class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        $connection = $setup->getConnection();
        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'payment/gammawallet/%']);
        $connection->delete($setup->getTable('flag'), ['flag_code LIKE ?' => 'gamma\\_wallet\\_%']);
        $setup->endSetup();
    }
}
