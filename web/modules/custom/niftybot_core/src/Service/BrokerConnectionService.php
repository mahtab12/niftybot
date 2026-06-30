<?php

namespace Drupal\niftybot_core\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Stores and verifies per-user broker API credentials.
 */
class BrokerConnectionService {

  public const STATUS_CONNECTED = 'connected';

  public const STATUS_PENDING_APPROVAL = 'pending_approval';

  public const STATUS_ERROR = 'error';

  public const STATUS_DISCONNECTED = 'disconnected';

  public const GROWW_API_KEYS_URL = 'https://groww.in/trade-api';

  public function __construct(
    protected Connection $database,
    protected CredentialVault $vault,
    protected BrokerManager $brokerManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Loads a user's broker connection row.
   */
  public function getConnection(int $uid, string $broker = 'groww'): ?object {
    $row = $this->database->select('niftybot_broker_connections', 'bc')
      ->fields('bc')
      ->condition('uid', $uid)
      ->condition('broker', $broker)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Returns a dashboard-friendly broker summary for the user.
   *
   * @return array<string, mixed>
   */
  public function getDashboardSummary(int $uid, string $broker = 'groww'): array {
    $connection = $this->getConnection($uid, $broker);
    if (!$connection) {
      return [
        'broker' => $broker,
        'connected' => FALSE,
        'credentials_saved' => FALSE,
        'pending_approval' => FALSE,
        'needs_groww_activation' => FALSE,
        'status' => self::STATUS_DISCONNECTED,
        'status_message' => '',
        'broker_user_id' => '',
        'ucc' => '',
        'balance' => NULL,
        'profile' => [],
        'groww_api_keys_url' => self::GROWW_API_KEYS_URL,
        'masked_api_key' => '',
        'masked_api_secret' => '',
        'has_stored_secret' => FALSE,
      ];
    }

    $profile = [];
    if (!empty($connection->profile_data)) {
      $decoded = json_decode($connection->profile_data, TRUE);
      if (is_array($decoded)) {
        $profile = $decoded;
      }
    }

    $credentials_saved = $this->hasStoredCredentials($connection);
    $connected = $connection->status === self::STATUS_CONNECTED;
    $pending_approval = $connection->status === self::STATUS_PENDING_APPROVAL;
    $stored_display = $this->getStoredCredentialDisplay($uid, $broker);

    return [
      'broker' => $connection->broker,
      'connected' => $connected,
      'credentials_saved' => $credentials_saved,
      'pending_approval' => $pending_approval,
      'needs_groww_activation' => $credentials_saved && !$connected,
      'status' => $connection->status,
      'status_message' => $connection->status_message ?? '',
      'broker_user_id' => $connection->broker_user_id ?? '',
      'ucc' => $connection->ucc ?? '',
      'balance' => $connection->balance !== NULL ? (float) $connection->balance : NULL,
      'last_verified' => (int) ($connection->last_verified ?? 0),
      'profile' => $profile,
      'groww_api_keys_url' => self::GROWW_API_KEYS_URL,
      'masked_api_key' => $stored_display['api_key'],
      'masked_api_secret' => $stored_display['api_secret'],
      'has_stored_secret' => $stored_display['has_secret'],
    ];
  }

  /**
   * Whether encrypted API credentials exist for the user.
   */
  public function hasStoredCredentials(?object $connection = NULL, int $uid = 0, string $broker = 'groww'): bool {
    if ($connection === NULL) {
      $connection = $this->getConnection($uid, $broker);
    }
    return $connection && !empty($connection->api_key_enc);
  }

  /**
   * Masked display values for stored credentials (never returns full secrets).
   *
   * @return array{api_key: string, api_secret: string, has_secret: bool}
   */
  public function getStoredCredentialDisplay(int $uid, string $broker = 'groww'): array {
    $empty = [
      'api_key' => '',
      'api_secret' => '',
      'has_secret' => FALSE,
    ];
    $connection = $this->getConnection($uid, $broker);
    if (!$connection || empty($connection->api_key_enc)) {
      return $empty;
    }

    $api_key = $this->vault->decrypt($connection->api_key_enc);
    if ($api_key === '') {
      return $empty;
    }

    $api_secret = '';
    if (!empty($connection->api_secret_enc)) {
      $api_secret = $this->vault->decrypt($connection->api_secret_enc);
    }

    return [
      'api_key' => $this->maskCredential($api_key),
      'api_secret' => $api_secret !== '' ? str_repeat('×', 16) : '',
      'has_secret' => $api_secret !== '',
    ];
  }

  /**
   * Returns a masked version of an API key or token for display.
   */
  public function maskCredential(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    if ($this->looksLikeAccessToken($value)) {
      $length = strlen($value);
      if ($length <= 12) {
        return str_repeat('×', $length);
      }
      return substr($value, 0, 8) . str_repeat('×', 12) . substr($value, -4);
    }

    $visible = min(10, max(6, (int) ceil(strlen($value) * 0.3)));
    return substr($value, 0, $visible) . str_repeat('×', 12);
  }

  /**
   * Re-attempts Groww auth when credentials are saved but not connected.
   *
   * @return array<string, mixed>
   */
  public function attemptAutoConnect(int $uid, string $broker = 'groww', int $min_interval = 60): array {
    $connection = $this->getConnection($uid, $broker);
    if (!$this->hasStoredCredentials($connection)) {
      return $this->getDashboardSummary($uid, $broker);
    }
    if ($connection->status === self::STATUS_CONNECTED) {
      return $this->getDashboardSummary($uid, $broker);
    }

    $now = \Drupal::time()->getRequestTime();
    $last_attempt = max((int) ($connection->updated ?? 0), (int) ($connection->last_verified ?? 0));
    if (($now - $last_attempt) < $min_interval) {
      return $this->getDashboardSummary($uid, $broker);
    }

    $api_key = $this->vault->decrypt($connection->api_key_enc);
    $api_secret = $this->vault->decrypt($connection->api_secret_enc ?? '');
    if ($api_key === '') {
      return $this->getDashboardSummary($uid, $broker);
    }
    if ($api_secret === '' && !$this->looksLikeAccessToken($api_key)) {
      return $this->getDashboardSummary($uid, $broker);
    }

    $this->saveAndVerify($uid, $broker, $api_key, $api_secret);
    return $this->getDashboardSummary($uid, $broker);
  }

  /**
   * Saves credentials per user and verifies with Groww when possible.
   *
   * @return array<string, mixed>
   */
  public function saveAndVerify(
    int $uid,
    string $broker,
    string $api_key,
    string $api_secret,
  ): array {
    $api_key = $this->normalizeCredential($api_key);
    $api_secret = $this->normalizeCredential($api_secret);

    if ($api_key === '') {
      return [
        'success' => FALSE,
        'connected' => FALSE,
        'credentials_saved' => FALSE,
        'pending_approval' => FALSE,
        'message' => 'API key and secret are required.',
      ];
    }
    if ($api_secret === '' && !$this->looksLikeAccessToken($api_key)) {
      return [
        'success' => FALSE,
        'connected' => FALSE,
        'credentials_saved' => FALSE,
        'pending_approval' => FALSE,
        'message' => 'API secret is required unless you paste a daily access token in the API key field.',
      ];
    }

    $now = \Drupal::time()->getRequestTime();
    $this->upsertConnection($uid, $broker, [
      'api_key_enc' => $this->vault->encrypt($api_key),
      'api_secret_enc' => $this->vault->encrypt($api_secret),
      'status' => self::STATUS_PENDING_APPROVAL,
      'status_message' => $this->pendingActivationMessage(),
      'updated' => $now,
      'created' => $now,
    ]);

    $result = $this->brokerManager->verifyBrokerCredentials($broker, $api_key, $api_secret);
    $profile = is_array($result['profile'] ?? NULL) ? $result['profile'] : [];
    $profile_ok = !empty($profile['success']);
    $top_ok = !empty($result['success']);
    $approval_required = !empty($result['approval_required']);

    if ($profile_ok || $top_ok) {
      $margin = is_array($result['margin'] ?? NULL) ? $result['margin'] : [];
      $balance = $this->extractBalance($margin);

      $this->upsertConnection($uid, $broker, [
        'status' => self::STATUS_CONNECTED,
        'status_message' => $result['message'] ?? 'Connected successfully.',
        'broker_user_id' => $profile['vendor_user_id'] ?? '',
        'ucc' => $profile['ucc'] ?? '',
        'balance' => $balance,
        'profile_data' => json_encode([
          'vendor_user_id' => $profile['vendor_user_id'] ?? '',
          'ucc' => $profile['ucc'] ?? '',
          'nse_enabled' => !empty($profile['nse_enabled']),
          'bse_enabled' => !empty($profile['bse_enabled']),
          'ddpi_enabled' => !empty($profile['ddpi_enabled']),
          'active_segments' => $profile['active_segments'] ?? [],
          'margin' => $margin,
        ]),
        'last_verified' => $now,
        'updated' => $now,
      ]);

      return [
        'success' => TRUE,
        'connected' => TRUE,
        'credentials_saved' => TRUE,
        'pending_approval' => FALSE,
        'message' => $result['message'] ?? 'Broker connected successfully.',
        'summary' => $this->getDashboardSummary($uid, $broker),
      ];
    }

    $message = $this->resolveVerificationMessage($result, $profile);
    $pending = $approval_required || $this->isApprovalPendingError($message, $result, $profile);

    $this->upsertConnection($uid, $broker, [
      'status' => $pending ? self::STATUS_PENDING_APPROVAL : self::STATUS_ERROR,
      'status_message' => $pending ? $this->pendingActivationMessage() : $message,
      'updated' => $now,
    ]);

    return [
      'success' => FALSE,
      'connected' => FALSE,
      'credentials_saved' => TRUE,
      'pending_approval' => $pending,
      'message' => $pending ? $this->pendingActivationMessage() : $message,
      'summary' => $this->getDashboardSummary($uid, $broker),
    ];
  }

  /**
   * User-facing message when Groww daily API approval is still required.
   */
  public function pendingActivationMessage(): string {
    return (string) t('Your Groww API credentials are saved. Open Groww Cloud → API Keys, approve your key for today, then return here — we will connect automatically.');
  }

  /**
   * Detects Groww auth failures resolved by daily API key approval.
   *
   * @param array<string, mixed>|null $result
   * @param array<string, mixed> $profile
   */
  protected function isApprovalPendingError(string $message, ?array $result, array $profile): bool {
    if (is_array($result) && !empty($result['approval_required'])) {
      return TRUE;
    }
    $haystack = strtolower($message);
    foreach (['approve', 'approval', 'checksum'] as $token) {
      if (str_contains($haystack, $token)) {
        return TRUE;
      }
    }
    if (!empty($profile['message']) && is_string($profile['message'])) {
      $profile_message = strtolower($profile['message']);
      foreach (['approve', 'approval', 'checksum'] as $token) {
        if (str_contains($profile_message, $token)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Whether the value looks like a Groww JWT access token.
   */
  public function looksLikeAccessToken(string $value): bool {
    $value = trim($value);
    return str_starts_with($value, 'eyJ') && substr_count($value, '.') >= 2;
  }

  /**
   * Trims credentials pasted from Groww (removes quotes and line breaks).
   */
  protected function normalizeCredential(string $value): string {
    $value = trim($value);
    $value = str_replace(["\r", "\n", "\t"], '', $value);
    return trim($value, " \t\n\r\0\x0B'\"");
  }

  /**
   * Builds a user-facing verification error message.
   *
   * @param array<string, mixed>|null $result
   * @param array<string, mixed> $profile
   */
  protected function resolveVerificationMessage(?array $result, array $profile): string {
    if (is_array($result) && !empty($result['message']) && is_string($result['message'])) {
      return $this->humanizeGrowwError($result['message']);
    }
    if (!empty($profile['message']) && is_string($profile['message'])) {
      return $this->humanizeGrowwError($profile['message']);
    }
    if (is_array($result) && !empty($result['detail']) && is_string($result['detail'])) {
      return $this->humanizeGrowwError($result['detail']);
    }
    if (!$this->brokerManager->hasApiKey()) {
      return 'Trading service is not configured. Contact the site administrator.';
    }
    return 'Verification failed. Check your Groww API key and secret, then approve the key for today on the Groww Cloud API Keys page.';
  }

  /**
   * Maps common Groww auth failures to clearer guidance.
   */
  protected function humanizeGrowwError(string $message): string {
    $lower = strtolower($message);
    $trimmed = trim($lower);

    // Drupal -> trading service auth, not the user's Groww API key.
    if ($trimmed === 'invalid api key' || $trimmed === 'missing api key') {
      return 'Could not reach the trading verification service. If you are an administrator, confirm the Trading API key in NiftyBot settings matches the trading service API_KEY.';
    }
    if (str_contains($lower, 'authentication failed')
      || str_contains($lower, 'api token')
      || str_contains($lower, 'expired')
      || str_contains($lower, 'is invalid')
      || str_contains($lower, 'daily access token')
      || str_contains($lower, 'permanent api key')) {
      return 'Groww could not authenticate. Use the permanent API Key from Groww Cloud → Generate API Key (not a one-day token). Confirm the API secret matches that key. If you only have a daily token, paste it in the API key field and leave the secret blank.';
    }
    if (str_contains($lower, 'checksum')
      || str_contains($lower, 'approval')
      || str_contains($lower, 'unauthorized')
      || str_contains($lower, '401')
      || str_contains($lower, 'access token')) {
      return 'Groww rejected these credentials. Confirm your API key and secret, then open Groww Cloud → API Keys and approve your key for today before connecting again.';
    }
    if (str_contains($lower, 'forbidden') || str_contains($lower, '403')) {
      return 'Could not reach the trading verification service. If you are an administrator, confirm the Trading API key in NiftyBot settings matches the trading service API_KEY.';
    }
    if (str_contains($lower, 'connect')
      || str_contains($lower, 'timed out')
      || str_contains($lower, 'connection')) {
      return 'Could not reach Groww to verify your credentials. Please try again in a moment.';
    }
    return $message;
  }

  /**
   * Re-verifies stored credentials and refreshes cached profile data.
   */
  public function refreshConnection(int $uid, string $broker = 'groww'): array {
    $connection = $this->getConnection($uid, $broker);
    if (!$connection || empty($connection->api_key_enc) || empty($connection->api_secret_enc)) {
      return [
        'success' => FALSE,
        'message' => 'No stored credentials found.',
      ];
    }

    $api_key = $this->vault->decrypt($connection->api_key_enc);
    $api_secret = $this->vault->decrypt($connection->api_secret_enc);
    if ($api_key === '' || $api_secret === '') {
      return [
        'success' => FALSE,
        'message' => 'Stored credentials could not be decrypted.',
      ];
    }

    return $this->saveAndVerify($uid, $broker, $api_key, $api_secret);
  }

  /**
   * Inserts or updates a broker connection row.
   *
   * @param array<string, mixed> $fields
   */
  protected function upsertConnection(int $uid, string $broker, array $fields): void {
    $existing = $this->getConnection($uid, $broker);
    if ($existing) {
      unset($fields['created']);
      $this->database->update('niftybot_broker_connections')
        ->fields($fields)
        ->condition('connection_id', $existing->connection_id)
        ->execute();
      return;
    }

    $fields['uid'] = $uid;
    $fields['broker'] = $broker;
    $this->database->insert('niftybot_broker_connections')
      ->fields($fields)
      ->execute();
  }

  /**
   * Picks the best available cash balance from margin payload.
   */
  protected function extractBalance(array $margin): ?float {
    if (isset($margin['clear_cash']) && $margin['clear_cash'] !== NULL) {
      return (float) $margin['clear_cash'];
    }
    $equity = $margin['equity_margin_details'] ?? [];
    if (is_array($equity) && isset($equity['available_cash'])) {
      return (float) $equity['available_cash'];
    }
    return NULL;
  }

  /**
   * Loads recent F&O orders for the user's connected Groww account.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getUserTradeHistory(int $uid, string $instrument, int $limit = 15): array {
    $connection = $this->getConnection($uid, 'groww');
    if (!$connection || $connection->status !== self::STATUS_CONNECTED) {
      return [];
    }
    if (empty($connection->api_key_enc)) {
      return [];
    }

    $api_key = $this->vault->decrypt($connection->api_key_enc);
    $api_secret = !empty($connection->api_secret_enc)
      ? $this->vault->decrypt($connection->api_secret_enc)
      : '';
    if ($api_key === '') {
      return [];
    }
    if ($api_secret === '' && !$this->looksLikeAccessToken($api_key)) {
      return [];
    }

    $response = $this->brokerManager->getUserOrderBook(
      'groww',
      $api_key,
      $api_secret,
      $instrument,
      $limit,
    );
    if (empty($response['success']) || !is_array($response['orders'] ?? NULL)) {
      return [];
    }

    return array_values($response['orders']);
  }

}
