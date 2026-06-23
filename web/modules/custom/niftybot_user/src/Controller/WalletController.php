<?php

namespace Drupal\niftybot_user\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wallet controller.
 */
class WalletController extends ControllerBase {

  /**
   * The wallet service.
   */
  protected WalletService $walletService;

  /**
   * Constructs the controller.
   */
  public function __construct(WalletService $wallet_service) {
    $this->walletService = $wallet_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_user.wallet_service')
    );
  }

  /**
   * Wallet overview page.
   */
  public function overview() {
    $uid = $this->currentUser()->id();
    $balance = $this->walletService->getWalletBalance($uid);
    $pending_withdrawals = $this->walletService->getPendingWithdrawalTotal($uid);
    $available = $this->walletService->getAvailableBalance($uid);

    $transactions = $this->walletService->getUserTransactions($uid);

    return [
      '#theme' => 'niftybot_wallet_overview',
      '#balance' => $balance,
      '#available_balance' => $available,
      '#pending_withdrawals' => $pending_withdrawals,
      '#transactions' => $transactions,
    ] + WalletService::walletRenderCache($uid);
  }

}
