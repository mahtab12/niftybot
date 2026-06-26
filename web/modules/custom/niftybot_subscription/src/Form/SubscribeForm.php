<?php

namespace Drupal\niftybot_subscription\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Subscribe to a plan form.
 */
class SubscribeForm extends FormBase {

  protected Connection $database;

  protected AccountProxyInterface $currentUser;

  protected WalletService $walletService;

  /**
   * Constructs the form.
   */
  public function __construct(
    Connection $database,
    AccountProxyInterface $current_user,
    WalletService $wallet_service,
  ) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->walletService = $wallet_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('niftybot_user.wallet_service')
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
    $wallet_balance = $this->walletService->getWalletBalance((int) $this->currentUser->id());
    $can_pay_wallet = $wallet_balance >= (float) $plan->price;
    $brokers = array_filter(array_map('trim', explode(',', (string) ($plan->brokers_allowed ?? ''))));

    $form['#attached']['library'][] = 'niftybot_subscription/subscribe';
    $form['#attached']['library'][] = 'niftybot_user/wallet';
    $form['#attributes']['class'][] = 'niftybot-subscribe-form';
    $form['#prefix'] = Markup::create(
      '<div class="niftybot-subscribe-page niftybot-dashboard">'
      . $this->breadcrumb((string) $plan->name)
      . $this->hero($plan)
    );
    $form['#suffix'] = Markup::create('</div>');

    $form['layout'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-subscribe-page__layout']],
    ];

