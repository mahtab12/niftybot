<?php

namespace Drupal\niftybot_user\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\niftybot_user\Service\WalletService;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin form to verify or reject NEFT wallet deposit requests.
 */
class WalletDepositReviewForm extends FormBase {

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
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs the form.
   */
  public function __construct(
    Connection $database,
    AccountProxyInterface $current_user,
    WalletService $wallet_service,
    ModuleHandlerInterface $module_handler,
  ) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->walletService = $wallet_service;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('niftybot_user.wallet_service'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_wallet_deposit_review_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $transaction_id = NULL) {
    $transaction = $this->walletService->loadTransaction((int) $transaction_id);

    if (!$transaction || $transaction->type !== 'deposit' || !in_array($transaction->payment_method, ['upi', 'bank_transfer'], TRUE)) {
      throw new NotFoundHttpException();
    }

    $method_label = $transaction->payment_method === 'upi'
      ? $this->t('UPI')
      : $this->t('NEFT / RTGS');

    $purpose_label = $this->t('Wallet deposit');
    if (!empty($transaction->notes) && preg_match('/^subscription:\d+$/', (string) $transaction->notes)) {
      $purpose_label = $this->t('Subscription payment');
    }

    $user = User::load($transaction->uid);
    $form_state->set('transaction_id', (int) $transaction_id);

    $form['details'] = [
      '#type' => 'details',
      '#title' => $this->t('Deposit Request'),
      '#open' => TRUE,
    ];

    $form['details']['info'] = [
      '#type' => 'table',
      '#rows' => [
        [$this->t('Transaction ID'), $transaction->transaction_id],
        [$this->t('Purpose'), $purpose_label],
        [$this->t('User'), $user ? $user->getAccountName() : $this->t('Unknown')],
        [$this->t('Email'), $user ? $user->getEmail() : $this->t('Unknown')],
        [$this->t('Payment Method'), $method_label],
        [$this->t('Amount'), '₹' . number_format((float) $transaction->amount, 2)],
        [$this->t('Payment Reference'), $transaction->payment_reference ?: $this->t('Not provided')],
        [$this->t('Status'), strtoupper($transaction->status)],
        [$this->t('Requested'), date('d M Y H:i', $transaction->created)],
      ],
    ];

    if ($transaction->status !== 'pending') {
      $form['notice'] = [
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('This deposit request has already been processed.') .
          '</div>',
      ];
      return $form;
    }

    $form['decision'] = [
      '#type' => 'details',
      '#title' => $this->t('Verification'),
      '#open' => TRUE,
    ];

    $form['decision']['action'] = [
      '#type' => 'radios',
      '#title' => $this->t('Decision'),
      '#options' => [
        'approve' => $this->t('Verify payment and credit wallet'),
        'reject' => $this->t('Reject deposit request'),
      ],
      '#required' => TRUE,
      '#default_value' => 'approve',
    ];

    $form['decision']['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Admin Notes'),
      '#description' => $this->t('Required when rejecting the deposit.'),
      '#states' => [
        'required' => [
          ':input[name="action"]' => ['value' => 'reject'],
        ],
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Decision'),
      '#attributes' => ['class' => ['button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('action') === 'reject' && trim((string) $form_state->getValue('notes')) === '') {
      $form_state->setErrorByName('notes', $this->t('Please provide a reason for rejection.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $transaction_id = (int) $form_state->get('transaction_id');
    $action = $form_state->getValue('action');

    if ($action === 'approve') {
      $transaction = $this->walletService->loadTransaction($transaction_id);
      $handled = array_filter($this->moduleHandler->invokeAll('niftybot_wallet_deposit_approve', [$transaction]));
      if ($handled) {
        $this->messenger()->addStatus($this->t('Deposit verified.'));
      }
      elseif ($this->walletService->completeDeposit($transaction_id)) {
        $this->messenger()->addStatus($this->t('Deposit verified and wallet credited.'));
      }
      else {
        $this->messenger()->addError($this->t('Unable to complete this deposit. It may have already been processed.'));
      }
    }
    else {
      $notes = trim((string) $form_state->getValue('notes'));
      $transaction = $this->walletService->loadTransaction($transaction_id);
      $this->moduleHandler->invokeAll('niftybot_wallet_deposit_reject', [$transaction, $notes]);
      if ($this->walletService->rejectDeposit($transaction_id, $notes)) {
        $this->messenger()->addStatus($this->t('Deposit request rejected.'));
      }
      else {
        $this->messenger()->addError($this->t('Unable to reject this deposit. It may have already been processed.'));
      }
    }

    $form_state->setRedirect('niftybot_user.wallet_deposits_admin');
  }

}
