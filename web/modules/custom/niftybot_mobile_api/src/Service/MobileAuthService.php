<?php

namespace Drupal\niftybot_mobile_api\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Drupal\user\UserAuthInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Issues JWT and proxies OAuth2 token requests for mobile clients.
 */
class MobileAuthService {

  public function __construct(
    protected UserAuthInterface $userAuth,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected JwtAuth $jwtAuth,
    protected HttpKernelInterface $httpKernel,
    protected RequestStack $requestStack,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
  ) {}

  /**
   * Authenticates credentials and returns a JWT access token.
   */
  public function login(string $username, string $password): JsonResponse {
    $username = trim($username);
    if ($username === '' || $password === '') {
      return $this->error('Username and password are required.', 400);
    }

    $uid = (int) $this->userAuth->authenticate($username, $password);
    if ($uid <= 0) {
      return $this->error('Invalid credentials.', 401);
    }

    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account || !$account->isActive()) {
      return $this->error('Account is blocked or inactive.', 403);
    }

    if (!$account->hasPermission('use niftybot mobile api')) {
      return $this->error('This account cannot use the mobile API.', 403);
    }

    user_login_finalize($account);

    try {
      $access_token = $this->jwtAuth->generateToken();
    }
    catch (\Exception $e) {
      return $this->error('Could not issue access token. Check JWT key configuration.', 500);
    }

    $ttl = (int) $this->configFactory->get('niftybot_mobile_api.settings')->get('token_ttl_seconds');

    return new JsonResponse([
      'success' => TRUE,
      'token_type' => 'Bearer',
      'access_token' => $access_token,
      'expires_in' => $ttl > 0 ? $ttl : 3600,
      'auth_method' => 'jwt',
      'oauth' => $this->oauthClientInfo(),
    ]);
  }

  /**
   * Re-issues a JWT when the current Bearer token is still valid.
   */
  public function refresh(): JsonResponse {
    $current = $this->requestStack->getCurrentRequest();
    if (!$current || !$this->jwtAuth->applies($current)) {
      return $this->error('Valid Bearer token required to refresh.', 401);
    }

    if (!$this->jwtAuth->authenticate($current)) {
      return $this->error('Token is invalid or expired.', 401);
    }

    try {
      $access_token = $this->jwtAuth->generateToken();
    }
    catch (\Exception $e) {
      return $this->error('Could not refresh access token.', 500);
    }

    $ttl = (int) $this->configFactory->get('niftybot_mobile_api.settings')->get('token_ttl_seconds');

    return new JsonResponse([
      'success' => TRUE,
      'token_type' => 'Bearer',
      'access_token' => $access_token,
      'expires_in' => $ttl > 0 ? $ttl : 3600,
      'auth_method' => 'jwt',
    ]);
  }

  /**
   * Proxies OAuth2 token requests to Simple OAuth (/oauth/token).
   */
  public function oauthToken(Request $request): Response {
    $sub = Request::create(
      '/oauth/token',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
      $request->getContent()
    );

    return $this->httpKernel->handle($sub, HttpKernelInterface::SUB_REQUEST);
  }

  /**
   * Public OAuth client metadata for Flutter (no secret).
   */
  public function oauthClientInfo(): array {
    $config = $this->configFactory->get('niftybot_mobile_api.settings');
    return [
      'token_url' => '/oauth/token',
      'client_id' => $config->get('oauth_client_id') ?: 'niftybot_mobile',
      'scopes' => 'niftybot:mobile',
      'grant_types' => ['authorization_code', 'refresh_token'],
      'pkce' => TRUE,
      'redirect_uri' => 'niftybot://oauth/callback',
    ];
  }

  /**
   * Returns OAuth client secret once for admins (stored in state on install).
   */
  public function getOAuthClientSecret(): ?string {
    $secret = $this->state->get('niftybot_mobile_api.oauth_client_secret');
    return is_string($secret) && $secret !== '' ? $secret : NULL;
  }

  /**
   * API discovery payload for mobile apps.
   */
  public function apiMeta(): array {
    $base = '/api/mobile/v1';
    return [
      'version' => '1.0.0',
      'auth' => [
        'jwt_login' => $base . '/auth/login',
        'jwt_refresh' => $base . '/auth/refresh',
        'oauth_token' => '/oauth/token',
        'header' => 'Authorization: Bearer {access_token}',
      ],
      'oauth' => $this->oauthClientInfo(),
      'endpoints' => [
        'me' => $base . '/me',
        'dashboard' => $base . '/dashboard',
        'plans' => $base . '/plans',
        'subscription' => $base . '/subscription',
        'wallet' => $base . '/wallet',
        'wallet_transactions' => $base . '/wallet/transactions',
        'wallet_deposit_info' => $base . '/wallet/deposit-info',
        'wallet_deposit' => $base . '/wallet/deposit',
        'wallet_withdraw' => $base . '/wallet/withdraw',
        'kyc' => $base . '/kyc',
        'plan_checkout' => $base . '/plans/{plan_id}/checkout',
        'plan_subscribe' => $base . '/plans/{plan_id}/subscribe',
        'broker' => $base . '/broker',
        'auto_trade_settings' => $base . '/auto-trade/settings',
        'auto_trade_status' => $base . '/auto-trade/{instrument}/status',
        'auto_trade_activate' => $base . '/auto-trade/{instrument}/activate',
        'auto_trade_deactivate' => $base . '/auto-trade/{instrument}/deactivate',
        'auto_trade_exit' => $base . '/auto-trade/{instrument}/exit',
        'notifications' => $base . '/notifications',
      ],
    ];
  }

  protected function error(string $message, int $status): JsonResponse {
    return new JsonResponse([
      'success' => FALSE,
      'message' => $message,
    ], $status);
  }

}
