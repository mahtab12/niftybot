<?php

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_core\Service\BrokerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays portfolio holdings and positions from the trading API.
 */
class PortfolioController extends ControllerBase {

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
   * Constructs a PortfolioController object.
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
   * Portfolio page — summary, holdings, and open positions.
   */
  public function portfolio() {
    $broker = 'groww';
    $api_configured = $this->brokerManager->hasApiKey();
    $summary = NULL;

    if ($api_configured) {
      $summary = $this->brokerManager->getPortfolioSummary(0, $broker);
    }

    $summary_data = is_array($summary) ? ($summary['summary'] ?? []) : [];
    $holdings = is_array($summary) ? ($summary['holdings'] ?? []) : [];
    $positions = is_array($summary) ? ($summary['positions'] ?? []) : [];

    $config = $this->configFactory->get('niftybot_core.settings');
    $api_key = $config->get('trading_api_key') ?? '';

    $build = [
      '#theme' => 'niftybot_portfolio',
      '#broker' => $broker,
      '#api_configured' => $api_configured,
      '#summary' => $summary_data,
      '#summary_success' => !empty($summary['success']),
      '#summary_message' => $summary['message'] ?? '',
      '#holdings' => $holdings,
      '#positions' => $positions,
      '#api_response' => is_array($summary) ? $summary : [],
      '#attached' => [
        'library' => [
          'niftybot_core/admin',
          'niftybot_core/portfolio_realtime',
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    if ($api_configured && $api_key !== '') {
      $build['#attached']['drupalSettings']['niftybotPortfolio'] = [
        'enabled' => TRUE,
        'broker' => $broker,
        'wsUrl' => $this->getTradingWsUrl(),
        'apiKey' => $api_key,
      ];
    }

    return $build;
  }

  /**
   * WebSocket base URL reachable from the admin browser.
   */
  protected function getTradingWsUrl(): string {
    $base = $this->configFactory
      ->get('niftybot_core.settings')
      ->get('trading_api_base_url') ?? 'http://trading:8000';

    if (str_contains($base, 'trading:8000')) {
      return 'ws://127.0.0.1:8000';
    }

    return preg_replace('#^http#', 'ws', rtrim($base, '/')) ?? 'ws://127.0.0.1:8000';
  }

}
