<?php

namespace Drupal\niftybot_mobile_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_core\Service\AutoTradeUserService;
use Drupal\niftybot_core\Service\BrokerManager;
use Drupal\niftybot_core\Service\InAppNotificationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Auto-trade and notifications for mobile clients.
 */
class MobileTradingController extends ControllerBase {

  public function __construct(
    protected AutoTradeUserService $autoTradeUser,
    protected BrokerManager $brokerManager,
    protected InAppNotificationService $notifications,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_core.auto_trade_user'),
      $container->get('niftybot_core.broker_manager'),
      $container->get('niftybot_core.in_app_notifications'),
    );
  }

  public function autoTradeSettings(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    return new JsonResponse([
      'success' => TRUE,
      'settings' => $this->autoTradeUser->getSettings($uid),
      'access' => $this->autoTradeUser->getAccessInfo($uid),
      'fee_summary' => $this->autoTradeUser->getFeeSummary($uid),
      'fee_history' => $this->autoTradeUser->getFeeHistory($uid, NULL, 10),
      'lot_steps' => $this->autoTradeUser->getLotSteps(),
    ]);
  }

  public function saveAutoTradeSettings(Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }

    try {
      $this->autoTradeUser->saveSettings(
        $uid,
        (int) ($data['nifty_quantity'] ?? 0),
        (int) ($data['sensex_quantity'] ?? 0),
        isset($data['crude_oil_quantity']) ? (int) $data['crude_oil_quantity'] : NULL,
        isset($data['gold_quantity']) ? (int) $data['gold_quantity'] : NULL,
      );
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['success' => FALSE, 'message' => $e->getMessage()], 400);
    }

    return new JsonResponse([
      'success' => TRUE,
      'settings' => $this->autoTradeUser->getSettings($uid),
    ]);
  }

  public function autoTradeStatus(string $instrument): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $status = $this->brokerManager->getUserAutoTradeStatus($uid, $instrument);
    if ($status === NULL) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Could not load auto-trade status.'], 502);
    }

    if ($this->autoTradeUser->isIndexInstrument($instrument)) {
      $market = $this->autoTradeUser->getIndexMarketStatus();
      $status['market_open'] = $market['market_open'];
      $status['market_status'] = $market['market_status'];
      $status['market_message'] = $market['market_message'];
    }

    return new JsonResponse($status);
  }

  public function autoTradeSuggestions(string $instrument): JsonResponse {
    $result = $this->brokerManager->getAutoTradeSuggestions($instrument);
    if ($result === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Could not load AI suggestions.',
      ], 502);
    }

    $code = !empty($result['success']) ? 200 : 400;
    return new JsonResponse($result, $code);
  }

  public function activateAutoTrade(string $instrument, Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $data = json_decode($request->getContent(), TRUE);
    $mode = is_array($data) ? (string) ($data['mode'] ?? 'buy') : 'buy';
    $quantity = is_array($data) ? (int) ($data['quantity'] ?? 0) : 0;

    if ($quantity > 0) {
      $settings = $this->autoTradeUser->getSettings($uid);
      $key = $this->autoTradeUser->quantityKey($instrument);
      $settings[$key] = $quantity;
      try {
        $this->autoTradeUser->saveSettings(
          $uid,
          $settings['nifty_quantity'],
          $settings['sensex_quantity'],
          $settings['crude_oil_quantity'],
          $settings['gold_quantity'],
        );
      }
      catch (\InvalidArgumentException $e) {
        return new JsonResponse(['success' => FALSE, 'message' => $e->getMessage()], 400);
      }
    }

    $result = $this->autoTradeUser->activateUserAutoTrade($uid, $instrument, $mode);
    if ($result === NULL) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Trading service unavailable.'], 502);
    }

    return new JsonResponse($result, !empty($result['success']) ? 200 : 400);
  }

  public function deactivateAutoTrade(string $instrument): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $result = $this->brokerManager->deactivateUserAutoTrade($uid, $instrument);
    if ($result === NULL) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Trading service unavailable.'], 502);
    }
    return new JsonResponse($result);
  }

  public function exitAutoTrade(string $instrument): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $result = $this->brokerManager->exitUserAutoTrade($uid, $instrument);
    if ($result === NULL) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Trading service unavailable.'], 502);
    }
    return new JsonResponse($result);
  }

  public function notifications(Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $limit = (int) $request->query->get('limit', 20);
    return new JsonResponse([
      'success' => TRUE,
      'unread_count' => $this->notifications->getUnreadCount($uid),
      'alerts' => $this->notifications->getAlerts($uid, $limit),
    ]);
  }

  public function markNotificationRead(int $alert_id): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $this->notifications->markRead($uid, $alert_id);
    return new JsonResponse([
      'success' => TRUE,
      'unread_count' => $this->notifications->getUnreadCount($uid),
    ]);
  }

  public function markAllNotificationsRead(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $this->notifications->markAllRead($uid);
    return new JsonResponse([
      'success' => TRUE,
      'unread_count' => 0,
    ]);
  }

}
