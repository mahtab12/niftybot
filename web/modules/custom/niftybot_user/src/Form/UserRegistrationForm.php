<?php

namespace Drupal\niftybot_user\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\niftybot_user\Service\MemberIdService;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Custom user registration form for NiftyBot.
 */
class UserRegistrationForm extends FormBase {

  /**
   * The password hasher.
   *
   * @var \Drupal\Core\Password\PasswordInterface
   */
  protected $passwordHasher;

  /**
   * The member ID service.
   */
  protected MemberIdService $memberIdService;

  /**
   * Constructs the form.
   */
  public function __construct(PasswordInterface $password_hasher, MemberIdService $member_id_service) {
    $this->passwordHasher = $password_hasher;
    $this->memberIdService = $member_id_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('password'),
      $container->get('niftybot_user.member_id_service'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_user_registration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;
    $form['#attributes']['class'][] = 'niftybot-registration-form';

    $form['full_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full Name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#attributes' => ['class' => ['form-control']],
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['contact_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['niftyoption-contact-fields']],
    ];

    $form['contact_row']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#required' => TRUE,
      '#attributes' => ['class' => ['form-control']],
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['contact_row']['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Mobile Number'),
      '#required' => TRUE,
      '#maxlength' => 15,
      '#attributes' => [
        'class' => ['form-control'],
        'pattern' => '[0-9]{10}',
        'placeholder' => '10-digit mobile number',
      ],
      '#wrapper_attributes' => ['class' => ['mb-3']],
    ];

    $form['password_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'g-3', 'niftyoption-field-pair', 'niftyoption-password-pair']],
    ];

    $form['password_row']['password'] = [
      '#type' => 'password_confirm',
      '#required' => TRUE,
      '#size' => 25,
    ];

    $form['terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the <a href="/terms" target="_blank">Terms of Service</a> and <a href="/privacy" target="_blank">Privacy Policy</a>.'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Account'),
      '#attributes' => ['class' => ['button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $email = strtolower($this->registrationEmail($form_state));
    $phone = $this->normalizePhone($this->registrationValue($form_state, ['contact_row', 'phone']));
    $password = $this->registrationPassword($form_state);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('contact_row][email', $this->t('Please enter a valid email address.'));
    }

    if (strlen($email) > UserInterface::USERNAME_MAX_LENGTH) {
      $form_state->setErrorByName('contact_row][email', $this->t('This email address is too long to use for sign-in.'));
    }

    if (user_load_by_mail($email)) {
      $form_state->setErrorByName('contact_row][email', $this->t('An account with this email already exists.'));
    }

    if (user_load_by_name($email)) {
      $form_state->setErrorByName('contact_row][email', $this->t('An account with this email already exists.'));
    }

    if (!preg_match('/^[0-9]{10}$/', $phone)) {
      $form_state->setErrorByName('contact_row][phone', $this->t('Please enter a valid 10-digit mobile number.'));
    }

    if (strlen($password) < 8) {
      $form_state->setErrorByName('password_row][password', $this->t('Password must be at least 8 characters.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $email = strtolower($this->registrationEmail($form_state));
    $password = $this->registrationPassword($form_state);
    $full_name = trim($this->registrationValue($form_state, ['full_name']));
    $phone = $this->normalizePhone($this->registrationValue($form_state, ['contact_row', 'phone']));

    $user = User::create([
      'name' => $email,
      'mail' => $email,
      'pass' => $password,
      'status' => 1,
    ]);

    if ($full_name !== '' && $user->hasField('field_full_name')) {
      $user->set('field_full_name', $full_name);
    }
    if ($phone !== '' && $user->hasField('field_mobile_number')) {
      $user->set('field_mobile_number', $phone);
    }

    $user->addRole('niftybot_trader');
    $user->save();

    $account = User::load($user->id());
    if ($account === NULL) {
      throw new \RuntimeException('Unable to load the new user account.');
    }

    $member_id = $this->memberIdService->assignMemberId((int) $account->id());
    if ($account->getAccountName() !== $member_id) {
      $account->setUsername($member_id);
      $account->save();
    }
    $account = User::load($account->id());

    _user_mail_notify('register_no_approval_required', $account);
    user_login_finalize($account);

    $this->messenger()->addStatus($this->t('Welcome to NiftyBot! Your member ID is @id. ₹2,500 trading credit has been added to your wallet. Please complete KYC verification to start trading.', [
      '@id' => $member_id,
    ]));
    $form_state->setRedirect('niftybot_user.kyc_submit');
  }

  /**
   * Reads a nested registration field value.
   */
  private function registrationValue(FormStateInterface $form_state, array $parents): string {
    $value = $form_state->getValue($parents);
    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * Reads the email field from the registration form.
   */
  private function registrationEmail(FormStateInterface $form_state): string {
    return $this->registrationValue($form_state, ['contact_row', 'email']);
  }

  /**
   * Reads the confirmed password from the registration form.
   */
  private function registrationPassword(FormStateInterface $form_state): string {
    $value = $form_state->getValue(['password_row', 'password']);
    if (is_array($value)) {
      return trim((string) ($value['pass1'] ?? ''));
    }
    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * Normalizes a mobile number to digits only.
   */
  private function normalizePhone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    return is_string($digits) ? $digits : '';
  }

}
