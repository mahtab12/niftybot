<?php

namespace Drupal\niftybot_user\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_user\Service\AvatarService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * API for saving preset avatar selections.
 */
class UserAvatarApiController extends ControllerBase {

  public function __construct(
    protected AvatarService $avatarService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_user.avatar_service'),
    );
  }

  /**
   * POST — save the user's avatar choice.
   */
  public function save(Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $data = json_decode($request->getContent(), TRUE);
    $avatar_id = is_array($data) ? (string) ($data['avatar_id'] ?? '') : '';

    if ($avatar_id === '') {
      return new JsonResponse([
        'success' => FALSE,
        'message' => (string) $this->t('Please choose an avatar.'),
      ], 400);
    }

    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$user) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => (string) $this->t('User not found.'),
      ], 404);
    }

    if (!$this->avatarService->setUserAvatar($user, $avatar_id)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => (string) $this->t('Avatar could not be saved.'),
      ], 500);
    }

    $normalized = $this->avatarService->getUserAvatarId($user);
    return new JsonResponse([
      'success' => TRUE,
      'avatar_id' => $normalized,
      'avatar_url' => $this->avatarService->getAvatarUrl($normalized),
      'message' => (string) $this->t('Profile picture updated.'),
    ]);
  }

}
