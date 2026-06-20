<?php

namespace Drupal\niftybot_subscription\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Subscribe to a plan form.
 */
class SubscribeForm extends FormBase {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs the form.
   */
  public function __construct(Connection $database, AccountProxyInterface $current_user) {
    $this->database = $database;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_subscribe_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $plan_id = NULL) {
    $plan = $this->database->select('niftybot_subscription_plans', 'sp')
      ->fields('sp')
      ->condition('plan_id', $plan_id)
      ->condition('status', 1)
      ->execute()
      ->fetchObject();

    if (!$plan) {
      throw new NotFoundHttpException();
    }

    $form_state->set('plan', $plan);
    $features = json_decode($plan->features, TRUE) ?? [];

    $form['plan_summary'] = [
      '#type' => 'details',
      '#title' => $this->t('Plan: @name', ['@name' => $plan->name]),
      '#open' => TRUE,
    ];

    $form['plan_summary']['info'] = [
      '#markup' => '<div class="plan-info">' .
        '<p>' . $plan->description . '</p>' .
        '<p><strong>' . $this->t('Price:') . '</strong> ₹' . number_format((float) $plan->price, 2) . '/month</p>' .
        '<p><strong>' . $this->t('Duration:') . '</strong> ' . $plan->duration_days . ' days</p>' .
        '<p><strong>' . $this->t('Max Orders/Day:') . '</strong> ' . $plan->max_orders_per_day . '</p>' .
        '</div>',
    ];

    if (!empty($features)) {
      $form['plan_summary']['features'] = [
        '#theme' => 'item_list',
        '#items' => $features,
        '#title' => $this->t('Features'),
      ];
    }

    $wallet_balance = (float) $this->database->select('niftybot_wallet', 'w')
      ->fields('w', ['balance'])
      ->condition('uid', $this->currentUser->id())
      ->execute()
      ->fetchField();

    $form['payment_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Payment Method'),
      '#options' => [
        'wallet' => $this->t('Pay from Wallet (Balance: ₹@bal)', [
          '@bal' => number_format($wallet_balance, 2),
        ]),
        'upi' => $this->t('UPI'),
        'card' => $this->t('Debit/Credit Card'),
      ],
      '#required' => TRUE,
      '#default_value' => $wallet_balance >= $plan->price ? 'wallet' : 'upi',
    ];

    $form['auto_renew'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-renew subscription'),
      '#default_value' => FALSE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Subscribe - ₹@price', ['@price' => number_format((float) $plan->price, 2)]),
      '#attributes' => ['class' => ['button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $plan = $form_state->get('plan');
    $payment_method = $form_state->getValue('payment_method');

    if ($payment_method === 'wallet') {
      $balance = (float) $this->database->select('niftybot_wallet', 'w')
        ->fields('w', ['balance'])
        ->condition('uid', $this->currentUser->id())
        ->execute()
        ->fetchField();

      if ($balance < (float) $plan->price) {
        $form_state->setErrorByName('payment_method', $this->t('Insufficient wallet balance. Please deposit funds or choose a different payment method.'));
      }
    }

    $active_sub = $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us', ['subscription_id'])
      ->condition('uid', $this->currentUser->id())
      ->condition('status', 'active')
      ->execute()
      ->fetchField();

    if ($active_sub) {
      $form_state->setErrorByName('payment_method', $this->t('You already have an active subscription. Please wait for it to expire or cancel it first.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $plan = $form_state->get('plan');
    $uid = $this->currentUser->id();
    $now = \Drupal::time()->getRequestTime();
    $end_date = $now + ($plan->duration_days * 86400);

    if ($form_state->getValue('payment_method') === 'wallet') {
      $this->database->update('niftybot_wallet')
        ->expression('balance', 'balance - :price', [':price' => $plan->price])
        ->condition('uid', $uid)
        ->execute();

      $this->database->insert('niftybot_wallet_transactions')
        ->fields([
          'uid' => $uid,
          'type' => 'withdrawal',
          'amount' => $plan->price,
          'status' => 'completed',
          'payment_method' => 'wallet',
          'notes' => 'Subscription: ' . $plan->name,
          'created' => $now,
          'updated' => $now,
        ])
        ->execute();
    }

    $this->database->insert('niftybot_user_subscriptions')
      ->fields([
        'uid' => $uid,
        'plan_id' => $plan->plan_id,
        'start_date' => $now,
        'end_date' => $end_date,
        'status' => 'active',
        'payment_amount' => $plan->price,
        'payment_reference' => 'WALLET-' . $uid . '-' . $now,
        'auto_renew' => $form_state->getValue('auto_renew') ? 1 : 0,
        'created' => $now,
      ])
      ->execute();

    $this->messenger()->addStatus($this->t('You have successfully subscribed to the @plan plan! Valid until @date.', [
      '@plan' => $plan->name,
      '@date' => date('d M Y', $end_date),
    ]));

    $form_state->setRedirect('niftybot_dashboard.main');
  }

}
