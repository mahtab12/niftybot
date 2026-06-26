<?php

namespace Drupal\niftybot_mobile_api\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Wallet deposits, KYC submission, and subscription checkout for mobile clients.
 */
class MobileAccountService {

  public const DEPOSIT_MIN = 100.0;

  public const DEPOSIT_MAX = 1000000.0;

  public const WITHDRAW_MIN = 100.0;

  public const BANK_ACCOUNT_NAME = 'NiftyBot Trading Pvt Ltd';

  public const BANK_ACCOUNT_NUMBER = '50200012345678';

  public const BANK_IFSC = 'ICIC0001234';

  public const BANK_NAME = 'ICICI Bank';

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected WalletService $walletService,
    protected FileRepositoryInterface $fileRepository,
    protected FileSystemInterface $fileSystem,
    protected TimeInterface $time,
    protected MobileUserPayloadService $payloadService,
  ) {}

  /**
   * Payment instructions for wallet deposits and external subscription payments.
   */
  public function depositPaymentInfo(?float $amount = NULL): array {
    $info = [
      'limits' => [
        'min' => self::DEPOSIT_MIN,
        'max' => self::DEPOSIT_MAX,
      ],
      'payment_methods' => ['upi', 'bank_transfer'],
      'upi' => [
        'upi_id' => WalletService::DEMO_UPI_ID,
      ],
      'bank_transfer' => [
        'account_name' => self::BANK_ACCOUNT_NAME,
        'account_number' => self::BANK_ACCOUNT_NUMBER,
        'ifsc' => self::BANK_IFSC,
        'bank' => self::BANK_NAME,
      ],
    ];

    if ($amount !== NULL) {
      $info['amount'] = $amount;
    }

    return $info;
  }

  /**
   * Creates a pending wallet deposit request.
   */
  public function createDeposit(int $uid, array $data): array {
    $errors = [];

    $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;
    if ($amount < self::DEPOSIT_MIN) {
      $errors['amount'] = 'Minimum deposit amount is ₹100.';
    }
    if ($amount > self::DEPOSIT_MAX) {
      $errors['amount'] = 'Maximum deposit amount is ₹10,00,000.';
    }

    $payment_method = (string) ($data['payment_method'] ?? '');
    if (!in_array($payment_method, ['upi', 'bank_transfer'], TRUE)) {
      $errors['payment_method'] = 'Payment method must be upi or bank_transfer.';
    }

    $reference = trim((string) ($data['payment_reference'] ?? ''));
    if ($reference === '') {
      $errors['payment_reference'] = $payment_method === 'upi'
        ? 'Please enter your UPI transaction reference.'
        : 'Please enter your UTR / transaction reference.';
    }

    if ($errors !== []) {
      return ['success' => FALSE, 'errors' => $errors];
    }

    $transaction_id = $this->walletService->createDeposit($uid, $amount, $payment_method, $reference);

    return [
      'success' => TRUE,
      'transaction_id' => $transaction_id,
      'message' => sprintf(
        'Your %s deposit request for ₹%s has been submitted. Your wallet will be credited after admin verification.',
        $payment_method === 'upi' ? 'UPI' : 'NEFT',
        number_format($amount, 2),
      ),
    ];
  }

  /**
   * Creates a pending wallet withdrawal request.
   */
  public function createWithdrawal(int $uid, array $data): array {
    $errors = [];

    $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;
    $available = $this->walletService->getAvailableBalance($uid);

    if ($amount < self::WITHDRAW_MIN) {
      $errors['amount'] = 'Minimum withdrawal amount is ₹100.';
    }
    if ($amount > $available) {
      $errors['amount'] = 'Insufficient available balance. You may have pending withdrawal requests.';
    }

    if ($errors !== []) {
      return ['success' => FALSE, 'errors' => $errors];
    }

    $notes = trim((string) ($data['notes'] ?? ''));
    $transaction_id = $this->walletService->createWithdrawalRequest(
      $uid,
      $amount,
      $notes !== '' ? $notes : NULL,
    );

    return [
      'success' => TRUE,
      'transaction_id' => $transaction_id,
      'message' => sprintf(
        'Withdrawal request for ₹%s has been submitted. Your wallet will be debited after admin approval.',
        number_format($amount, 2),
      ),
    ];
  }

  /**
   * KYC status and submission schema for mobile clients.
   */
  public function kycStatus(int $uid): array {
    $record = $this->getKycRecord($uid);
    $labels = niftybot_user_kyc_status_labels();
    $upload_ready = niftybot_user_private_files_ready();

    $can_submit = $upload_ready && (!$record || $record->status === 'rejected');

    $payload = [
      'upload_ready' => $upload_ready,
      'can_submit' => $can_submit,
      'status' => $record ? (string) $record->status : 'not_submitted',
      'status_label' => $record
        ? (string) ($labels[$record->status] ?? $record->status)
        : 'Not Submitted',
      'rejection_reason' => $record && $record->status === 'rejected'
        ? ($record->rejection_reason ?? NULL)
        : NULL,
      'submitted_at' => $record ? (int) $record->created : NULL,
      'updated_at' => $record ? (int) $record->updated : NULL,
      'required_documents' => [
        'pan_document' => ['extensions' => ['jpg', 'jpeg', 'png', 'pdf'], 'max_bytes' => 5242880],
        'aadhaar_document' => ['extensions' => ['jpg', 'jpeg', 'png', 'pdf'], 'max_bytes' => 5242880],
        'selfie' => ['extensions' => ['jpg', 'jpeg', 'png'], 'max_bytes' => 5242880],
      ],
    ];

    if ($record && $record->status === 'rejected') {
      $payload['prefill'] = [
        'full_name' => (string) ($record->full_name ?? ''),
        'date_of_birth' => (string) ($record->date_of_birth ?? ''),
        'address' => (string) ($record->address ?? ''),
        'pan_number' => (string) ($record->pan_number ?? ''),
        'bank_name' => (string) ($record->bank_name ?? ''),
        'bank_account_number' => (string) ($record->bank_account_number ?? ''),
        'bank_ifsc' => (string) ($record->bank_ifsc ?? ''),
      ];
    }

    return $payload;
  }

  /**
   * Submits or resubmits KYC from multipart form data.
   */
  public function submitKyc(int $uid, array $fields, array $files): array {
    if (!niftybot_user_private_files_ready()) {
      return [
        'success' => FALSE,
        'message' => 'Document uploads are unavailable because the private files directory is not configured or is not writable.',
      ];
    }

    $existing = $this->getKycRecord($uid);
    if ($existing && $existing->status !== 'rejected') {
      return [
        'success' => FALSE,
        'message' => 'KYC has already been submitted and cannot be changed at this time.',
      ];
    }

    $errors = [];

    $full_name = trim((string) ($fields['full_name'] ?? ''));
    if ($full_name === '') {
      $errors['full_name'] = 'Full name is required.';
    }

    $date_of_birth = trim((string) ($fields['date_of_birth'] ?? ''));
    if ($date_of_birth === '') {
      $errors['date_of_birth'] = 'Date of birth is required.';
    }

    $address = trim((string) ($fields['address'] ?? ''));
    if ($address === '') {
      $errors['address'] = 'Residential address is required.';
    }

    $pan = $this->normalizePan((string) ($fields['pan_number'] ?? ''));
    if ($pan === '') {
      $errors['pan_number'] = 'PAN number is required.';
    }
    elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan)) {
      $errors['pan_number'] = 'Invalid PAN number format. Example: ABCDE1234F';
    }

    $aadhaar = $this->normalizeAadhaar((string) ($fields['aadhaar_number'] ?? ''));
    if ($aadhaar === '') {
      $errors['aadhaar_number'] = 'Aadhaar number is required.';
    }
    elseif (!preg_match('/^[0-9]{12}$/', $aadhaar)) {
      $errors['aadhaar_number'] = 'Invalid Aadhaar number. Must be 12 digits.';
    }

    $bank_name = trim((string) ($fields['bank_name'] ?? ''));
    if ($bank_name === '') {
      $errors['bank_name'] = 'Bank name is required.';
    }

    $bank_account_number = trim((string) ($fields['bank_account_number'] ?? ''));
    if ($bank_account_number === '') {
      $errors['bank_account_number'] = 'Bank account number is required.';
    }

    $ifsc = $this->normalizeIfsc((string) ($fields['bank_ifsc'] ?? ''));
    if ($ifsc === '') {
      $errors['bank_ifsc'] = 'IFSC code is required.';
    }
    elseif (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
      $errors['bank_ifsc'] = 'Invalid IFSC code format. Example: SBIN0001234';
    }

    $declaration = $fields['declaration'] ?? FALSE;
    if (!filter_var($declaration, FILTER_VALIDATE_BOOLEAN)) {
      $errors['declaration'] = 'You must accept the declaration to submit KYC.';
    }

    if ($pan !== '') {
      $pan_exists = $this->database->select('niftybot_kyc', 'k')
        ->fields('k', ['kyc_id'])
        ->condition('pan_number', $pan)
        ->condition('uid', $uid, '<>')
        ->execute()
        ->fetchField();

      if ($pan_exists) {
        $errors['pan_number'] = 'This PAN number is already registered.';
      }
    }

    foreach (['pan_document', 'aadhaar_document', 'selfie'] as $field_name) {
      if (!isset($files[$field_name]) || !$files[$field_name] instanceof UploadedFile) {
        $errors[$field_name] = 'This document is required.';
      }
    }

    if ($errors !== []) {
      return ['success' => FALSE, 'errors' => $errors];
    }

    niftybot_user_ensure_kyc_upload_directories();

    try {
      $pan_fid = $this->saveUploadedFile($files['pan_document'], 'private://kyc/pan', ['jpg', 'jpeg', 'png', 'pdf']);
      $aadhaar_fid = $this->saveUploadedFile($files['aadhaar_document'], 'private://kyc/aadhaar', ['jpg', 'jpeg', 'png', 'pdf']);
      $selfie_fid = $this->saveUploadedFile($files['selfie'], 'private://kyc/selfie', ['jpg', 'jpeg', 'png']);
    }
    catch (\InvalidArgumentException $e) {
      return ['success' => FALSE, 'message' => $e->getMessage()];
    }

    $now = $this->time->getRequestTime();
    $aadhaar_hash = hash('sha256', $aadhaar);
    $aadhaar_last4 = substr($aadhaar, -4);

    $kyc_fields = [
      'uid' => $uid,
      'pan_number' => $pan,
      'aadhaar_last4' => $aadhaar_last4,
      'aadhaar_hash' => $aadhaar_hash,
      'full_name' => $full_name,
      'date_of_birth' => $date_of_birth,
      'address' => $address,
      'bank_account_number' => $bank_account_number,
      'bank_ifsc' => $ifsc,
      'bank_name' => $bank_name,
      'pan_document_fid' => $pan_fid,
      'aadhaar_document_fid' => $aadhaar_fid,
      'selfie_fid' => $selfie_fid,
      'status' => 'pending',
      'rejection_reason' => NULL,
      'updated' => $now,
    ];

    if ($existing) {
      $this->database->update('niftybot_kyc')
        ->fields($kyc_fields)
        ->condition('kyc_id', $existing->kyc_id)
        ->execute();
    }
    else {
      $kyc_fields['created'] = $now;
      $this->database->insert('niftybot_kyc')
        ->fields($kyc_fields)
        ->execute();
    }

    if ($full_name !== '') {
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if ($user && $user->hasField('field_full_name')) {
        $user->set('field_full_name', $full_name);
        $user->save();
      }
    }

    return [
      'success' => TRUE,
      'message' => 'Your KYC verification has been submitted successfully. You will be notified once it is reviewed.',
      'kyc' => $this->kycStatus($uid),
    ];
  }

  /**
   * Checkout details for a subscription plan.
   */
  public function planCheckout(int $uid, int $plan_id): ?array {
    $plan = $this->loadActivePlan($plan_id);
    if (!$plan) {
      return NULL;
    }

    $wallet_balance = $this->walletService->getWalletBalance($uid);
    $price = (float) $plan->price;
    $brokers = array_filter(array_map('trim', explode(',', (string) ($plan->brokers_allowed ?? ''))));

    return [
      'plan' => [
        'plan_id' => (int) $plan->plan_id,
        'name' => $plan->name,
        'machine_name' => $plan->machine_name,
        'description' => $plan->description,
        'price' => $price,
        'duration_days' => (int) $plan->duration_days,
        'max_trades_per_day' => (int) $plan->max_orders_per_day,
        'max_capital' => $plan->max_capital !== NULL ? (float) $plan->max_capital : NULL,
        'features' => json_decode($plan->features ?? '[]', TRUE) ?: [],
        'brokers' => array_values(array_map('ucfirst', $brokers)),
      ],
      'wallet' => $this->payloadService->walletSummary($uid),
      'can_pay_wallet' => $wallet_balance >= $price,
      'payment_methods' => ['wallet', 'upi', 'bank_transfer'],
      'payment_info' => $this->depositPaymentInfo($price),
      'has_active_subscription' => $this->hasActiveSubscription($uid),
      'has_pending_payment' => $this->hasPendingSubscriptionPayment($uid),
    ];
  }

  /**
   * Subscribes a user to a plan.
   */
  public function subscribe(int $uid, int $plan_id, array $data): array {
    $plan = $this->loadActivePlan($plan_id);
    if (!$plan) {
      return ['success' => FALSE, 'message' => 'Plan not found.'];
    }

    if ($this->hasActiveSubscription($uid)) {
      return [
        'success' => FALSE,
        'errors' => [
          'payment_method' => 'You already have an active subscription. Please wait for it to expire or cancel it first.',
        ],
      ];
    }

    if ($this->hasPendingSubscriptionPayment($uid)) {
      return [
        'success' => FALSE,
        'errors' => [
          'payment_method' => 'You already have a subscription payment pending verification. Please wait for admin approval or contact support.',
        ],
      ];
    }

    $payment_method = (string) ($data['payment_method'] ?? '');
    if (!in_array($payment_method, ['wallet', 'upi', 'bank_transfer'], TRUE)) {
      return [
        'success' => FALSE,
        'errors' => ['payment_method' => 'Payment method must be wallet, upi, or bank_transfer.'],
      ];
    }

    $auto_renew = filter_var($data['auto_renew'] ?? FALSE, FILTER_VALIDATE_BOOLEAN);
    $price = (float) $plan->price;
    $now = $this->time->getRequestTime();

    if ($payment_method === 'wallet') {
      $balance = $this->walletService->getWalletBalance($uid);
      if ($balance < $price) {
        return [
          'success' => FALSE,
          'errors' => [
            'payment_method' => 'Insufficient wallet balance. Please deposit funds or choose a different payment method.',
          ],
        ];
      }

      $end_date = $now + ((int) $plan->duration_days * 86400);
      $this->walletService->debitBalance($uid, $price);

      $this->database->insert('niftybot_wallet_transactions')
        ->fields([
          'uid' => $uid,
          'type' => 'withdrawal',
          'amount' => $price,
          'status' => 'completed',
          'payment_method' => 'subscription',
          'notes' => 'Subscription: ' . $plan->name,
          'balance_applied' => 1,
          'created' => $now,
          'updated' => $now,
        ])
        ->execute();

      $subscription_id = (int) $this->database->insert('niftybot_user_subscriptions')
        ->fields([
          'uid' => $uid,
          'plan_id' => $plan->plan_id,
          'start_date' => $now,
          'end_date' => $end_date,
          'status' => 'active',
          'payment_amount' => $price,
          'payment_reference' => 'WALLET-' . $uid . '-' . $now,
          'auto_renew' => $auto_renew ? 1 : 0,
          'created' => $now,
        ])
        ->execute();

      return [
        'success' => TRUE,
        'subscription_id' => $subscription_id,
        'status' => 'active',
        'end_date' => $end_date,
        'message' => sprintf(
          'You have successfully subscribed to the %s plan. Valid until %s.',
          $plan->name,
          date('d M Y', $end_date),
        ),
        'subscription' => $this->payloadService->subscriptionSummary($uid),
      ];
    }

    $reference = trim((string) ($data['payment_reference'] ?? ''));
    if ($reference === '') {
      return [
        'success' => FALSE,
        'errors' => [
          'payment_reference' => $payment_method === 'upi'
            ? 'Please enter your UPI transaction reference.'
            : 'Please enter your UTR / transaction reference.',
        ],
      ];
    }

    $subscription_id = (int) $this->database->insert('niftybot_user_subscriptions')
      ->fields([
        'uid' => $uid,
        'plan_id' => $plan->plan_id,
        'start_date' => 0,
        'end_date' => 0,
        'status' => 'pending_payment',
        'payment_amount' => $price,
        'payment_reference' => $reference,
        'auto_renew' => $auto_renew ? 1 : 0,
        'created' => $now,
      ])
      ->execute();

    $deposit_id = $this->walletService->createDeposit(
      $uid,
      $price,
      $payment_method,
      $reference,
      'subscription:' . $subscription_id,
    );

    $method_label = $payment_method === 'upi' ? 'UPI' : 'bank transfer';

    return [
      'success' => TRUE,
      'subscription_id' => $subscription_id,
      'deposit_transaction_id' => $deposit_id,
      'status' => 'pending_payment',
      'message' => sprintf(
        'Your %s payment for %s (₹%s) has been submitted. Your subscription will activate after admin verification.',
        $method_label,
        $plan->name,
        number_format($price, 2),
      ),
    ];
  }

  protected function getKycRecord(int $uid): ?object {
    $record = $this->database->select('niftybot_kyc', 'k')
      ->fields('k')
      ->condition('uid', $uid)
      ->execute()
      ->fetchObject();

    return $record === FALSE ? NULL : $record;
  }

  protected function loadActivePlan(int $plan_id): ?object {
    $plan = $this->database->select('niftybot_subscription_plans', 'sp')
      ->fields('sp')
      ->condition('plan_id', $plan_id)
      ->condition('status', 1)
      ->execute()
      ->fetchObject();

    return $plan === FALSE ? NULL : $plan;
  }

  protected function hasActiveSubscription(int $uid): bool {
    return (bool) $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us', ['subscription_id'])
      ->condition('uid', $uid)
      ->condition('status', 'active')
      ->execute()
      ->fetchField();
  }

  protected function hasPendingSubscriptionPayment(int $uid): bool {
    return (bool) $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us', ['subscription_id'])
      ->condition('uid', $uid)
      ->condition('status', 'pending_payment')
      ->execute()
      ->fetchField();
  }

  protected function normalizePan(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
  }

  protected function normalizeAadhaar(string $value): string {
    return preg_replace('/\D/', '', trim($value)) ?? '';
  }

  protected function normalizeIfsc(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
  }

  /**
   * Saves an uploaded file to a private scheme directory.
   */
  protected function saveUploadedFile(UploadedFile $uploaded, string $directory, array $allowed_extensions): int {
    if (!$uploaded->isValid()) {
      throw new \InvalidArgumentException('File upload failed.');
    }

    $extension = strtolower($uploaded->getClientOriginalExtension() ?? '');
    if (!in_array($extension, $allowed_extensions, TRUE)) {
      throw new \InvalidArgumentException('Invalid file type for ' . $uploaded->getClientOriginalName() . '.');
    }

    if ($uploaded->getSize() > 5 * 1024 * 1024) {
      throw new \InvalidArgumentException('File exceeds the 5MB size limit.');
    }

    $filename = $this->fileSystem->basename($uploaded->getClientOriginalName());
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?? 'upload';
    if ($filename === '' || $filename === '.' || $filename === '..') {
      $filename = 'upload.' . $extension;
    }

    $contents = file_get_contents($uploaded->getPathname());
    if ($contents === FALSE) {
      throw new \InvalidArgumentException('Could not read uploaded file.');
    }

    $destination = $directory . '/' . $filename;
    $file = $this->fileRepository->writeData($contents, $destination, FileSystemInterface::EXISTS_RENAME);
    $file->setPermanent();
    $file->save();

    return (int) $file->id();
  }

}
