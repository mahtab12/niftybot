<?php

namespace Drupal\niftybot_mobile_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_mobile_api\Service\MobileUserPayloadService;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dashboard and plans mobile endpoints.
 */
class MobileDashboardController extends ControllerBase {

  public function __construct(
    protected MobileUserPayloadService $payloadService,
    protected WalletService $walletService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_mobile_api.user_payload'),
      $container->get('niftybot_user.wallet_service'),
    );
  }

  public function dashboard(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    return new JsonResponse([
      'success' => TRUE,
      'data' => $this->payloadService->dashboard($uid),
    ]);
  }

  public function plans(): JsonResponse {
    return new JsonResponse([
      'success' => TRUE,
      'plans' => $this->payloadService->plans(),
    ]);
  }

  public function subscription(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    return new JsonResponse([
      'success' => TRUE,
      'subscription' => $this->payloadService->subscriptionSummary($uid),
    ]);
  }

  public function wallet(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    return new JsonResponse([
      'success' => TRUE,
      'wallet' => $this->payloadService->walletSummary($uid),
    ]);
  }

  public function walletTransactions(Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $filter = (string) $request->query->get('filter', 'all');
    if (!in_array($filter, WalletService::TRANSACTION_FILTERS, TRUE)) {
      $filter = 'all';
    }

    $transactions = array_map(function ($transaction) {
      $labels = $this->walletService->getTransactionDisplayLabels($transaction);
      return [
        'transaction_id' => (int) $transaction->transaction_id,
        'type' => $transaction->type,
        'amount' => (float) $transaction->amount,
        'status' => $transaction->status,
        'payment_method' => $transaction->payment_method ?? '',
        'notes' => $transaction->notes ?? '',
        'created' => (int) $transaction->created,
        'display_type' => $labels['type'],
        'display_type_label' => (string) $labels['type_label'],
        'display_method_label' => (string) $labels['method_label'],
      ];
    }, $this->walletService->getUserTransactions($uid, 50, $filter));

    return new JsonResponse([
      'success' => TRUE,
      'filter' => $filter,
      'filters' => $this->walletService->getTransactionFilterOptions(),
      'transactions' => $transactions,
    ]);
  }

  public function broker(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    /** @var \Drupal\niftybot_core\Service\BrokerConnectionService $broker */
    $broker = \Drupal::service('niftybot_core.broker_connection');
    return new JsonResponse([
      'success' => TRUE,
      'broker' => $broker->getDashboardSummary($uid, 'groww'),
    ]);
  }

}
