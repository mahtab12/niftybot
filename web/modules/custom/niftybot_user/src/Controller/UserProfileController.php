<?php

namespace Drupal\niftybot_user\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\niftybot_user\Service\MemberIdService;
use Drupal\niftybot_user\Service\WalletService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * User profile controller.
 */
class UserProfileController extends ControllerBase {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The member ID service.
   */
  protected MemberIdService $memberIdService;

  /**
   * Constructs the controller.
   */
  public function __construct(Connection $database, MemberIdService $member_id_service) {
    $this->database = $database;
    $this->memberIdService = $member_id_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('niftybot_user.member_id_service'),
    );
  }

  /**
   * Display user profile page.
   */
  public function profile() {
    $uid = $this->currentUser()->id();
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);

    $kyc = $this->database->select('niftybot_kyc', 'k')
      ->fields('k')
      ->condition('uid', $uid)
      ->execute()
      ->fetchObject();

    $kyc_status = $kyc ? $kyc->status : 'not_submitted';

    $wallet_balance = $this->database->select('niftybot_wallet', 'w')
      ->fields('w', ['balance'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    $subscription = $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us')
      ->condition('uid', $uid)
      ->condition('status', 'active')
      ->execute()
      ->fetchObject();

    return [
      '#theme' => 'niftybot_user_profile',
      '#attached' => [
        'library' => [
          'niftybot_user/profile',
        ],
      ],
      '#user' => $user,
      '#display_name' => niftybot_user_display_name($user),
      '#full_name' => niftybot_user_profile_field_value($user, 'field_full_name')
        ?: ($kyc ? trim((string) $kyc->full_name) : ''),
      '#mobile_number' => niftybot_user_profile_field_value($user, 'field_mobile_number'),
      '#kyc' => $kyc ?: NULL,
      '#kyc_status' => $kyc_status,
      '#bank_account_masked' => $kyc
        ? niftybot_user_mask_bank_account((string) $kyc->bank_account_number)
        : '',
      '#aadhaar_display' => $kyc
        ? niftybot_user_format_aadhaar_display((string) $kyc->aadhaar_last4)
        : '',
      '#wallet_balance' => (float) ($wallet_balance ?? 0),
      '#subscription' => $subscription,
      '#member_id' => $this->memberIdService->getMemberId($uid),
    ] + WalletService::walletRenderCache($uid);
  }

}
