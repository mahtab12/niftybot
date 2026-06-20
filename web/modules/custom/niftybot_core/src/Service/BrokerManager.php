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
      ->get('trading_api_base_url') ?? 'http://localhost:8000';
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
      $options['json'] = $data;
    }

    try {
      $response = $this->httpClient->request($method, $url, $options);
      $body = $response->getBody()->getContents();
      return json_decode($body, TRUE);
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

}
