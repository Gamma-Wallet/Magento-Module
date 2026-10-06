<?php
namespace Gamma\Wallet\Model\Config\Source;

use Magento\Sales\Model\Config\Source\Order\Status;
use Magento\Sales\Model\Order;

/** The order statuses of the pending-payment state, for "Status while waiting for the credits". */
class PendingPaymentStatus extends Status
{
    protected $_stateStatuses = Order::STATE_PENDING_PAYMENT;
}
