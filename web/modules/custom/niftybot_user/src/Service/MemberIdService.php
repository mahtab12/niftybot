<?php

namespace Drupal\niftybot_user\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;

/**
 * Generates and stores unique member IDs (e.g. NBX00001).
 */
class MemberIdService {

  /**
   * State key for the next member sequence number.
   */
  public const SEQUENCE_STATE_KEY = 'niftybot_user.member_sequence';

  /**
   * Lock name for atomic ID generation.
   */
  public const LOCK_NAME = 'niftybot_user.member_id';

  /**
   * Default 3-letter prefix when not configured.
   */
  public const DEFAULT_PREFIX = 'NBX';

  /**
   * Constructs the service.
   */
  public function __construct(
    protected Connection $database,
    protected StateInterface $state,
    protected LockBackendInterface $lock,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the configured 3-letter member ID prefix.
   */
  public function getPrefix(): string {
    $prefix = strtoupper((string) $this->configFactory
      ->get('niftybot_core.settings')
      ->get('member_id_prefix'));

    if (!preg_match('/^[A-Z]{3}$/', $prefix)) {
      return self::DEFAULT_PREFIX;
    }

    return $prefix;
  }

  /**
   * Returns the member ID for a user, if assigned.
   */
  public function getMemberId(int $uid): ?string {
    if ($uid <= 0) {
      return NULL;
    }

    $member_id = $this->database->select('niftybot_user_member_ids', 'm')
      ->fields('m', ['member_id'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    return $member_id ? (string) $member_id : NULL;
  }

  /**
   * Assigns a unique member ID to a user.
   *
   * @return string
   *   The assigned member ID.
   *
   * @throws \RuntimeException
   *   If a member ID could not be generated.
   */
  public function assignMemberId(int $uid): string {
    $existing = $this->getMemberId($uid);
    if ($existing !== NULL) {
      return $existing;
    }

    $member_id = $this->generateUniqueMemberId();
    $now = time();

    try {
      $this->database->insert('niftybot_user_member_ids')
        ->fields([
          'uid' => $uid,
          'member_id' => $member_id,
          'created' => $now,
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $existing = $this->getMemberId($uid);
      if ($existing !== NULL) {
        return $existing;
      }
      throw new \RuntimeException('Unable to assign member ID.', 0, $e);
    }

    return $member_id;
  }

  /**
   * Generates the next unique member ID with locking.
   */
  protected function generateUniqueMemberId(): string {
    $attempts = 0;
    while (!$this->lock->acquire(self::LOCK_NAME)) {
      $this->lock->wait(self::LOCK_NAME);
      $attempts++;
      if ($attempts > 50) {
        throw new \RuntimeException('Timed out waiting for member ID lock.');
      }
    }

    try {
      $prefix = $this->getPrefix();
      $sequence = max(
        (int) $this->state->get(self::SEQUENCE_STATE_KEY, 0),
        $this->getHighestSequenceForPrefix($prefix),
      );

      do {
        $sequence++;
        $member_id = $prefix . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
        $exists = (bool) $this->database->select('niftybot_user_member_ids', 'm')
          ->condition('member_id', $member_id)
          ->countQuery()
          ->execute()
          ->fetchField();
      } while ($exists);

      $this->state->set(self::SEQUENCE_STATE_KEY, $sequence);
      return $member_id;
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }
  }

  /**
   * Highest numeric suffix already stored for the given prefix.
   */
  protected function getHighestSequenceForPrefix(string $prefix): int {
    $pattern = $prefix . '[0-9]{5}';
    $ids = $this->database->select('niftybot_user_member_ids', 'm')
      ->fields('m', ['member_id'])
      ->condition('member_id', $this->database->escapeLike($prefix) . '%', 'LIKE')
      ->execute()
      ->fetchCol();

    $highest = 0;
    foreach ($ids as $member_id) {
      $suffix = (int) substr((string) $member_id, 3);
      if ($suffix > $highest) {
        $highest = $suffix;
      }
    }

    return $highest;
  }

}
