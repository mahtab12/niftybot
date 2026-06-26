<?php

namespace Drupal\niftybot_core\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Stores and verifies per-user broker API credentials.
 */
class BrokerConnectionService {

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
        'status' => 'disconnected',
        'status_message' => '',
        'broker_user_id' => '',
        'ucc' => '',
        'balance' => NULL,
        'profile' => [],
      ];
    }

    $profile = [];
    if (!empty($connection->profile_data)) {
      $decoded = json_decode($connection->profile_data, TRUE);
      if (is_array($decoded)) {
        $profile = $decoded;
      }
    }

    return [
      'broker' => $connection->broker,
      'connected' => $connection->status === 'connected',
      'status' => $connection->status,
      'status_message' => $connection->status_message ?? '',
      'broker_user_id' => $connection->broker_user_id ?? '',
      'ucc' => $connection->ucc ?? '',
      'balance' => $connection->balance !== NULL ? (float) $connection->balance : NULL,
      'last_verified' => (int) ($connection->last_verified ?? 0),
      'profile' => $profile,
    ];
  }

  /**
   * Verifies credentials with the trading service and persists the result.
   *
   * @return array{success: bool, message: string, summary?: array<string, mixed>}
   */
  public function saveAndVerify(
    int $uid,
    string $broker,
    string $api_key,
    string $api_secret,
  ): array {
    $now = \Drupal::time()->getRequestTime();
    $result = $this->brokerManager->verifyBrokerCredentials($broker, $api_key, $api_secret);
    $profile = is_array($result['profile'] ?? NULL) ? $result['profile'] : [];
    $profile_ok = !empty($profile['success']);

    if (!$result || !$profile_ok) {
      $message = $profile['message'] ?? ($result['message'] ?? 'Verification failed.');
      $this->upsertConnection($uid, $broker, [
        'api_key_enc' => $this->vault->encrypt($api_key),
        'api_secret_enc' => $this->vault->encrypt($api_secret),
        'status' => 'error',
        'status_message' => $message,
        'updated' => $now,
        'created' => $now,
      ]);
      return [
        'success' => FALSE,
        'message' => $message,
      ];
    }

    $profile = is_array($result['profile'] ?? NULL) ? $result['profile'] : [];
    $margin = is_array($result['margin'] ?? NULL) ? $result['margin'] : [];
    $balance = $this->extractBalance($margin);

    $this->upsertConnection($uid, $broker, [
      'api_key_enc' => $this->vault->encrypt($api_key),
      'api_secret_enc' => $this->vault->encrypt($api_secret),
      'status' => 'connected',
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
      'created' => $now,
    ]);

    return [
      'success' => TRUE,
      'message' => $result['message'] ?? 'Broker connected successfully.',
      'summary' => $this->getDashboardSummary($uid, $broker),
    ];
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
    if (!$connection || $connection->status !== 'connected') {
      return [];
    }
    if (empty($connection->api_key_enc) || empty($connection->api_secret_enc)) {
      return [];
    }

    $api_key = $this->vault->decrypt($connection->api_key_enc);
    $api_secret = $this->vault->decrypt($connection->api_secret_enc);
    if ($api_key === '' || $api_secret === '') {
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
