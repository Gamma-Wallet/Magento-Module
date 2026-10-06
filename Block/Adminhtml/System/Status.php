<?php
namespace Gamma\Wallet\Block\Adminhtml\System;

use Gamma\Wallet\Model\Gamma;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;

/** The "Status" line of the settings page: the connection as Gamma last described it, and "Check again". */
class Status extends Field
{
    public function __construct(
        Context $context,
        private Gamma $gamma,
        private StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _renderScopeLabel(AbstractElement $element)
    {
        return '';
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $e = $this->_escaper;
        $token = $this->gamma->token();
        if ($token === '') {
            return '<p>' . $e->escapeHtml(__('Not connected. Paste your integration token above and save.')) . '</p>';
        }
        $connection = $this->gamma->connection();
        $lines = [];
        if (!$connection) {
            $lines[] = '<p>' . $e->escapeHtml(__('Not checked yet.')) . '</p>';
        } elseif (!empty($connection['error'])) {
            $lines[] = '<p style="color:#e22626"><strong>' . $e->escapeHtml($connection['error']) . '</strong></p>';
        } else {
            $lines[] = '<p style="color:#185b00"><strong>&#10003; ' . $e->escapeHtml(__('Connected')) . '</strong></p>';
            $rows = [
                [__('Business'), $connection['businessName'] ?? ''],
                [__('Currency'), $connection['currencyCode'] ?? ''],
                [__('Token'), Gamma::masked($token)],
            ];
            if (isset($connection['token']['daysLeft'])) {
                $rows[] = [__('Expires'), __('%1 day(s) left', max(0, (int)$connection['token']['daysLeft']))];
            }
            foreach ($rows as [$label, $value]) {
                $lines[] = '<p style="margin:2px 0"><span style="color:#666">' . $e->escapeHtml($label) . ':</span> <strong>' . $e->escapeHtml((string)$value) . '</strong></p>';
            }
            if (empty($connection['canClaim'])) {
                $lines[] = '<p style="color:#e22626">' . $e->escapeHtml(__('Gamma Wallet for Magento works only with a Reward service. Your business has no Reward service active in Gamma, so customers get no reward QR code and store credits are not offered at checkout. Activate a Reward service in Gamma Business.')) . '</p>';
            }
            $shopCurrency = (string)$this->storeManager->getStore()->getBaseCurrencyCode();
            if (!empty($connection['currencyCode']) && strcasecmp($connection['currencyCode'], $shopCurrency) !== 0) {
                $lines[] = '<p style="color:#e22626">' . $e->escapeHtml(__('Your shop sells in %1 but your Gamma business uses %2. Orders cannot be sent to Gamma until they match.', $shopCurrency, $connection['currencyCode'])) . '</p>';
            }
        }
        if (!empty($connection['checkedOn'])) {
            $lines[] = '<p style="color:#666;font-size:12px;margin:4px 0">' . $e->escapeHtml(__('Checked')) . ' ' . $e->escapeHtml($this->_localeDate->formatDateTime(
                (new \DateTime('@' . (int)$connection['checkedOn'])), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT)) . '</p>';
        }
        $lines[] = '<a class="action-default" style="display:inline-block;margin-top:6px" href="' . $e->escapeUrl($this->getUrl('gammawallet/connection/check')) . '">' . $e->escapeHtml(__('Check again')) . '</a>';

        return implode("\n", $lines);
    }
}
