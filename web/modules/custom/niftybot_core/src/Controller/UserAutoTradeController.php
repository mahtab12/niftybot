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
    $quantity = $instrument === 'sensex'
      ? $settings['sensex_quantity']
      : $settings['nifty_quantity'];

    $build = parent::buildPage($instrument, $page_title, $index_label, $intro);
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
    $build['#user_trade_history'] = $broker_connection->getUserTradeHistory($uid, $instrument, 15);

    if (is_array($build['#status'])) {
      $build['#status']['trade_history'] = [];
    }

    if ($this->brokerManager->hasApiKey()) {
      $user_status = $this->brokerManager->getUserAutoTradeStatus($uid, $instrument);
      if (is_array($user_status)) {
        $build['#status'] = $user_status;
        $build['#status_success'] = !empty($user_status['success']);
      }
    }

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
      'apiBase' => '/niftybot/api/auto-trade',
      'csrfToken' => \Drupal::csrfToken()->get('rest'),
      'lotStep' => $lot_step,
      'quantity' => $quantity,
      'niftyQuantity' => $settings['nifty_quantity'],
      'sensexQuantity' => $settings['sensex_quantity'],
      'access' => $access,
    ];

    unset($build['#attached']['drupalSettings']['niftybotAutoTrade']['wsUrl']);
    unset($build['#attached']['drupalSettings']['niftybotAutoTrade']['restUrl']);
    unset($build['#attached']['drupalSettings']['niftybotAutoTrade']['apiKey']);

    return $build;
  }

}
