<?php

namespace Drupal\niftybot_mobile_api\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Mobile user registration (mirrors web /niftybot/register).
 */
class MobileRegistrationService {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MobileAuthService $authService,
  ) {}

  /**
   * Registers a new trader account and returns a JWT.
   */
  public function register(array $data): JsonResponse {
    $fullName = trim((string) ($data['full_name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $phone = $this->normalizePhone((string) ($data['phone'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $passwordConfirm = (string) ($data['password_confirm'] ?? $password);
    $terms = !empty($data['terms']);

    $errors = [];

    if ($fullName === '') {
      $errors['full_name'] = 'Full name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors['email'] = 'Please enter a valid email address.';
    }
    elseif (strlen($email) > UserInterface::USERNAME_MAX_LENGTH) {
      $errors['email'] = 'This email address is too long to use for sign-in.';
    }
    elseif (user_load_by_mail($email) || user_load_by_name($email)) {
      $errors['email'] = 'An account with this email already exists.';
    }

    if (!preg_match('/^[0-9]{10}$/', $phone)) {
      $errors['phone'] = 'Please enter a valid 10-digit mobile number.';
    }

    if (strlen($password) < 8) {
      $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($password !== $passwordConfirm) {
      $errors['password_confirm'] = 'Passwords do not match.';
    }
    if (!$terms) {
      $errors['terms'] = 'You must accept the terms to create an account.';
    }

    if ($errors !== []) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Validation failed.',
        'errors' => $errors,
      ], 400);
    }

    $user = User::create([
      'name' => $email,
      'mail' => $email,
      'pass' => $password,
      'status' => 1,
    ]);

    if ($user->hasField('field_full_name')) {
      $user->set('field_full_name', $fullName);
    }
    if ($user->hasField('field_mobile_number')) {
      $user->set('field_mobile_number', $phone);
    }

    $user->addRole('niftybot_trader');
    $user->save();

    $account = User::load($user->id());
    if ($account === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Could not create account.',
      ], 500);
    }

    if (!$account->hasPermission('use niftybot mobile api')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Account created but cannot use the mobile API. Contact support.',
      ], 403);
    }

    _user_mail_notify('register_no_approval_required', $account);

    return $this->authService->issueTokenForAccount($account);
  }

  private function normalizePhone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    return is_string($digits) ? $digits : '';
  }

}
