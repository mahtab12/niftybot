<?php

namespace Drupal\niftybot_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Manages communication with the Python trading API service.
 */
class BrokerManager {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a BrokerManager object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    ClientInterface $http_client,
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('niftybot');
    $this->httpClient = $http_client;
  }

  /**
   * Get the trading API base URL.
   */
  protected function getBaseUrl(): string {
    return $this->configFactory
      ->get('niftybot_core.settings')
      ->get('trading_api_base_url') ?? 'http://trading:8000';
  }

  /**
   * Whether a trading API key is configured.
   */
  public function hasApiKey(): bool {
    $api_key = $this->configFactory
      ->get('niftybot_core.settings')
      ->get('trading_api_key');
    return is_string($api_key) && $api_key !== '';
  }

  /**
   * Send a request to the trading API.
   *
   * @param string $method
   *   HTTP method.
   * @param string $endpoint
   *   API endpoint path.
   * @param array $data
   *   Request data.
   *
   * @return array|null
   *   Response data or NULL on failure.
   */
  public function request(string $method, string $endpoint, array $data = []): ?array {
    $url = rtrim($this->getBaseUrl(), '/') . '/' . ltrim($endpoint, '/');
    $api_key = $this->configFactory
      ->get('niftybot_core.settings')
      ->get('trading_api_key');

    $options = [
      'headers' => [
        'Content-Type' => 'application/json',
        'X-API-Key' => $api_key,
      ],
      'timeout' => 30,
    ];

    if (!empty($data)) {
      if (strtoupper($method) === 'GET') {
        $options['query'] = $data;
      }
      else {
        $options['json'] = $data;
      }
    }

    try {
      $response = $this->httpClient->request($method, $url, $options);
      $body = $response->getBody()->getContents();
      $decoded = json_decode($body, TRUE);
      return is_array($decoded) ? $decoded : NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Trading API request failed: @endpoint - @message', [
        '@endpoint' => $endpoint,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Place an order via the trading API.
   */
  public function placeOrder(array $order_data): ?array {
    return $this->request('POST', '/api/v1/orders', $order_data);
  }

  /**
   * Cancel an order.
   */
  public function cancelOrder(string $order_id, string $broker): ?array {
    return $this->request('DELETE', "/api/v1/orders/{$order_id}", [
      'broker' => $broker,
    ]);
  }

  /**
   * Get portfolio holdings from the trading API.
   */
  public function getHoldings(int $user_id, string $broker): ?array {
    return $this->request('GET', "/api/v1/holdings/{$user_id}", [
      'broker' => $broker,
    ]);
  }

  /**
   * Get positions from the trading API.
   */
  public function getPositions(int $user_id, string $broker): ?array {
    return $this->request('GET', "/api/v1/positions/{$user_id}", [
      'broker' => $broker,
    ]);
  }

  /**
   * Get order status.
   */
  public function getOrderStatus(string $order_id, string $broker): ?array {
    return $this->request('GET', "/api/v1/orders/{$order_id}/status", [
      'broker' => $broker,
    ]);
  }

  /**
   * Get portfolio summary with P&L, day return, and balances.
   */
  public function getPortfolioSummary(int $user_id, string $broker = 'groww'): ?array {
    return $this->request('GET', "/api/v1/portfolio/summary/{$user_id}", [
      'broker' => $broker,
    ]);
  }

  /**
   * Get broker connection health from the trading API.
   */
  public function getBrokerHealth(string $broker = 'groww'): ?array {
    return $this->request('GET', "/health/broker/{$broker}");
  }

  /**
   * Get the broker user profile (vendor user ID, UCC, segments).
   */
  public function getUserProfile(string $broker = 'groww'): ?array {
    return $this->request('GET', "/api/v1/user/profile/{$broker}");
  }

  /**
   * Get live market dashboard quotes (Nifty, Sensex, commodities).
   */
  public function getMarketDashboard(string $broker = 'groww'): ?array {
    return $this->request('GET', '/api/v1/market/dashboard', [
      'broker' => $broker,
    ]);
  }

  /**
   * Get option expiry dates for an underlying index.
   */
  public function getExpiries(string $underlying, string $exchange, string $broker = 'groww'): ?array {
    return $this->request('GET', '/api/v1/market/expiries', [
      'underlying' => $underlying,
      'exchange' => $exchange,
      'broker' => $broker,
    ]);
  }

  /**
   * Get full option chain for an underlying and expiry.
   */
  public function getOptionChain(
    string $underlying,
    string $expiry_date,
    string $exchange,
    string $broker = 'groww',
  ): ?array {
    return $this->request('GET', '/api/v1/market/option-chain', [
      'underlying' => $underlying,
      'expiry_date' => $expiry_date,
      'exchange' => $exchange,
      'broker' => $broker,
    ]);
  }

  /**
   * Get auto-trade engine status for an instrument (nifty or sensex).
   */
  public function getAutoTradeStatus(string $instrument = 'nifty'): ?array {
    $instrument = strtolower($instrument);
    return $this->request('GET', "/api/v1/auto-trade/{$instrument}/status");
  }

  /**
   * Activate auto-trade for an instrument.
   */
  public function activateAutoTrade(string $instrument = 'nifty', string $mode = 'buy'): ?array {
    $instrument = strtolower($instrument);
    $mode = strtolower($mode);
    if (!in_array($mode, ['buy', 'sell'], TRUE)) {
      $mode = 'buy';
    }
    return $this->request('POST', "/api/v1/auto-trade/{$instrument}/activate", [
      'mode' => $mode,
    ]);
  }

  /**
   * Deactivate auto-trade for an instrument.
   */
  public function deactivateAutoTrade(string $instrument = 'nifty'): ?array {
    $instrument = strtolower($instrument);
    return $this->request('POST', "/api/v1/auto-trade/{$instrument}/deactivate");
  }

  /**
   * Manually exit the open auto-trade position for an instrument.
   */
  public function exitAutoTradePosition(string $instrument = 'nifty'): ?array {
    $instrument = strtolower($instrument);
    return $this->request('POST', "/api/v1/auto-trade/{$instrument}/exit");
  }

  /**
   * List smart orders (GTT / OCO).
   */
  public function listSmartOrders(
    string $smart_order_type,
    string $segment = 'FNO',
    string $broker = 'groww',
    ?string $status = 'ACTIVE',
  ): ?array {
    $query = [
      'smart_order_type' => $smart_order_type,
      'segment' => $segment,
      'broker' => $broker,
      'page_size' => 25,
    ];
    if ($status !== NULL && $status !== '') {
      $query['status'] = $status;
    }
    return $this->request('GET', '/api/v1/smart-orders', $query);
  }

  /**
   * Cancel a smart order.
   */
  public function cancelSmartOrder(
    string $smart_order_id,
    string $smart_order_type,
    string $segment = 'FNO',
    string $broker = 'groww',
  ): ?array {
    return $this->request('DELETE', "/api/v1/smart-orders/{$smart_order_id}", [
      'broker' => $broker,
      'smart_order_type' => $smart_order_type,
      'segment' => $segment,
    ]);
  }

  /**
   * Verify broker API credentials and return profile + margin.
   */
  public function verifyBrokerCredentials(
    string $broker,
    string $api_key,
    string $api_secret,
  ): ?array {
    return $this->request('POST', '/api/v1/broker/credentials/verify', [
      'broker' => $broker,
      'api_key' => $api_key,
      'api_secret' => $api_secret,
    ]);
  }

  /**
   * Fetches order book for user-supplied Groww credentials.
   */
  public function getUserOrderBook(
    string $broker,
    string $api_key,
    string $api_secret,
    string $instrument = '',
    int $limit = 20,
  ): ?array {
    return $this->request('POST', '/api/v1/broker/credentials/order-book', [
      'broker' => $broker,
      'api_key' => $api_key,
      'api_secret' => $api_secret,
      'instrument' => $instrument,
      'limit' => $limit,
    ]);
  }

  /**
   * Get available brokers.
   */
  public function getAvailableBrokers(): array {
    return [
      'groww' => 'Groww',
      'zerodha' => 'Zerodha',
      'angel' => 'Angel One',
      'upstox' => 'Upstox',
      'fyers' => 'Fyers',
    ];
  }

  /**
   * Activate per-user auto-trade on the trading service.
   */
  public function activateUserAutoTrade(int $uid, string $instrument, array $payload): ?array {
    $instrument = strtolower($instrument);
    return $this->request('POST', "/api/v1/user-auto-trade/{$uid}/{$instrument}/activate", $payload);
  }

  /**
   * Deactivate per-user auto-trade.
   */
  public function deactivateUserAutoTrade(int $uid, string $instrument): ?array {
    $instrument = strtolower($instrument);
    return $this->request('POST', "/api/v1/user-auto-trade/{$uid}/{$instrument}/deactivate");
  }

  /**
   * Manual exit for per-user auto-trade.
   */
  public function exitUserAutoTrade(int $uid, string $instrument): ?array {
    $instrument = strtolower($instrument);
    return $this->request('POST', "/api/v1/user-auto-trade/{$uid}/{$instrument}/exit");
  }

  /**
   * Status for per-user auto-trade engine.
   */
  public function getUserAutoTradeStatus(int $uid, string $instrument): ?array {
    $instrument = strtolower($instrument);
    return $this->request('GET', "/api/v1/user-auto-trade/{$uid}/{$instrument}/status");
  }

}
