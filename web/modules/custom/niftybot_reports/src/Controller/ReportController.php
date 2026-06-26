<?php

namespace Drupal\niftybot_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report generation controller.
 */
class ReportController extends ControllerBase {

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
   * User reports overview.
   */
  public function userReports() {
    $uid = $this->currentUser()->id();

    $total_orders = (int) $this->database->select('niftybot_orders', 'o')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();

    $executed_orders = (int) $this->database->select('niftybot_orders', 'o')
      ->condition('uid', $uid)
      ->condition('status', 'executed')
      ->countQuery()
      ->execute()
      ->fetchField();

    $cancelled_orders = (int) $this->database->select('niftybot_orders', 'o')
      ->condition('uid', $uid)
      ->condition('status', 'cancelled')
      ->countQuery()
      ->execute()
      ->fetchField();

    $positions = $this->database->select('niftybot_positions', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAll();

    $total_pnl = 0;
    $total_investment = 0;
    foreach ($positions as $pos) {
      $total_pnl += (float) $pos->pnl;
      $total_investment += (float) $pos->average_price * (int) $pos->quantity;
    }

    $stats = [
      'total_orders' => $total_orders,
      'executed_orders' => $executed_orders,
      'cancelled_orders' => $cancelled_orders,
      'success_rate' => $total_orders > 0 ? round(($executed_orders / $total_orders) * 100, 1) : 0,
      'total_investment' => $total_investment,
      'total_pnl' => $total_pnl,
    ];

    return [
      '#theme' => 'niftybot_reports_overview',
      '#stats' => $stats,
      '#monthly_summary' => [],
      '#attached' => [
        'library' => ['niftybot_reports/reports'],
      ],
    ];
  }

  /**
   * P&L report page.
   */
  public function pnlReport() {
    $uid = $this->currentUser()->id();

    $trades = $this->database->select('niftybot_orders', 'o')
      ->fields('o')
      ->condition('uid', $uid)
      ->condition('status', 'executed')
      ->orderBy('executed_at', 'DESC')
      ->execute()
      ->fetchAll();

    $total_pnl = 0;
    $winning = 0;
    $losing = 0;

    $daily_pnl = [];
    foreach ($trades as $trade) {
      if ($trade->executed_at) {
        $date = date('Y-m-d', $trade->executed_at);
        if (!isset($daily_pnl[$date])) {
          $daily_pnl[$date] = ['date' => $date, 'buy' => 0, 'sell' => 0, 'pnl' => 0, 'trades' => 0];
        }

        $value = (float) $trade->executed_price * (int) $trade->executed_quantity;
        if ($trade->transaction_type === 'BUY') {
          $daily_pnl[$date]['buy'] += $value;
        }
        else {
          $daily_pnl[$date]['sell'] += $value;
        }
        $daily_pnl[$date]['trades']++;
      }
    }

    foreach ($daily_pnl as &$day) {
      $day['pnl'] = $day['sell'] - $day['buy'];
      $total_pnl += $day['pnl'];
      if ($day['pnl'] > 0) {
        $winning++;
      }
      elseif ($day['pnl'] < 0) {
        $losing++;
      }
    }

    return [
      '#theme' => 'niftybot_pnl_report',
      '#daily_pnl' => array_values($daily_pnl),
      '#total_pnl' => $total_pnl,
      '#winning_trades' => $winning,
      '#losing_trades' => $losing,
      '#attached' => [
        'library' => ['niftybot_reports/pnl'],
      ],
    ];
  }

  /**
   * Export data as CSV.
   */
  public function exportCsv(string $type): Response {
    $uid = $this->currentUser()->id();
    $filename = "niftybot_{$type}_" . date('Y-m-d') . '.csv';

    $response = new StreamedResponse(function () use ($type, $uid) {
      $handle = fopen('php://output', 'w');

      switch ($type) {
        case 'orders':
          fputcsv($handle, ['Order ID', 'Symbol', 'Exchange', 'Type', 'Order Type', 'Product', 'Qty', 'Price', 'Executed Price', 'Status', 'Broker', 'Date']);
          $orders = $this->database->select('niftybot_orders', 'o')
            ->fields('o')
            ->condition('uid', $uid)
            ->orderBy('created', 'DESC')
            ->execute()
            ->fetchAll();
          foreach ($orders as $order) {
            fputcsv($handle, [
              $order->order_id,
              $order->symbol,
              $order->exchange,
              $order->transaction_type,
              $order->order_type,
              $order->product_type,
              $order->quantity,
              $order->price,
              $order->executed_price,
              $order->status,
              $order->broker,
              date('Y-m-d H:i:s', $order->placed_at),
            ]);
          }
          break;

        case 'trades':
          fputcsv($handle, ['Date', 'Symbol', 'Exchange', 'Type', 'Qty', 'Price', 'Value', 'Broker']);
          $trades = $this->database->select('niftybot_orders', 'o')
            ->fields('o')
            ->condition('uid', $uid)
            ->condition('status', 'executed')
            ->orderBy('executed_at', 'DESC')
            ->execute()
            ->fetchAll();
          foreach ($trades as $trade) {
            fputcsv($handle, [
              $trade->executed_at ? date('Y-m-d H:i:s', $trade->executed_at) : '',
              $trade->symbol,
              $trade->exchange,
              $trade->transaction_type,
              $trade->executed_quantity,
              $trade->executed_price,
              (float) $trade->executed_price * (int) $trade->executed_quantity,
              $trade->broker,
            ]);
          }
          break;

        case 'positions':
          fputcsv($handle, ['Symbol', 'Exchange', 'Type', 'Qty', 'Avg Price', 'Current Price', 'P&L', 'P&L %', 'Broker', 'Status']);
          $positions = $this->database->select('niftybot_positions', 'p')
            ->fields('p')
            ->condition('uid', $uid)
            ->execute()
            ->fetchAll();
          foreach ($positions as $pos) {
            fputcsv($handle, [
              $pos->symbol,
              $pos->exchange,
              $pos->product_type,
              $pos->quantity,
              $pos->average_price,
              $pos->current_price,
              $pos->pnl,
              $pos->pnl_percentage,
              $pos->broker,
              $pos->status,
            ]);
          }
          break;
      }

      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

  /**
   * Admin platform reports.
   */
  public function adminReports() {
    $total_users = (int) $this->database->select('users_field_data', 'u')
      ->condition('status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    $active_subs = (int) $this->database->select('niftybot_user_subscriptions', 'us')
      ->condition('status', 'active')
      ->countQuery()
      ->execute()
      ->fetchField();

    $total_orders = (int) $this->database->select('niftybot_orders', 'o')
      ->countQuery()
      ->execute()
      ->fetchField();

    $revenue_query = $this->database->select('niftybot_user_subscriptions', 'us');
    $revenue_query->addExpression('SUM(payment_amount)', 'total');
    $revenue_query->condition('status', ['active', 'expired'], 'IN');
    $revenue_total = (float) $revenue_query->execute()->fetchField();

    $platform_stats = [
      'total_users' => $total_users,
      'active_subscriptions' => $active_subs,
      'total_orders' => $total_orders,
      'total_revenue' => $revenue_total,
    ];

    return [
      '#theme' => 'niftybot_admin_reports',
      '#platform_stats' => $platform_stats,
      '#top_traders' => [],
      '#revenue' => [],
    ];
  }

}
