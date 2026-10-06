<?php
namespace Gamma\Wallet\Plugin;

use Gamma\Wallet\Block\Email;
use Magento\Sales\Block\Order\Email\Items;

/**
 * Adds the reward QR code below the items of the order confirmation email. The email's layout directive
 * outputs only the first block of its handle, so a block added through layout would never be shown.
 */
class OrderEmailItems
{
    public function afterToHtml(Items $subject, $result)
    {
        try {
            $order = $subject->getOrder();
            if (!$order) {
                return $result;
            }
            $html = $subject->getLayout()->createBlock(Email::class)->setData('order', $order)->toHtml();

            return $result . $html;
        } catch (\Throwable $e) {
            // Never let the reward break the order email.
            return $result;
        }
    }
}
