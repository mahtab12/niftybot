<?php

namespace Drupal\niftybot_user\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wallet deposit form with admin-verified UPI and NEFT payments.
 */
class WalletDepositForm extends FormBase {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The wallet service.
   */
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
    return 'niftybot_wallet_deposit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'niftybot_user/wallet';

    $uid = $this->currentUser->id();
    $wallet = $this->database->select('niftybot_wallet', 'w')
      ->fields('w', ['balance'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    $form['current_balance'] = [
      '#markup' => '<div class="wallet-balance"><strong>' . $this->t('Current Balance:') . '</strong> ₹' . number_format((float) $wallet, 2) . '</div>',
    ] + WalletService::walletRenderCache($uid);

    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Deposit Amount (₹)'),
      '#required' => TRUE,
      '#min' => 100,
      '#max' => 1000000,
      '#step' => '0.01',
      '#description' => $this->t('Minimum deposit: ₹100. Maximum: ₹10,00,000.'),
      '#attributes' => ['class' => ['wallet-deposit-amount']],
    ];

    $form['payment_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Payment Method'),
      '#options' => [
        'upi' => $this->t('UPI'),
        'bank_transfer' => $this->t('Bank Transfer (NEFT/RTGS)'),
      ],
      '#required' => TRUE,
      '#default_value' => 'upi',
      '#attributes' => ['class' => ['wallet-deposit-method']],
    ];

    $form['upi_payment'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wallet-payment-panel', 'wallet-payment-panel--upi']],
      '#states' => [
        'visible' => [
          ':input[name="payment_method"]' => ['value' => 'upi'],
        ],
      ],
    ];

    $form['upi_payment']['demo'] = [
      '#theme' => 'niftybot_upi_payment_demo',
      '#upi_id' => WalletService::DEMO_UPI_ID,
      '#amount' => NULL,
    ];

    $form['upi_payment']['help'] = [
      '#markup' => '<p class="wallet-payment-help">' . $this->t('Scan the QR code or pay to the UPI ID above. Enter your UPI transaction reference below and submit for admin verification.') . '</p>',
    ];

    $form['upi_payment']['upi_reference'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UPI Transaction Reference'),
      '#maxlength' => 255,
      '#description' => $this->t('Enter the 12-digit UPI reference number from your payment app.'),
      '#states' => [
        'required' => [
          ':input[name="payment_method"]' => ['value' => 'upi'],
        ],
      ],
    ];

    $form['bank_transfer'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['wallet-payment-panel', 'wallet-payment-panel--neft']],
      '#states' => [
        'visible' => [
          ':input[name="payment_method"]' => ['value' => 'bank_transfer'],
        ],
      ],
    ];

    $form['bank_transfer']['instructions'] = [
      '#markup' => '<div class="neft-bank-details">'
        . '<h4>' . $this->t('NEFT / RTGS Bank Details') . '</h4>'
        . '<table class="niftybot-table compact">'
        . '<tr><td><strong>' . $this->t('Account Name') . '</strong></td><td>NiftyBot Trading Pvt Ltd</td></tr>'
        . '<tr><td><strong>' . $this->t('Account Number') . '</strong></td><td>50200012345678</td></tr>'
        . '<tr><td><strong>' . $this->t('IFSC Code') . '</strong></td><td>ICIC0001234</td></tr>'
        . '<tr><td><strong>' . $this->t('Bank') . '</strong></td><td>ICICI Bank</td></tr>'
        . '</table>'
        . '<p>' . $this->t('Transfer the deposit amount and enter your UTR / transaction reference below. An admin will verify the payment before your wallet is credited.') . '</p>'
        . '</div>',
    ];

    $form['bank_transfer']['payment_reference'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UTR / Transaction Reference'),
      '#maxlength' => 255,
      '#description' => $this->t('Enter the reference number from your bank transfer receipt.'),
      '#states' => [
        'required' => [
          ':input[name="payment_method"]' => ['value' => 'bank_transfer'],
        ],
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit UPI deposit request'),
      '#name' => 'submit_upi',
      '#attributes' => ['class' => ['button--primary', 'wallet-deposit-submit-upi']],
      '#states' => [
        'visible' => [
          ':input[name="payment_method"]' => ['value' => 'upi'],
        ],
      ],
    ];

    $form['actions']['submit_neft'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit NEFT deposit request'),
      '#name' => 'submit_neft',
      '#attributes' => ['class' => ['button--primary', 'wallet-deposit-submit-neft']],
      '#states' => [
        'visible' => [
          ':input[name="payment_method"]' => ['value' => 'bank_transfer'],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $this->validateAmount($form_state);

    $payment_method = $form_state->getValue('payment_method');
    if ($payment_method === 'bank_transfer') {
      $reference = trim((string) $form_state->getValue('payment_reference'));
      if ($reference === '') {
        $form_state->setErrorByName('payment_reference', $this->t('Please enter your UTR / transaction reference.'));
      }
    }
    if ($payment_method === 'upi') {
      $reference = trim((string) $form_state->getValue('upi_reference'));
      if ($reference === '') {
        $form_state->setErrorByName('upi_reference', $this->t('Please enter your UPI transaction reference.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement()['#name'] ?? 'submit_upi';

    if ($trigger === 'submit_neft') {
      $this->processPendingDeposit($form_state, 'bank_transfer', trim((string) $form_state->getValue('payment_reference')));
      return;
    }

    $this->processPendingDeposit($form_state, 'upi', trim((string) $form_state->getValue('upi_reference')));
  }

  /**
   * Submits a deposit request for admin verification.
   */
  protected function processPendingDeposit(FormStateInterface $form_state, string $payment_method, string $reference): void {
    if ($reference === '') {
      return;
    }

    $uid = $this->currentUser->id();
    $amount = (float) $form_state->getValue('amount');

    $this->walletService->createDeposit($uid, $amount, $payment_method, $reference);

    $this->messenger()->addStatus($this->t('Your @method deposit request for ₹@amount has been submitted. Your wallet will be credited after admin verification.', [
      '@method' => $payment_method === 'upi' ? 'UPI' : 'NEFT',
      '@amount' => number_format($amount, 2),
    ]));
    $form_state->setRedirect('niftybot_user.wallet');
  }

  /**
   * Validates the deposit amount.
   */
  protected function validateAmount(FormStateInterface $form_state): void {
    $amount = (float) $form_state->getValue('amount');
    if ($amount < 100) {
      $form_state->setErrorByName('amount', $this->t('Minimum deposit amount is ₹100.'));
    }
    if ($amount > 1000000) {
      $form_state->setErrorByName('amount', $this->t('Maximum deposit amount is ₹10,00,000.'));
    }
  }

}
