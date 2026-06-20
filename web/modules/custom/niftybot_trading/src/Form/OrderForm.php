<?php

namespace Drupal\niftybot_trading\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\niftybot_core\Service\BrokerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Order placement form.
 */
class OrderForm extends FormBase {

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
    return 'niftybot_order_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();

    $kyc_status = $this->database->select('niftybot_kyc', 'k')
      ->fields('k', ['status'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    if ($kyc_status !== 'approved') {
      $form['kyc_warning'] = [
        '#markup' => '<div class="messages messages--error">' .
          $this->t('KYC verification is required before placing orders. <a href="@url">Complete KYC</a>', [
            '@url' => '/niftybot/kyc',
          ]) . '</div>',
      ];
      return $form;
    }

    $active_sub = $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us')
      ->condition('uid', $uid)
      ->condition('status', 'active')
      ->execute()
      ->fetchObject();

    if (!$active_sub) {
      $form['sub_warning'] = [
        '#markup' => '<div class="messages messages--error">' .
          $this->t('An active subscription is required to place orders. <a href="@url">View Plans</a>', [
            '@url' => '/niftybot/plans',
          ]) . '</div>',
      ];
      return $form;
    }

    $plan = $this->database->select('niftybot_subscription_plans', 'sp')
      ->fields('sp')
      ->condition('plan_id', $active_sub->plan_id)
      ->execute()
      ->fetchObject();

    $today_start = strtotime('today');
    $orders_today = $this->database->select('niftybot_orders', 'o')
      ->condition('uid', $uid)
      ->condition('placed_at', $today_start, '>=')
      ->condition('status', ['cancelled', 'failed'], 'NOT IN')
      ->countQuery()
      ->execute()
      ->fetchField();

    $form_state->set('plan', $plan);
    $form_state->set('orders_today', (int) $orders_today);

    $allowed_brokers = $plan ? array_filter(explode(',', $plan->brokers_allowed)) : [];
    $all_brokers = $this->brokerManager->getAvailableBrokers();
    $broker_options = array_intersect_key($all_brokers, array_flip($allowed_brokers));

    if (empty($broker_options)) {
      $broker_options = $all_brokers;
    }

    $form['#attributes']['class'][] = 'niftybot-order-form';

    $form['order_info'] = [
      '#markup' => '<div class="order-info">' .
        $this->t('Orders today: @count / @max', [
          '@count' => $orders_today,
          '@max' => $plan ? $plan->max_orders_per_day : 'N/A',
        ]) . '</div>',
    ];

    $form['broker'] = [
      '#type' => 'select',
      '#title' => $this->t('Broker'),
      '#options' => $broker_options,
      '#required' => TRUE,
    ];

    $form['symbol'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Symbol'),
      '#required' => TRUE,
      '#maxlength' => 50,
      '#attributes' => [
        'placeholder' => 'e.g., RELIANCE, NIFTY24JUNFUT',
        'style' => 'text-transform: uppercase;',
      ],
    ];

    $form['exchange'] = [
      '#type' => 'select',
      '#title' => $this->t('Exchange'),
      '#options' => [
        'NSE' => 'NSE',
        'BSE' => 'BSE',
        'NFO' => 'NFO (F&O)',
        'MCX' => 'MCX',
      ],
      '#required' => TRUE,
      '#default_value' => 'NSE',
    ];

    $form['instrument_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Instrument Type'),
      '#options' => [
        'equity' => $this->t('Equity'),
        'futures' => $this->t('Futures'),
        'options' => $this->t('Options'),
      ],
      '#required' => TRUE,
      '#default_value' => 'equity',
    ];

    $form['transaction_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Transaction Type'),
      '#options' => [
        'BUY' => $this->t('BUY'),
        'SELL' => $this->t('SELL'),
      ],
      '#required' => TRUE,
      '#default_value' => 'BUY',
    ];

