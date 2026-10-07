<?php
namespace Gamma\Wallet\Model;

use Gamma\Wallet\Model\Api\ApiError;
use Gamma\Wallet\Model\Api\Client;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\FlagManager;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;

/**
 * The module's settings (payment/gammawallet/*, saved by the shop owner) and what it keeps for itself
 * in Magento's flag table: the installation secret and the cached connection check. Flags are read
 * from the database, so they never wait for a cache refresh.
 */
class Gamma
{
    public const METHOD = 'gammawallet';
    /** Paid outside the shop, after the order is placed: the business creates the invoice later. */
    public const PAY_LATER = ['checkmo', 'banktransfer', 'cashondelivery', 'purchaseorder'];
    public const STALE_SECONDS = 3600;
    private const PATH = 'payment/gammawallet/';
    private const FLAG_SECRET = 'gamma_wallet_secret';
    private const FLAG_CONNECTION = 'gamma_wallet_connection';
    /** When the module was installed (Unix time): orders placed before it never earn a reward. */
    public const FLAG_INSTALLED_ON = 'gamma_wallet_installed_on';

    private ?array $connection = null;

    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private FlagManager $flagManager,
        private CurlFactory $curlFactory,
        private DeploymentConfig $deploymentConfig
    ) {
    }

    public function get(string $key, $default = null)
    {
        $value = $this->scopeConfig->getValue(self::PATH . $key, ScopeInterface::SCOPE_STORE);

        return $value === null ? $default : $value;
    }

    /** Stored encrypted; Magento decrypts it on reading, because config.xml marks the field as encrypted. */
    public function token(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::PATH . 'token'));
    }

    public function api(): ?Client
    {
        $token = $this->token();

        return $token === '' ? null : new Client($this->curlFactory, $token, $this->apiUrl());
    }

    /** The Gamma Integration API. For testing, app/etc/env.php can name another: 'gamma_wallet' => ['api_url' => 'https://…']. */
    private function apiUrl(): string
    {
        $url = (string)$this->deploymentConfig->get('gamma_wallet/api_url');

        return rtrim($url !== '' ? $url : Client::DEFAULT_URL, '/');
    }

    /** When the module was installed, or 0 when unknown. */
    public function installedOn(): int
    {
        return (int)$this->flagManager->getFlagData(self::FLAG_INSTALLED_ON);
    }

    public function creditsEnabled(): bool
    {
        return (bool)$this->get('active', 0);
    }

    public function rewardsEnabled(): bool
    {
        return (bool)$this->get('rewards', 0);
    }

    public function rewardInEmail(): bool
    {
        return (bool)$this->get('reward_email', 0);
    }

    public function awaitingStatus(): string
    {
        return (string)$this->get('order_status', 'gamma_awaiting');
    }

    public static function methodCode(Order $order): string
    {
        return (string)($order->getPayment() ? $order->getPayment()->getMethod() : '');
    }

    public static function isPayLater(string $code): bool
    {
        return in_array($code, self::PAY_LATER, true);
    }

    /** Payment methods ticked as "no reward" in the settings earn none. Pay-later ones are ticked by default. */
    public function methodEarnsReward(string $code): bool
    {
        if ($code === '' || $code === self::METHOD || $code === 'free') {
            return false;
        }
        $excluded = array_filter(explode(',', (string)$this->get('no_reward_methods', '')));

        return !in_array($code, $excluded, true);
    }

    // ---------------------------------------------------------------- data the module keeps

    private function secret(): string
    {
        $secret = (string)$this->flagManager->getFlagData(self::FLAG_SECRET);
        if ($secret === '') {
            $secret = bin2hex(random_bytes(24));
            $this->flagManager->saveFlag(self::FLAG_SECRET, $secret);
        }

        return $secret;
    }

    /** A key only this shop's server can make, for the customer's order status links. */
    public function orderKey(int $orderId): string
    {
        return substr(hash_hmac('sha256', 'order:' . $orderId, $this->secret()), 0, 32);
    }

    /**
     * The reference Gamma knows an order by: "MG-3f9a1c-000000042". Order numbers start again in every
     * Magento installation, so a business with two shops (or a reinstalled one) would reuse them; the
     * short tag, fixed for this installation, keeps them apart.
     */
    public function reference(Order $order): string
    {
        return 'MG-' . substr(hash('sha256', 'reference:' . $this->secret()), 0, 6) . '-' . $order->getIncrementId();
    }

    // ---------------------------------------------------------------- the connection, cached

    public function connection(): ?array
    {
        if ($this->connection === null) {
            $value = $this->flagManager->getFlagData(self::FLAG_CONNECTION);
            $this->connection = is_array($value) ? $value : [];
        }

        return $this->connection ?: null;
    }

    private function saveConnection(array $connection): void
    {
        $this->connection = $connection;
        $this->flagManager->saveFlag(self::FLAG_CONNECTION, $connection);
    }

    public function checkConnection(int $timeout = 20): ?array
    {
        $api = $this->api();
        if (!$api) {
            $this->saveConnection([]);

            return null;
        }
        try {
            $connection = $api->connection($timeout);
            $connection['checkedOn'] = time();
        } catch (ApiError $e) {
            $connection = ['error' => self::explain($e), 'checkedOn' => time()];
            // Gamma briefly out of reach: keep what it said last time, so the shop keeps working.
            $previous = $this->connection();
            if ($e->isRetryable() && $previous && isset($previous['canClaim'])) {
                $connection['canClaim'] = $previous['canClaim'];
                if (isset($previous['currencyCode'])) {
                    $connection['currencyCode'] = $previous['currencyCode'];
                }
            }
        }
        $this->saveConnection($connection);

        return $connection;
    }

    public function refreshIfStale(int $timeout = 5): void
    {
        if ($this->token() === '') {
            return;
        }
        $connection = $this->connection();
        if (!$connection || (int)($connection['checkedOn'] ?? 0) < time() - self::STALE_SECONDS) {
            $this->checkConnection($timeout);
        }
    }

    /**
     * True while the business's active Gamma service is a Reward service. The module does nothing for
     * customers otherwise. Uses the last check, which the hourly cron keeps fresh; only when there has
     * never been one is Gamma asked here.
     */
    public function rewardServiceActive(): bool
    {
        if ($this->token() === '') {
            return false;
        }
        if (!$this->connection()) {
            $this->checkConnection(3);
        }

        return !empty($this->connection()['canClaim']);
    }

    /** True when the currency is the business's, or when that cannot be known yet. */
    public function currencyMatches(string $code): bool
    {
        $business = $this->connection()['currencyCode'] ?? null;

        return !$business || strtoupper($business) === strtoupper($code);
    }

    public static function explain(ApiError $e): string
    {
        switch ($e->identifier) {
            case '0388':
                return (string)__('Gamma does not recognise this token. Copy it again from Gamma Business → Integrations.');
            case '0389':
                return (string)__('This token was disabled or replaced. Create a new one in Gamma Business → Integrations.');
            case '0390':
                return (string)__('This token has expired. Create a new one in Gamma Business → Integrations.');
            case '0393':
                return (string)__('The business this token belongs to is not available in Gamma.');
        }
        if ($e->status === 0) {
            return (string)__('Gamma could not be reached. Check that this server can make outgoing HTTPS connections.');
        }
        if ($e->status === 429) {
            return (string)__('Too many requests to Gamma. Try again in a minute.');
        }

        return $e->getMessage();
    }

    /** "GWINT_Ab12Cd3…x9Yz": enough to recognise a token, never enough to use it. */
    public static function masked(string $token): string
    {
        return strlen($token) > 17 ? substr($token, 0, 13) . '…' . substr($token, -4) : '…';
    }
}
