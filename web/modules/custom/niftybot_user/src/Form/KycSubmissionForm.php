<?php

namespace Drupal\niftybot_user\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * KYC verification submission form.
 */
class KycSubmissionForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs the form.
   */
  public function __construct(Connection $database, AccountProxyInterface $current_user) {
    $this->database = $database;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_kyc_submission_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $existing_kyc = $this->getExistingKyc();

    if ($existing_kyc && in_array($existing_kyc->status, ['approved', 'under_review'])) {
      $form['status'] = [
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('Your KYC is currently @status. You cannot submit a new application.', [
            '@status' => $existing_kyc->status,
          ]) . '</div>',
      ];
      return $form;
    }

    if (!\Drupal\Core\Site\Settings::get('file_private_path')) {
      $form['private_files_warning'] = [
        '#markup' => '<div class="messages messages--error">' .
          $this->t('Document uploads are unavailable because the private files directory is not configured. Please contact the site administrator.') .
          '</div>',
      ];
      return $form;
    }

    niftybot_user_ensure_kyc_upload_directories();

    $form['#attributes']['class'][] = 'niftybot-kyc-form';
    $form['#attributes']['enctype'] = 'multipart/form-data';

    $form['personal'] = [
      '#type' => 'details',
      '#title' => $this->t('Personal Information'),
      '#open' => TRUE,
    ];

    $form['personal']['full_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full Name (as on PAN Card)'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $existing_kyc->full_name ?? '',
    ];

    $form['personal']['date_of_birth'] = [
      '#type' => 'date',
      '#title' => $this->t('Date of Birth'),
      '#required' => TRUE,
      '#default_value' => $existing_kyc->date_of_birth ?? '',
    ];

    $form['personal']['address'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Residential Address'),
      '#required' => TRUE,
      '#rows' => 3,
      '#default_value' => $existing_kyc->address ?? '',
    ];

    $form['identity'] = [
      '#type' => 'details',
      '#title' => $this->t('Identity Documents'),
      '#open' => TRUE,
    ];

    $form['identity']['pan_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PAN Number'),
      '#required' => TRUE,
      '#maxlength' => 10,
      '#attributes' => [
        'pattern' => '[A-Z]{5}[0-9]{4}[A-Z]{1}',
        'placeholder' => 'ABCDE1234F',
        'style' => 'text-transform: uppercase;',
      ],
      '#default_value' => $existing_kyc->pan_number ?? '',
    ];

    $form['identity']['aadhaar_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Aadhaar Number'),
      '#required' => TRUE,
      '#maxlength' => 12,
      '#attributes' => [
        'pattern' => '[0-9]{12}',
        'placeholder' => '12-digit Aadhaar number',
      ],
    ];

    $form['identity']['pan_document'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('PAN Card Copy'),
      // '#required' => TRUE,
      '#upload_location' => 'private://kyc/pan/',
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg jpeg png pdf'],
        'file_validate_size' => [5 * 1024 * 1024],
      ],
      '#description' => $this->t('Upload a clear photo/scan of your PAN card. Max 5MB.'),
    ];

    $form['identity']['aadhaar_document'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Aadhaar Card Copy'),
      // '#required' => TRUE,
      '#upload_location' => 'private://kyc/aadhaar/',
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg jpeg png pdf'],
        'file_validate_size' => [5 * 1024 * 1024],
      ],
      '#description' => $this->t('Upload a clear photo/scan of your Aadhaar card (front). Max 5MB.'),
    ];

    $form['bank'] = [
      '#type' => 'details',
      '#title' => $this->t('Bank Account Details'),
      '#open' => TRUE,
    ];

    $form['bank']['bank_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bank Name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $existing_kyc->bank_name ?? '',
    ];

    $form['bank']['bank_account_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bank Account Number'),
      '#required' => TRUE,
      '#maxlength' => 20,
      '#default_value' => $existing_kyc->bank_account_number ?? '',
    ];

    $form['bank']['bank_ifsc'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IFSC Code'),
      '#required' => TRUE,
      '#maxlength' => 11,
      '#attributes' => [
        'pattern' => '[A-Z]{4}0[A-Z0-9]{6}',
        'placeholder' => 'SBIN0001234',
        'style' => 'text-transform: uppercase;',
      ],
      '#default_value' => $existing_kyc->bank_ifsc ?? '',
    ];

    $form['selfie'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Selfie with PAN Card'),
      // '#required' => TRUE,
      '#upload_location' => 'private://kyc/selfie/',
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg jpeg png'],
        'file_validate_size' => [5 * 1024 * 1024],
      ],
      '#description' => $this->t('Upload a selfie holding your PAN card for verification. Max 5MB.'),
    ];

    $form['declaration'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I declare that all information provided is accurate and I authorize NiftyBot to verify my identity.'),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit KYC Verification'),
      '#attributes' => ['class' => ['button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $pan = strtoupper($form_state->getValue('pan_number'));
    if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan)) {
      $form_state->setErrorByName('pan_number', $this->t('Invalid PAN number format.'));
    }

    $aadhaar = $form_state->getValue('aadhaar_number');
    if (!preg_match('/^[0-9]{12}$/', $aadhaar)) {
      $form_state->setErrorByName('aadhaar_number', $this->t('Invalid Aadhaar number. Must be 12 digits.'));
    }

    $ifsc = strtoupper($form_state->getValue('bank_ifsc'));
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
      $form_state->setErrorByName('bank_ifsc', $this->t('Invalid IFSC code format.'));
    }

    $pan_exists = $this->database->select('niftybot_kyc', 'k')
      ->fields('k', ['kyc_id'])
      ->condition('pan_number', $pan)
      ->condition('uid', $this->currentUser->id(), '<>')
      ->execute()
      ->fetchField();

    if ($pan_exists) {
      $form_state->setErrorByName('pan_number', $this->t('This PAN number is already registered.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();
    $aadhaar = $form_state->getValue('aadhaar_number');
    $aadhaar_hash = hash('sha256', $aadhaar);
    $aadhaar_last4 = substr($aadhaar, -4);
    $now = \Drupal::time()->getRequestTime();

    $pan_doc = $form_state->getValue('pan_document');
    $aadhaar_doc = $form_state->getValue('aadhaar_document');
    $selfie = $form_state->getValue('selfie');

    $existing = $this->getExistingKyc();

    $fields = [
      'uid' => $uid,
      'pan_number' => strtoupper($form_state->getValue('pan_number')),
      'aadhaar_last4' => $aadhaar_last4,
      'aadhaar_hash' => $aadhaar_hash,
      'full_name' => $form_state->getValue('full_name'),
      'date_of_birth' => $form_state->getValue('date_of_birth'),
      'address' => $form_state->getValue('address'),
      'bank_account_number' => $form_state->getValue('bank_account_number'),
      'bank_ifsc' => strtoupper($form_state->getValue('bank_ifsc')),
      'bank_name' => $form_state->getValue('bank_name'),
      'pan_document_fid' => $pan_doc[0] ?? NULL,
      'aadhaar_document_fid' => $aadhaar_doc[0] ?? NULL,
      'selfie_fid' => $selfie[0] ?? NULL,
      'status' => 'pending',
      'rejection_reason' => NULL,
      'updated' => $now,
    ];

    if ($existing) {
      $this->database->update('niftybot_kyc')
        ->fields($fields)
        ->condition('kyc_id', $existing->kyc_id)
        ->execute();
    }
    else {
      $fields['created'] = $now;
      $this->database->insert('niftybot_kyc')
        ->fields($fields)
        ->execute();
    }

    $this->messenger()->addStatus($this->t('Your KYC verification has been submitted successfully. You will be notified once it is reviewed.'));
    $form_state->setRedirect('niftybot_user.kyc_status');
  }

  /**
   * Get existing KYC record for current user.
   */
  protected function getExistingKyc() {
    return $this->database->select('niftybot_kyc', 'k')
      ->fields('k')
      ->condition('uid', $this->currentUser->id())
      ->execute()
      ->fetchObject();
  }

}
