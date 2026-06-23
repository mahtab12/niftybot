<?php

namespace Drupal\niftybot_user\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\niftybot_user\Service\WalletService;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin form to approve or reject wallet withdrawal requests.
 */
class WalletWithdrawalReviewForm extends FormBase {

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
    AccountProxyInterface $current_user,
    WalletService $wallet_service,
  ) {
    $this->currentUser = $current_user;
    $this->walletService = $wallet_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('niftybot_user.wallet_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_wallet_withdrawal_review_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $transaction_id = NULL) {
    $transaction = $this->walletService->loadTransaction((int) $transaction_id);

    if (!$transaction || $transaction->type !== 'withdrawal') {
      throw new NotFoundHttpException();
    }

    $user = User::load($transaction->uid);
    $balance = $this->walletService->getWalletBalance((int) $transaction->uid);

    $form_state->set('transaction_id', (int) $transaction_id);

    $form['details'] = [
      '#type' => 'details',
      '#title' => $this->t('Withdrawal Request'),
      '#open' => TRUE,
    ];

    $form['details']['info'] = [
      '#type' => 'table',
      '#rows' => [
        [$this->t('Transaction ID'), $transaction->transaction_id],
        [$this->t('User'), $user ? $user->getAccountName() : $this->t('Unknown')],
        [$this->t('Email'), $user ? $user->getEmail() : $this->t('Unknown')],
        [$this->t('Amount'), '₹' . number_format((float) $transaction->amount, 2)],
        [$this->t('Current Wallet Balance'), '₹' . number_format($balance, 2)],
        [$this->t('User Notes'), $transaction->notes ?: $this->t('None')],
        [$this->t('Status'), strtoupper($transaction->status)],
        [$this->t('Requested'), date('d M Y H:i', $transaction->created)],
      ],
    ];

    if ($transaction->status !== 'pending') {
      $form['notice'] = [
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('This withdrawal request has already been processed.') .
          '</div>',
      ];
      return $form;
    }

    if ($balance < (float) $transaction->amount) {
      $form['insufficient'] = [
        '#markup' => '<div class="messages messages--error">' .
          $this->t('The user no longer has sufficient balance to approve this withdrawal.') .
          '</div>',
      ];
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
        'approve' => $this->t('Approve withdrawal and debit wallet'),
        'reject' => $this->t('Reject withdrawal request'),
      ],
      '#required' => TRUE,
      '#default_value' => 'approve',
    ];

    $form['decision']['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Admin Notes'),
      '#description' => $this->t('Required when rejecting the withdrawal.'),
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
      if ($this->walletService->completeWithdrawal($transaction_id)) {
        $this->messenger()->addStatus($this->t('Withdrawal approved and wallet debited.'));
      }
      else {
        $this->messenger()->addError($this->t('Unable to approve this withdrawal. The user may have insufficient balance.'));
      }
    }
    else {
      $notes = trim((string) $form_state->getValue('notes'));
      if ($this->walletService->rejectWithdrawal($transaction_id, $notes)) {
        $this->messenger()->addStatus($this->t('Withdrawal request rejected.'));
      }
      else {
        $this->messenger()->addError($this->t('Unable to reject this withdrawal. It may have already been processed.'));
      }
    }

    $form_state->setRedirect('niftybot_user.wallet_withdrawals_admin');
  }

}
