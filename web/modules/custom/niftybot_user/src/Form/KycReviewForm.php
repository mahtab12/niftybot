<?php

namespace Drupal\niftybot_user\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\niftybot_core\Service\NotificationService;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin KYC review form.
 */
class KycReviewForm extends FormBase {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The notification service.
   */
  protected NotificationService $notificationService;

  /**
   * Constructs the form.
   */
  public function __construct(
    Connection $database,
    AccountProxyInterface $current_user,
    NotificationService $notification_service,
  ) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->notificationService = $notification_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('niftybot_core.notification_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_kyc_review_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $kyc_id = NULL) {
    $kyc = $this->database->select('niftybot_kyc', 'k')
      ->fields('k')
      ->condition('kyc_id', $kyc_id)
      ->execute()
      ->fetchObject();

    if (!$kyc) {
      throw new NotFoundHttpException();
    }

    $user = User::load($kyc->uid);
    $form_state->set('kyc_id', $kyc_id);
    $form_state->set('kyc_uid', $kyc->uid);

    $form['applicant'] = [
      '#type' => 'details',
      '#title' => $this->t('Applicant Details'),
      '#open' => TRUE,
    ];

    $form['applicant']['info'] = [
      '#type' => 'table',
      '#rows' => [
        [$this->t('User'), $user ? $user->getAccountName() : 'Unknown'],
        [$this->t('Email'), $user ? $user->getEmail() : 'Unknown'],
        [$this->t('Full Name'), $kyc->full_name],
        [$this->t('Date of Birth'), $kyc->date_of_birth],
        [$this->t('PAN Number'), $kyc->pan_number],
        [$this->t('Aadhaar (last 4)'), '****' . $kyc->aadhaar_last4],
        [$this->t('Bank'), $kyc->bank_name . ' - ' . $kyc->bank_account_number],
        [$this->t('IFSC'), $kyc->bank_ifsc],
        [$this->t('Address'), $kyc->address],
        [$this->t('Submitted'), date('d M Y H:i', $kyc->created)],
        [$this->t('Current Status'), strtoupper($kyc->status)],
      ],
    ];

    $form['decision'] = [
      '#type' => 'details',
      '#title' => $this->t('Review Decision'),
      '#open' => TRUE,
    ];

    $status_options = [
      'pending' => $this->t('Pending Review'),
      'under_review' => $this->t('Under Review'),
      'approved' => $this->t('Approved'),
      'rejected' => $this->t('Rejected'),
    ];

    $form['decision']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Set Status'),
      '#options' => $status_options,
      '#required' => TRUE,
      '#default_value' => array_key_exists($kyc->status, $status_options) ? $kyc->status : 'under_review',
    ];

    $form['decision']['rejection_reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Rejection Reason'),
      '#description' => $this->t('Required if rejecting the KYC application.'),
      '#states' => [
        'visible' => [
          ':input[name="status"]' => ['value' => 'rejected'],
        ],
        'required' => [
          ':input[name="status"]' => ['value' => 'rejected'],
        ],
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Decision'),
      '#attributes' => ['class' => ['button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $status = $this->getSubmittedStatus($form_state);
    if ($status === 'rejected' && empty(trim($form_state->getValue('rejection_reason') ?? ''))) {
      $form_state->setErrorByName('rejection_reason', $this->t('Please provide a reason for rejection.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $kyc_id = $form_state->get('kyc_id');
    $kyc_uid = $form_state->get('kyc_uid');
    $status = $this->getSubmittedStatus($form_state);

    $fields = [
      'status' => $status,
      'reviewed_by' => $this->currentUser->id(),
      'updated' => \Drupal::time()->getRequestTime(),
      'rejection_reason' => $status === 'rejected' ? $form_state->getValue('rejection_reason') : NULL,
    ];

    $this->database->update('niftybot_kyc')
      ->fields($fields)
      ->condition('kyc_id', $kyc_id)
      ->execute();

    $user = User::load($kyc_uid);
    if ($user) {
      $this->notificationService->notifyKycStatus($user->getEmail(), $status);
    }

    $this->messenger()->addStatus($this->t('KYC status updated to @status.', ['@status' => $status]));
    $form_state->setRedirect('niftybot_user.kyc_admin');
  }

  /**
   * Gets the submitted review status from form values.
   */
  protected function getSubmittedStatus(FormStateInterface $form_state): string {
    return (string) ($form_state->getValue('status') ?? '');
  }

}
