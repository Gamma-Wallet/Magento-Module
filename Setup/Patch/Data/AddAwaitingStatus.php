<?php
namespace Gamma\Wallet\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;

/**
 * The order status "Awaiting Gamma store credits", in the pending-payment state, visible to the customer.
 * Kept when the module is removed, because past orders use it.
 */
class AddAwaitingStatus implements DataPatchInterface
{
    public const STATUS = 'gamma_awaiting';

    public function __construct(private ModuleDataSetupInterface $setup)
    {
    }

    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $connection->insertOnDuplicate($this->setup->getTable('sales_order_status'),
            ['status' => self::STATUS, 'label' => 'Awaiting Gamma store credits'], ['label']);
        $connection->insertOnDuplicate($this->setup->getTable('sales_order_status_state'),
            ['status' => self::STATUS, 'state' => Order::STATE_PENDING_PAYMENT, 'is_default' => 0, 'visible_on_front' => 1],
            ['visible_on_front']);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
