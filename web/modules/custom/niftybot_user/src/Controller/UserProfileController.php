<?php

namespace Drupal\niftybot_user\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * User profile controller.
 */
class UserProfileController extends ControllerBase {

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
   * Display user profile page.
   */
  public function profile() {
    $uid = $this->currentUser->id();
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);

    $kyc = $this->database->select('niftybot_kyc', 'k')
      ->fields('k', ['status'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    $wallet_balance = $this->database->select('niftybot_wallet', 'w')
      ->fields('w', ['balance'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    $subscription = $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us')
      ->condition('uid', $uid)
      ->condition('status', 'active')
      ->execute()
      ->fetchObject();

    return [
      '#theme' => 'niftybot_user_profile',
      '#user' => $user,
      '#kyc_status' => $kyc ?: 'not_submitted',
      '#wallet_balance' => (float) ($wallet_balance ?? 0),
      '#subscription' => $subscription,
    ];
  }

}
