<?php

namespace Drupal\niftybot_trading\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Trade history controller.
 */
class TradeHistoryController extends ControllerBase {

  protected Connection $database;

  /**
   * Constructs the controller.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Display trade history.
   */
  public function history() {
    $uid = $this->currentUser()->id();

    $trades = $this->database->select('niftybot_orders', 'o')
      ->fields('o')
      ->condition('uid', $uid)
      ->condition('status', ['executed', 'partially_executed'], 'IN')
      ->orderBy('executed_at', 'DESC')
      ->range(0, 200)
      ->execute()
      ->fetchAll();

    $total_buy = 0;
    $total_sell = 0;
    $total_trades = count($trades);

    foreach ($trades as $trade) {
      $value = (float) $trade->executed_price * (int) $trade->executed_quantity;
      if ($trade->transaction_type === 'BUY') {
        $total_buy += $value;
      }
      else {
        $total_sell += $value;
      }
    }

    $summary = [
      'total_trades' => $total_trades,
      'total_buy_value' => $total_buy,
      'total_sell_value' => $total_sell,
      'net_pnl' => $total_sell - $total_buy,
    ];

    return [
      '#theme' => 'niftybot_trade_history',
      '#trades' => $trades,
      '#summary' => $summary,
    ];
  }

}
