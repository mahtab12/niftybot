<?php

namespace Drupal\niftybot_trading\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Position tracking controller.
 */
class PositionController extends ControllerBase {

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
   * List user's current positions.
   */
  public function positionsList() {
    $uid = $this->currentUser->id();

    $positions = $this->database->select('niftybot_positions', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->condition('status', 'open')
      ->orderBy('opened_at', 'DESC')
      ->execute()
      ->fetchAll();

    $total_pnl = 0;
    $total_investment = 0;

    foreach ($positions as &$position) {
      $investment = (float) $position->average_price * (int) $position->quantity;
      $total_investment += $investment;
      $total_pnl += (float) $position->pnl;
    }

    return [
      '#theme' => 'niftybot_positions_list',
      '#positions' => $positions,
      '#total_pnl' => $total_pnl,
      '#total_investment' => $total_investment,
    ];
  }

}
