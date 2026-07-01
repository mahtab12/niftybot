<?php

namespace Drupal\niftybot_core\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\niftybot_core\Service\BrokerConnectionService;
use Drupal\niftybot_core\Service\CredentialVault;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Groww broker API credential form.
 */
class BrokerConnectionForm extends FormBase {

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected BrokerConnectionService $brokerConnection,
    protected CredentialVault $credentialVault,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('niftybot_core.broker_connection'),
      $container->get('niftybot_core.credential_vault'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_broker_connection_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $uid = (int) $this->currentUser->id();
    $summary = $this->brokerConnection->attemptAutoConnect($uid, 'groww');
    $connection = $this->brokerConnection->getConnection($uid, 'groww');

    $form['#attached']['library'][] = 'niftybot_core/broker_connection';
    $form['#attributes']['class'][] = 'niftybot-broker-form';
    $form['#prefix'] = Markup::create('<div class="niftybot-broker-page">');
    $form['#suffix'] = Markup::create('</div>');

    $form['hero'] = [
      '#markup' => Markup::create(
        '<div class="card niftybot-broker-hero mb-4"><div class="card-body">'
        . '<div class="niftybot-broker-hero__intro">'
        . '<div class="niftybot-broker-hero__icon" aria-hidden="true"><i class="bi bi-plug"></i></div>'
        . '<div>'
        . '<h2 class="niftybot-broker-hero__title">' . $this->t('Broker connection') . '</h2>'
        . '<p class="niftybot-broker-hero__text">' . $this->t('Link your personal Groww account. API credentials are stored securely for your user account only — not shared with other traders on the platform.') . '</p>'
        . '</div></div></div></div>'
      ),
    ];

    $form['layout'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-broker-page__layout']],
    ];

    $form['layout']['status'] = [
      '#markup' => Markup::create($this->statusCard($summary)),
    ];

    $form['layout']['credentials'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'niftybot-broker-form-card']],
    ];

    $form['layout']['credentials']['body'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card-body', 'niftybot-broker-form-card__body']],
    ];

    $form['layout']['credentials']['body']['heading'] = [
      '#markup' => '<h3 class="niftybot-broker-form__heading">' . $this->t('Your Groww API credentials') . '</h3>'
        . '<p class="niftybot-broker-form__lead">' . $this->t('Each trader connects their own Groww API key and secret. Credentials are encrypted and tied to your login.') . '</p>',
    ];

    if (!empty($summary['connected'])) {
      $form['layout']['credentials']['body']['connected_notice'] = [
        '#markup' => '<div class="alert alert-success mb-3" role="status">'
          . $this->t('Connected as <strong>@id</strong>. Submit new credentials below to replace your stored keys.', [
            '@id' => $summary['broker_user_id'] ?: $this->t('your Groww account'),
          ])
          . '</div>',
      ];
    }
    elseif (!empty($summary['pending_approval'])) {
      $groww_url = $summary['groww_api_keys_url'] ?? BrokerConnectionService::GROWW_API_KEYS_URL;
      $form['layout']['credentials']['body']['pending_notice'] = [
        '#markup' => '<div class="alert alert-warning mb-3" role="status">'
          . $this->t('Your API credentials are saved. <a href="@url" target="_blank" rel="noopener noreferrer">Activate your API key on Groww</a> for today — we will connect automatically when you return.', [
            '@url' => $groww_url,
          ])
          . '</div>',
      ];
    }

    if (!empty($summary['credentials_saved']) && !empty($summary['masked_api_key'])) {
      $form['layout']['credentials']['body']['stored'] = [
        '#markup' => Markup::create($this->storedCredentialsBlock($summary)),
      ];
    }

    $has_stored = !empty($summary['credentials_saved']);

    $form['layout']['credentials']['body']['api_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('API key'),
      '#rows' => 3,
      '#required' => !$has_stored,
      '#description' => $has_stored
        ? $this->t('Leave blank to keep your existing API key.')
        : $this->t('From Groww Cloud → Generate API Key, paste the API Key and Secret shown in the popup. Groww API keys are long JWT strings starting with eyJ. Approve the key for today on the same page.'),
      '#attributes' => [
        'placeholder' => 'eyJraWQiOiJaTUtj…',
        'autocomplete' => 'off',
        'class' => ['niftybot-broker-form__api-key'],
      ],
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['layout']['credentials']['body']['api_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('API secret'),
      '#required' => !$has_stored,
      '#description' => $has_stored
        ? $this->t('Leave blank to keep your existing API secret.')
        : $this->t('Paste the API Secret shown when you generated the key (shown only once). Special characters are allowed. Approve the key for today on Groww Cloud. If using only a daily access token, paste it in the API key field and leave this blank.'),
      '#attributes' => [
        'autocomplete' => 'new-password',
        'class' => ['niftybot-broker-form__api-secret'],
      ],
      '#wrapper_attributes' => ['class' => ['mb-0']],
    ];

    $form['layout']['credentials']['body']['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['niftybot-broker-page__actions']],
    ];
    $form['layout']['credentials']['body']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $has_stored ? $this->t('Update connection') : $this->t('Connect broker'),
    ];

    if ($has_stored) {
      $form['layout']['credentials']['body']['actions']['refresh'] = [
        '#type' => 'submit',
        '#value' => $this->t('Refresh status'),
        '#submit' => ['::refreshConnection'],
        '#limit_validation_errors' => [],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $api_key = trim((string) $form_state->getValue('api_key'));
    $api_secret = trim((string) $form_state->getValue('api_secret'));
    $trigger = $form_state->getTriggeringElement();

    if (($trigger['#op'] ?? '') === 'refresh') {
      return;
    }

    $uid = (int) $this->currentUser->id();
    $existing = $this->brokerConnection->getConnection($uid, 'groww');
    $has_stored = $this->brokerConnection->hasStoredCredentials($existing, $uid, 'groww');

    if ($api_key === '' && !$has_stored) {
      $form_state->setErrorByName('api_key', $this->t('API key is required.'));
    }
    if ($api_secret === '' && !$has_stored && !$this->brokerConnection->looksLikeAccessToken($api_key)) {
      $form_state->setErrorByName('api_secret', $this->t('API secret is required unless you are pasting a daily access token in the API key field.'));
    }
    if ($has_stored && $api_key === '' && $api_secret === '') {
      $form_state->setErrorByName('api_key', $this->t('Enter a new API key or secret to update, or use Refresh status.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = (int) $this->currentUser->id();
    $api_key = trim((string) $form_state->getValue('api_key'));
    $api_secret = trim((string) $form_state->getValue('api_secret'));
    $existing = $this->brokerConnection->getConnection($uid, 'groww');

    if ($existing) {
      if ($api_key === '') {
        $api_key = $this->credentialVault->decrypt($existing->api_key_enc);
      }
      if ($api_secret === '') {
        $api_secret = $this->credentialVault->decrypt($existing->api_secret_enc);
      }
    }

    $result = $this->brokerConnection->saveAndVerify($uid, 'groww', $api_key, $api_secret);
    if (!empty($result['success'])) {
      $this->messenger()->addStatus($this->t('Your Groww broker is connected.'));
    }
    elseif (!empty($result['credentials_saved'])) {
      $this->messenger()->addWarning($result['message'] ?? $this->brokerConnection->pendingActivationMessage());
    }
    else {
      $this->messenger()->addError($result['message'] ?? $this->t('Could not verify broker credentials.'));
    }

    $form_state->setRedirect('niftybot_core.user_broker');
  }

  /**
   * Refreshes broker connection without changing credentials.
   */
  public function refreshConnection(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser->id();
    $result = $this->brokerConnection->refreshConnection($uid, 'groww');
    if (!empty($result['success'])) {
      $this->messenger()->addStatus($this->t('Broker connected successfully.'));
    }
    elseif (!empty($result['credentials_saved']) || !empty($result['pending_approval'])) {
      $this->messenger()->addWarning($result['message'] ?? $this->brokerConnection->pendingActivationMessage());
    }
    else {
      $this->messenger()->addError($result['message'] ?? $this->t('Refresh failed.'));
    }
    $form_state->setRedirect('niftybot_core.user_broker');
  }

  /**
   * Renders the current connection status card.
   *
   * @param array<string, mixed> $summary
   */
  protected function statusCard(array $summary): string {
    if (!empty($summary['connected'])) {
      $status_class = 'success';
      $status_label = $this->t('Connected');
    }
    elseif (!empty($summary['pending_approval'])) {
      $status_class = 'warning';
      $status_label = $this->t('Pending Groww approval');
    }
    elseif (!empty($summary['credentials_saved'])) {
      $status_class = 'danger';
      $status_label = $this->t('Activation required');
    }
    else {
      $status_class = 'secondary';
      $status_label = $this->t('Not connected');
    }

    $balance = $summary['balance'] !== NULL
      ? '₹' . number_format((float) $summary['balance'], 2)
      : '—';
    $auto_trade_url = Url::fromRoute('niftybot_core.user_auto_trade')->toString();
    $groww_url = $summary['groww_api_keys_url'] ?? BrokerConnectionService::GROWW_API_KEYS_URL;
    $masked_key = !empty($summary['masked_api_key'])
      ? Html::escape($summary['masked_api_key'])
      : '';
    $masked_secret = !empty($summary['masked_api_secret'])
      ? Html::escape($summary['masked_api_secret'])
      : '';
    $has_stored_secret = !empty($summary['has_stored_secret']);

    if (!empty($summary['connected'])) {
      $message = $summary['status_message'] ?: $this->t('Your Groww account is linked.');
    }
    elseif (!empty($summary['pending_approval'])) {
      $message = $this->t('Credentials saved. <a href="@url" target="_blank" rel="noopener noreferrer">Approve your API key on Groww</a> for today — connection happens automatically when you return.', [
        '@url' => $groww_url,
      ]);
    }
    elseif (!empty($summary['credentials_saved'])) {
      $message = $summary['status_message'] ?: $this->t('Credentials are saved but Groww is not connected yet.');
    }
    else {
      $message = $this->t('Add your personal Groww API credentials below.');
    }

    $credentials_rows = '';
    if (!empty($summary['credentials_saved']) && $masked_key !== '') {
      $credentials_rows .= '<div class="niftybot-broker-status-grid__item">'
        . '<span class="niftybot-broker-status-grid__label">' . $this->t('API key') . '</span>'
        . '<span class="niftybot-broker-status-grid__value"><code class="niftybot-broker-masked-credential">' . $masked_key . '</code>'
        . ' <span class="badge bg-light text-success border">' . $this->t('Saved') . '</span></span></div>';
      if ($has_stored_secret && $masked_secret !== '') {
        $credentials_rows .= '<div class="niftybot-broker-status-grid__item">'
          . '<span class="niftybot-broker-status-grid__label">' . $this->t('API secret') . '</span>'
          . '<span class="niftybot-broker-status-grid__value"><code class="niftybot-broker-masked-credential">' . $masked_secret . '</code>'
          . ' <span class="badge bg-light text-success border">' . $this->t('Saved') . '</span></span></div>';
      }
    }

    return '<div class="card niftybot-broker-status-card h-100"><div class="card-body">'
      . '<div class="niftybot-broker-status-grid">'
      . '<div class="niftybot-broker-status-grid__item">'
      . '<span class="niftybot-broker-status-grid__label">' . $this->t('Broker') . '</span>'
      . '<span class="niftybot-broker-status-grid__value">Groww</span></div>'
      . '<div class="niftybot-broker-status-grid__item">'
      . '<span class="niftybot-broker-status-grid__label">' . $this->t('Status') . '</span>'
      . '<span class="niftybot-broker-status-grid__value"><span class="badge bg-' . $status_class . '">' . $status_label . '</span></span></div>'
      . $credentials_rows
      . '<div class="niftybot-broker-status-grid__item">'
      . '<span class="niftybot-broker-status-grid__label">' . $this->t('Groww user ID') . '</span>'
      . '<span class="niftybot-broker-status-grid__value"><code>' . ($summary['broker_user_id'] ?: '—') . '</code></span></div>'
      . '<div class="niftybot-broker-status-grid__item">'
      . '<span class="niftybot-broker-status-grid__label">' . $this->t('Available balance') . '</span>'
      . '<span class="niftybot-broker-status-grid__value">' . $balance . '</span></div>'
      . '</div>'
      . '<p class="niftybot-broker-status-card__footer">'
      . $message . ' <a href="' . $auto_trade_url . '">' . $this->t('Go to Auto Trade') . '</a>'
      . '</p></div></div>';
  }

  /**
   * Renders the saved-credentials summary above the credential form fields.
   *
   * @param array<string, mixed> $summary
   */
  protected function storedCredentialsBlock(array $summary): string {
    $masked_key = Html::escape($summary['masked_api_key'] ?? '');
    $masked_secret = Html::escape($summary['masked_api_secret'] ?? '');
    $has_secret = !empty($summary['has_stored_secret']);

    $secret_row = '';
    if ($has_secret && $masked_secret !== '') {
      $secret_row = '<div class="niftybot-broker-stored__row">'
        . '<span class="niftybot-broker-stored__label">' . $this->t('API secret') . '</span>'
        . '<span class="niftybot-broker-stored__value"><code>' . $masked_secret . '</code>'
        . '<span class="badge bg-light text-success border">' . $this->t('Saved') . '</span></span></div>';
    }

    return '<div class="niftybot-broker-stored mb-3" role="status">'
      . '<div class="niftybot-broker-stored__header">'
      . '<i class="bi bi-shield-check" aria-hidden="true"></i>'
      . '<span>' . $this->t('Credentials on file') . '</span></div>'
      . '<div class="niftybot-broker-stored__row">'
      . '<span class="niftybot-broker-stored__label">' . $this->t('API key') . '</span>'
      . '<span class="niftybot-broker-stored__value"><code>' . $masked_key . '</code>'
      . '<span class="badge bg-light text-success border">' . $this->t('Saved') . '</span></span></div>'
      . $secret_row
      . '<p class="niftybot-broker-stored__hint">' . $this->t('Leave the fields below blank to keep these credentials, or enter new values to replace them.') . '</p>'
      . '</div>';
  }

}
