<?php

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin overview controller for NiftyBot platform.
 */
class AdminController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs an AdminController object.
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
   * Admin overview page.
   */
  public function overview() {
    $stats = $this->getPlatformStats();

    return [
      '#theme' => 'niftybot_admin_overview',
      '#stats' => $stats,
      '#recent_activity' => [],
      '#attached' => [
        'library' => ['niftybot_core/admin'],
      ],
    ];
  }

  /**
   * Gather platform statistics.
   */
  protected function getPlatformStats() {
    $user_count = $this->database->select('users_field_data', 'u')
      ->condition('u.status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    return [
      'total_users' => (int) $user_count,
      'pending_kyc' => 0,
      'active_subscriptions' => 0,
      'orders_today' => 0,
      'total_trades' => 0,
    ];
  }

}
