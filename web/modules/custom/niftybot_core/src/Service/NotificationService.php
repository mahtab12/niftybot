<?php

namespace Drupal\niftybot_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Handles platform notifications (email, in-app).
 */
class NotificationService {

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a NotificationService object.
   */
  public function __construct(
    MailManagerInterface $mail_manager,
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->mailManager = $mail_manager;
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('niftybot');
  }

  /**
   * Send notification to a user.
   */
  public function notify(string $type, string $email, string $subject, string $message): bool {
    $config = $this->configFactory->get('niftybot_core.settings');

    if (!$config->get('email_notifications')) {
      return FALSE;
    }

    $params = [
      'subject' => $subject,
      'message' => $message,
      'type' => $type,
    ];

    $result = $this->mailManager->mail(
      'niftybot_core',
      'notification',
      $email,
      'en',
      $params,
      NULL,
      TRUE
    );

    if (!$result['result']) {
      $this->logger->error('Failed to send @type notification to @email', [
        '@type' => $type,
        '@email' => $email,
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Notify on order execution.
   */
  public function notifyOrderExecution(string $email, array $order_data): bool {
    $config = $this->configFactory->get('niftybot_core.settings');

    if (!$config->get('notify_on_order_execution')) {
      return FALSE;
    }

    $subject = "Order Executed: {$order_data['symbol']}";
    $message = "Your {$order_data['transaction_type']} order for {$order_data['quantity']} units of {$order_data['symbol']} has been executed at ₹{$order_data['price']}.";

    return $this->notify('order_execution', $email, $subject, $message);
  }

  /**
   * Notify on KYC status change.
   */
  public function notifyKycStatus(string $email, string $status): bool {
    $config = $this->configFactory->get('niftybot_core.settings');

    if (!$config->get('notify_on_kyc_status')) {
      return FALSE;
    }

    $subject = "KYC Verification: " . ucfirst($status);
    $message = "Your KYC verification has been {$status}. ";

    if ($status === 'approved') {
      $message .= 'You can now start trading on the platform.';
    }
    elseif ($status === 'rejected') {
      $message .= 'Please re-submit your documents with the correct information.';
    }

    return $this->notify('kyc_status', $email, $subject, $message);
  }

}