    $form['layout']['plan'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'niftybot-subscribe-page__card', 'niftybot-subscribe-page__plan']],
    ];

    $form['layout']['plan']['body'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card-body', 'niftybot-subscribe-page__card-body']],
    ];

    $form['layout']['plan']['body']['heading'] = [
      '#markup' => '<h3 class="niftybot-subscribe-page__section-title">' . $this->t('Plan includes') . '</h3>',
    ];

    $form['layout']['plan']['body']['stats'] = [
      '#markup' => Markup::create($this->planStats($plan)),
    ];

    if (!empty($features)) {
      $form['layout']['plan']['body']['features'] = [
        '#theme' => 'item_list',
        '#items' => $features,
        '#attributes' => ['class' => ['niftybot-subscribe-page__features']],
        '#wrapper_attributes' => ['class' => ['niftybot-subscribe-page__features-wrap']],
      ];
    }

    if (!empty($brokers)) {
      $form['layout']['plan']['body']['brokers'] = [
        '#markup' => '<p class="niftybot-subscribe-page__brokers"><strong>' . $this->t('Brokers:') . '</strong> '
          . implode(', ', array_map('ucfirst', $brokers)) . '</p>',
      ];
    }

    $form['layout']['checkout'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'niftybot-subscribe-page__card', 'niftybot-subscribe-page__checkout']],
    ];

    $form['layout']['checkout']['body'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card-body', 'niftybot-subscribe-page__card-body']],
    ];

    $form['layout']['checkout']['body']['heading'] = [
      '#markup' => '<h3 class="niftybot-subscribe-page__section-title">' . $this->t('Checkout') . '</h3>',
    ];

    $form['layout']['checkout']['body']['wallet'] = [
      '#markup' => Markup::create(
        '<div class="niftybot-subscribe-page__wallet' . ($can_pay_wallet ? '' : ' niftybot-subscribe-page__wallet--low') . '">'
        . '<div><span class="niftybot-subscribe-page__wallet-label">' . $this->t('Wallet balance') . '</span>'
        . '<strong class="niftybot-subscribe-page__wallet-value">₹' . number_format($wallet_balance, 2) . '</strong></div>'
        . ($can_pay_wallet
          ? '<span class="badge bg-success">' . $this->t('Sufficient') . '</span>'
          : '<a href="' . Url::fromRoute('niftybot_user.wallet_deposit')->toString() . '" class="btn btn-sm btn-outline-primary">' . $this->t('Add funds') . '</a>')
        . '</div>'
      ),
    ];

    $form['layout']['checkout']['body']['payment_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Payment method'),
      '#parents' => ['payment_method'],
      '#options' => [
        'wallet' => $this->t('Wallet'),
        'upi' => $this->t('UPI'),
        'bank_transfer' => $this->t('Bank transfer (NEFT/RTGS)'),
      ],
      '#required' => TRUE,
      '#default_value' => $can_pay_wallet ? 'wallet' : 'upi',
      '#wrapper_attributes' => ['class' => ['niftybot-subscribe-page__payment']],
    ];

    $form['layout']['checkout']['body']['upi_payment'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wallet-payment-panel', 'wallet-payment-panel--upi', 'niftybot-subscribe-page__payment-panel']],
      '#states' => [
        'visible' => [
          ':input[name="payment_method"]' => ['value' => 'upi'],
        ],
      ],
    ];

    $form['layout']['checkout']['body']['upi_payment']['demo'] = [
      '#theme' => 'niftybot_upi_payment_demo',
      '#upi_id' => WalletService::DEMO_UPI_ID,
      '#amount' => (float) $plan->price,
    ];

    $form['layout']['checkout']['body']['upi_payment']['help'] = [
      '#markup' => '<p class="wallet-payment-help">' . $this->t('Pay exactly ₹@amount via UPI, then enter your transaction reference below. Your subscription activates after admin verification.', [
        '@amount' => number_format((float) $plan->price, 2),
      ]) . '</p>',
    ];

    $form['layout']['checkout']['body']['upi_payment']['upi_reference'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UPI transaction reference'),
      '#parents' => ['upi_reference'],
      '#maxlength' => 255,
      '#description' => $this->t('Enter the reference number from your UPI payment app.'),
      '#states' => [
        'required' => [
          ':input[name="payment_method"]' => ['value' => 'upi'],
        ],
      ],
    ];

    $form['layout']['checkout']['body']['bank_transfer'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wallet-payment-panel', 'wallet-payment-panel--neft', 'niftybot-subscribe-page__payment-panel']],
      '#states' => [
        'visible' => [
          ':input[name="payment_method"]' => ['value' => 'bank_transfer'],
        ],
      ],
    ];

    $form['layout']['checkout']['body']['bank_transfer']['instructions'] = [
      '#markup' => '<div class="neft-bank-details">'
        . '<h4>' . $this->t('NEFT / RTGS bank details') . '</h4>'
        . '<table class="table table-striped niftybot-table compact">'
        . '<tr><td><strong>' . $this->t('Account name') . '</strong></td><td>NiftyBot Trading Pvt Ltd</td></tr>'
        . '<tr><td><strong>' . $this->t('Account number') . '</strong></td><td>50200012345678</td></tr>'
        . '<tr><td><strong>' . $this->t('IFSC code') . '</strong></td><td>ICIC0001234</td></tr>'
        . '<tr><td><strong>' . $this->t('Bank') . '</strong></td><td>ICICI Bank</td></tr>'
        . '<tr><td><strong>' . $this->t('Amount') . '</strong></td><td>₹' . number_format((float) $plan->price, 2) . '</td></tr>'
        . '</table>'
        . '<p>' . $this->t('Transfer the exact plan amount and enter your UTR below. Your subscription activates after admin verification.') . '</p>'
        . '</div>',
    ];

    $form['layout']['checkout']['body']['bank_transfer']['payment_reference'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UTR / transaction reference'),
      '#parents' => ['payment_reference'],
      '#maxlength' => 255,
      '#description' => $this->t('Enter the reference number from your bank transfer receipt.'),
      '#states' => [
        'required' => [
          ':input[name="payment_method"]' => ['value' => 'bank_transfer'],
        ],
      ],
    ];

    $form['layout']['checkout']['body']['payment_note'] = [
      '#markup' => '<p class="niftybot-subscribe-page__payment-note">' . $this->t('Wallet payment is instant. UPI and bank transfer require admin verification before your plan is activated.') . '</p>',
    ];

    $form['layout']['checkout']['body']['auto_renew'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-renew when this plan expires'),
      '#parents' => ['auto_renew'],
      '#default_value' => FALSE,
      '#wrapper_attributes' => ['class' => ['niftybot-subscribe-page__renew']],
    ];

    $price_label = number_format((float) $plan->price, 2);

    $form['layout']['checkout']['body']['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['niftybot-subscribe-page__actions']],
    ];
    $form['layout']['checkout']['body']['actions']['submit_wallet'] = [
      '#type' => 'submit',
      '#value' => $this->t('Subscribe — ₹@price', ['@price' => $price_label]),
      '#name' => 'submit_wallet',
      '#attributes' => ['class' => ['btn', 'btn-primary']],
      '#states' => [
        'visible' => [
          ':input[name="payment_method"]' => ['value' => 'wallet'],
        ],
      ],
    ];
    $form['layout']['checkout']['body']['actions']['submit_upi'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit UPI payment — ₹@price', ['@price' => $price_label]),
      '#name' => 'submit_upi',
      '#attributes' => ['class' => ['btn', 'btn-primary']],
      '#states' => [
        'visible' => [
          ':input[name="payment_method"]' => ['value' => 'upi'],
        ],
      ],
    ];
    $form['layout']['checkout']['body']['actions']['submit_neft'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit bank transfer — ₹@price', ['@price' => $price_label]),
      '#name' => 'submit_neft',
      '#attributes' => ['class' => ['btn', 'btn-primary']],
      '#states' => [
        'visible' => [
          ':input[name="payment_method"]' => ['value' => 'bank_transfer'],
        ],
      ],
    ];

    $form['layout']['checkout']['body']['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to plans'),
      '#url' => Url::fromRoute('niftybot_subscription.plans'),
      '#attributes' => ['class' => ['btn', 'btn-outline-secondary']],
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
      $balance = $this->walletService->getWalletBalance((int) $this->currentUser->id());
      if ($balance < (float) $plan->price) {
        $form_state->setErrorByName('payment_method', $this->t('Insufficient wallet balance. Please deposit funds or choose a different payment method.'));
      }
    }

    if ($payment_method === 'upi' && trim((string) $form_state->getValue('upi_reference')) === '') {
      $form_state->setErrorByName('upi_reference', $this->t('Please enter your UPI transaction reference.'));
    }

    if ($payment_method === 'bank_transfer' && trim((string) $form_state->getValue('payment_reference')) === '') {
      $form_state->setErrorByName('payment_reference', $this->t('Please enter your UTR / transaction reference.'));
    }

    $uid = (int) $this->currentUser->id();

    $active_sub = $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us', ['subscription_id'])
      ->condition('uid', $uid)
      ->condition('status', 'active')
      ->execute()
      ->fetchField();

    if ($active_sub) {
      $form_state->setErrorByName('payment_method', $this->t('You already have an active subscription. Please wait for it to expire or cancel it first.'));
    }

    $pending_sub = $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us', ['subscription_id'])
      ->condition('uid', $uid)
      ->condition('status', 'pending_payment')
      ->execute()
      ->fetchField();

    if ($pending_sub) {
      $form_state->setErrorByName('payment_method', $this->t('You already have a subscription payment pending verification. Please wait for admin approval or contact support.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $plan = $form_state->get('plan');
    $uid = (int) $this->currentUser->id();
    $now = \Drupal::time()->getRequestTime();
    $payment_method = $form_state->getValue('payment_method');
    $trigger = $form_state->getTriggeringElement()['#name'] ?? 'submit_wallet';

    if ($payment_method === 'upi' || $payment_method === 'bank_transfer') {
      $reference = $payment_method === 'upi'
        ? trim((string) $form_state->getValue('upi_reference'))
        : trim((string) $form_state->getValue('payment_reference'));

      if ($reference === '') {
        return;
      }

      $subscription_id = (int) $this->database->insert('niftybot_user_subscriptions')
        ->fields([
          'uid' => $uid,
          'plan_id' => $plan->plan_id,
          'start_date' => 0,
          'end_date' => 0,
          'status' => 'pending_payment',
          'payment_amount' => $plan->price,
          'payment_reference' => $reference,
          'auto_renew' => $form_state->getValue('auto_renew') ? 1 : 0,
          'created' => $now,
        ])
        ->execute();

      $this->walletService->createDeposit(
        $uid,
        (float) $plan->price,
        $payment_method,
        $reference,
        'subscription:' . $subscription_id,
      );

      $method_label = $payment_method === 'upi' ? $this->t('UPI') : $this->t('bank transfer');
      $this->messenger()->addStatus($this->t('Your @method payment for @plan (₹@amount) has been submitted. Your subscription will activate after admin verification.', [
        '@method' => $method_label,
        '@plan' => $plan->name,
        '@amount' => number_format((float) $plan->price, 2),
      ]));
      $form_state->setRedirect('niftybot_subscription.my_subscription');
      return;
    }

    if ($trigger !== 'submit_wallet' && $payment_method === 'wallet') {
      return;
    }

    $end_date = $now + ($plan->duration_days * 86400);

    $this->walletService->debitBalance($uid, (float) $plan->price);

    $this->database->insert('niftybot_wallet_transactions')
      ->fields([
        'uid' => $uid,
        'type' => 'withdrawal',
        'amount' => $plan->price,
        'status' => 'completed',
        'payment_method' => 'subscription',
        'notes' => 'Subscription: ' . $plan->name,
        'balance_applied' => 1,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

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

  /**
   * Breadcrumb markup.
   */
  protected function breadcrumb(string $plan_name): string {
    $plans_url = Url::fromRoute('niftybot_subscription.plans')->toString();
    return '<nav class="niftybot-subscribe-page__breadcrumb" aria-label="' . $this->t('Breadcrumb') . '">'
      . '<a href="' . $plans_url . '">' . $this->t('Plans') . '</a>'
      . '<i class="bi bi-chevron-right" aria-hidden="true"></i>'
      . '<span>' . $plan_name . '</span></nav>';
  }

  /**
   * Hero banner markup.
   */
  protected function hero(object $plan): string {
    return '<div class="card niftybot-subscribe-page__hero"><div class="card-body">'
      . '<div class="niftybot-subscribe-page__hero-inner">'
      . '<div class="niftybot-subscribe-page__hero-icon" aria-hidden="true"><i class="bi bi-tags-fill"></i></div>'
      . '<div>'
      . '<h2 class="niftybot-subscribe-page__hero-title">' . $this->t('Subscribe to @plan', ['@plan' => $plan->name]) . '</h2>'
      . '<p class="niftybot-subscribe-page__hero-text">' . htmlspecialchars((string) ($plan->description ?: $this->t('Unlock trading features with this subscription plan.')), ENT_QUOTES, 'UTF-8') . '</p>'
      . '</div>'
      . '<div class="niftybot-subscribe-page__price">'
      . '<span class="niftybot-subscribe-page__price-value">₹' . number_format((float) $plan->price, 0) . '</span>'
      . '<span class="niftybot-subscribe-page__price-period">/' . $this->t('@days days', ['@days' => $plan->duration_days]) . '</span>'
      . '</div></div></div></div>';
  }

  /**
   * Plan stats grid markup.
   */
  protected function planStats(object $plan): string {
    $items = [
      [
        'label' => $this->t('Trades / day'),
        'value' => (int) $plan->max_orders_per_day > 0
          ? (string) $plan->max_orders_per_day
          : (string) $this->t('Unlimited'),
      ],
      [
        'label' => $this->t('Duration'),
        'value' => $this->t('@days days', ['@days' => $plan->duration_days]),
      ],
    ];
    if (!empty($plan->max_capital)) {
      $items[] = [
        'label' => $this->t('Max capital'),
        'value' => '₹' . number_format((float) $plan->max_capital, 0),
      ];
    }

    $html = '<div class="niftybot-subscribe-page__stats">';
    foreach ($items as $item) {
      $html .= '<div class="niftybot-subscribe-page__stat">'
        . '<span class="niftybot-subscribe-page__stat-label">' . $item['label'] . '</span>'
        . '<strong class="niftybot-subscribe-page__stat-value">' . $item['value'] . '</strong></div>';
    }
    $html .= '</div>';
    return $html;
  }

}
