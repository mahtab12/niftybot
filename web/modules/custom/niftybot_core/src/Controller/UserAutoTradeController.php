<?php

namespace Drupal\niftybot_core\Controller;

use Drupal\niftybot_core\Service\AutoTradeUserService;

/**
 * User-facing auto-trade pages.
 */
class UserAutoTradeController extends AutoTradeController {

  public function __construct(
    $broker_manager,
    $config_factory,
    protected AutoTradeUserService $autoTradeUser,
  ) {
    parent::__construct($broker_manager, $config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create($container) {
    return new static(
      $container->get('niftybot_core.broker_manager'),
      $container->get('config.factory'),
      $container->get('niftybot_core.auto_trade_user'),
    );
  }

  /**
   * Auto-trade overview with instructions.
   */
  public function overview() {
    return [
      '#theme' => 'niftybot_auto_trade_overview',
      '#attached' => [
        'library' => ['niftybot_core/auto_trade_user'],
      ],
      '#cache' => [
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * Nifty auto-trade for traders.
   */
  public function niftyAutoTrade() {
    return $this->buildUserPage(
      'nifty',
      $this->t('Nifty Auto Trade'),
      $this->t('Nifty 50 LTP'),
      $this->t('Automated Nifty options via our AI algo engine. Set your quantity (multiples of 65). Subscribe monthly or pay ₹100 per executed trade from your wallet.'),
    );
  }

  /**
   * Sensex auto-trade for traders.
   */
  public function sensexAutoTrade() {
    return $this->buildUserPage(
      'sensex',
      $this->t('Sensex Auto Trade'),
      $this->t('Sensex LTP'),
      $this->t('Automated Sensex options via our AI algo engine. Set your quantity (multiples of 20). Subscribe monthly or pay ₹100 per executed trade from your wallet.'),
    );
  }

  /**
   * Crude oil MCX auto-trade for traders.
   */
  public function crudeOilAutoTrade() {
    return $this->buildUserPage(
      'crude_oil',
      $this->t('Crude Oil Auto Trade'),
      $this->t('Crude Oil Mini LTP'),
      $this->t('Automated MCX crude oil mini futures via our AI algo engine. Set your quantity (multiples of 10). Subscribe monthly or pay ₹100 per executed trade from your wallet.'),
    );
  }

  /**
   * Gold MCX auto-trade for traders.
   */
  public function goldAutoTrade() {
    return $this->buildUserPage(
      'gold',
      $this->t('Gold Auto Trade'),
      $this->t('Gold Mini LTP'),
      $this->t('Automated MCX gold mini futures via our AI algo engine. Set your quantity (multiples of 100). Subscribe monthly or pay ₹100 per executed trade from your wallet.'),
    );
  }

  /**
   * Builds a user auto-trade page with broker gate messaging.
   */
  protected function buildUserPage(
    string $instrument,
    $page_title,
    $index_label,
    $intro,
  ): array {
    $uid = (int) $this->currentUser()->id();
    $lot_step = $this->autoTradeUser->lotStep($instrument);
    $settings = $this->autoTradeUser->getSettings($uid);
    $access = $this->autoTradeUser->getAccessInfo($uid);
    $quantity = $this->autoTradeUser->getQuantityForInstrument($uid, $instrument);

    $build = parent::buildPage($instrument, $page_title, $index_label, $intro, FALSE);
    $build['#theme'] = 'niftybot_auto_trade_user';
    $build['#lot_size'] = $lot_step;
    $build['#lot_step'] = $lot_step;
    $build['#trade_quantity'] = $quantity;
    $build['#auto_trade_access'] = $access;
    $build['#auto_trade_fee_history'] = $this->autoTradeUser->getFeeHistory($uid, $instrument, 15);
    $build['#auto_trade_fee_summary'] = $this->autoTradeUser->getFeeSummary($uid, $instrument);
    $build['#attached']['library'][] = 'niftybot_core/auto_trade_user';
    $build['#cache']['contexts'] = ['user'];

    /** @var \Drupal\niftybot_core\Service\BrokerConnectionService $broker_connection */
    $broker_connection = \Drupal::service('niftybot_core.broker_connection');
    $summary = $broker_connection->getDashboardSummary($uid, 'groww');
    $build['#broker_connected'] = !empty($summary['connected']);
    $build['#broker_summary'] = $summary;
    $build['#is_user'] = TRUE;
    $build['#groww_trading_supported'] = $this->autoTradeUser->isGrowwTradingSupported($instrument);
    $build['#groww_unsupported_message'] = AutoTradeUserService::GROWW_MCX_UNSUPPORTED_MESSAGE;
    $market = $this->autoTradeUser->isIndexInstrument($instrument)
      ? $this->autoTradeUser->getIndexMarketStatus()
      : ['market_open' => TRUE, 'market_status' => 'open', 'market_message' => ''];
    $build['#market_open'] = $market['market_open'];
    $build['#market_closed_message'] = $market['market_message'];
    // Loaded asynchronously by the browser — avoids blocking page render on Groww.
    $build['#user_trade_history'] = [];

    if (is_array($build['#status'])) {
      $build['#status']['trade_history'] = [];
    }

    // Live status and AI suggestions load via JS polling (/niftybot/api/auto-trade/.../status).

    if (isset($build['#attached']['library'])) {
      $build['#attached']['library'] = array_values(array_diff(
        $build['#attached']['library'],
        ['niftybot_core/admin']
      ));
    }

    $build['#attached']['drupalSettings']['niftybotAutoTrade'] = [
      'enabled' => TRUE,
      'instrument' => $instrument,
      'isUser' => TRUE,
      'useDrupalApi' => TRUE,
      'brokerConnected' => !empty($summary['connected']),
      'apiBase' => '/niftybot/api/auto-trade',
      'csrfToken' => \Drupal::csrfToken()->get('rest'),
      'lotStep' => $lot_step,
      'quantity' => $quantity,
      'quantities' => $settings,
      'access' => $access,
      'growwTradingSupported' => $this->autoTradeUser->isGrowwTradingSupported($instrument),
      'growwUnsupportedMessage' => AutoTradeUserService::GROWW_MCX_UNSUPPORTED_MESSAGE,
      'isIndexInstrument' => $this->autoTradeUser->isIndexInstrument($instrument),
      'marketOpen' => $market['market_open'],
      'marketClosedMessage' => $market['market_message'],
    ];

    unset($build['#attached']['drupalSettings']['niftybotAutoTrade']['wsUrl']);
    unset($build['#attached']['drupalSettings']['niftybotAutoTrade']['restUrl']);
    unset($build['#attached']['drupalSettings']['niftybotAutoTrade']['apiKey']);

    return $build;
  }

}
