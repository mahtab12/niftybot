<?php

namespace Drupal\niftybot_user\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wallet deposit form.
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
    return 'niftybot_wallet_deposit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $wallet = $this->database->select('niftybot_wallet', 'w')
      ->fields('w', ['balance'])
      ->condition('uid', $this->currentUser->id())
      ->execute()
      ->fetchField();

    $form['current_balance'] = [
      '#markup' => '<div class="wallet-balance"><strong>' . $this->t('Current Balance:') . '</strong> ₹' . number_format((float) $wallet, 2) . '</div>',
    ];

    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Deposit Amount (₹)'),
      '#required' => TRUE,
      '#min' => 100,
      '#max' => 1000000,
      '#step' => '0.01',
      '#description' => $this->t('Minimum deposit: ₹100. Maximum: ₹10,00,000.'),
    ];

    $form['payment_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Payment Method'),
      '#options' => [
        'upi' => $this->t('UPI'),
        'bank_transfer' => $this->t('Bank Transfer (NEFT/RTGS)'),
        'card' => $this->t('Debit/Credit Card'),
      ],
      '#required' => TRUE,
      '#default_value' => 'upi',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Proceed to Payment'),
      '#attributes' => ['class' => ['button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $amount = $form_state->getValue('amount');
    if ($amount < 100) {
      $form_state->setErrorByName('amount', $this->t('Minimum deposit amount is ₹100.'));
    }
    if ($amount > 1000000) {
      $form_state->setErrorByName('amount', $this->t('Maximum deposit amount is ₹10,00,000.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();
    $amount = $form_state->getValue('amount');
    $now = \Drupal::time()->getRequestTime();

    $this->database->insert('niftybot_wallet_transactions')
      ->fields([
        'uid' => $uid,
        'type' => 'deposit',
        'amount' => $amount,
        'status' => 'pending',
        'payment_method' => $form_state->getValue('payment_method'),
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    // TODO: Integrate with actual payment gateway (Razorpay, Cashfree, etc.)
    // For now, auto-approve the deposit for development.
    $this->database->update('niftybot_wallet')
      ->expression('balance', 'balance + :amount', [':amount' => $amount])
      ->condition('uid', $uid)
      ->execute();

    $this->database->update('niftybot_wallet_transactions')
      ->fields(['status' => 'completed', 'updated' => $now])
      ->condition('uid', $uid)
      ->condition('status', 'pending')
      ->orderBy('transaction_id', 'DESC')
      ->execute();

    $this->messenger()->addStatus($this->t('₹@amount has been deposited to your wallet.', [
      '@amount' => number_format($amount, 2),
    ]));
    $form_state->setRedirect('niftybot_user.wallet');
  }

}
