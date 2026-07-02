<?php

namespace Drupal\niftybot_trading\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
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

    $form['#attached']['library'][] = 'niftybot_trading/order';
    $form['#attributes']['class'][] = 'niftybot-order-form';
    $form['#prefix'] = Markup::create('<div class="niftybot-order-page">' . $this->pageHero(TRUE));
    $form['#suffix'] = Markup::create('</div></div></div>');

    $kyc_status = $this->database->select('niftybot_kyc', 'k')
      ->fields('k', ['status'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    if ($kyc_status !== 'approved') {
      $form['#prefix'] = Markup::create('<div class="niftybot-order-page">' . $this->pageHero(FALSE));
      $form['#suffix'] = Markup::create('</div>');
      $form['kyc_warning'] = [
        '#markup' => $this->warningAlert(
          $this->t('KYC verification is required before placing orders.'),
          $this->t('Complete KYC'),
          Url::fromRoute('niftybot_user.kyc_submit')->toString()
        ),
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
      $form['#prefix'] = Markup::create('<div class="niftybot-order-page">' . $this->pageHero(FALSE));
      $form['#suffix'] = Markup::create('</div>');
      $form['sub_warning'] = [
        '#markup' => $this->warningAlert(
          $this->t('An active subscription is required to place orders.'),
          $this->t('View plans'),
          Url::fromRoute('niftybot_subscription.plans')->toString()
        ),
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

    $max_orders = $plan ? (int) $plan->max_orders_per_day : 0;
    $plan_name = $plan ? $plan->name : $this->t('N/A');

    $form['order_info'] = [
      '#markup' => Markup::create(
        '<div class="niftybot-order-stats">'
        . '<div class="niftybot-order-stats__item">'
        . '<span class="niftybot-order-stats__label">' . $this->t('Orders today') . '</span>'
        . '<span class="niftybot-order-stats__value">' . $orders_today . ' / ' . ($max_orders ?: 'N/A') . '</span>'
        . '</div>'
        . '<div class="niftybot-order-stats__item">'
        . '<span class="niftybot-order-stats__label">' . $this->t('Plan') . '</span>'
        . '<span class="niftybot-order-stats__value">' . $plan_name . '</span>'
        . '</div>'
        . '</div></div></div>'
        . '<div class="card niftybot-order-form-card mb-0"><div class="card-body">'
      ),
    ];

    $form['instrument'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-order-form__section']],
      '#prefix' => $this->sectionHeading(
        'bi-graph-up-arrow',
        $this->t('Instrument'),
        $this->t('Select broker, symbol, and market segment')
      ),
    ];

    $form['instrument']['instrument_grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-order-form__grid', 'niftybot-order-form__grid--2']],
    ];

    $form['instrument']['instrument_grid']['broker'] = [
      '#type' => 'select',
      '#title' => $this->t('Broker'),
      '#options' => $broker_options,
      '#required' => TRUE,
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['instrument']['instrument_grid']['symbol'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Symbol'),
      '#required' => TRUE,
      '#maxlength' => 50,
      '#attributes' => [
        'placeholder' => 'e.g., RELIANCE, NIFTY24JUNFUT',
      ],
      '#wrapper_attributes' => ['class' => ['mb-3', 'niftybot-order-form__symbol']],
    ];

    $form['instrument']['instrument_grid']['exchange'] = [
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
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['instrument']['instrument_grid']['instrument_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Instrument type'),
      '#options' => [
        'equity' => $this->t('Equity'),
        'futures' => $this->t('Futures'),
        'options' => $this->t('Options'),
      ],
      '#required' => TRUE,
      '#default_value' => 'equity',
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['order_details'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-order-form__section']],
      '#prefix' => $this->sectionHeading(
        'bi-sliders',
        $this->t('Order details'),
        $this->t('Side, order type, and product configuration')
      ),
    ];

    $form['order_details']['transaction_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Side'),
      '#options' => [
        'BUY' => $this->t('BUY'),
        'SELL' => $this->t('SELL'),
      ],
      '#required' => TRUE,
      '#default_value' => 'BUY',
      '#wrapper_attributes' => ['class' => ['niftybot-order-form__side']],
    ];

    $form['order_details']['order_grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-order-form__grid', 'niftybot-order-form__grid--2']],
    ];

    $form['order_details']['order_grid']['order_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Order type'),
      '#options' => [
        'MARKET' => $this->t('Market'),
        'LIMIT' => $this->t('Limit'),
        'SL' => $this->t('Stop Loss'),
        'SL-M' => $this->t('Stop Loss Market'),
      ],
      '#required' => TRUE,
      '#default_value' => 'MARKET',
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['order_details']['order_grid']['product_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Product type'),
      '#options' => [
        'CNC' => $this->t('CNC (Delivery)'),
        'MIS' => $this->t('MIS (Intraday)'),
        'NRML' => $this->t('NRML (F&O Normal)'),
      ],
      '#required' => TRUE,
      '#default_value' => 'CNC',
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['pricing'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-order-form__section']],
      '#prefix' => $this->sectionHeading(
        'bi-currency-rupee',
        $this->t('Quantity & pricing'),
        $this->t('Set size and price levels for your order')
      ),
    ];

    $form['pricing']['pricing_grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-order-form__grid', 'niftybot-order-form__grid--3']],
    ];

    $form['pricing']['pricing_grid']['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Quantity'),
      '#required' => TRUE,
      '#min' => 1,
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['pricing']['pricing_grid']['price'] = [
      '#type' => 'number',
      '#title' => $this->t('Price (₹)'),
      '#min' => 0,
      '#step' => '0.05',
      '#wrapper_attributes' => ['class' => ['mb-3']],
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

    $form['pricing']['pricing_grid']['trigger_price'] = [
      '#type' => 'number',
      '#title' => $this->t('Trigger price (₹)'),
      '#min' => 0,
      '#step' => '0.05',
      '#wrapper_attributes' => ['class' => ['mb-3']],
      '#states' => [
        'visible' => [
          [':input[name="order_type"]' => ['value' => 'SL']],
          [':input[name="order_type"]' => ['value' => 'SL-M']],
        ],
      ],
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced options'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['niftybot-order-form__section', 'mb-0']],
    ];

    $form['advanced']['advanced_grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-order-form__grid', 'niftybot-order-form__grid--2']],
    ];

    $form['advanced']['advanced_grid']['stoploss'] = [
      '#type' => 'number',
      '#title' => $this->t('Stop loss (₹)'),
      '#min' => 0,
      '#step' => '0.05',
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['advanced']['advanced_grid']['target'] = [
      '#type' => 'number',
      '#title' => $this->t('Target (₹)'),
      '#min' => 0,
      '#step' => '0.05',
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['advanced']['advanced_grid']['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#rows' => 2,
      '#wrapper_attributes' => ['class' => ['mb-0', 'niftybot-order-form__span-2']],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Place order'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $plan = $form_state->get('plan');
    $orders_today = $form_state->get('orders_today');

    if ($plan && (int) $plan->max_orders_per_day > 0 && $orders_today >= $plan->max_orders_per_day) {
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
    elseif ($api_response
      && ($api_response['success'] ?? TRUE) === FALSE
      && ($api_response['error_code'] ?? '') === 'INSUFFICIENT_BALANCE') {
      $failure_message = $api_response['message'] ?? 'Insufficient balance in your Groww account.';
      $this->database->update('niftybot_orders')
        ->fields([
          'status' => 'failed',
          'status_message' => $failure_message,
          'updated' => $now,
        ])
        ->condition('order_id', $order_id)
        ->execute();

      $this->messenger()->addError($this->t('Buy order rejected — insufficient balance in your Groww account. Add funds on Groww and try again.'));
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

  /**
   * Hero banner markup for the order page.
   *
   * @param bool $leave_open_for_stats
   *   When TRUE, leaves the hero card open so daily stats can be injected.
   */
  protected function pageHero(bool $leave_open_for_stats = FALSE): string {
    $orders_url = Url::fromRoute('niftybot_trading.orders')->toString();
    $html = '<div class="card niftybot-order-hero mb-4"><div class="card-body">'
      . '<div class="niftybot-order-hero__top">'
      . '<div class="niftybot-order-hero__intro">'
      . '<div class="niftybot-order-hero__icon" aria-hidden="true"><i class="bi bi-lightning-charge"></i></div>'
      . '<div>'
      . '<h2 class="niftybot-order-hero__title">' . $this->t('Place order') . '</h2>'
      . '<p class="niftybot-order-hero__text">' . $this->t('Submit market, limit, or stop-loss orders through your connected broker.') . '</p>'
      . '</div></div>'
      . '<a href="' . $orders_url . '" class="niftybot-order-hero__link">'
      . '<i class="bi bi-list-ul" aria-hidden="true"></i> ' . $this->t('View orders')
      . '</a></div>';

    if (!$leave_open_for_stats) {
      $html .= '</div></div>';
    }

    return $html;
  }

  /**
   * Section heading markup.
   */
  protected function sectionHeading(string $icon, string $title, string $subtitle): Markup {
    return Markup::create(
      '<div class="niftybot-order-form__section-title">'
      . '<span class="niftybot-order-form__section-icon" aria-hidden="true"><i class="bi ' . $icon . '"></i></span>'
      . '<div><h4>' . $title . '</h4>'
      . '<p>' . $subtitle . '</p></div>'
      . '</div>'
    );
  }

  /**
   * Warning alert for blocked order states.
   */
  protected function warningAlert(string $message, string $link_label, string $url): string {
    return '<div class="card niftybot-order-form-card"><div class="card-body">'
      . '<div class="alert alert-warning d-flex align-items-start mb-0" role="alert">'
      . '<i class="bi bi-exclamation-triangle-fill me-2 flex-shrink-0" aria-hidden="true"></i>'
      . '<span>' . $message . ' <a href="' . $url . '" class="alert-link">' . $link_label . '</a></span>'
      . '</div></div></div>';
  }

}
