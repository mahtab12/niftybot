<?php

namespace Drupal\niftybot_investment\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\niftybot_user\Service\WalletService;

/**
 * FXC investment plans, weekly profit accrual, and Saturday payouts.
 */
class InvestmentService {

  /**
   * Day of week for profit payout (0 = Sunday, 6 = Saturday).
   */
  public const PAYOUT_DAY = 6;

  /**
   * Hour (24h) when Saturday payout runs.
   */
  public const PAYOUT_HOUR = 8;

  /**
   * Constructs the service.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected WalletService $walletService,
  ) {}

  /**
   * Returns active investment plans.
   *
   * @return array<int, object>
   */
  public function getActivePlans(): array {
    return $this->database->select('niftybot_investment_plans', 'p')
      ->fields('p')
      ->condition('status', 'active')
      ->orderBy('weight')
      ->orderBy('amount')
      ->execute()
      ->fetchAll();
  }

  /**
   * Loads a plan by ID.
   */
  public function getPlan(int $plan_id): ?object {
    $plan = $this->database->select('niftybot_investment_plans', 'p')
      ->fields('p')
      ->condition('plan_id', $plan_id)
      ->execute()
      ->fetchObject();

    return $plan ?: NULL;
  }

  /**
   * Returns the user's active investment with plan details, if any.
   */
  public function getActiveInvestment(int $uid): ?object {
    $query = $this->database->select('niftybot_user_investments', 'i');
    $query->join('niftybot_investment_plans', 'p', 'p.plan_id = i.plan_id');
    $query->fields('i');
    $query->fields('p', ['name', 'machine_name', 'weekly_return_min', 'weekly_return_max']);
    $query->condition('i.uid', $uid);
    $query->condition('i.status', 'active');
    $query->range(0, 1);

    $row = $query->execute()->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Creates an investment by debiting the user's wallet.
   *
   * @throws \InvalidArgumentException
   */
  public function createInvestment(int $uid, int $plan_id): int {
    if ($this->getActiveInvestment($uid)) {
      throw new \InvalidArgumentException('You already have an active GrassRed investment.');
    }

    $plan = $this->getPlan($plan_id);
    if (!$plan || $plan->status !== 'active') {
      throw new \InvalidArgumentException('Invalid investment plan.');
    }

    $amount = (float) $plan->amount;
    $balance = $this->walletService->getWalletBalance($uid);
    if ($balance < $amount) {
      throw new \InvalidArgumentException('Insufficient wallet balance. Please deposit funds first.');
    }

    $now = $this->time->getRequestTime();

    $this->walletService->debitBalance($uid, $amount);

    $this->database->insert('niftybot_wallet_transactions')
      ->fields([
        'uid' => $uid,
        'type' => 'withdrawal',
        'amount' => $amount,
        'status' => 'completed',
        'payment_method' => 'fxc_investment',
        'notes' => 'GrassRed Investment — ' . $plan->name,
        'balance_applied' => 1,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    return (int) $this->database->insert('niftybot_user_investments')
      ->fields([
        'uid' => $uid,
        'plan_id' => $plan_id,
        'principal_amount' => $amount,
        'status' => 'active',
        'ai_profit_pending' => 0,
        'last_week_profit' => 0,
        'total_profit_earned' => 0,
        'started_at' => $now,
        'last_payout_at' => 0,
        'updated' => $now,
      ])
      ->execute();
  }

  /**
   * Accrues daily AI profit into pending balance for active investments.
   */
  public function accrueDailyProfit(): int {
    $now = $this->time->getRequestTime();
    $today = (new \DateTimeImmutable('@' . $now))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
      ->format('Y-m-d');

    $state = \Drupal::state();
    if ($state->get('niftybot_investment.last_accrual_date') === $today) {
      return 0;
    }

    $investments = $this->database->select('niftybot_user_investments', 'i')
      ->fields('i')
      ->condition('status', 'active')
      ->execute()
      ->fetchAll();

    $updated = 0;
    foreach ($investments as $investment) {
      $plan = $this->getPlan((int) $investment->plan_id);
      if (!$plan) {
        continue;
      }

      $daily = $this->calculateDailyAccrual(
        (float) $investment->principal_amount,
        (float) $plan->weekly_return_min,
        (float) $plan->weekly_return_max,
      );

      if ($daily <= 0) {
        continue;
      }

      $this->database->update('niftybot_user_investments')
        ->expression('ai_profit_pending', 'ai_profit_pending + :daily', [':daily' => $daily])
        ->condition('investment_id', $investment->investment_id)
        ->execute();

      $updated++;
    }

    $state->set('niftybot_investment.last_accrual_date', $today);

    return $updated;
  }

  /**
   * Credits pending AI profit to wallets on payout day.
   */
  public function processSaturdayPayouts(): int {
    $investments = $this->database->select('niftybot_user_investments', 'i')
      ->fields('i')
      ->condition('status', 'active')
      ->condition('ai_profit_pending', 0, '>')
      ->execute()
      ->fetchAll();

    $now = $this->time->getRequestTime();
    $week = $this->getCurrentWeekBounds();
    $processed = 0;

    foreach ($investments as $investment) {
      $already_paid = (int) $this->database->select('niftybot_investment_profits', 'p')
        ->condition('investment_id', $investment->investment_id)
        ->condition('week_end', $week['end'])
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($already_paid > 0) {
        continue;
      }

      $profit = round((float) $investment->ai_profit_pending, 2);
      if ($profit <= 0) {
        continue;
      }

      $principal = (float) $investment->principal_amount;
      $percent = $principal > 0 ? round(($profit / $principal) * 100, 2) : 0;

      $this->walletService->creditBalance(
        (int) $investment->uid,
        $profit,
        'investment_profit',
        'GrassRed weekly AI profit payout',
      );

      $this->database->insert('niftybot_investment_profits')
        ->fields([
          'investment_id' => $investment->investment_id,
          'uid' => $investment->uid,
          'principal_amount' => $principal,
          'profit_amount' => $profit,
          'profit_percent' => $percent,
          'week_start' => $week['start'],
          'week_end' => $week['end'],
          'status' => 'credited',
          'created' => $now,
          'credited_at' => $now,
        ])
        ->execute();

      $this->database->update('niftybot_user_investments')
        ->expression('total_profit_earned', 'total_profit_earned + :profit', [':profit' => $profit])
        ->fields([
          'ai_profit_pending' => 0,
          'last_week_profit' => $profit,
          'last_payout_at' => $now,
          'updated' => $now,
        ])
        ->condition('investment_id', $investment->investment_id)
        ->execute();

      $processed++;
    }

    return $processed;
  }

  /**
   * Returns profit history for a user.
   *
   * @return array<int, object>
   */
  public function getProfitHistory(int $uid, int $limit = 12): array {
    return $this->database->select('niftybot_investment_profits', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->orderBy('credited_at', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Returns platform trades grouped by date (newest first).
   *
   * @return array<string, array<int, object>>
   */
  public function getPlatformTradesGrouped(int $limit_days = 30): array {
    $trades = $this->database->select('niftybot_platform_trades', 't')
      ->fields('t')
      ->orderBy('trade_date', 'DESC')
      ->orderBy('trade_id', 'DESC')
      ->range(0, 200)
      ->execute()
      ->fetchAll();

    $grouped = [];
    foreach ($trades as $trade) {
      $grouped[$trade->trade_date][] = $trade;
    }

    return array_slice($grouped, 0, $limit_days, TRUE);
  }

  /**
   * Daily P&L summary for platform trades.
   *
   * @return array<string, array{pnl: float, count: int}>
   */
  public function getDailyPnlSummary(): array {
    $query = $this->database->select('niftybot_platform_trades', 't');
    $query->addField('t', 'trade_date');
    $query->addExpression('SUM(t.pnl)', 'daily_pnl');
    $query->addExpression('COUNT(*)', 'trade_count');
    $query->groupBy('trade_date');
    $query->orderBy('trade_date', 'DESC');
    $query->range(0, 30);
    $rows = $query->execute()->fetchAll();

    $summary = [];
    foreach ($rows as $row) {
      $summary[$row->trade_date] = [
        'pnl' => (float) $row->daily_pnl,
        'count' => (int) $row->trade_count,
      ];
    }

    return $summary;
  }

  /**
   * Next Saturday payout timestamp.
   */
  public function getNextPayoutTimestamp(): int {
    $now = $this->time->getRequestTime();
    $tz = new \DateTimeZone(date_default_timezone_get());
    $dt = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
    $day = (int) $dt->format('w');

    $days_until = (self::PAYOUT_DAY - $day + 7) % 7;
    if ($days_until === 0 && (int) $dt->format('G') >= self::PAYOUT_HOUR) {
      $days_until = 7;
    }

    $payout = $dt->modify('+' . $days_until . ' days')->setTime(self::PAYOUT_HOUR, 0, 0);
    return $payout->getTimestamp();
  }

  /**
   * Projected weekly profit range for display.
   *
   * @return array{min: float, max: float, mid: float}
   */
  public function getProjectedWeeklyProfit(object $investment): array {
    $principal = (float) $investment->principal_amount;
    $min_pct = (float) ($investment->weekly_return_min ?? 4);
    $max_pct = (float) ($investment->weekly_return_max ?? 5);

    return [
      'min' => round($principal * $min_pct / 100, 2),
      'max' => round($principal * $max_pct / 100, 2),
      'mid' => round($principal * (($min_pct + $max_pct) / 2) / 100, 2),
    ];
  }

  /**
   * Whether today is payout day after payout hour.
   */
  public function isPayoutWindow(): bool {
    $now = $this->time->getRequestTime();
    $tz = new \DateTimeZone(date_default_timezone_get());
    $dt = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);

    return (int) $dt->format('w') === self::PAYOUT_DAY
      && (int) $dt->format('G') >= self::PAYOUT_HOUR;
  }

  /**
   * Daily accrual amount (midpoint of weekly range / 7).
   */
  protected function calculateDailyAccrual(float $principal, float $min_pct, float $max_pct): float {
    $weekly_mid = $principal * (($min_pct + $max_pct) / 2) / 100;
    return round($weekly_mid / 7, 2);
  }

  /**
   * Current week Mon–Sun date bounds.
   *
   * @return array{start: string, end: string}
   */
  protected function getCurrentWeekBounds(): array {
    $now = $this->time->getRequestTime();
    $tz = new \DateTimeZone(date_default_timezone_get());
    $dt = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
    $day = (int) $dt->format('N');
    $monday = $dt->modify('-' . ($day - 1) . ' days');
    $sunday = $monday->modify('+6 days');

    return [
      'start' => $monday->format('Y-m-d'),
      'end' => $sunday->format('Y-m-d'),
    ];
  }

}
