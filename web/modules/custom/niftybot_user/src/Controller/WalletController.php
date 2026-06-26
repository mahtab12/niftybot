<?php

namespace Drupal\niftybot_user\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

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
  public function overview(Request $request) {
    $uid = (int) $this->currentUser()->id();
    $balance = $this->walletService->getWalletBalance($uid);
    $pending_withdrawals = $this->walletService->getPendingWithdrawalTotal($uid);
    $available = $this->walletService->getAvailableBalance($uid);

    $filter = (string) $request->query->get('filter', 'all');
    if (!in_array($filter, WalletService::TRANSACTION_FILTERS, TRUE)) {
      $filter = 'all';
    }

    $transactions = array_map(function ($transaction) {
      $labels = $this->walletService->getTransactionDisplayLabels($transaction);
      $transaction->display_type = $labels['type'];
      $transaction->display_type_label = $labels['type_label'];
      $transaction->display_method_label = $labels['method_label'];
      $transaction->display_badge_class = $labels['badge_class'];
      return $transaction;
    }, $this->walletService->getUserTransactions($uid, 50, $filter));

    return [
      '#theme' => 'niftybot_wallet_overview',
      '#balance' => $balance,
      '#available_balance' => $available,
      '#pending_withdrawals' => $pending_withdrawals,
      '#transactions' => $transactions,
      '#active_filter' => $filter,
      '#filter_options' => $this->walletService->getTransactionFilterOptions(),
      '#attached' => [
        'library' => ['niftybot_user/wallet'],
      ],
    ] + WalletService::walletRenderCache($uid);
  }

}
