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
      '#title' => $this->t('Require Active Subscription for Trading'),
      '#default_value' => $config->get('require_subscription') ?? TRUE,
    ];

    $form['trading'] = [
      '#type' => 'details',
      '#title' => $this->t('Trading Settings'),
      '#open' => TRUE,
    ];

    $form['trading']['trading_api_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Trading API Base URL'),
      '#description' => $this->t('Base URL of the Python trading API service.'),
      '#default_value' => $config->get('trading_api_base_url') ?? 'http://localhost:8000',
      '#required' => TRUE,
    ];

    $form['trading']['trading_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Trading API Key'),
      '#description' => $this->t('API key for authenticating with the trading service.'),
      '#default_value' => $config->get('trading_api_key') ?? '',
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('niftybot_core.settings')
      ->set('platform_name', $form_state->getValue('platform_name'))
      ->set('enable_registration', $form_state->getValue('enable_registration'))
      ->set('require_kyc', $form_state->getValue('require_kyc'))
      ->set('require_subscription', $form_state->getValue('require_subscription'))
      ->set('trading_api_base_url', $form_state->getValue('trading_api_base_url'))
      ->set('trading_api_key', $form_state->getValue('trading_api_key'))
      ->set('max_orders_per_day', $form_state->getValue('max_orders_per_day'))
      ->set('supported_exchanges', array_filter($form_state->getValue('supported_exchanges')))
      ->set('email_notifications', $form_state->getValue('email_notifications'))
      ->set('notify_on_order_execution', $form_state->getValue('notify_on_order_execution'))
      ->set('notify_on_kyc_status', $form_state->getValue('notify_on_kyc_status'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
