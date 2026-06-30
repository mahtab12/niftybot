<?php

namespace Drupal\niftybot_mobile_api\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\niftybot_core\Service\BrokerConnectionService;
use Drupal\niftybot_user\Service\MemberIdService;
use Drupal\niftybot_user\Service\WalletService;

/**
 * Builds normalized JSON payloads for mobile clients.
 */
class MobileUserPayloadService {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MemberIdService $memberIdService,
    protected WalletService $walletService,
    protected BrokerConnectionService $brokerConnection,
    protected TimeInterface $time,
  ) {}

  /**
   * Profile summary for /me.
   */
  public function profile(int $uid): array {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user) {
      return [];
    }

    return [
      'uid' => $uid,
      'username' => $user->getAccountName(),
      'email' => $user->getEmail(),
      'display_name' => $user->getDisplayName(),
      'member_id' => $this->memberIdService->getMemberId($uid),
      'kyc_status' => $this->kycStatus($uid),
      'wallet' => $this->walletSummary($uid),
      'subscription' => $this->subscriptionSummary($uid),
      'broker' => $this->brokerConnection->attemptAutoConnect($uid, 'groww'),
    ];
  }

  /**
   * Dashboard aggregate.
   */
  public function dashboard(int $uid): array {
    $subscription = $this->subscriptionSummary($uid);
    $open_positions = (int) $this->database->select('niftybot_positions', 'p')
      ->condition('uid', $uid)
      ->condition('status', 'open')
      ->countQuery()
      ->execute()
      ->fetchField();

    $orders_today_start = strtotime('today midnight');
    $orders_today = (int) $this->database->select('niftybot_orders', 'o')
      ->condition('uid', $uid)
      ->condition('created', $orders_today_start, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    return [
      'profile' => [
        'uid' => $uid,
        'display_name' => $this->entityTypeManager->getStorage('user')->load($uid)?->getDisplayName(),
        'member_id' => $this->memberIdService->getMemberId($uid),
        'kyc_status' => $this->kycStatus($uid),
      ],
      'wallet' => $this->walletSummary($uid),
      'subscription' => $subscription,
      'broker' => $this->brokerConnection->attemptAutoConnect($uid, 'groww'),
      'stats' => [
        'open_positions' => $open_positions,
        'orders_today' => $orders_today,
      ],
    ];
  }

  /**
   * Active subscription with plan details.
   */
  public function subscriptionSummary(int $uid): ?array {
    $row = $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us')
      ->condition('uid', $uid)
      ->condition('status', 'active')
      ->execute()
      ->fetchObject();

    if (!$row) {
      return NULL;
    }

    $plan = $this->database->select('niftybot_subscription_plans', 'sp')
      ->fields('sp')
      ->condition('plan_id', $row->plan_id)
      ->execute()
      ->fetchObject();

    $days_remaining = max(0, (int) ceil(($row->end_date - $this->time->getRequestTime()) / 86400));

    return [
      'subscription_id' => (int) $row->subscription_id,
      'status' => $row->status,
      'start_date' => (int) $row->start_date,
      'end_date' => (int) $row->end_date,
      'days_remaining' => $days_remaining,
      'plan' => $plan ? [
        'plan_id' => (int) $plan->plan_id,
        'name' => $plan->name,
        'machine_name' => $plan->machine_name,
        'price' => (float) $plan->price,
        'max_trades_per_day' => (int) $plan->max_orders_per_day,
        'features' => json_decode($plan->features ?? '[]', TRUE) ?: [],
      ] : NULL,
    ];
  }

  /**
   * Wallet balances.
   */
  public function walletSummary(int $uid): array {
    return [
      'balance' => $this->walletService->getWalletBalance($uid),
      'available' => $this->walletService->getAvailableBalance($uid),
      'pending_withdrawals' => $this->walletService->getPendingWithdrawalTotal($uid),
    ];
  }

  /**
   * Subscription plans list.
   */
  public function plans(): array {
    $rows = $this->database->select('niftybot_subscription_plans', 'sp')
      ->fields('sp')
      ->condition('status', 1)
      ->orderBy('weight')
      ->execute()
      ->fetchAll();

    $plans = [];
    foreach ($rows as $plan) {
      $plans[] = [
        'plan_id' => (int) $plan->plan_id,
        'name' => $plan->name,
        'machine_name' => $plan->machine_name,
        'description' => $plan->description,
        'price' => (float) $plan->price,
        'duration_days' => (int) $plan->duration_days,
        'max_trades_per_day' => (int) $plan->max_orders_per_day,
        'max_capital' => $plan->max_capital !== NULL ? (float) $plan->max_capital : NULL,
        'features' => json_decode($plan->features ?? '[]', TRUE) ?: [],
      ];
    }

    return $plans;
  }

  protected function kycStatus(int $uid): string {
    $status = $this->database->select('niftybot_kyc', 'k')
      ->fields('k', ['status'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    return $status ? (string) $status : 'not_submitted';
  }

}
