<?php
namespace Gamma\Wallet\Model\Api;

use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * The calls to the Gamma Integration API. Every call carries the shop's integration token (GWINT_…)
 * and runs on the shop's server, never in the customer's browser. Gamma answers in an envelope
 * {version, statusCode, message, result, error}; this class returns `result` or throws ApiError.
 */
class Client
{
    public const DEFAULT_URL = 'https://integration.gamma-wallet.com';
    public const VERSION = '1.0.0';

    public function __construct(private CurlFactory $curlFactory, private string $token)
    {
    }

    /** Can be overridden for testing: SetEnv GAMMA_WALLET_API_URL https://… (or a PHP env variable). */
    public static function baseUrl(): string
    {
        $url = getenv('GAMMA_WALLET_API_URL');

        return rtrim($url ?: self::DEFAULT_URL, '/');
    }

    /** Who the token belongs to: business, currency, whether customers can claim, token expiry. */
    public function connection(int $timeout = 20): array
    {
        return $this->send('GET', '/api/Connection/Me', null, $timeout);
    }

    /** Declares a paid order. Safe to repeat with the same reference: Gamma returns the same bill. */
    public function createBill(array $bill): array
    {
        return $this->send('POST', '/api/Bill/Create', $bill);
    }

    public function getBill(string $billId): array
    {
        return $this->send('GET', '/api/Bill/Get/' . rawurlencode($billId));
    }

    /** A new store-credit request for the whole order. */
    public function startCredit(array $order): array
    {
        return $this->send('POST', '/api/Credit/Start', $order);
    }

    /** Waiting, Paid or Expired, with the seconds left. */
    public function checkCredit(string $creditRequest): array
    {
        return $this->send('POST', '/api/Credit/Check', ['creditRequest' => $creditRequest]);
    }

    /**
     * JSON with amounts written exactly: 0.8, never 0.80000000000000004. With serialize_precision = 17,
     * which many servers still set, Gamma would sign a total the customer's app does not match.
     */
    public static function json(array $body): string
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        $json = json_encode($body, JSON_PRESERVE_ZERO_FRACTION);
        ini_set('serialize_precision', (string)$previous);

        return (string)$json;
    }

    private function send(string $method, string $path, ?array $body = null, int $timeout = 20): array
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
        $curl->addHeader('Authorization', 'Bearer ' . $this->token);
        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('User-Agent', 'gamma-wallet-magento/' . self::VERSION);
        try {
            if ($method === 'POST') {
                $curl->addHeader('Content-Type', 'application/json');
                $curl->post(self::baseUrl() . $path, self::json($body ?? []));
            } else {
                $curl->get(self::baseUrl() . $path);
            }
        } catch (\Exception $e) {
            throw new ApiError(0, null, 'Gamma could not be reached: ' . $e->getMessage());
        }
        $status = (int)$curl->getStatus();
        $envelope = json_decode((string)$curl->getBody(), true);
        if ($status >= 200 && $status < 300 && is_array($envelope) && isset($envelope['result']) && is_array($envelope['result'])) {
            return $envelope['result'];
        }
        $error = (is_array($envelope) && isset($envelope['error']) && is_array($envelope['error'])) ? $envelope['error'] : [];

        throw new ApiError($status, $error['identifier'] ?? null, $error['message'] ?? null);
    }
}
