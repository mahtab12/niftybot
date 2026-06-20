<?php

namespace Drupal\niftybot_user\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * KYC management controller.
 */
class KycController extends ControllerBase {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * Constructs the controller.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Show KYC status page for current user.
   */
  public function status() {
    $kyc = $this->database->select('niftybot_kyc', 'k')
      ->fields('k')
      ->condition('uid', $this->currentUser->id())
      ->execute()
      ->fetchObject();

    $status_labels = [
      'pending' => $this->t('Pending Review'),
      'under_review' => $this->t('Under Review'),
      'approved' => $this->t('Approved'),
      'rejected' => $this->t('Rejected'),
    ];

    return [
      '#theme' => 'niftybot_kyc_status',
      '#kyc' => $kyc,
      '#status_label' => $kyc ? ($status_labels[$kyc->status] ?? $kyc->status) : $this->t('Not Submitted'),
    ];
  }

  /**
   * Admin KYC list page.
   */
  public function adminList() {
    $query = $this->database->select('niftybot_kyc', 'k')
      ->fields('k')
      ->orderBy('created', 'DESC');

    $kyc_records = $query->execute()->fetchAll();

    foreach ($kyc_records as &$record) {
      $user = $this->entityTypeManager()->getStorage('user')->load($record->uid);
      $record->username = $user ? $user->getAccountName() : 'Unknown';
      $record->email = $user ? $user->getEmail() : '';
      $record->review_url = Url::fromRoute('niftybot_user.kyc_review', ['kyc_id' => $record->kyc_id])->toString();
    }

    return [
      '#theme' => 'niftybot_kyc_admin_list',
      '#kyc_records' => $kyc_records,
    ];
  }

}
