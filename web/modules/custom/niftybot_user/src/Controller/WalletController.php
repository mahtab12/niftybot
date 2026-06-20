<?php

namespace Drupal\niftybot_user\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wallet controller.
 */
class WalletController extends ControllerBase {

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
   * Wallet overview page.
   */
  public function overview() {
    $uid = $this->currentUser->id();

    $balance = $this->database->select('niftybot_wallet', 'w')
      ->fields('w', ['balance'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    $transactions = $this->database->select('niftybot_wallet_transactions', 'wt')
      ->fields('wt')
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->range(0, 50)
      ->execute()
      ->fetchAll();

    return [
      '#theme' => 'niftybot_wallet_overview',
      '#balance' => (float) ($balance ?? 0),
      '#transactions' => $transactions,
    ];
  }

}
