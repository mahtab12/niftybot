<?php

namespace Drupal\niftybot_subscription\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin controller for subscription management.
 */
class SubscriptionAdminController extends ControllerBase {

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
   * List all subscription plans.
   */
  public function plansList() {
    $plans = $this->database->select('niftybot_subscription_plans', 'sp')
      ->fields('sp')
      ->orderBy('weight')
      ->execute()
      ->fetchAll();

    $header = [
      $this->t('Name'),
      $this->t('Price'),
      $this->t('Duration'),
      $this->t('Max Orders/Day'),
      $this->t('Status'),
      $this->t('Actions'),
    ];

    $rows = [];
    foreach ($plans as $plan) {
      $rows[] = [
        $plan->name,
        '₹' . number_format((float) $plan->price, 2),
        $plan->duration_days . ' days',
        $plan->max_orders_per_day,
        $plan->status ? $this->t('Active') : $this->t('Inactive'),
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute('niftybot_subscription.admin_plan_edit', ['plan_id' => $plan->plan_id]),
          ],
        ],
      ];
    }

    $build['add_link'] = [
      '#type' => 'link',
      '#title' => $this->t('+ Add New Plan'),
      '#url' => Url::fromRoute('niftybot_subscription.admin_plan_add'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $build['plans_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No subscription plans configured.'),
    ];

    return $build;
  }

  /**
   * List active subscribers.
   */
  public function subscribersList() {
    $query = $this->database->select('niftybot_user_subscriptions', 'us');
    $query->join('niftybot_subscription_plans', 'sp', 'us.plan_id = sp.plan_id');
    $query->fields('us');
    $query->addField('sp', 'name', 'plan_name');
    $query->condition('us.status', 'active');
    $query->orderBy('us.created', 'DESC');

    $subscriptions = $query->execute()->fetchAll();

    $header = [
      $this->t('User'),
      $this->t('Plan'),
      $this->t('Start Date'),
      $this->t('End Date'),
      $this->t('Amount Paid'),
      $this->t('Auto-Renew'),
    ];

    $rows = [];
    foreach ($subscriptions as $sub) {
      $user = $this->entityTypeManager()->getStorage('user')->load($sub->uid);
      $rows[] = [
        $user ? $user->getAccountName() : 'UID: ' . $sub->uid,
        $sub->plan_name,
        date('d M Y', $sub->start_date),
        date('d M Y', $sub->end_date),
        '₹' . number_format((float) $sub->payment_amount, 2),
        $sub->auto_renew ? $this->t('Yes') : $this->t('No'),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No active subscribers.'),
    ];
  }

}
