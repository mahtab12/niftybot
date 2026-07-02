<?php

namespace Drupal\niftybot_mobile_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_mobile_api\Service\MobileAuthService;
use Drupal\niftybot_mobile_api\Service\MobileRegistrationService;
use Drupal\niftybot_mobile_api\Service\MobileUserPayloadService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Mobile authentication and API discovery.
 */
class MobileAuthController extends ControllerBase {

  public function __construct(
    protected MobileAuthService $authService,
    protected MobileRegistrationService $registrationService,
    protected MobileUserPayloadService $payloadService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_mobile_api.auth'),
      $container->get('niftybot_mobile_api.registration'),
      $container->get('niftybot_mobile_api.user_payload'),
    );
  }

  /**
   * Public API metadata for Flutter bootstrap.
   */
  public function meta(): JsonResponse {
    return new JsonResponse([
      'success' => TRUE,
      'data' => $this->authService->apiMeta(),
    ]);
  }

  /**
   * POST username/password → JWT access token.
   */
  public function login(Request $request): JsonResponse {
    $data = $this->decodeJson($request);
    return $this->authService->login(
      (string) ($data['username'] ?? $data['name'] ?? ''),
      (string) ($data['password'] ?? $data['pass'] ?? ''),
    );
  }

  /**
   * POST register → JWT access token.
   */
  public function register(Request $request): JsonResponse {
    $data = $this->decodeJson($request);
    return $this->registrationService->register($data);
  }

  /**
   * POST refresh JWT using current Bearer token.
   */
  public function refresh(): JsonResponse {
    return $this->authService->refresh();
  }

  /**
   * POST proxy to Simple OAuth /oauth/token.
   */
  public function oauthToken(Request $request): Response {
    return $this->authService->oauthToken($request);
  }

  /**
   * GET current user profile.
   */
  public function me(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    return new JsonResponse([
      'success' => TRUE,
      'user' => $this->payloadService->profile($uid),
    ]);
  }

  /**
   * Decodes JSON request body.
   */
  protected function decodeJson(Request $request): array {
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    return $data;
  }

}
