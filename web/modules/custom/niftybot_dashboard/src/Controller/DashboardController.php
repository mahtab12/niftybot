<?php

namespace Drupal\niftybot_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\niftybot_investment\Service\InvestmentService;
use Drupal\niftybot_core\Service\BrokerConnectionService;
use Drupal\niftybot_user\Service\MemberIdService;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Main dashboard controller.
 */
class DashboardController extends ControllerBase {

  protected Connection $database;

  protected ?InvestmentService $investmentService;

  protected MemberIdService $memberIdService;

  protected WalletService $walletService;

  protected BrokerConnectionService $brokerConnection;

  /**
   * Constructs the controller.
   */
  public function __construct(
    Connection $database,
    MemberIdService $member_id_service,
    WalletService $wallet_service,
    BrokerConnectionService $broker_connection,
    ?InvestmentService $investment_service = NULL,
  ) {
    $this->database = $database;
    $this->memberIdService = $member_id_service;
    $this->walletService = $wallet_service;
    $this->brokerConnection = $broker_connection;
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
      $container->get('niftybot_user.member_id_service'),
      $container->get('niftybot_user.wallet_service'),
      $container->get('niftybot_core.broker_connection'),
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

    $wallet_totals = $this->getWalletTotals($uid);
    $available_balance = $this->walletService->getAvailableBalance($uid);
    $member_id = $this->memberIdService->getMemberId($uid);
    $display_name = $this->getUserDisplayName($user);
    $phone = niftybot_user_profile_field_value($user, 'field_mobile_number') ?? '';
    $city = niftybot_user_profile_field_value($user, 'field_city') ?? '';

    $investment_principal = $fxc_investment ? (float) $fxc_investment->principal_amount : 0.0;
    $investment_profit_pending = $fxc_investment ? (float) $fxc_investment->ai_profit_pending : 0.0;
    $investment_profit_total = $fxc_investment ? (float) $fxc_investment->total_profit_earned : 0.0;
    $broker = $this->brokerConnection->getDashboardSummary($uid, 'groww');

    return [
      '#theme' => 'niftybot_dashboard',
      '#user' => $user,
      '#display_name' => $display_name,
      '#member_id' => $member_id,
      '#phone' => $phone,
      '#city' => $city,
      '#kyc_status' => $kyc_status ?: 'not_submitted',
      '#subscription' => $subscription,
      '#plan' => $plan,
      '#wallet_balance' => $wallet_balance,
      '#available_balance' => $available_balance,
      '#wallet_totals' => $wallet_totals,
      '#positions' => $positions,
      '#recent_orders' => $recent_orders,
      '#stats' => $stats,
      '#fxc_investment' => $fxc_investment,
      '#investment_principal' => $investment_principal,
      '#investment_profit_pending' => $investment_profit_pending,
      '#investment_profit_total' => $investment_profit_total,
      '#broker' => $broker,
      '#attached' => [
        'library' => ['niftybot_dashboard/dashboard'],
      ],
    ] + WalletService::walletRenderCache($uid);
  }

  /**
   * Returns a friendly display name for the dashboard header.
   */
  protected function getUserDisplayName($user): string {
    return niftybot_user_display_name($user);
  }

  /**
   * Completed wallet deposit and withdrawal totals.
   *
   * @return array{deposits: float, withdrawals: float}
   */
  protected function getWalletTotals(int $uid): array {
    $deposit_query = $this->database->select('niftybot_wallet_transactions', 'wt');
    $deposit_query->addExpression('SUM(amount)', 'total');
    $deposit_query->condition('uid', $uid);
    $deposit_query->condition('type', 'deposit');
    $deposit_query->condition('status', 'completed');
    $deposits = (float) ($deposit_query->execute()->fetchField() ?? 0);

    $withdraw_query = $this->database->select('niftybot_wallet_transactions', 'wt');
    $withdraw_query->addExpression('SUM(amount)', 'total');
    $withdraw_query->condition('uid', $uid);
    $withdraw_query->condition('type', 'withdrawal');
    $withdraw_query->condition('status', 'completed');
    $withdrawals = (float) ($withdraw_query->execute()->fetchField() ?? 0);

    return [
      'deposits' => $deposits,
      'withdrawals' => $withdrawals,
    ];
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
      ->fields('p', ['pnl', 'average_price', 'quantity'])
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
