<?php

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_core\Service\AutoTradeUserService;
use Drupal\niftybot_core\Service\BrokerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * JSON API for user auto-trade settings, activation, and billing webhooks.
 */
class UserAutoTradeApiController extends ControllerBase {

  public function __construct(
    protected AutoTradeUserService $autoTradeUser,
    protected BrokerManager $brokerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_core.auto_trade_user'),
      $container->get('niftybot_core.broker_manager'),
    );
  }

  /**
   * GET access info, wallet, and quantity settings.
   */
  public function settings(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $settings = $this->autoTradeUser->getSettings($uid);
    $access = $this->autoTradeUser->getAccessInfo($uid);

    return new JsonResponse([
      'success' => TRUE,
      'settings' => $settings,
      'access' => $access,
      'fee_summary' => $this->autoTradeUser->getFeeSummary($uid),
      'fee_history' => $this->autoTradeUser->getFeeHistory($uid, NULL, 10),
      'lot_steps' => [
        'nifty' => AutoTradeUserService::NIFTY_LOT_STEP,
        'sensex' => AutoTradeUserService::SENSEX_LOT_STEP,
      ],
    ]);
  }

  /**
   * POST quantity settings.
   */
  public function saveSettings(Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }

    $nifty = (int) ($data['nifty_quantity'] ?? 0);
    $sensex = (int) ($data['sensex_quantity'] ?? 0);

    try {
      $this->autoTradeUser->saveSettings($uid, $nifty, $sensex);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $e->getMessage(),
      ], 400);
    }

    return new JsonResponse([
      'success' => TRUE,
      'settings' => $this->autoTradeUser->getSettings($uid),
    ]);
  }

  /**
   * GET user auto-trade engine status.
   */
  public function status(string $instrument): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $status = $this->brokerManager->getUserAutoTradeStatus($uid, $instrument);
    if ($status === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Could not load auto-trade status.',
      ], 502);
    }

    return new JsonResponse($status);
  }

  /**
   * POST activate user auto-trade.
   */
  public function activate(string $instrument, Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $data = json_decode($request->getContent(), TRUE);
    $mode = is_array($data) ? (string) ($data['mode'] ?? 'buy') : 'buy';
    $quantity = is_array($data) ? (int) ($data['quantity'] ?? 0) : 0;

    if ($quantity > 0) {
      $settings = $this->autoTradeUser->getSettings($uid);
      $key = strtolower($instrument) === 'sensex' ? 'sensex_quantity' : 'nifty_quantity';
      $settings[$key] = $quantity;
      try {
        $this->autoTradeUser->saveSettings($uid, $settings['nifty_quantity'], $settings['sensex_quantity']);
      }
      catch (\InvalidArgumentException $e) {
        return new JsonResponse(['success' => FALSE, 'message' => $e->getMessage()], 400);
      }
    }

    $result = $this->autoTradeUser->activateUserAutoTrade($uid, $instrument, $mode);
    if ($result === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Trading service unavailable.',
      ], 502);
    }

    $code = !empty($result['success']) ? 200 : 400;
    return new JsonResponse($result, $code);
  }

  /**
   * POST deactivate user auto-trade.
   */
  public function deactivate(string $instrument): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $result = $this->brokerManager->deactivateUserAutoTrade($uid, $instrument);
    if ($result === NULL) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Trading service unavailable.'], 502);
    }
    return new JsonResponse($result);
  }

  /**
   * POST manual exit for user auto-trade position.
   */
  public function exit(string $instrument): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $result = $this->brokerManager->exitUserAutoTrade($uid, $instrument);
    if ($result === NULL) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Trading service unavailable.'], 502);
    }
    return new JsonResponse($result);
  }

  /**
   * Internal webhook: charge wallet after algo trade execution.
   */
  public function charge(Request $request): JsonResponse {
    $api_key = $request->headers->get('X-API-Key');
    if (!$this->autoTradeUser->isValidTradingApiKey($api_key)) {
      throw new AccessDeniedHttpException();
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }

    $uid = (int) ($data['uid'] ?? 0);
    $instrument = (string) ($data['instrument'] ?? '');
    $trade_id = (string) ($data['trade_id'] ?? '');

    if ($uid <= 0 || $trade_id === '' || !in_array(strtolower($instrument), ['nifty', 'sensex'], TRUE)) {
      throw new BadRequestHttpException('uid, instrument, and trade_id are required.');
    }

    $charged = $this->autoTradeUser->chargeTradeFee($uid, $instrument, $trade_id);
    return new JsonResponse([
      'success' => $charged,
      'message' => $charged ? 'Fee charged.' : 'Insufficient wallet balance.',
    ], $charged ? 200 : 402);
  }

}
