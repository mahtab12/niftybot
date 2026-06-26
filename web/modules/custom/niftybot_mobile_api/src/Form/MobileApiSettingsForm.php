<?php

namespace Drupal\niftybot_mobile_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\niftybot_mobile_api\Service\MobileAuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin settings for the NiftyBot mobile API integration.
 */
class MobileApiSettingsForm extends ConfigFormBase {

  public function __construct(
    protected MobileAuthService $authService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_mobile_api.auth'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['niftybot_mobile_api.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'niftybot_mobile_api_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('niftybot_mobile_api.settings');
    $oauth = $this->authService->oauthClientInfo();
    $secret = $this->authService->getOAuthClientSecret();

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Configure REST endpoints for the Flutter mobile app. Use <code>/api/mobile/v1/meta</code> for API discovery.') . '</p>',
    ];

    $form['oauth_client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth client ID'),
      '#default_value' => $config->get('oauth_client_id') ?: 'niftybot_mobile',
      '#required' => TRUE,
    ];

    $form['token_ttl_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('JWT access token TTL (seconds)'),
      '#default_value' => $config->get('token_ttl_seconds') ?: 3600,
      '#min' => 300,
      '#required' => TRUE,
    ];

    $form['flutter'] = [
      '#type' => 'details',
      '#title' => $this->t('Flutter integration'),
      '#open' => TRUE,
    ];

    $form['flutter']['login_url'] = [
      '#type' => 'item',
      '#title' => $this->t('JWT login endpoint'),
      '#markup' => '<code>POST /api/mobile/v1/auth/login</code>',
    ];

    $form['flutter']['oauth_token_url'] = [
      '#type' => 'item',
      '#title' => $this->t('OAuth token endpoint'),
      '#markup' => '<code>POST /oauth/token</code> or <code>POST /api/mobile/v1/auth/oauth/token</code>',
    ];

    $form['flutter']['oauth_client'] = [
      '#type' => 'item',
      '#title' => $this->t('OAuth client ID (public)'),
      '#markup' => '<code>' . htmlspecialchars($oauth['client_id']) . '</code>',
    ];

    if ($secret) {
      $form['flutter']['oauth_secret'] = [
        '#type' => 'item',
        '#title' => $this->t('OAuth client secret (store securely in Flutter build secrets)'),
        '#markup' => '<code>' . htmlspecialchars($secret) . '</code>',
      ];
    }

    $form['flutter']['auth_header'] = [
      '#type' => 'item',
      '#title' => $this->t('Authenticated requests'),
      '#markup' => '<code>Authorization: Bearer {access_token}</code>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('niftybot_mobile_api.settings')
      ->set('oauth_client_id', $form_state->getValue('oauth_client_id'))
      ->set('token_ttl_seconds', (int) $form_state->getValue('token_ttl_seconds'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
