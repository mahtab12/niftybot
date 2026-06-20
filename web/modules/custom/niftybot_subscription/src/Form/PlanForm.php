<?php

namespace Drupal\niftybot_subscription\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for creating/editing subscription plans.
 */
class PlanForm extends FormBase {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * Constructs the form.
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
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'niftybot_plan_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $plan_id = NULL) {
    $plan = NULL;
    if ($plan_id) {
      $plan = $this->database->select('niftybot_subscription_plans', 'sp')
        ->fields('sp')
        ->condition('plan_id', $plan_id)
        ->execute()
        ->fetchObject();
      $form_state->set('plan_id', $plan_id);
    }

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Plan Name'),
      '#required' => TRUE,
      '#default_value' => $plan->name ?? '',
    ];

    $form['machine_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Machine Name'),
      '#required' => TRUE,
      '#default_value' => $plan->machine_name ?? '',
      '#disabled' => (bool) $plan,
      '#machine_name' => [
        'exists' => [$this, 'machineNameExists'],
      ],
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#rows' => 3,
      '#default_value' => $plan->description ?? '',
    ];

    $form['price'] = [
      '#type' => 'number',
      '#title' => $this->t('Price (₹)'),
      '#required' => TRUE,
      '#min' => 0,
      '#step' => '0.01',
      '#default_value' => $plan->price ?? '',
    ];

    $form['duration_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Duration (days)'),
      '#required' => TRUE,
      '#min' => 1,
      '#default_value' => $plan->duration_days ?? 30,
    ];

    $form['max_orders_per_day'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Orders Per Day'),
      '#required' => TRUE,
      '#min' => 1,
      '#default_value' => $plan->max_orders_per_day ?? 10,
    ];

    $form['max_capital'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Capital (₹)'),
      '#min' => 0,
      '#step' => '0.01',
      '#default_value' => $plan->max_capital ?? '',
    ];

    $form['features'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Features (one per line)'),
      '#rows' => 6,
      '#default_value' => $plan ? implode("\n", json_decode($plan->features, TRUE) ?? []) : '',
      '#description' => $this->t('Enter one feature per line.'),
    ];

    $form['brokers_allowed'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed Brokers'),
      '#options' => [
        'groww' => 'Groww',
        'zerodha' => 'Zerodha',
        'angel' => 'Angel One',
        'upstox' => 'Upstox',
        'fyers' => 'Fyers',
      ],
      '#default_value' => $plan ? explode(',', $plan->brokers_allowed) : [],
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Active'),
      '#default_value' => $plan ? $plan->status : 1,
    ];

    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Sort Weight'),
      '#default_value' => $plan->weight ?? 0,
      '#description' => $this->t('Lower weight = higher position in the plans list.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $plan ? $this->t('Update Plan') : $this->t('Create Plan'),
      '#attributes' => ['class' => ['button--primary']],
    ];

    return $form;
  }

  /**
   * Machine name exists callback.
   */
  public function machineNameExists($value) {
    return (bool) $this->database->select('niftybot_subscription_plans', 'sp')
      ->fields('sp', ['plan_id'])
      ->condition('machine_name', $value)
      ->execute()
      ->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $now = \Drupal::time()->getRequestTime();
    $features = array_filter(array_map('trim', explode("\n", $form_state->getValue('features'))));
    $brokers = array_filter($form_state->getValue('brokers_allowed'));

    $fields = [
      'name' => $form_state->getValue('name'),
      'description' => $form_state->getValue('description'),
      'price' => $form_state->getValue('price'),
      'duration_days' => $form_state->getValue('duration_days'),
      'max_orders_per_day' => $form_state->getValue('max_orders_per_day'),
      'max_capital' => $form_state->getValue('max_capital'),
      'features' => json_encode(array_values($features)),
      'brokers_allowed' => implode(',', $brokers),
      'status' => $form_state->getValue('status') ? 1 : 0,
      'weight' => $form_state->getValue('weight'),
      'updated' => $now,
    ];

    $plan_id = $form_state->get('plan_id');

    if ($plan_id) {
      $this->database->update('niftybot_subscription_plans')
        ->fields($fields)
        ->condition('plan_id', $plan_id)
        ->execute();
      $this->messenger()->addStatus($this->t('Plan updated successfully.'));
    }
    else {
      $fields['machine_name'] = $form_state->getValue('machine_name');
      $fields['created'] = $now;
      $this->database->insert('niftybot_subscription_plans')
        ->fields($fields)
        ->execute();
      $this->messenger()->addStatus($this->t('Plan created successfully.'));
    }

    $form_state->setRedirect('niftybot_subscription.admin_plans');
  }

}
