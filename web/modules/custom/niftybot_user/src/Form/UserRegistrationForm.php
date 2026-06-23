<?php

namespace Drupal\niftybot_user\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\niftybot_user\Service\MemberIdService;
use Drupal\user\Entity\User;
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
    $form['#attributes']['class'][] = 'niftybot-registration-form';

    $form['full_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full Name'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#required' => TRUE,
    ];

    $form['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Mobile Number'),
      '#required' => TRUE,
      '#maxlength' => 15,
      '#attributes' => [
        'pattern' => '[0-9]{10}',
        'placeholder' => '10-digit mobile number',
      ],
    ];

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#required' => TRUE,
      '#maxlength' => 60,
    ];

    $form['password'] = [
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
    $email = $form_state->getValue('email');
    $username = $form_state->getValue('username');
    $phone = $form_state->getValue('phone');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('email', $this->t('Please enter a valid email address.'));
    }

    if (user_load_by_mail($email)) {
      $form_state->setErrorByName('email', $this->t('An account with this email already exists.'));
    }

    if (user_load_by_name($username)) {
      $form_state->setErrorByName('username', $this->t('This username is already taken.'));
    }

    if (!preg_match('/^[0-9]{10}$/', $phone)) {
      $form_state->setErrorByName('phone', $this->t('Please enter a valid 10-digit mobile number.'));
    }

    if (strlen($form_state->getValue('password')) < 8) {
      $form_state->setErrorByName('password', $this->t('Password must be at least 8 characters.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user = User::create([
      'name' => $form_state->getValue('username'),
      'mail' => $form_state->getValue('email'),
      'pass' => $form_state->getValue('password'),
      'status' => 1,
      'field_full_name' => $form_state->getValue('full_name'),
      'field_phone' => $form_state->getValue('phone'),
    ]);

    $user->addRole('niftybot_trader');
    $user->save();

    $member_id = $this->memberIdService->getMemberId((int) $user->id());

    _user_mail_notify('register_no_approval_required', $user);
    user_login_finalize($user);

    $this->messenger()->addStatus($this->t('Welcome to NiftyBot! Your member ID is @id. Please complete KYC verification to start trading.', [
      '@id' => $member_id,
    ]));
    $form_state->setRedirect('niftybot_user.kyc_submit');
  }

}
