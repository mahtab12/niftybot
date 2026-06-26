<?php

namespace Drupal\niftybot_user\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;

/**
 * Wallet balance and transaction operations.
 */
class WalletService {

  /**
   * Demo UPI ID for wallet deposits.
   */
  public const DEMO_UPI_ID = 'mahtab.afridi@ybl';

  /**
   * Cache tag for the admin deposit verification list.
   */
  public const DEPOSITS_LIST_CACHE_TAG = 'niftybot_wallet_deposits';

  /**
   * Supported wallet transaction history filters.
   */
  public const TRANSACTION_FILTERS = [
    'all',
    'deposit',
    'withdrawal',
    'investment',
    'algo_trade',
  ];

  /**
   * Cache tag for the admin withdrawal verification list.
   */
  public const WITHDRAWALS_LIST_CACHE_TAG = 'niftybot_wallet_withdrawals';

  /**
   * Constructs the service.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Returns the cache tag for a user's wallet data.
   */
  public static function walletCacheTag(int $uid): string {
    return 'niftybot_wallet:' . $uid;
  }

  /**
   * Returns render cache metadata for user-specific wallet pages.
   */
  public static function walletRenderCache(int $uid): array {
    return [
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user', 'session'],
        'tags' => [self::walletCacheTag($uid)],
      ],
    ];
  }

  /**
   * Invalidates cached wallet data for a user.
   */
  public function invalidateWalletCache(int $uid): void {
    $this->cacheTagsInvalidator->invalidateTags([
      self::walletCacheTag($uid),
      self::DEPOSITS_LIST_CACHE_TAG,
      self::WITHDRAWALS_LIST_CACHE_TAG,
    ]);
  }

  /**
   * Returns the current wallet balance.
   */
  public function getWalletBalance(int $uid): float {
    return (float) ($this->database->select('niftybot_wallet', 'w')
      ->fields('w', ['balance'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField() ?? 0);
  }

  /**
   * Reconciles wallet balance from completed transaction ledger.
   */
  public function reconcileWalletBalance(int $uid): float {
    $credit_query = $this->database->select('niftybot_wallet_transactions', 'wt');
    $credit_query->addExpression('SUM(amount)', 'total');
    $credit_query->condition('uid', $uid);
    $credit_query->condition('type', 'deposit');
    $credit_query->condition('status', 'completed');
    $credits = (float) ($credit_query->execute()->fetchField() ?? 0);

    $debit_query = $this->database->select('niftybot_wallet_transactions', 'wt');
    $debit_query->addExpression('SUM(amount)', 'total');
    $debit_query->condition('uid', $uid);
    $debit_query->condition('type', 'withdrawal');
    $debit_query->condition('status', 'completed');
    $debits = (float) ($debit_query->execute()->fetchField() ?? 0);

    $balance = $credits - $debits;

    $this->database->update('niftybot_wallet')
      ->fields([
        'balance' => $balance,
        'updated' => $this->time->getRequestTime(),
      ])
      ->condition('uid', $uid)
      ->execute();

    $this->invalidateWalletCache($uid);

    return $balance;
  }

  /**
   * Returns the total amount reserved by pending withdrawal requests.
   */
  public function getPendingWithdrawalTotal(int $uid): float {
    $query = $this->database->select('niftybot_wallet_transactions', 'wt');
    $query->addExpression('SUM(amount)', 'total');
    $query->condition('uid', $uid);
    $query->condition('type', 'withdrawal');
    $query->condition('status', 'pending');

    return (float) ($query->execute()->fetchField() ?? 0);
  }

  /**
   * Returns spendable balance after reserving pending withdrawals.
   */
  public function getAvailableBalance(int $uid): float {
    return max(0, $this->getWalletBalance($uid) - $this->getPendingWithdrawalTotal($uid));
  }

  /**
   * Creates a pending deposit transaction.
   */
  public function createDeposit(int $uid, float $amount, string $payment_method, ?string $payment_reference = NULL, ?string $notes = NULL): int {
    $now = $this->time->getRequestTime();

    $transaction_id = (int) $this->database->insert('niftybot_wallet_transactions')
      ->fields([
        'uid' => $uid,
        'type' => 'deposit',
        'amount' => $amount,
        'status' => 'pending',
        'payment_method' => $payment_method,
        'payment_reference' => $payment_reference,
        'notes' => $notes,
        'balance_applied' => 0,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    $this->invalidateWalletCache($uid);

    return $transaction_id;
  }

  /**
   * Completes a pending deposit and credits the wallet.
   */
  public function completeDeposit(int $transaction_id, ?int $uid = NULL): bool {
    $transaction = $this->loadTransaction($transaction_id);
    if (!$transaction || $transaction->type !== 'deposit' || $transaction->status !== 'pending') {
      return FALSE;
    }
    if ($uid !== NULL && (int) $transaction->uid !== $uid) {
      return FALSE;
    }

    $now = $this->time->getRequestTime();

    if (empty($transaction->balance_applied)) {
      $this->database->update('niftybot_wallet')
        ->expression('balance', 'balance + :amount', [':amount' => $transaction->amount])
        ->condition('uid', $transaction->uid)
        ->execute();
    }

    return $this->markDepositCompleted($transaction_id, TRUE);
  }

  /**
   * Marks a pending deposit as completed without crediting the wallet.
   */
  public function markDepositCompleted(int $transaction_id, bool $balance_applied = FALSE): bool {
    $transaction = $this->loadTransaction($transaction_id);
    if (!$transaction || $transaction->type !== 'deposit' || $transaction->status !== 'pending') {
      return FALSE;
    }

    $now = $this->time->getRequestTime();

    $this->database->update('niftybot_wallet_transactions')
      ->fields([
        'status' => 'completed',
        'balance_applied' => $balance_applied ? 1 : 0,
        'updated' => $now,
      ])
      ->condition('transaction_id', $transaction_id)
      ->condition('status', 'pending')
      ->execute();

    $this->invalidateWalletCache((int) $transaction->uid);

    return TRUE;
  }

  /**
   * Rejects a pending deposit request.
   */
  public function rejectDeposit(int $transaction_id, ?string $notes = NULL): bool {
    $transaction = $this->loadTransaction($transaction_id);
    if (!$transaction || $transaction->type !== 'deposit' || $transaction->status !== 'pending') {
      return FALSE;
    }

    $fields = [
      'status' => 'failed',
      'updated' => $this->time->getRequestTime(),
    ];
    if ($notes !== NULL && $notes !== '') {
      $fields['notes'] = $notes;
    }

    $this->database->update('niftybot_wallet_transactions')
      ->fields($fields)
      ->condition('transaction_id', $transaction_id)
      ->condition('status', 'pending')
      ->execute();

    $this->invalidateWalletCache((int) $transaction->uid);

    return TRUE;
  }

  /**
   * Debits a wallet balance and invalidates cache.
   */
  public function debitBalance(int $uid, float $amount): void {
    $this->database->update('niftybot_wallet')
      ->expression('balance', 'balance - :amount', [':amount' => $amount])
      ->condition('uid', $uid)
      ->execute();

    $this->invalidateWalletCache($uid);
  }

  /**
   * Credits a wallet balance and records a completed deposit transaction.
   */
  public function creditBalance(int $uid, float $amount, string $payment_method, ?string $notes = NULL): void {
    $now = $this->time->getRequestTime();

    $this->database->update('niftybot_wallet')
      ->expression('balance', 'balance + :amount', [':amount' => $amount])
      ->condition('uid', $uid)
      ->execute();

    $this->database->insert('niftybot_wallet_transactions')
      ->fields([
        'uid' => $uid,
        'type' => 'deposit',
        'amount' => $amount,
        'status' => 'completed',
        'payment_method' => $payment_method,
        'notes' => $notes,
        'balance_applied' => 1,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    $this->invalidateWalletCache($uid);
  }

  /**
   * Creates a pending withdrawal request without debiting the wallet.
   */
  public function createWithdrawalRequest(int $uid, float $amount, ?string $notes = NULL): int {
    $now = $this->time->getRequestTime();

    $transaction_id = (int) $this->database->insert('niftybot_wallet_transactions')
      ->fields([
        'uid' => $uid,
        'type' => 'withdrawal',
        'amount' => $amount,
        'status' => 'pending',
        'payment_method' => 'bank_transfer',
        'notes' => $notes,
        'balance_applied' => 0,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    $this->invalidateWalletCache($uid);

    return $transaction_id;
  }

  /**
   * Approves a pending withdrawal and debits the wallet.
   */
  public function completeWithdrawal(int $transaction_id): bool {
    $transaction = $this->loadTransaction($transaction_id);
    if (!$transaction || $transaction->type !== 'withdrawal' || $transaction->status !== 'pending') {
      return FALSE;
    }

    if ($this->getWalletBalance((int) $transaction->uid) < (float) $transaction->amount) {
      return FALSE;
    }

    if (empty($transaction->balance_applied)) {
      $this->debitBalance((int) $transaction->uid, (float) $transaction->amount);
    }

    $this->database->update('niftybot_wallet_transactions')
      ->fields([
        'status' => 'completed',
        'balance_applied' => 1,
        'updated' => $this->time->getRequestTime(),
      ])
      ->condition('transaction_id', $transaction_id)
      ->condition('status', 'pending')
      ->execute();

    $this->invalidateWalletCache((int) $transaction->uid);

    return TRUE;
  }

  /**
   * Rejects a pending withdrawal request.
   */
  public function rejectWithdrawal(int $transaction_id, ?string $notes = NULL): bool {
    $transaction = $this->loadTransaction($transaction_id);
    if (!$transaction || $transaction->type !== 'withdrawal' || $transaction->status !== 'pending') {
      return FALSE;
    }

    $fields = [
      'status' => 'failed',
      'updated' => $this->time->getRequestTime(),
    ];
    if ($notes !== NULL && $notes !== '') {
      $fields['notes'] = $notes;
    }

    $this->database->update('niftybot_wallet_transactions')
      ->fields($fields)
      ->condition('transaction_id', $transaction_id)
      ->condition('status', 'pending')
      ->execute();

    $this->invalidateWalletCache((int) $transaction->uid);

    return TRUE;
  }

  /**
   * Loads a wallet transaction by ID.
   */
  public function loadTransaction(int $transaction_id): ?object {
    $transaction = $this->database->select('niftybot_wallet_transactions', 'wt')
      ->fields('wt')
      ->condition('transaction_id', $transaction_id)
      ->execute()
      ->fetchObject();

    return $transaction ?: NULL;
  }

  /**
   * Returns recent transactions for a user.
   */
  public function getUserTransactions(int $uid, int $limit = 50, string $filter = 'all'): array {
    if (!in_array($filter, self::TRANSACTION_FILTERS, TRUE)) {
      $filter = 'all';
    }

    $query = $this->database->select('niftybot_wallet_transactions', 'wt')
      ->fields('wt')
      ->condition('uid', $uid);

    $this->applyTransactionFilter($query, $filter);

    return $query
      ->orderBy('created', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Filter options for the wallet transaction history UI.
   *
   * @return array<int, array{id: string, label: \Drupal\Core\StringTranslation\TranslatableMarkup}>
   */
  public function getTransactionFilterOptions(): array {
    return [
      ['id' => 'all', 'label' => t('All')],
      ['id' => 'deposit', 'label' => t('Deposits')],
      ['id' => 'withdrawal', 'label' => t('Withdrawals')],
      ['id' => 'investment', 'label' => t('Investments')],
      ['id' => 'algo_trade', 'label' => t('Algo trade fees')],
    ];
  }

  /**
   * Applies a transaction category filter to a wallet query.
   */
  protected function applyTransactionFilter($query, string $filter): void {
    switch ($filter) {
      case 'deposit':
        $query->condition('type', 'deposit');
        break;

      case 'algo_trade':
        $query->condition('payment_method', 'algo_trade');
        break;

      case 'investment':
        $query->condition('type', 'withdrawal');
        $or = $query->orConditionGroup()
          ->condition('payment_method', ['fxc_investment', 'subscription'], 'IN')
          ->condition('notes', '%StrikeFlow Investment%', 'LIKE')
          ->condition('notes', '%FXC Global investment%', 'LIKE');
        $subscription_wallet = $query->andConditionGroup()
          ->condition('payment_method', 'wallet')
          ->condition('notes', 'Subscription:%', 'LIKE');
        $or->condition($subscription_wallet);
        $query->condition($or);
        break;

      case 'withdrawal':
        $query->condition('type', 'withdrawal');
        $query->condition('payment_method', ['fxc_investment', 'subscription', 'algo_trade'], 'NOT IN');
        $query->condition('notes', '%StrikeFlow Investment%', 'NOT LIKE');
        $query->condition('notes', '%FXC Global investment%', 'NOT LIKE');
        $subscription_wallet = $query->andConditionGroup()
          ->condition('payment_method', 'wallet')
          ->condition('notes', 'Subscription:%', 'LIKE');
        $query->condition($subscription_wallet, NULL, 'NOT');
        break;
    }
  }

  /**
   * Whether a withdrawal row is an algo trade platform fee.
   */
  public function isAlgoTradeFeeTransaction(object $transaction): bool {
    return ($transaction->type ?? '') === 'withdrawal'
      && ($transaction->payment_method ?? '') === 'algo_trade';
  }

  /**
   * Whether a withdrawal row is an in-platform investment (not a bank payout).
   */
  public function isInvestmentTransaction(object $transaction): bool {
    if (($transaction->type ?? '') !== 'withdrawal') {
      return FALSE;
    }

    $method = (string) ($transaction->payment_method ?? '');
    if (in_array($method, ['fxc_investment', 'subscription'], TRUE)) {
      return TRUE;
    }

    $notes = (string) ($transaction->notes ?? '');
    if ($method === 'wallet' && str_starts_with($notes, 'Subscription:')) {
      return TRUE;
    }

    return str_contains($notes, 'StrikeFlow Investment')
      || str_contains($notes, 'FXC Global investment');
  }

  /**
   * User-facing labels for wallet transaction history rows.
   *
   * @return array{type: string, type_label: \Drupal\Core\StringTranslation\TranslatableMarkup, method_label: \Drupal\Core\StringTranslation\TranslatableMarkup, badge_class: string}
   */
  public function getTransactionDisplayLabels(object $transaction): array {
    if (($transaction->type ?? '') === 'deposit') {
      return [
        'type' => 'deposit',
        'type_label' => t('Deposit'),
        'method_label' => $this->formatPaymentMethodLabel($transaction->payment_method ?? ''),
        'badge_class' => 'shade-light-green green',
      ];
    }

    if ($this->isInvestmentTransaction($transaction)) {
      return [
        'type' => 'investment',
        'type_label' => t('Investment'),
        'method_label' => t('Investment'),
        'badge_class' => 'shade-light-purple purple',
      ];
    }

    if ($this->isAlgoTradeFeeTransaction($transaction)) {
      return [
        'type' => 'algo_trade',
        'type_label' => t('Algo trade fee'),
        'method_label' => t('Auto trade'),
        'badge_class' => 'shade-light-orange orange',
      ];
    }

    return [
      'type' => 'withdrawal',
      'type_label' => t('Withdrawal'),
      'method_label' => $this->formatPaymentMethodLabel($transaction->payment_method ?? ''),
      'badge_class' => 'shade-light-blue blue',
    ];
  }

  /**
   * Formats stored payment method codes for display.
   */
  protected function formatPaymentMethodLabel(string $payment_method): string {
    return match ($payment_method) {
      'upi' => (string) t('UPI'),
      'bank_transfer' => (string) t('Bank transfer'),
      'card' => (string) t('Card'),
      'wallet' => (string) t('Wallet'),
      'algo_trade' => (string) t('Algo trade fee'),
      'subscription' => (string) t('Subscription'),
      'fxc_investment' => (string) t('Investment'),
      default => ucwords(str_replace('_', ' ', $payment_method)),
    };
  }

}
