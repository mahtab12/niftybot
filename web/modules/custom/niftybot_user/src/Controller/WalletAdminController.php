<?php

namespace Drupal\niftybot_user\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin wallet transaction management.
 */
class WalletAdminController extends ControllerBase {

  /**
   * The database connection.
   */
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
   * Lists deposit requests awaiting or after admin verification.
   */
  public function pendingDeposits() {
    $query = $this->database->select('niftybot_wallet_transactions', 'wt')
      ->fields('wt')
      ->condition('type', 'deposit')
      ->condition('payment_method', ['upi', 'bank_transfer'], 'IN')
      ->orderBy('created', 'DESC');

    $deposits = $query->execute()->fetchAll();

    foreach ($deposits as &$deposit) {
      $user = $this->entityTypeManager()->getStorage('user')->load($deposit->uid);
      $deposit->username = $user ? $user->getAccountName() : 'Unknown';
      $deposit->email = $user ? $user->getEmail() : '';
      $deposit->review_url = Url::fromRoute('niftybot_user.wallet_deposit_review', [
        'transaction_id' => $deposit->transaction_id,
      ])->toString();
    }

    return [
      '#theme' => 'niftybot_wallet_deposits_admin',
      '#deposits' => $deposits,
      '#cache' => [
        'max-age' => 0,
        'tags' => [WalletService::DEPOSITS_LIST_CACHE_TAG],
      ],
    ];
  }

  /**
   * Lists withdrawal requests for admin verification.
   */
  public function pendingWithdrawals() {
    $withdrawals = $this->database->select('niftybot_wallet_transactions', 'wt')
      ->fields('wt')
      ->condition('type', 'withdrawal')
      ->condition('payment_method', 'bank_transfer')
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll();

    foreach ($withdrawals as &$withdrawal) {
      $user = $this->entityTypeManager()->getStorage('user')->load($withdrawal->uid);
      $withdrawal->username = $user ? $user->getAccountName() : 'Unknown';
      $withdrawal->email = $user ? $user->getEmail() : '';
      $withdrawal->review_url = Url::fromRoute('niftybot_user.wallet_withdrawal_review', [
        'transaction_id' => $withdrawal->transaction_id,
      ])->toString();
    }

    return [
      '#theme' => 'niftybot_wallet_withdrawals_admin',
      '#withdrawals' => $withdrawals,
      '#cache' => [
        'max-age' => 0,
        'tags' => [WalletService::WITHDRAWALS_LIST_CACHE_TAG],
      ],
    ];
  }

}
