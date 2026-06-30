<?php

namespace Drupal\niftybot_investment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\niftybot_investment\Service\InvestmentService;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm and start an FXC investment plan.
 */
class InvestForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected InvestmentService $investmentService,
    protected WalletService $walletService,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_investment.investment_service'),
      $container->get('niftybot_user.wallet_service'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_invest_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $plan_id = 0) {
    $plan = $this->investmentService->getPlan($plan_id);
    if (!$plan || $plan->status !== 'active') {
      $this->messenger()->addError($this->t('Investment plan not found.'));
      $form_state->setRedirect('niftybot_investment.plans');
      return $form;
    }

    if ($this->investmentService->getActiveInvestment((int) $this->currentUser->id())) {
      $this->messenger()->addWarning($this->t('You already have an active GrassRed investment.'));
      $form_state->setRedirect('niftybot_investment.dashboard');
      return $form;
    }

    $uid = (int) $this->currentUser->id();
    $balance = $this->walletService->getWalletBalance($uid);
    $amount = (float) $plan->amount;

    $form['#attached']['library'][] = 'niftybot_investment/investment';
    $form['plan_id'] = ['#type' => 'value', '#value' => $plan_id];

    $form['summary'] = [
      '#markup' => '<div class="invest-summary">'
        . '<h3>' . $this->t('@name', ['@name' => $plan->name]) . '</h3>'
        . '<p><strong>' . $this->t('Principal:') . '</strong> ₹' . number_format($amount, 2) . '</p>'
        . '<p><strong>' . $this->t('Weekly AI profit:') . '</strong> '
        . number_format((float) $plan->weekly_return_min, 1) . '% – '
        . number_format((float) $plan->weekly_return_max, 1) . '%</p>'
        . '<p><strong>' . $this->t('Your wallet balance:') . '</strong> ₹' . number_format($balance, 2) . '</p>'
        . '<p class="invest-note">' . $this->t('GrassRed AI algo strategies accrue profit daily. Payouts credit your wallet every Saturday morning.') . '</p>'
        . '</div>',
    ] + WalletService::walletRenderCache($uid);

    if ($balance < $amount) {
      $form['insufficient'] = [
        '#markup' => '<p class="messages messages--warning">'
          . $this->t('Insufficient balance. Please <a href=":url">deposit funds</a> first.', [
            ':url' => Url::fromRoute('niftybot_user.wallet_deposit')->toString(),
          ])
          . '</p>',
      ];
      return $form;
    }

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand that ₹@amount will be debited from my wallet and invested in GrassRed Investment.', [
        '@amount' => number_format($amount, 2),
      ]),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start investment'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = (int) $this->currentUser->id();
    $plan_id = (int) $form_state->getValue('plan_id');

    try {
      $this->investmentService->createInvestment($uid, $plan_id);
      $this->messenger()->addStatus($this->t('Your GrassRed investment is now active. AI algo profit will accrue through the week.'));
      $form_state->setRedirect('niftybot_investment.dashboard');
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRedirect('niftybot_investment.plans');
    }
  }

}
