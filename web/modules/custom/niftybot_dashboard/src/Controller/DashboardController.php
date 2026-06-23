<?php

namespace Drupal\niftybot_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\niftybot_investment\Service\InvestmentService;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Main dashboard controller.
 */
class DashboardController extends ControllerBase {

  protected Connection $database;

  protected ?InvestmentService $investmentService;

  /**
   * Constructs the controller.
   */
  public function __construct(Connection $database, ?InvestmentService $investment_service = NULL) {
    $this->database = $database;
    $this->investmentService = $investment_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $investment_service = $container->has('niftybot_investment.investment_service')
      ? $container->get('niftybot_investment.investment_service')
      : NULL;

    return new static(
      $container->get('database'),
      $investment_service,
    );
  }

  /**
   * Main dashboard page.
   */
  public function main() {
    $uid = $this->currentUser()->id();
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);

    $kyc_status = $this->database->select('niftybot_kyc', 'k')
      ->fields('k', ['status'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    $wallet_balance = (float) ($this->database->select('niftybot_wallet', 'w')
      ->fields('w', ['balance'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField() ?? 0);

    $subscription = $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us')
      ->condition('uid', $uid)
      ->condition('status', 'active')
      ->execute()
      ->fetchObject();

    $plan = NULL;
    if ($subscription) {
      $plan = $this->database->select('niftybot_subscription_plans', 'sp')
        ->fields('sp')
        ->condition('plan_id', $subscription->plan_id)
        ->execute()
        ->fetchObject();
    }

    $positions = $this->database->select('niftybot_positions', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->condition('status', 'open')
      ->orderBy('pnl', 'DESC')
      ->range(0, 10)
      ->execute()
      ->fetchAll();

    $recent_orders = $this->database->select('niftybot_orders', 'o')
      ->fields('o')
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->range(0, 5)
      ->execute()
      ->fetchAll();

    $stats = $this->getUserStats($uid);

    $fxc_investment = NULL;
    if ($this->investmentService) {
      $fxc_investment = $this->investmentService->getActiveInvestment($uid);
    }

    return [
      '#theme' => 'niftybot_dashboard',
      '#user' => $user,
      '#kyc_status' => $kyc_status ?: 'not_submitted',
      '#subscription' => $subscription,
      '#plan' => $plan,
      '#wallet_balance' => $wallet_balance,
      '#positions' => $positions,
      '#recent_orders' => $recent_orders,
      '#stats' => $stats,
      '#fxc_investment' => $fxc_investment,
    ] + WalletService::walletRenderCache($uid);
  }

  /**
   * Calculate user trading statistics.
   */
  protected function getUserStats(int $uid): array {
    $today_start = strtotime('today');

    $orders_today = (int) $this->database->select('niftybot_orders', 'o')
      ->condition('uid', $uid)
      ->condition('placed_at', $today_start, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    $total_orders = (int) $this->database->select('niftybot_orders', 'o')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();

    $open_positions = (int) $this->database->select('niftybot_positions', 'p')
      ->condition('uid', $uid)
      ->condition('status', 'open')
      ->countQuery()
      ->execute()
      ->fetchField();

    $total_pnl_result = $this->database->select('niftybot_positions', 'p')
      ->condition('uid', $uid)
      ->condition('status', 'open')
      ->execute()
      ->fetchAll();

    $total_pnl = 0;
    $total_investment = 0;
    foreach ($total_pnl_result as $pos) {
      $total_pnl += (float) $pos->pnl;
      $total_investment += (float) $pos->average_price * (int) $pos->quantity;
    }

    return [
      'orders_today' => $orders_today,
      'total_orders' => $total_orders,
      'open_positions' => $open_positions,
      'total_pnl' => $total_pnl,
      'total_investment' => $total_investment,
    ];
  }

}
