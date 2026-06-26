<?php

namespace Drupal\niftybot_core\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * In-app trade alert notifications (per-user read state).
 */
class InAppNotificationService {

  public function __construct(
    protected Connection $database,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Returns unread alert count for a user.
   */
  public function getUnreadCount(int $uid): int {
    if ($uid <= 0) {
      return 0;
    }
    return (int) $this->database->query(
      'SELECT COUNT(a.alert_id) FROM {niftybot_trade_alerts} a
       LEFT JOIN {niftybot_user_notification_reads} r
         ON r.alert_id = a.alert_id AND r.uid = :uid
       WHERE r.id IS NULL',
      [':uid' => $uid]
    )->fetchField();
  }

  /**
   * Returns recent alerts with read flags for a user.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getAlerts(int $uid, int $limit = 20): array {
    if ($uid <= 0) {
      return [];
    }
    $limit = max(1, min($limit, 50));
    $result = $this->database->queryRange(
      'SELECT a.alert_id, a.instrument, a.signal_action, a.confidence, a.title,
              a.message, a.underlying_ltp, a.created,
              CASE WHEN r.id IS NULL THEN 0 ELSE 1 END AS is_read
       FROM {niftybot_trade_alerts} a
       LEFT JOIN {niftybot_user_notification_reads} r
         ON r.alert_id = a.alert_id AND r.uid = :uid
       ORDER BY a.created DESC, a.alert_id DESC',
      [':uid' => $uid],
      0,
      $limit
    );

    $alerts = [];
    foreach ($result as $row) {
      $alerts[] = [
        'alert_id' => (int) $row->alert_id,
        'instrument' => $row->instrument,
        'signal_action' => $row->signal_action,
        'confidence' => $row->confidence !== NULL ? (float) $row->confidence : NULL,
        'title' => $row->title,
        'message' => $row->message,
        'underlying_ltp' => $row->underlying_ltp !== NULL ? (float) $row->underlying_ltp : NULL,
        'created' => (int) $row->created,
        'is_read' => (bool) $row->is_read,
      ];
    }
    return $alerts;
  }

  /**
   * Marks one alert as read for a user.
   */
  public function markRead(int $uid, int $alert_id): void {
    if ($uid <= 0 || $alert_id <= 0) {
      return;
    }
    $exists = $this->database->select('niftybot_user_notification_reads', 'r')
      ->fields('r', ['id'])
      ->condition('uid', $uid)
      ->condition('alert_id', $alert_id)
      ->execute()
      ->fetchField();
    if ($exists) {
      return;
    }
    $this->database->insert('niftybot_user_notification_reads')
      ->fields([
        'uid' => $uid,
        'alert_id' => $alert_id,
        'read_at' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Marks all alerts as read for a user.
   */
  public function markAllRead(int $uid): void {
    if ($uid <= 0) {
      return;
    }
    $unread = $this->database->query(
      'SELECT a.alert_id FROM {niftybot_trade_alerts} a
       LEFT JOIN {niftybot_user_notification_reads} r
         ON r.alert_id = a.alert_id AND r.uid = :uid
       WHERE r.id IS NULL',
      [':uid' => $uid]
    )->fetchCol();
    if ($unread === []) {
      return;
    }
    $now = \Drupal::time()->getRequestTime();
    $query = $this->database->insert('niftybot_user_notification_reads')
      ->fields(['uid', 'alert_id', 'read_at']);
    foreach ($unread as $alert_id) {
      $query->values([
        'uid' => $uid,
        'alert_id' => (int) $alert_id,
        'read_at' => $now,
      ]);
    }
    $query->execute();
  }

}
