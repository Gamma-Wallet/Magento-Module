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

        return $connection->update(
            $this->table(),
            ['settled_request_id' => $requestId, 'credit_qr' => ''],
            ['order_id = ?' => $orderId, 'settled_request_id IS NULL']
        ) === 1;
    }

    /**
     * Reserves the right to ask Gamma for a new store-credit code, for one caller only, so an order never
     * has two live codes (two tabs, a double click, the success page and the order page opened together).
     *
     * $firstOnly: only when the order never had a code (the first time the customer sees it). Otherwise
     * only once the last code expired over 15 seconds ago (Gamma still accepts a code a few seconds past
     * its time). The reservation counts as a live code for 30 seconds. Returns the previous expiry (to
     * give the slot back if Gamma cannot be reached), or null when another request holds it.
     */
    public function claimCodeSlot(int $orderId, bool $firstOnly): ?string
    {
        $connection = $this->resource->getConnection('sales');
        $connection->insertOnDuplicate($this->table(), ['order_id' => $orderId], ['order_id']);
        $previous = (string)$this->get($orderId)['credit_expires_on'];
        $where = ['order_id = ?' => $orderId, 'settled_request_id IS NULL'];
        if ($firstOnly) {
            $where[] = 'credit_request IS NULL';
            $where[] = 'credit_expires_on IS NULL';
        } else {
            $where['(credit_expires_on IS NULL OR credit_expires_on < ?)'] = gmdate('Y-m-d\TH:i:s\Z', time() - 15);
        }
        $claimed = $connection->update($this->table(), ['credit_expires_on' => gmdate('Y-m-d\TH:i:s\Z', time() + 30)], $where);

        return $claimed === 1 ? $previous : null;
    }

    /** Gives a reserved slot back when Gamma could not start the code. */
    public function releaseCodeSlot(int $orderId, string $previous): void
    {
        $this->resource->getConnection('sales')->update(
            $this->table(),
            ['credit_expires_on' => $previous !== '' ? $previous : null],
            ['order_id = ?' => $orderId]
        );
    }

    /** Orders whose reward failed and may be tried again by cron. */
    public function retryable(int $maxAttempts): array
    {
        $connection = $this->resource->getConnection('sales');

        return $connection->fetchCol($connection->select()->from($this->table(), 'order_id')
            ->where('bill_id IS NULL')->where('error IS NOT NULL')->where('attempts < ?', $maxAttempts)
            ->where('updated_at > ?', gmdate('Y-m-d H:i:s', time() - 7 * 86400)));
    }

    /**
     * Store-credit orders not settled yet whose code was shown in the last hours: the customer may have
     * confirmed in the app after leaving the page, so cron asks Gamma about them.
     */
    public function awaitingSettlement(int $hours): array
    {
        $connection = $this->resource->getConnection('sales');

        return $connection->fetchCol($connection->select()->from($this->table(), 'order_id')
            ->where('settled_request_id IS NULL')->where('credit_request IS NOT NULL')
            ->where('updated_at > ?', gmdate('Y-m-d H:i:s', time() - $hours * 3600)));
    }
}
