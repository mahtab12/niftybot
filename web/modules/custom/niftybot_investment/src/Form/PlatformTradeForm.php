<?php

namespace Drupal\niftybot_investment\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form to add or edit platform Nifty trades.
 */
class PlatformTradeForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_platform_trade_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $trade_id = 0) {
    $defaults = [
      'trade_date' => date('Y-m-d'),
      'symbol' => 'NIFTY',
      'strategy' => 'GrassRed Investment',
      'transaction_type' => 'BUY',
      'quantity' => 1,
      'entry_price' => '',
      'exit_price' => '',
      'pnl' => '',
      'notes' => '',
    ];

    if ($trade_id) {
      $existing = $this->database->select('niftybot_platform_trades', 't')
        ->fields('t')
        ->condition('trade_id', $trade_id)
        ->execute()
        ->fetchAssoc();
      if ($existing) {
        $defaults = array_merge($defaults, $existing);
        $form_state->set('trade_id', $trade_id);
      }
    }

    $form['trade_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Trade date'),
      '#required' => TRUE,
      '#default_value' => $defaults['trade_date'],
    ];

    $form['symbol'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Symbol'),
      '#required' => TRUE,
      '#default_value' => $defaults['symbol'],
      '#maxlength' => 32,
    ];

    $form['strategy'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Strategy'),
      '#required' => TRUE,
      '#default_value' => $defaults['strategy'],
      '#maxlength' => 64,
    ];

    $form['transaction_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => [
        'BUY' => $this->t('Buy'),
        'SELL' => $this->t('Sell'),
      ],
      '#required' => TRUE,
      '#default_value' => $defaults['transaction_type'],
    ];

    $form['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Quantity (lots)'),
      '#required' => TRUE,
      '#min' => 1,
      '#default_value' => $defaults['quantity'],
    ];

    $form['entry_price'] = [
      '#type' => 'number',
      '#title' => $this->t('Entry price'),
      '#required' => TRUE,
      '#step' => '0.01',
      '#default_value' => $defaults['entry_price'],
    ];

    $form['exit_price'] = [
      '#type' => 'number',
      '#title' => $this->t('Exit price'),
      '#required' => TRUE,
      '#step' => '0.01',
      '#default_value' => $defaults['exit_price'],
    ];

    $form['pnl'] = [
      '#type' => 'number',
      '#title' => $this->t('Profit / Loss (₹)'),
      '#description' => $this->t('Use negative values for losses.'),
      '#required' => TRUE,
      '#step' => '0.01',
      '#default_value' => $defaults['pnl'],
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#default_value' => $defaults['notes'],
      '#rows' => 3,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $trade_id ? $this->t('Update trade') : $this->t('Save trade'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $now = $this->time->getRequestTime();
    $entry = (float) $form_state->getValue('entry_price');
    $exit = (float) $form_state->getValue('exit_price');
    $pnl = (float) $form_state->getValue('pnl');
    $pnl_pct = $entry > 0 ? round((($exit - $entry) / $entry) * 100, 2) : 0;

    $fields = [
      'trade_date' => $form_state->getValue('trade_date'),
      'symbol' => $form_state->getValue('symbol'),
      'strategy' => $form_state->getValue('strategy'),
      'transaction_type' => $form_state->getValue('transaction_type'),
      'quantity' => (int) $form_state->getValue('quantity'),
      'entry_price' => $entry,
      'exit_price' => $exit,
      'pnl' => $pnl,
      'pnl_percentage' => $pnl_pct,
      'notes' => $form_state->getValue('notes'),
      'updated' => $now,
    ];

    $trade_id = $form_state->get('trade_id');
    if ($trade_id) {
      $this->database->update('niftybot_platform_trades')
        ->fields($fields)
        ->condition('trade_id', $trade_id)
        ->execute();
      $this->messenger()->addStatus($this->t('Trade updated.'));
    }
    else {
      $fields['created'] = $now;
      $this->database->insert('niftybot_platform_trades')->fields($fields)->execute();
      $this->messenger()->addStatus($this->t('Trade recorded.'));
    }

    $form_state->setRedirectUrl(Url::fromRoute('niftybot_investment.admin_trades'));
  }

}
