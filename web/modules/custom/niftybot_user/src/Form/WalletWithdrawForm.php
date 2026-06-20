<?php

namespace Drupal\niftybot_user\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wallet withdrawal form.
 */
class WalletWithdrawForm extends FormBase {

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
    return 'niftybot_wallet_withdraw_form';
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

    $balance = (float) $wallet;

    $form['current_balance'] = [
      '#markup' => '<div class="wallet-balance"><strong>' . $this->t('Available Balance:') . '</strong> ₹' . number_format($balance, 2) . '</div>',
    ];

    if ($balance < 100) {
      $form['insufficient'] = [
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('Insufficient balance for withdrawal. Minimum withdrawal is ₹100.') . '</div>',
      ];
      return $form;
    }

    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Withdrawal Amount (₹)'),
      '#required' => TRUE,
      '#min' => 100,
      '#max' => $balance,
      '#step' => '0.01',
      '#description' => $this->t('Minimum: ₹100. Maximum: ₹@max', [
        '@max' => number_format($balance, 2),
      ]),
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes (optional)'),
      '#rows' => 2,
    ];

    $form['info'] = [
      '#markup' => '<p class="description">' .
        $this->t('Withdrawal will be processed to your registered bank account within 2-3 business days.') . '</p>',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Request Withdrawal'),
      '#attributes' => ['class' => ['button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $amount = $form_state->getValue('amount');

    $balance = (float) $this->database->select('niftybot_wallet', 'w')
      ->fields('w', ['balance'])
      ->condition('uid', $this->currentUser->id())
      ->execute()
      ->fetchField();

    if ($amount > $balance) {
      $form_state->setErrorByName('amount', $this->t('Insufficient balance.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();
    $amount = $form_state->getValue('amount');
    $now = \Drupal::time()->getRequestTime();

    $this->database->update('niftybot_wallet')
      ->expression('balance', 'balance - :amount', [':amount' => $amount])
      ->condition('uid', $uid)
      ->execute();

    $this->database->insert('niftybot_wallet_transactions')
      ->fields([
        'uid' => $uid,
        'type' => 'withdrawal',
        'amount' => $amount,
        'status' => 'pending',
        'payment_method' => 'bank_transfer',
        'notes' => $form_state->getValue('notes'),
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    $this->messenger()->addStatus($this->t('Withdrawal request for ₹@amount has been submitted. It will be processed within 2-3 business days.', [
      '@amount' => number_format($amount, 2),
    ]));
    $form_state->setRedirect('niftybot_user.wallet');
  }

}
