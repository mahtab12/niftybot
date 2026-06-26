<?php

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_core\Service\InAppNotificationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * In-app notification API for the dashboard header.
 */
class UserNotificationController extends ControllerBase {

  public function __construct(
    protected InAppNotificationService $notifications,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_core.in_app_notifications'),
    );
  }

  /**
   * Lists recent trade alerts for the current user.
   */
  public function list(Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $limit = (int) $request->query->get('limit', 20);
    return new JsonResponse([
      'success' => TRUE,
      'unread_count' => $this->notifications->getUnreadCount($uid),
      'alerts' => $this->notifications->getAlerts($uid, $limit),
    ]);
  }

  /**
   * Returns unread count only (lightweight poll).
   */
  public function unreadCount(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    return new JsonResponse([
      'success' => TRUE,
      'unread_count' => $this->notifications->getUnreadCount($uid),
    ]);
  }

  /**
   * Marks a single alert as read.
   */
  public function markRead(int $alert_id): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $this->notifications->markRead($uid, $alert_id);
    return new JsonResponse([
      'success' => TRUE,
      'unread_count' => $this->notifications->getUnreadCount($uid),
    ]);
  }

  /**
   * Marks all alerts as read.
   */
  public function markAllRead(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $this->notifications->markAllRead($uid);
    return new JsonResponse([
      'success' => TRUE,
      'unread_count' => 0,
    ]);
  }

}