    $form['order_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Order Type'),
      '#options' => [
        'MARKET' => $this->t('Market'),
        'LIMIT' => $this->t('Limit'),
        'SL' => $this->t('Stop Loss'),
        'SL-M' => $this->t('Stop Loss Market'),
      ],
      '#required' => TRUE,
      '#default_value' => 'MARKET',
    ];

    $form['product_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Product Type'),
      '#options' => [
        'CNC' => $this->t('CNC (Delivery)'),
        'MIS' => $this->t('MIS (Intraday)'),
        'NRML' => $this->t('NRML (F&O Normal)'),
      ],
      '#required' => TRUE,
      '#default_value' => 'CNC',
    ];

    $form['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Quantity'),
      '#required' => TRUE,
      '#min' => 1,
    ];

    $form['price'] = [
      '#type' => 'number',
      '#title' => $this->t('Price (₹)'),
      '#min' => 0,
      '#step' => '0.05',
      '#states' => [
        'visible' => [
          [':input[name="order_type"]' => ['value' => 'LIMIT']],
          [':input[name="order_type"]' => ['value' => 'SL']],
        ],
        'required' => [
          [':input[name="order_type"]' => ['value' => 'LIMIT']],
          [':input[name="order_type"]' => ['value' => 'SL']],
        ],
      ],
    ];

    $form['trigger_price'] = [
      '#type' => 'number',
      '#title' => $this->t('Trigger Price (₹)'),
      '#min' => 0,
      '#step' => '0.05',
      '#states' => [
        'visible' => [
          [':input[name="order_type"]' => ['value' => 'SL']],
          [':input[name="order_type"]' => ['value' => 'SL-M']],
        ],
      ],
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Options'),
      '#open' => FALSE,
    ];

    $form['advanced']['stoploss'] = [
      '#type' => 'number',
      '#title' => $this->t('Stop Loss (₹)'),
      '#min' => 0,
      '#step' => '0.05',
    ];

    $form['advanced']['target'] = [
      '#type' => 'number',
      '#title' => $this->t('Target (₹)'),
      '#min' => 0,
      '#step' => '0.05',
    ];

    $form['advanced']['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#rows' => 2,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Place Order'),
      '#attributes' => ['class' => ['button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $plan = $form_state->get('plan');
    $orders_today = $form_state->get('orders_today');

    if ($plan && $orders_today >= $plan->max_orders_per_day) {
      $form_state->setErrorByName('quantity', $this->t('You have reached the maximum number of orders (@max) for today. Please upgrade your plan for higher limits.', [
        '@max' => $plan->max_orders_per_day,
      ]));
    }

    $order_type = $form_state->getValue('order_type');
    if (in_array($order_type, ['LIMIT', 'SL']) && empty($form_state->getValue('price'))) {
      $form_state->setErrorByName('price', $this->t('Price is required for @type orders.', ['@type' => $order_type]));
    }

    if (in_array($order_type, ['SL', 'SL-M']) && empty($form_state->getValue('trigger_price'))) {
      $form_state->setErrorByName('trigger_price', $this->t('Trigger price is required for @type orders.', ['@type' => $order_type]));
    }

    $quantity = $form_state->getValue('quantity');
    if ($quantity < 1) {
      $form_state->setErrorByName('quantity', $this->t('Quantity must be at least 1.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();
    $now = \Drupal::time()->getRequestTime();

    $order_data = [
      'uid' => $uid,
      'broker' => $form_state->getValue('broker'),
      'symbol' => strtoupper($form_state->getValue('symbol')),
      'exchange' => $form_state->getValue('exchange'),
      'instrument_type' => $form_state->getValue('instrument_type'),
      'transaction_type' => $form_state->getValue('transaction_type'),
      'order_type' => $form_state->getValue('order_type'),
      'product_type' => $form_state->getValue('product_type'),
      'quantity' => $form_state->getValue('quantity'),
      'price' => $form_state->getValue('price') ?: NULL,
      'trigger_price' => $form_state->getValue('trigger_price') ?: NULL,
      'stoploss' => $form_state->getValue('stoploss') ?: NULL,
      'target' => $form_state->getValue('target') ?: NULL,
      'notes' => $form_state->getValue('notes'),
      'status' => 'pending',
      'placed_at' => $now,
      'created' => $now,
      'updated' => $now,
    ];

    $order_id = $this->database->insert('niftybot_orders')
      ->fields($order_data)
      ->execute();

    $api_response = $this->brokerManager->placeOrder([
      'order_id' => $order_id,
      'user_id' => $uid,
      'broker' => $order_data['broker'],
      'symbol' => $order_data['symbol'],
      'exchange' => $order_data['exchange'],
      'transaction_type' => $order_data['transaction_type'],
      'order_type' => $order_data['order_type'],
      'product_type' => $order_data['product_type'],
      'quantity' => $order_data['quantity'],
      'price' => $order_data['price'],
      'trigger_price' => $order_data['trigger_price'],
    ]);

    if ($api_response && isset($api_response['broker_order_id'])) {
      $this->database->update('niftybot_orders')
        ->fields([
          'broker_order_id' => $api_response['broker_order_id'],
          'status' => 'placed',
          'updated' => $now,
        ])
        ->condition('order_id', $order_id)
        ->execute();

      $this->messenger()->addStatus($this->t('Order placed successfully! Order ID: @id', [
        '@id' => $api_response['broker_order_id'],
      ]));
    }
    else {
      $this->database->update('niftybot_orders')
        ->fields([
          'status' => 'pending',
          'status_message' => 'Awaiting processing by trading service.',
          'updated' => $now,
        ])
        ->condition('order_id', $order_id)
        ->execute();

      $this->messenger()->addWarning($this->t('Order has been queued for processing. You will be notified once it is executed.'));
    }

    $form_state->setRedirect('niftybot_trading.orders');
  }

}
