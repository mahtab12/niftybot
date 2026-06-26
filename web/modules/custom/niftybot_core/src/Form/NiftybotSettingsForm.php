<?php

namespace Drupal\niftybot_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * NiftyBot platform settings form.
 */
class NiftybotSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['niftybot_core.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('niftybot_core.settings');

    $form['platform'] = [
      '#type' => 'details',
      '#title' => $this->t('Platform Settings'),
      '#open' => TRUE,
    ];

    $form['platform']['platform_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Platform Name'),
      '#default_value' => $config->get('platform_name') ?? 'NiftyBot',
      '#required' => TRUE,
    ];

    $form['platform']['member_id_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Member ID prefix'),
      '#description' => $this->t('Three uppercase letters used for member IDs (e.g. NBX00001).'),
      '#default_value' => $config->get('member_id_prefix') ?? 'NBX',
      '#required' => TRUE,
      '#maxlength' => 3,
      '#size' => 4,
      '#attributes' => [
        'pattern' => '[A-Za-z]{3}',
        'style' => 'text-transform: uppercase;',
      ],
    ];

    $form['platform']['enable_registration'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable User Registration'),
      '#default_value' => $config->get('enable_registration') ?? TRUE,
    ];

    $form['platform']['require_kyc'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require KYC Verification Before Trading'),
      '#default_value' => $config->get('require_kyc') ?? TRUE,
    ];

    $form['platform']['require_subscription'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require Active Subscription for Manual Trading'),
      '#description' => $this->t('Auto-trade can still be used with pay-per-trade wallet balance when this is enabled.'),
      '#default_value' => $config->get('require_subscription') ?? TRUE,
    ];

    $form['platform']['auto_trade_per_trade_fee'] = [
      '#type' => 'number',
      '#title' => $this->t('Auto-trade fee per executed algo trade (₹)'),
      '#default_value' => $config->get('auto_trade_per_trade_fee') ?? 100,
      '#min' => 1,
      '#step' => 1,
    ];

    $form['platform']['auto_trade_min_wallet_balance'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum wallet balance for pay-per-trade auto-trade (₹)'),
      '#default_value' => $config->get('auto_trade_min_wallet_balance') ?? 100,
      '#min' => 1,
      '#step' => 1,
    ];

    $form['trading_api'] = [
      '#type' => 'details',
      '#title' => $this->t('Trading Service API'),
      '#description' => $this->t('Credentials for Drupal to call the Python FastAPI trading service. Must match <code>API_KEY</code> in <code>trading_service/.env</code>. Groww broker credentials stay in that file.'),
      '#open' => TRUE,
    ];

    $form['trading_api']['trading_api_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Trading API Base URL'),
      '#description' => $this->t('Use <code>http://trading:8000</code> when running via DDEV, or <code>http://localhost:8000</code> from your host machine.'),
      '#default_value' => $config->get('trading_api_base_url') ?? 'http://trading:8000',
      '#required' => TRUE,
    ];

    $form['trading_api']['trading_api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Trading Service API Key'),
      '#description' => $this->t('Sent as the <code>X-API-Key</code> header on every request to the trading service. Copy the <code>API_KEY</code> value from <code>trading_service/.env</code>.'),
      '#size' => 60,
    ];

    if ($config->get('trading_api_key')) {
      $form['trading_api']['trading_api_key']['#description'] .= ' ' . $this->t('An API key is currently saved. Leave blank to keep the existing value.');
    }

    $form['trading'] = [
      '#type' => 'details',
      '#title' => $this->t('Trading Settings'),
      '#open' => TRUE,
    ];

    $form['trading']['max_orders_per_day'] = [
      '#type' => 'number',
      '#title' => $this->t('Default Max Orders Per Day'),
      '#default_value' => $config->get('max_orders_per_day') ?? 50,
      '#min' => 1,
    ];

    $form['trading']['supported_exchanges'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Supported Exchanges'),
      '#options' => [
        'NSE' => $this->t('NSE (National Stock Exchange)'),
        'BSE' => $this->t('BSE (Bombay Stock Exchange)'),
        'NFO' => $this->t('NFO (NSE F&O)'),
        'BFO' => $this->t('BFO (BSE F&O)'),
        'MCX' => $this->t('MCX (Multi Commodity Exchange)'),
      ],
      '#default_value' => $config->get('supported_exchanges') ?? ['NSE', 'BSE'],
    ];

    $form['notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Notification Settings'),
      '#open' => FALSE,
    ];

    $form['notifications']['email_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Email Notifications'),
      '#default_value' => $config->get('email_notifications') ?? TRUE,
    ];

    $form['notifications']['notify_on_order_execution'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notify on Order Execution'),
      '#default_value' => $config->get('notify_on_order_execution') ?? TRUE,
    ];

    $form['notifications']['notify_on_kyc_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notify on KYC Status Change'),
      '#default_value' => $config->get('notify_on_kyc_status') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $prefix = strtoupper(trim((string) $form_state->getValue('member_id_prefix')));
    if (!preg_match('/^[A-Z]{3}$/', $prefix)) {
      $form_state->setErrorByName('member_id_prefix', $this->t('Member ID prefix must be exactly 3 letters.'));
    }
    else {
      $form_state->setValue('member_id_prefix', $prefix);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('niftybot_core.settings')
      ->set('platform_name', $form_state->getValue('platform_name'))
      ->set('member_id_prefix', $form_state->getValue('member_id_prefix'))
      ->set('enable_registration', $form_state->getValue('enable_registration'))
      ->set('require_kyc', $form_state->getValue('require_kyc'))
      ->set('require_subscription', $form_state->getValue('require_subscription'))
      ->set('auto_trade_per_trade_fee', $form_state->getValue('auto_trade_per_trade_fee'))
      ->set('auto_trade_min_wallet_balance', $form_state->getValue('auto_trade_min_wallet_balance'))
      ->set('trading_api_base_url', $form_state->getValue('trading_api_base_url'))
      ->set('max_orders_per_day', $form_state->getValue('max_orders_per_day'))
      ->set('supported_exchanges', array_filter($form_state->getValue('supported_exchanges')))
      ->set('email_notifications', $form_state->getValue('email_notifications'))
      ->set('notify_on_order_execution', $form_state->getValue('notify_on_order_execution'))
      ->set('notify_on_kyc_status', $form_state->getValue('notify_on_kyc_status'))
      ->save();

    $api_key = trim((string) $form_state->getValue('trading_api_key'));
    if ($api_key !== '') {
      $this->config('niftybot_core.settings')
        ->set('trading_api_key', $api_key)
        ->save();
    }

    parent::submitForm($form, $form_state);
  }

}
