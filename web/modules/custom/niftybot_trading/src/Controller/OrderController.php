<?php

namespace Drupal\niftybot_trading\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Order management controller.
 */
class OrderController extends ControllerBase {

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
   * List user's orders.
   */
  public function ordersList() {
    $orders = $this->database->select('niftybot_orders', 'o')
      ->fields('o')
      ->condition('uid', $this->currentUser->id())
      ->orderBy('created', 'DESC')
      ->range(0, 100)
      ->execute()
      ->fetchAll();

    foreach ($orders as &$order) {
      $order->detail_url = Url::fromRoute('niftybot_trading.order_detail', ['order_id' => $order->order_id])->toString();
      if (in_array($order->status, ['pending', 'placed'])) {
        $order->cancel_url = Url::fromRoute('niftybot_trading.order_cancel', ['order_id' => $order->order_id])->toString();
      }
    }

    return [
      '#theme' => 'niftybot_orders_list',
      '#orders' => $orders,
      '#is_admin' => FALSE,
    ];
  }

  /**
   * Order detail page.
   */
  public function orderDetail($order_id) {
    $order = $this->database->select('niftybot_orders', 'o')
      ->fields('o')
      ->condition('order_id', $order_id)
      ->execute()
      ->fetchObject();

    if (!$order) {
      throw new NotFoundHttpException();
    }

    if ((int) $order->uid !== (int) $this->currentUser->id() && !$this->currentUser->hasPermission('administer niftybot')) {
      throw new AccessDeniedHttpException();
    }

    return [
      '#theme' => 'niftybot_order_detail',
      '#order' => $order,
    ];
  }

  /**
   * Admin: list all orders.
   */
  public function adminOrdersList() {
    $orders = $this->database->select('niftybot_orders', 'o')
      ->fields('o')
      ->orderBy('created', 'DESC')
      ->range(0, 200)
      ->execute()
      ->fetchAll();

    foreach ($orders as &$order) {
      $user = $this->entityTypeManager()->getStorage('user')->load($order->uid);
      $order->username = $user ? $user->getAccountName() : 'UID: ' . $order->uid;
      $order->detail_url = Url::fromRoute('niftybot_trading.order_detail', ['order_id' => $order->order_id])->toString();
    }

    return [
      '#theme' => 'niftybot_orders_list',
      '#orders' => $orders,
      '#is_admin' => TRUE,
    ];
  }

}
