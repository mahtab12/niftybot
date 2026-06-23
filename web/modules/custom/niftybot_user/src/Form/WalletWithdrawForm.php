<?php

namespace Drupal\niftybot_user\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wallet withdrawal form.
 */
class WalletWithdrawForm extends FormBase {

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
    return 'niftybot_wallet_withdraw_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();
    $balance = $this->walletService->getWalletBalance($uid);
    $pending = $this->walletService->getPendingWithdrawalTotal($uid);
    $available = $this->walletService->getAvailableBalance($uid);

    $balance_markup = '<div class="wallet-balance"><strong>' . $this->t('Wallet Balance:') . '</strong> ₹' . number_format($balance, 2) . '</div>';
    if ($pending > 0) {
      $balance_markup .= '<div class="wallet-balance-meta"><p>' . $this->t('Pending withdrawals: ₹@pending', [
        '@pending' => number_format($pending, 2),
      ]) . '</p><p>' . $this->t('Available to withdraw: ₹@available', [
        '@available' => number_format($available, 2),
      ]) . '</p></div>';
    }

    $form['current_balance'] = [
      '#markup' => $balance_markup,
    ] + WalletService::walletRenderCache($uid);

    if ($available < 100) {
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
      '#max' => $available,
      '#step' => '0.01',
      '#description' => $this->t('Minimum: ₹100. Maximum: ₹@max', [
        '@max' => number_format($available, 2),
      ]),
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes (optional)'),
      '#rows' => 2,
    ];

    $form['info'] = [
      '#markup' => '<p class="description">' .
        $this->t('Your request will be reviewed by an admin. The amount will be deducted from your wallet only after approval and transferred to your registered bank account within 2-3 business days.') . '</p>',
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
    $amount = (float) $form_state->getValue('amount');
    $available = $this->walletService->getAvailableBalance($this->currentUser->id());

    if ($amount > $available) {
      $form_state->setErrorByName('amount', $this->t('Insufficient available balance. You may have pending withdrawal requests.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();
    $amount = (float) $form_state->getValue('amount');

    $this->walletService->createWithdrawalRequest($uid, $amount, $form_state->getValue('notes'));

    $this->messenger()->addStatus($this->t('Withdrawal request for ₹@amount has been submitted. Your wallet will be debited after admin approval.', [
      '@amount' => number_format($amount, 2),
    ]));
    $form_state->setRedirect('niftybot_user.wallet');
  }

}
