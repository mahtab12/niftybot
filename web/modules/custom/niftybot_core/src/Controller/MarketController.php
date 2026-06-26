<?php

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_core\Service\BrokerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Live market watch — indices and commodities with option chain drill-down.
 */
class MarketController extends ControllerBase {

  /**
   * The broker manager.
   *
   * @var \Drupal\niftybot_core\Service\BrokerManager
   */
  protected $brokerManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a MarketController object.
   */
  public function __construct(
    BrokerManager $broker_manager,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->brokerManager = $broker_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_core.broker_manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * Market dashboard page.
   */
  public function market() {
    $broker = 'groww';
    $api_configured = $this->brokerManager->hasApiKey();
    $dashboard = NULL;

    if ($api_configured) {
      $dashboard = $this->brokerManager->getMarketDashboard($broker);
    }

    $items = is_array($dashboard) ? ($dashboard['items'] ?? []) : [];
    $config = $this->configFactory->get('niftybot_core.settings');
    $api_key = $config->get('trading_api_key') ?? '';

    $build = [
      '#theme' => 'niftybot_market',
      '#broker' => $broker,
      '#api_configured' => $api_configured,
      '#items' => $items,
      '#dashboard_success' => !empty($dashboard['success']),
      '#dashboard_message' => $dashboard['message'] ?? '',
      '#attached' => [
        'library' => [
          'niftybot_core/admin',
          'niftybot_core/market_realtime',
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    if ($api_configured && $api_key !== '') {
      $build['#attached']['drupalSettings']['niftybotMarket'] = [
        'enabled' => TRUE,
        'broker' => $broker,
        'wsUrl' => $this->getTradingWsUrl(),
        'restUrl' => $this->getTradingRestUrl(),
        'apiKey' => $api_key,
      ];
    }

    return $build;
  }

  /**
   * REST base URL reachable from the admin browser.
   */
  protected function getTradingRestUrl(): string {
    $base = $this->configFactory
      ->get('niftybot_core.settings')
      ->get('trading_api_base_url') ?? 'http://trading:8000';

    if (str_contains($base, 'trading:8000')) {
      return 'http://127.0.0.1:8000';
    }

    return rtrim($base, '/');
  }

  /**
   * WebSocket base URL reachable from the admin browser.
   */
  protected function getTradingWsUrl(): string {
    $base = $this->getTradingRestUrl();
    return preg_replace('#^http#', 'ws', $base) ?? 'ws://127.0.0.1:8000';
  }

}
