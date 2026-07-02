<?php

namespace Drupal\niftybot_mobile_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_core\Service\BrokerConnectionService;
use Drupal\niftybot_core\Service\CredentialVault;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Groww broker connection for mobile clients.
 */
class MobileBrokerController extends ControllerBase {

  public function __construct(
    protected BrokerConnectionService $brokerConnection,
    protected CredentialVault $credentialVault,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_core.broker_connection'),
      $container->get('niftybot_core.credential_vault'),
    );
  }

  /**
   * Saves and verifies Groww API credentials.
   */
  public function connect(Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }

    $api_key = trim((string) ($data['api_key'] ?? ''));
    $api_secret = trim((string) ($data['api_secret'] ?? ''));
    $existing = $this->brokerConnection->getConnection($uid, 'groww');
    $has_stored = $this->brokerConnection->hasStoredCredentials($existing, $uid, 'groww');

    if ($api_key === '' && !$has_stored) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'API key is required.',
      ], 400);
    }
    if ($api_secret === '' && !$has_stored && !$this->brokerConnection->looksLikeAccessToken($api_key)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'API secret is required unless you are pasting a daily access token in the API key field.',
      ], 400);
    }
    if ($has_stored && $api_key === '' && $api_secret === '') {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Enter a new API key or secret to update, or use refresh.',
      ], 400);
    }

    if ($existing) {
      if ($api_key === '') {
        $api_key = $this->credentialVault->decrypt($existing->api_key_enc);
      }
      if ($api_secret === '') {
        $api_secret = $this->credentialVault->decrypt($existing->api_secret_enc);
      }
    }

    $result = $this->brokerConnection->saveAndVerify($uid, 'groww', $api_key, $api_secret);
    $summary = $this->brokerConnection->attemptAutoConnect($uid, 'groww');

    $ok = !empty($result['success']) || !empty($result['credentials_saved']);
    $status = !empty($result['success']) ? 200 : ($ok ? 200 : 400);

    return new JsonResponse([
      'success' => $ok,
      'message' => $result['message'] ?? '',
      'broker' => $summary,
      'connected' => !empty($summary['connected']),
      'credentials_saved' => !empty($result['credentials_saved']),
      'pending_approval' => !empty($summary['pending_approval']),
    ], $status);
  }

  /**
   * Re-verifies stored credentials without changing them.
   */
  public function refresh(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $result = $this->brokerConnection->refreshConnection($uid, 'groww');
    $summary = $this->brokerConnection->attemptAutoConnect($uid, 'groww');

    return new JsonResponse([
      'success' => !empty($result['success']),
      'message' => $result['message'] ?? '',
      'broker' => $summary,
      'connected' => !empty($summary['connected']),
      'pending_approval' => !empty($summary['pending_approval']),
    ], !empty($result['success']) ? 200 : 400);
  }

}
