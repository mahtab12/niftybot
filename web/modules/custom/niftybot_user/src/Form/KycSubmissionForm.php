<?php

namespace Drupal\niftybot_user\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
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

    if ($existing_kyc && $existing_kyc->status !== 'rejected') {
      return niftybot_user_kyc_status_render($existing_kyc);
    }

    if (!niftybot_user_private_files_ready()) {
      $form['#attached']['library'][] = 'niftybot_user/kyc';
      $form['private_files_warning'] = [
        '#markup' => '<div class="alert alert-danger d-flex align-items-start mb-0" role="alert">'
          . '<i class="bi bi-exclamation-triangle-fill me-2 flex-shrink-0" aria-hidden="true"></i>'
          . '<span>' . $this->t('Document uploads are unavailable because the private files directory is not configured or is not writable. Please contact the site administrator.') . '</span></div>',
      ];
      return $form;
    }

    niftybot_user_ensure_kyc_upload_directories();

    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'niftybot_user/kyc';
    $form['#attributes']['class'][] = 'niftybot-kyc-form';
    $form['#attributes']['enctype'] = 'multipart/form-data';
    $form['#prefix'] = Markup::create(
      '<div class="niftybot-kyc-page">'
      . '<div class="card niftybot-kyc-hero mb-4">'
      . '<div class="card-body">'
      . '<div class="d-flex align-items-start gap-3">'
      . '<div class="niftybot-kyc-hero__icon" aria-hidden="true"><i class="bi bi-shield-check"></i></div>'
      . '<div>'
      . '<h2 class="niftybot-kyc-hero__title">' . $this->t('KYC Verification') . '</h2>'
      . '<p class="niftybot-kyc-hero__text">' . $this->t('Complete identity verification to start trading. Your documents are stored securely and reviewed by our team.') . '</p>'
      . '</div></div></div></div>'
      . '<div class="card niftybot-kyc-form-card">'
      . '<div class="card-body">'
    );
    $form['#suffix'] = Markup::create('</div></div></div>');

    $form['personal'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-kyc-form__section']],
      '#prefix' => $this->sectionHeading(
        'bi-person-badge',
        $this->t('Personal Information'),
        $this->t('Enter details exactly as shown on your PAN card')
      ),
    ];

    $form['personal']['full_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full Name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $this->getDefaultFullName($existing_kyc),
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['personal']['dob_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'g-3']],
    ];

    $form['personal']['dob_row']['date_of_birth'] = [
      '#type' => 'date',
      '#title' => $this->t('Date of Birth'),
      '#required' => TRUE,
      '#default_value' => $existing_kyc?->date_of_birth ?? '',
      '#wrapper_attributes' => ['class' => ['mb-3', 'col-md-6']],
    ];

    $form['personal']['address'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Residential Address'),
      '#required' => TRUE,
      '#rows' => 3,
      '#default_value' => $existing_kyc?->address ?? '',
      '#wrapper_attributes' => ['class' => ['mb-0']],
    ];

    $form['identity'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-kyc-form__section']],
      '#prefix' => $this->sectionHeading(
        'bi-card-heading',
        $this->t('Identity Documents'),
        $this->t('PAN and Aadhaar details with clear document uploads')
      ),
    ];

    $form['identity']['id_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'g-3']],
    ];

    $form['identity']['id_row']['pan_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PAN Number'),
      '#required' => TRUE,
      '#maxlength' => 10,
      '#attributes' => [
        'pattern' => '[A-Z]{5}[0-9]{4}[A-Z]{1}',
        'placeholder' => 'ABCDE1234F',
        'style' => 'text-transform: uppercase;',
      ],
      '#default_value' => $existing_kyc?->pan_number ?? '',
      '#wrapper_attributes' => ['class' => ['mb-3', 'col-md-6']],
    ];

    $form['identity']['id_row']['aadhaar_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Aadhaar Number'),
      '#required' => TRUE,
      '#maxlength' => 12,
      '#attributes' => [
        'pattern' => '[0-9]{12}',
        'placeholder' => '12-digit Aadhaar number',
        'inputmode' => 'numeric',
      ],
      '#wrapper_attributes' => ['class' => ['mb-3', 'col-md-6']],
    ];

    $form['identity']['docs_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'g-3']],
    ];

    $form['identity']['docs_row']['pan_document'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('PAN Card Copy'),
      '#upload_location' => 'private://kyc/pan/',
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg jpeg png pdf'],
        'file_validate_size' => [5 * 1024 * 1024],
      ],
      '#description' => $this->t('JPG, PNG or PDF. Max 5MB.'),
      '#wrapper_attributes' => ['class' => ['mb-0', 'col-md-6', 'niftybot-kyc-form__file']],
    ];

    $form['identity']['docs_row']['aadhaar_document'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Aadhaar Card Copy'),
      '#upload_location' => 'private://kyc/aadhaar/',
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg jpeg png pdf'],
        'file_validate_size' => [5 * 1024 * 1024],
      ],
      '#description' => $this->t('Front side. JPG, PNG or PDF. Max 5MB.'),
      '#wrapper_attributes' => ['class' => ['mb-0', 'col-md-6', 'niftybot-kyc-form__file']],
    ];

    $form['bank'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-kyc-form__section']],
      '#prefix' => $this->sectionHeading(
        'bi-bank',
        $this->t('Bank Account Details'),
        $this->t('For withdrawals and settlement of trading profits')
      ),
    ];

    $form['bank']['bank_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bank Name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $existing_kyc?->bank_name ?? '',
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['bank']['account_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'g-3']],
    ];

    $form['bank']['account_row']['bank_account_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bank Account Number'),
      '#required' => TRUE,
      '#maxlength' => 20,
      '#attributes' => ['inputmode' => 'numeric'],
      '#default_value' => $existing_kyc?->bank_account_number ?? '',
      '#wrapper_attributes' => ['class' => ['mb-0', 'col-md-6']],
    ];

    $form['bank']['account_row']['bank_ifsc'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IFSC Code'),
      '#required' => TRUE,
      '#maxlength' => 11,
      '#attributes' => [
        'pattern' => '[A-Z]{4}0[A-Z0-9]{6}',
        'placeholder' => 'SBIN0001234',
        'style' => 'text-transform: uppercase;',
      ],
      '#default_value' => $existing_kyc?->bank_ifsc ?? '',
      '#wrapper_attributes' => ['class' => ['mb-0', 'col-md-6']],
    ];

    $form['verification'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftybot-kyc-form__section']],
      '#prefix' => $this->sectionHeading(
        'bi-camera',
        $this->t('Selfie Verification'),
        $this->t('Hold your PAN card beside your face in a well-lit photo')
      ),
    ];

    $form['verification']['selfie'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Selfie with PAN Card'),
      '#upload_location' => 'private://kyc/selfie/',
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg jpeg png'],
        'file_validate_size' => [5 * 1024 * 1024],
      ],
      '#description' => $this->t('JPG or PNG only. Max 5MB.'),
      '#wrapper_attributes' => ['class' => ['mb-0', 'niftybot-kyc-form__file']],
    ];

    $form['declaration'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I declare that all information provided is accurate and I authorize GrassRed to verify my identity.'),
      '#required' => TRUE,
      '#wrapper_attributes' => ['class' => ['niftybot-kyc-form__declaration', 'mb-0']],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit KYC Verification'),
      '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-lg', 'niftybot-kyc-form__submit']],
    ];

    return $form;
  }

  /**
   * Renders a section heading for the KYC form layout.
   */
  protected function sectionHeading(string $icon, string $title, string $subtitle): Markup {
    return Markup::create(
      '<div class="niftybot-kyc-form__section-title">'
      . '<span class="niftybot-kyc-form__section-icon" aria-hidden="true"><i class="bi ' . $icon . '"></i></span>'
      . '<div><h4 class="mb-0">' . $title . '</h4>'
      . '<p class="mb-0 text-secondary small">' . $subtitle . '</p></div>'
      . '</div>'
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $pan = $this->normalizePan((string) $form_state->getValue(['identity', 'id_row', 'pan_number']));
    $aadhaar = $this->normalizeAadhaar((string) $form_state->getValue(['identity', 'id_row', 'aadhaar_number']));
    $ifsc = $this->normalizeIfsc((string) $form_state->getValue(['bank', 'account_row', 'bank_ifsc']));

    $form_state->setValue(['identity', 'id_row', 'pan_number'], $pan);
    $form_state->setValue(['identity', 'id_row', 'aadhaar_number'], $aadhaar);
    $form_state->setValue(['bank', 'account_row', 'bank_ifsc'], $ifsc);

    if ($pan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan)) {
      $form_state->setErrorByName('identity][id_row][pan_number', $this->t('Invalid PAN number format. Example: ABCDE1234F'));
    }

    if ($aadhaar !== '' && !preg_match('/^[0-9]{12}$/', $aadhaar)) {
      $form_state->setErrorByName('identity][id_row][aadhaar_number', $this->t('Invalid Aadhaar number. Must be 12 digits.'));
    }

    if ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
      $form_state->setErrorByName('bank][account_row][bank_ifsc', $this->t('Invalid IFSC code format. Example: SBIN0001234'));
    }

    if ($pan === '') {
      return;
    }

    $pan_exists = $this->database->select('niftybot_kyc', 'k')
      ->fields('k', ['kyc_id'])
      ->condition('pan_number', $pan)
      ->condition('uid', $this->currentUser->id(), '<>')
      ->execute()
      ->fetchField();

    if ($pan_exists) {
      $form_state->setErrorByName('identity][id_row][pan_number', $this->t('This PAN number is already registered.'));
    }
  }

  /**
   * Normalizes a PAN value for validation and storage.
   */
  protected function normalizePan(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
  }

  /**
   * Normalizes an Aadhaar value (digits only).
   */
  protected function normalizeAadhaar(string $value): string {
    return preg_replace('/\D/', '', trim($value)) ?? '';
  }

  /**
   * Normalizes an IFSC value for validation and storage.
   */
  protected function normalizeIfsc(string $value): string {
    return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();
    $aadhaar = $this->normalizeAadhaar((string) $form_state->getValue(['identity', 'id_row', 'aadhaar_number']));
    $aadhaar_hash = hash('sha256', $aadhaar);
    $aadhaar_last4 = substr($aadhaar, -4);
    $now = \Drupal::time()->getRequestTime();

    $pan_doc = $form_state->getValue(['identity', 'docs_row', 'pan_document']);
    $aadhaar_doc = $form_state->getValue(['identity', 'docs_row', 'aadhaar_document']);
    $selfie = $form_state->getValue(['verification', 'selfie']);

    $existing = $this->getExistingKyc();

    $fields = [
      'uid' => $uid,
      'pan_number' => $this->normalizePan((string) $form_state->getValue(['identity', 'id_row', 'pan_number'])),
      'aadhaar_last4' => $aadhaar_last4,
      'aadhaar_hash' => $aadhaar_hash,
      'full_name' => $form_state->getValue(['personal', 'full_name']),
      'date_of_birth' => $form_state->getValue(['personal', 'dob_row', 'date_of_birth']),
      'address' => $form_state->getValue(['personal', 'address']),
      'bank_account_number' => $form_state->getValue(['bank', 'account_row', 'bank_account_number']),
      'bank_ifsc' => $this->normalizeIfsc((string) $form_state->getValue(['bank', 'account_row', 'bank_ifsc'])),
      'bank_name' => $form_state->getValue(['bank', 'bank_name']),
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

    $full_name = trim((string) $form_state->getValue(['personal', 'full_name']));
    if ($full_name !== '') {
      $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
      if ($user && $user->hasField('field_full_name')) {
        $user->set('field_full_name', $full_name);
        $user->save();
      }
    }

    $this->messenger()->addStatus($this->t('Your KYC verification has been submitted successfully. You will be notified once it is reviewed.'));
    $form_state->setRedirect('niftybot_user.kyc_submit');
  }

  /**
   * Default full name from KYC record or user profile.
   */
  protected function getDefaultFullName(?object $existing_kyc): string {
    if ($existing_kyc && !empty($existing_kyc->full_name)) {
      return (string) $existing_kyc->full_name;
    }

    $user = \Drupal::entityTypeManager()->getStorage('user')->load($this->currentUser->id());
    if ($user) {
      $full_name = niftybot_user_profile_field_value($user, 'field_full_name');
      if ($full_name !== NULL) {
        return $full_name;
      }
    }

    $kyc_full_name = niftybot_user_kyc_full_name((int) $this->currentUser->id());
    if ($kyc_full_name !== NULL) {
      return $kyc_full_name;
    }

    return '';
  }

  /**
   * Get existing KYC record for current user.
   */
  protected function getExistingKyc(): ?object {
    $record = $this->database->select('niftybot_kyc', 'k')
      ->fields('k')
      ->condition('uid', $this->currentUser->id())
      ->execute()
      ->fetchObject();

    return $record === FALSE ? NULL : $record;
  }

}
