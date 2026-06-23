<?php

namespace Drupal\niftybot_investment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_investment\Service\InvestmentService;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * FXC investment dashboard and plans.
 */
class InvestmentController extends ControllerBase {

  /**
   * Constructs the controller.
   */
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

  /**
   * FXC investment dashboard with AI profit summary.
   */
  public function dashboard() {
    $uid = (int) $this->currentUser()->id();
    $investment = $this->investmentService->getActiveInvestment($uid);
    $projected = [];
    $profit_history = [];

    if ($investment) {
      $projected = $this->investmentService->getProjectedWeeklyProfit($investment);
      $profit_history = $this->investmentService->getProfitHistory($uid);
    }

    return [
      '#theme' => 'niftybot_investment_dashboard',
      '#investment' => $investment,
      '#profit_history' => $profit_history,
      '#projected' => $projected,
      '#next_payout' => $this->investmentService->getNextPayoutTimestamp(),
      '#has_active' => (bool) $investment,
      '#attached' => ['library' => ['niftybot_investment/investment']],
    ] + WalletService::walletRenderCache($uid);
  }

  /**
   * Lists available FXC investment plans.
   */
  public function plans() {
    $uid = (int) $this->currentUser()->id();

    return [
      '#theme' => 'niftybot_investment_plans',
      '#plans' => $this->investmentService->getActivePlans(),
      '#active_investment' => $this->investmentService->getActiveInvestment($uid),
      '#wallet_balance' => $this->walletService->getWalletBalance($uid),
      '#attached' => ['library' => ['niftybot_investment/investment']],
    ] + WalletService::walletRenderCache($uid);
  }

}
