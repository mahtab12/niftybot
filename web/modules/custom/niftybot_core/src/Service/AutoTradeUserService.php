<?php

namespace Drupal\niftybot_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\niftybot_user\Service\WalletService;

/**
 * Per-user auto-trade access, quantity settings, and pay-per-trade billing.
 */
class AutoTradeUserService {

  public const NIFTY_LOT_STEP = 65;

  public const SENSEX_LOT_STEP = 20;

  public const CRUDE_OIL_LOT_STEP = 10;

  public const GOLD_LOT_STEP = 100;

  /**
   * Supported auto-trade instruments and lot steps.
   *
   * @var array<string, int>
   */
  public const LOT_STEPS = [
    'nifty' => self::NIFTY_LOT_STEP,
    'sensex' => self::SENSEX_LOT_STEP,
    'crude_oil' => self::CRUDE_OIL_LOT_STEP,
    'gold' => self::GOLD_LOT_STEP,
  ];

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected WalletService $walletService,
    protected BrokerManager $brokerManager,
    protected BrokerConnectionService $brokerConnection,
    protected TimeInterface $time,
  ) {}

  /**
   * Per-trade fee in INR (default ₹100).
   */
  public function getPerTradeFee(): float {
    return (float) ($this->configFactory->get('niftybot_core.settings')->get('auto_trade_per_trade_fee') ?? 100);
  }

  /**
   * Minimum wallet balance to use pay-per-trade auto-trade.
   */
  public function getMinWalletBalance(): float {
    return (float) ($this->configFactory->get('niftybot_core.settings')->get('auto_trade_min_wallet_balance') ?? 100);
  }

  /**
   * Lot step for an instrument.
   */
  public function lotStep(string $instrument): int {
    $key = strtolower($instrument);
    return self::LOT_STEPS[$key] ?? self::NIFTY_LOT_STEP;
  }

  /**
   * Settings field name for an instrument quantity.
   */
  public function quantityKey(string $instrument): string {
    return match (strtolower($instrument)) {
      'sensex' => 'sensex_quantity',
      'crude_oil' => 'crude_oil_quantity',
      'gold' => 'gold_quantity',
      default => 'nifty_quantity',
    };
  }

  /**
   * Lot steps for all instruments (API / UI).
   *
   * @return array<string, int>
   */
  public function getLotSteps(): array {
    return self::LOT_STEPS;
  }

  /**
   * Default quantity for an instrument.
   */
  public function defaultQuantity(string $instrument): int {
    return $this->lotStep($instrument);
  }

  /**
   * Validates quantity is a positive multiple of the instrument lot step.
   */
  public function validateQuantity(string $instrument, int $quantity): bool {
    $step = $this->lotStep($instrument);
    return $quantity >= $step && $quantity % $step === 0;
  }

  /**
   * Loads or returns default quantity settings for a user.
   *
   * @return array<string, int>
   */
  public function getSettings(int $uid): array {
    $row = $this->database->select('niftybot_user_auto_trade_settings', 's')
      ->fields('s')
      ->condition('uid', $uid)
      ->execute()
      ->fetchObject();

    if ($row) {
      return [
        'nifty_quantity' => (int) $row->nifty_quantity,
        'sensex_quantity' => (int) $row->sensex_quantity,
        'crude_oil_quantity' => isset($row->crude_oil_quantity)
          ? (int) $row->crude_oil_quantity
          : self::CRUDE_OIL_LOT_STEP,
        'gold_quantity' => isset($row->gold_quantity)
          ? (int) $row->gold_quantity
          : self::GOLD_LOT_STEP,
      ];
    }

    return [
      'nifty_quantity' => self::NIFTY_LOT_STEP,
      'sensex_quantity' => self::SENSEX_LOT_STEP,
      'crude_oil_quantity' => self::CRUDE_OIL_LOT_STEP,
      'gold_quantity' => self::GOLD_LOT_STEP,
    ];
  }

  /**
   * Quantity for a specific instrument.
   */
  public function getQuantityForInstrument(int $uid, string $instrument): int {
    $settings = $this->getSettings($uid);
    $key = $this->quantityKey($instrument);
    return $settings[$key];
  }

  /**
   * Persists quantity settings after validation.
   */
  public function saveSettings(
    int $uid,
    int $nifty_quantity,
    int $sensex_quantity,
    ?int $crude_oil_quantity = NULL,
    ?int $gold_quantity = NULL,
  ): void {
    $current = $this->getSettings($uid);
    $crude_oil_quantity = $crude_oil_quantity ?? $current['crude_oil_quantity'];
    $gold_quantity = $gold_quantity ?? $current['gold_quantity'];

    foreach ([
      'nifty' => $nifty_quantity,
      'sensex' => $sensex_quantity,
      'crude_oil' => $crude_oil_quantity,
      'gold' => $gold_quantity,
    ] as $instrument => $quantity) {
      if (!$this->validateQuantity($instrument, $quantity)) {
        $step = $this->lotStep($instrument);
        throw new \InvalidArgumentException(
          ucfirst(str_replace('_', ' ', $instrument)) . " quantity must be a multiple of {$step}."
        );
      }
    }

    $now = $this->time->getRequestTime();
    $existing = $this->database->select('niftybot_user_auto_trade_settings', 's')
      ->fields('s', ['uid'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    $fields = [
      'nifty_quantity' => $nifty_quantity,
      'sensex_quantity' => $sensex_quantity,
      'crude_oil_quantity' => $crude_oil_quantity,
      'gold_quantity' => $gold_quantity,
      'updated' => $now,
    ];

    if ($existing) {
      $this->database->update('niftybot_user_auto_trade_settings')
        ->fields($fields)
        ->condition('uid', $uid)
        ->execute();
    }
    else {
      $fields['uid'] = $uid;
      $this->database->insert('niftybot_user_auto_trade_settings')
        ->fields($fields)
        ->execute();
    }
  }

  /**
   * Whether the user has an active subscription.
   */
  public function hasActiveSubscription(int $uid): bool {
    if (!$this->database->schema()->tableExists('niftybot_user_subscriptions')) {
      return FALSE;
    }

    $now = $this->time->getRequestTime();
    $sub_id = $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us', ['subscription_id'])
      ->condition('uid', $uid)
      ->condition('status', 'active')
      ->condition('end_date', $now, '>=')
      ->execute()
      ->fetchField();

    return (bool) $sub_id;
  }

  /**
   * Access summary for auto-trade UI and API.
   *
   * @return array{
   *   can_access: bool,
   *   billing_mode: string|null,
   *   per_trade_fee: float,
   *   min_wallet_balance: float,
   *   wallet_balance: float,
   *   has_subscription: bool,
   *   message: string
   * }
   */
  public function getAccessInfo(int $uid): array {
    $fee = $this->getPerTradeFee();
    $min = $this->getMinWalletBalance();
    $balance = $this->walletService->getWalletBalance($uid);
    $has_sub = $this->hasActiveSubscription($uid);

    if ($has_sub) {
      return [
        'can_access' => TRUE,
        'billing_mode' => 'subscription',
        'per_trade_fee' => $fee,
        'min_wallet_balance' => $min,
        'wallet_balance' => $balance,
        'has_subscription' => TRUE,
        'message' => '',
      ];
    }

    if ($balance >= $min) {
      return [
        'can_access' => TRUE,
        'billing_mode' => 'pay_per_trade',
        'per_trade_fee' => $fee,
        'min_wallet_balance' => $min,
        'wallet_balance' => $balance,
        'has_subscription' => FALSE,
        'message' => '',
      ];
    }

    return [
      'can_access' => FALSE,
      'billing_mode' => NULL,
      'per_trade_fee' => $fee,
      'min_wallet_balance' => $min,
      'wallet_balance' => $balance,
      'has_subscription' => FALSE,
      'message' => 'Add at least ₹' . number_format($min, 0) . ' to your wallet or subscribe to a monthly plan.',
    ];
  }

  /**
   * Whether the user can afford another pay-per-trade execution.
   */
  public function canAffordTrade(int $uid): bool {
    $info = $this->getAccessInfo($uid);
    if (!$info['can_access']) {
      return FALSE;
    }
    if ($info['billing_mode'] === 'subscription') {
      return TRUE;
    }
    return $info['wallet_balance'] >= $info['per_trade_fee'];
  }

  /**
   * Debits per-trade fee after algo trade execution (pay-per-trade users only).
   */
  public function chargeTradeFee(int $uid, string $instrument, string $trade_id): bool {
    $info = $this->getAccessInfo($uid);
    if ($info['billing_mode'] !== 'pay_per_trade') {
      return TRUE;
    }

    $fee = $info['per_trade_fee'];
    if ($info['wallet_balance'] < $fee) {
      return FALSE;
    }

    $existing = $this->database->select('niftybot_user_auto_trade_fees', 'f')
      ->fields('f', ['fee_id'])
      ->condition('uid', $uid)
      ->condition('trade_id', $trade_id)
      ->execute()
      ->fetchField();
    if ($existing) {
      return TRUE;
    }

    $now = $this->time->getRequestTime();
    $this->walletService->debitBalance($uid, $fee);

    $this->database->insert('niftybot_wallet_transactions')
      ->fields([
        'uid' => $uid,
        'type' => 'withdrawal',
        'amount' => $fee,
        'status' => 'completed',
        'payment_method' => 'algo_trade',
        'payment_reference' => $trade_id,
        'notes' => 'Algo trade fee — ' . strtoupper($instrument),
        'balance_applied' => 1,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    $this->database->insert('niftybot_user_auto_trade_fees')
      ->fields([
        'uid' => $uid,
        'instrument' => strtolower($instrument),
        'trade_id' => $trade_id,
        'amount' => $fee,
        'created' => $now,
      ])
      ->execute();

    return TRUE;
  }

  /**
   * Registers the user auto-trade session with the Python trading service.
   *
   * @return array<string, mixed>|null
   */
  public function activateUserAutoTrade(int $uid, string $instrument, string $mode): ?array {
    $connection = $this->brokerConnection->getConnection($uid, 'groww');
    if (!$connection || $connection->status !== BrokerConnectionService::STATUS_CONNECTED) {
      return [
        'success' => FALSE,
        'message' => 'Connect your Groww broker account first.',
      ];
    }

    $verify = $this->brokerConnection->verifyStoredCredentials($uid, 'groww');
    if (empty($verify['connected'])) {
      return [
        'success' => FALSE,
        'message' => (string) ($verify['message'] ?? 'Groww broker connection is no longer valid. Update your API credentials and try again.'),
      ];
    }

    $connection = $this->brokerConnection->getConnection($uid, 'groww');
    $vault = \Drupal::service('niftybot_core.credential_vault');
    $api_key = $vault->decrypt($connection->api_key_enc);
    $api_secret = $vault->decrypt($connection->api_secret_enc ?? '');
    if ($api_key === '') {
      return [
        'success' => FALSE,
        'message' => 'Stored broker credentials could not be loaded.',
      ];
    }
    if ($api_secret === '' && !$this->brokerConnection->looksLikeAccessToken($api_key)) {
      return [
        'success' => FALSE,
        'message' => 'Stored broker credentials could not be loaded.',
      ];
    }

    $quantity = $this->getQuantityForInstrument($uid, $instrument);
    if (!$this->validateQuantity($instrument, $quantity)) {
      return [
        'success' => FALSE,
        'message' => 'Invalid trade quantity for this instrument.',
      ];
    }

    $access = $this->getAccessInfo($uid);
    if (!$access['can_access']) {
      return [
        'success' => FALSE,
        'message' => $access['message'],
      ];
    }

    if ($access['billing_mode'] === 'pay_per_trade' && !$this->canAffordTrade($uid)) {
      return [
        'success' => FALSE,
        'message' => 'Insufficient wallet balance for the ₹' . number_format($access['per_trade_fee'], 0) . ' per-trade fee.',
      ];
    }

    return $this->brokerManager->activateUserAutoTrade($uid, $instrument, [
      'mode' => $mode,
      'quantity' => $quantity,
      'api_key' => $api_key,
      'api_secret' => $api_secret,
      'billing_mode' => $access['billing_mode'],
      'per_trade_fee' => $access['per_trade_fee'],
      'webhook_url' => $this->getBillingWebhookUrl(),
    ]);
  }

  /**
   * Internal URL for trading service to charge per-trade fees.
   */
  public function getBillingWebhookUrl(): string {
    return \Drupal::request()->getSchemeAndHttpHost() . '/niftybot/internal/auto-trade/charge';
  }

  /**
   * Validates trading service API key on internal webhooks.
   */
  public function isValidTradingApiKey(?string $api_key): bool {
    if ($api_key === NULL || $api_key === '') {
      return FALSE;
    }
    $expected = $this->configFactory->get('niftybot_core.settings')->get('trading_api_key');
    if (!is_string($expected) || $expected === '') {
      return FALSE;
    }
    return hash_equals($expected, $api_key);
  }

  /**
   * Recent per-trade fee charges for a user.
   *
   * @return array<int, array{fee_id: int, instrument: string, trade_id: string, amount: float, created: int}>
   */
  public function getFeeHistory(int $uid, ?string $instrument = NULL, int $limit = 15): array {
    if (!$this->database->schema()->tableExists('niftybot_user_auto_trade_fees')) {
      return [];
    }

    $query = $this->database->select('niftybot_user_auto_trade_fees', 'f')
      ->fields('f')
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->range(0, $limit);

    if ($instrument !== NULL && $instrument !== '') {
      $query->condition('instrument', strtolower($instrument));
    }

    $rows = $query->execute()->fetchAll();
    $history = [];
    foreach ($rows as $row) {
      $history[] = [
        'fee_id' => (int) $row->fee_id,
        'instrument' => (string) $row->instrument,
        'trade_id' => (string) $row->trade_id,
        'amount' => (float) $row->amount,
        'created' => (int) $row->created,
      ];
    }

    return $history;
  }

  /**
   * Summary stats for per-trade fee charges.
   *
   * @return array{
   *   total_fees: float,
   *   trade_count: int,
   *   month_fees: float,
   *   month_count: int
   * }
   */
  public function getFeeSummary(int $uid, ?string $instrument = NULL): array {
    if (!$this->database->schema()->tableExists('niftybot_user_auto_trade_fees')) {
      return [
        'total_fees' => 0.0,
        'trade_count' => 0,
        'month_fees' => 0.0,
        'month_count' => 0,
      ];
    }

    $query = $this->database->select('niftybot_user_auto_trade_fees', 'f')
      ->condition('uid', $uid);

    if ($instrument !== NULL && $instrument !== '') {
      $query->condition('instrument', strtolower($instrument));
    }

    $query->addExpression('COUNT(*)', 'trade_count');
    $query->addExpression('SUM(amount)', 'total_fees');
    $summary = $query->execute()->fetchObject();

    $month_start = strtotime('first day of this month 00:00:00');
    $month_query = $this->database->select('niftybot_user_auto_trade_fees', 'f')
      ->condition('uid', $uid)
      ->condition('created', $month_start, '>=');

    if ($instrument !== NULL && $instrument !== '') {
      $month_query->condition('instrument', strtolower($instrument));
    }

    $month_query->addExpression('COUNT(*)', 'month_count');
    $month_query->addExpression('SUM(amount)', 'month_fees');
    $month = $month_query->execute()->fetchObject();

    return [
      'total_fees' => (float) ($summary->total_fees ?? 0),
      'trade_count' => (int) ($summary->trade_count ?? 0),
      'month_fees' => (float) ($month->month_fees ?? 0),
      'month_count' => (int) ($month->month_count ?? 0),
    ];
  }

}
