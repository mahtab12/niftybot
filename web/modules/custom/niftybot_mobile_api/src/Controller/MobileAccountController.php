<?php

namespace Drupal\niftybot_mobile_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_mobile_api\Service\MobileAccountService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Wallet, KYC, and subscription payment endpoints for mobile clients.
 */
class MobileAccountController extends ControllerBase {

  public function __construct(
    protected MobileAccountService $accountService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_mobile_api.account'),
    );
  }

  public function depositInfo(): JsonResponse {
    return new JsonResponse([
      'success' => TRUE,
      'deposit' => $this->accountService->depositPaymentInfo(),
    ]);
  }

  public function createDeposit(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }

    $result = $this->accountService->createDeposit((int) $this->currentUser()->id(), $data);
    if (!$result['success']) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Validation failed.',
        'errors' => $result['errors'],
      ], 400);
    }

    return new JsonResponse($result);
  }

  public function createWithdrawal(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }

    $result = $this->accountService->createWithdrawal((int) $this->currentUser()->id(), $data);
    if (!$result['success']) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Validation failed.',
        'errors' => $result['errors'],
      ], 400);
    }

    return new JsonResponse($result);
  }

  public function kycStatus(): JsonResponse {
    return new JsonResponse([
      'success' => TRUE,
      'kyc' => $this->accountService->kycStatus((int) $this->currentUser()->id()),
    ]);
  }

  public function submitKyc(Request $request): JsonResponse {
    $fields = [
      'full_name' => $request->request->get('full_name'),
      'date_of_birth' => $request->request->get('date_of_birth'),
      'address' => $request->request->get('address'),
      'pan_number' => $request->request->get('pan_number'),
      'aadhaar_number' => $request->request->get('aadhaar_number'),
      'bank_name' => $request->request->get('bank_name'),
      'bank_account_number' => $request->request->get('bank_account_number'),
      'bank_ifsc' => $request->request->get('bank_ifsc'),
      'declaration' => $request->request->get('declaration'),
    ];

    $files = [
      'pan_document' => $request->files->get('pan_document'),
      'aadhaar_document' => $request->files->get('aadhaar_document'),
      'selfie' => $request->files->get('selfie'),
    ];

    $result = $this->accountService->submitKyc((int) $this->currentUser()->id(), $fields, $files);

    if (!$result['success']) {
      $status = isset($result['errors']) ? 400 : 409;
      return new JsonResponse([
        'success' => FALSE,
        'message' => $result['message'] ?? 'Validation failed.',
        'errors' => $result['errors'] ?? NULL,
      ], $status);
    }

    return new JsonResponse($result);
  }

  public function planCheckout(int $plan_id): JsonResponse {
    $checkout = $this->accountService->planCheckout((int) $this->currentUser()->id(), $plan_id);
    if ($checkout === NULL) {
      throw new NotFoundHttpException('Plan not found.');
    }

    return new JsonResponse([
      'success' => TRUE,
      'checkout' => $checkout,
    ]);
  }

  public function subscribe(int $plan_id, Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }

    $result = $this->accountService->subscribe((int) $this->currentUser()->id(), $plan_id, $data);
    if (!$result['success']) {
      $status = isset($result['errors']) ? 400 : 404;
      return new JsonResponse([
        'success' => FALSE,
        'message' => $result['message'] ?? 'Validation failed.',
        'errors' => $result['errors'] ?? NULL,
      ], $status);
    }

    return new JsonResponse($result);
  }

}
