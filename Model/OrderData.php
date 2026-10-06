<?php
namespace Gamma\Wallet\Model;

use Magento\Framework\App\ResourceConnection;

/** What Gamma said about each order (table gamma_wallet_order), one row per order. */
class OrderData
{
    public const COLUMNS = ['bill_id', 'code', 'link', 'qr_url', 'bill_status', 'claimed_on', 'error', 'attempts', 'emailed',
        'credit_request', 'credit_request_id', 'credit_expires_on', 'credit_link', 'credit_qr', 'settled_request_id'];

    public function __construct(private ResourceConnection $resource)
    {
    }

    private function table(): string
    {
        return $this->resource->getTableName('gamma_wallet_order');
    }

    public function get(int $orderId): array
    {
        $connection = $this->resource->getConnection('sales');
        $row = $connection->fetchRow($connection->select()->from($this->table())->where('order_id = ?', $orderId));

        return array_merge(array_fill_keys(self::COLUMNS, null), $row ?: []);
    }

    public function save(int $orderId, array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::COLUMNS));
        if (!$values) {
            return;
        }
        $this->resource->getConnection('sales')->insertOnDuplicate($this->table(), ['order_id' => $orderId] + $values, array_keys($values));
    }

    /**
     * Marks the order settled, once: true only for the caller that made the change, so two status checks
     * arriving together can never settle (and invoice) the same order twice.
     */
    public function claimSettlement(int $orderId, string $requestId): bool
    {
        $connection = $this->resource->getConnection('sales');
        $connection->insertOnDuplicate($this->table(), ['order_id' => $orderId], ['order_id']);

        return $connection->update($this->table(), ['settled_request_id' => $requestId, 'credit_qr' => ''],
            ['order_id = ?' => $orderId, 'settled_request_id IS NULL']) === 1;
    }

    /** Orders whose reward failed and may be tried again by cron. */
    public function retryable(int $maxAttempts): array
    {
        $connection = $this->resource->getConnection('sales');

        return $connection->fetchCol($connection->select()->from($this->table(), 'order_id')
            ->where('bill_id IS NULL')->where('error IS NOT NULL')->where('attempts < ?', $maxAttempts)
            ->where('updated_at > ?', gmdate('Y-m-d H:i:s', time() - 7 * 86400)));
    }
}
