<?php

namespace Drupal\niftybot_mobile_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_investment\Service\InvestmentService;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GrassRed Investment endpoints for mobile clients.
 */
class MobileInvestmentController extends ControllerBase {

  public function __construct(
    protected InvestmentService $investmentService,
    protected WalletService $walletService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_investment.investment_service'),
      $container->get('niftybot_user.wallet_service'),
    );
  }

  public function dashboard(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $investment = $this->investmentService->getActiveInvestment($uid);
    $projected = NULL;
    $profit_history = [];

    if ($investment) {
      $projected = $this->investmentService->getProjectedWeeklyProfit($investment);
      $profit_history = array_map(
        fn($row) => $this->serializeProfit($row),
        $this->investmentService->getProfitHistory($uid),
      );
    }

    return new JsonResponse([
      'success' => TRUE,
      'has_active' => (bool) $investment,
      'investment' => $investment ? $this->serializeInvestment($investment) : NULL,
      'projected_weekly' => $projected,
      'next_payout_at' => $this->investmentService->getNextPayoutTimestamp(),
      'profit_history' => $profit_history,
    ]);
  }

  public function plans(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $active = $this->investmentService->getActiveInvestment($uid);
    $plans = $this->investmentService->getActivePlans();

    return new JsonResponse([
      'success' => TRUE,
      'wallet_balance' => $this->walletService->getWalletBalance($uid),
      'has_active_investment' => (bool) $active,
      'active_investment' => $active ? $this->serializeInvestment($active) : NULL,
      'plans' => array_map(fn($plan) => $this->serializePlan($plan), $plans),
    ]);
  }

  public function invest(int $plan_id): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    try {
      $investment_id = $this->investmentService->createInvestment($uid, $plan_id);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $e->getMessage(),
      ], 400);
    }

    $investment = $this->investmentService->getActiveInvestment($uid);
    return new JsonResponse([
      'success' => TRUE,
      'investment_id' => $investment_id,
      'message' => 'Your GrassRed investment is now active.',
      'investment' => $investment ? $this->serializeInvestment($investment) : NULL,
    ]);
  }

  public function trades(): JsonResponse {
    $grouped = $this->investmentService->getPlatformTradesGrouped();
    $summary = $this->investmentService->getDailyPnlSummary();
    $days = [];

    foreach ($grouped as $date => $trades) {
      $days[] = [
        'date' => $date,
        'daily_pnl' => $summary[$date]['pnl'] ?? 0,
        'trade_count' => $summary[$date]['count'] ?? count($trades),
        'trades' => array_map(fn($trade) => $this->serializeTrade($trade), $trades),
      ];
    }

    return new JsonResponse([
      'success' => TRUE,
      'days' => $days,
    ]);
  }

  protected function serializePlan(object $plan): array {
    $amount = (float) $plan->amount;
    $min_pct = (float) $plan->weekly_return_min;
    $max_pct = (float) $plan->weekly_return_max;

    return [
      'plan_id' => (int) $plan->plan_id,
      'name' => $plan->name,
      'machine_name' => $plan->machine_name,
      'amount' => $amount,
      'weekly_return_min' => $min_pct,
      'weekly_return_max' => $max_pct,
      'description' => $plan->description ?? '',
      'projected_weekly_min' => round($amount * $min_pct / 100, 2),
      'projected_weekly_max' => round($amount * $max_pct / 100, 2),
      'featured' => $plan->machine_name === 'plan_b',
    ];
  }

  protected function serializeInvestment(object $investment): array {
    return [
      'investment_id' => (int) $investment->investment_id,
      'plan_id' => (int) $investment->plan_id,
      'name' => $investment->name ?? '',
      'machine_name' => $investment->machine_name ?? '',
      'principal_amount' => (float) $investment->principal_amount,
      'ai_profit_pending' => (float) $investment->ai_profit_pending,
      'last_week_profit' => (float) $investment->last_week_profit,
      'total_profit_earned' => (float) $investment->total_profit_earned,
      'weekly_return_min' => (float) ($investment->weekly_return_min ?? 4),
      'weekly_return_max' => (float) ($investment->weekly_return_max ?? 5),
      'started_at' => (int) $investment->started_at,
      'last_payout_at' => (int) ($investment->last_payout_at ?? 0),
      'status' => $investment->status,
    ];
  }

  protected function serializeProfit(object $row): array {
    return [
      'profit_id' => (int) $row->profit_id,
      'profit_amount' => (float) $row->profit_amount,
      'profit_percent' => (float) $row->profit_percent,
      'week_start' => $row->week_start,
      'week_end' => $row->week_end,
      'credited_at' => (int) $row->credited_at,
    ];
  }

  protected function serializeTrade(object $trade): array {
    return [
      'trade_id' => (int) $trade->trade_id,
      'trade_date' => $trade->trade_date,
      'symbol' => $trade->symbol,
      'strategy' => $trade->strategy,
      'transaction_type' => $trade->transaction_type,
      'quantity' => (int) $trade->quantity,
      'entry_price' => (float) $trade->entry_price,
      'exit_price' => (float) $trade->exit_price,
      'pnl' => (float) $trade->pnl,
      'pnl_percentage' => $trade->pnl_percentage !== NULL ? (float) $trade->pnl_percentage : NULL,
      'notes' => $trade->notes ?? '',
    ];
  }

}
