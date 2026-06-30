<?php

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_core\Service\BrokerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Auto-trade control panels for Nifty and Sensex F&O.
 */
class AutoTradeController extends ControllerBase {

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
   * Constructs an AutoTradeController object.
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
   * Legacy route alias (cached routes may still reference autoTrade).
   */
  public function autoTrade() {
    return $this->niftyAutoTrade();
  }

  /**
   * Nifty auto-trade page.
   */
  public function niftyAutoTrade() {
    return $this->buildPage(
      'nifty',
      $this->t('Nifty Auto Trade'),
      $this->t('Nifty 50 LTP'),
      $this->t('Automated Nifty options — 1 lot (65 qty). Requires OI + EMA + RSI alignment, then buys ATM CE (bullish) or PE (bearish). Min confidence 75%.'),
    );
  }

  /**
   * Sensex auto-trade page.
   */
  public function sensexAutoTrade() {
    return $this->buildPage(
      'sensex',
      $this->t('Sensex Auto Trade'),
      $this->t('Sensex LTP'),
      $this->t('Automated Sensex options — 1 lot (20 qty). Requires OI + EMA + RSI alignment, then buys ATM CE (bullish) or PE (bearish). Min confidence 75%.'),
    );
  }

  /**
   * Build an auto-trade admin page for the given instrument.
   */
  protected function buildPage(
    string $instrument,
    $page_title,
    $index_label,
    $intro,
    bool $fetch_remote_status = TRUE,
  ): array {
    $api_configured = $this->brokerManager->hasApiKey();
    $status = NULL;

    if ($api_configured && $fetch_remote_status) {
      $status = $this->brokerManager->getAutoTradeStatus($instrument);
    }

    $config = $this->configFactory->get('niftybot_core.settings');
    $api_key = $config->get('trading_api_key') ?? '';

    $build = [
      '#theme' => 'niftybot_auto_trade',
      '#instrument' => $instrument,
      '#page_title' => $page_title,
      '#index_label' => $index_label,
      '#intro' => $intro,
      '#api_configured' => $api_configured,
      '#status' => is_array($status) ? $status : [],
      '#status_success' => !empty($status['success']),
      '#is_user' => FALSE,
      '#user_trade_history' => [],
      '#broker_connected' => FALSE,
      '#broker_summary' => [],
      '#attached' => [
        'library' => [
          'niftybot_core/admin',
          'niftybot_core/auto_trade_realtime',
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    if ($api_configured && $api_key !== '') {
      $build['#attached']['drupalSettings']['niftybotAutoTrade'] = [
        'enabled' => TRUE,
        'instrument' => $instrument,
        'isUser' => !empty($build['#is_user']),
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
