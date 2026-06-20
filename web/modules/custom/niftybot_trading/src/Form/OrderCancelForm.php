<?php

namespace Drupal\niftybot_trading\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\niftybot_core\Service\BrokerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Order cancellation confirmation form.
 */
class OrderCancelForm extends ConfirmFormBase {

  protected Connection $database;
  protected AccountProxyInterface $currentUser;
  protected BrokerManager $brokerManager;

  /**
   * Constructs the form.
   */
  public function __construct(
    Connection $database,
    AccountProxyInterface $current_user,
    BrokerManager $broker_manager,
  ) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->brokerManager = $broker_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('niftybot_core.broker_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_order_cancel_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to cancel this order?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('niftybot_trading.orders');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $order_id = NULL) {
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

    if (!in_array($order->status, ['pending', 'placed'])) {
      $this->messenger()->addError($this->t('This order cannot be cancelled because its status is @status.', [
        '@status' => $order->status,
      ]));
      return $this->redirect('niftybot_trading.orders');
    }

    $form_state->set('order', $order);

    $form['order_info'] = [
      '#markup' => '<div class="order-cancel-info">' .
        '<p><strong>' . $this->t('Symbol:') . '</strong> ' . $order->symbol . '</p>' .
        '<p><strong>' . $this->t('Type:') . '</strong> ' . $order->transaction_type . ' ' . $order->order_type . '</p>' .
        '<p><strong>' . $this->t('Quantity:') . '</strong> ' . $order->quantity . '</p>' .
        '</div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $order = $form_state->get('order');
    $now = \Drupal::time()->getRequestTime();

    if ($order->broker_order_id) {
      $this->brokerManager->cancelOrder($order->broker_order_id, $order->broker);
    }

    $this->database->update('niftybot_orders')
      ->fields([
        'status' => 'cancelled',
        'status_message' => 'Cancelled by user.',
        'updated' => $now,
      ])
      ->condition('order_id', $order->order_id)
      ->execute();

    $this->messenger()->addStatus($this->t('Order for @symbol has been cancelled.', [
      '@symbol' => $order->symbol,
    ]));

    $form_state->setRedirect('niftybot_trading.orders');
  }

}
