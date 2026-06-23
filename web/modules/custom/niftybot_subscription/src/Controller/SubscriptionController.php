<?php

namespace Drupal\niftybot_subscription\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Subscription controller for user-facing pages.
 */
class SubscriptionController extends ControllerBase {

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
   * Display available subscription plans.
   */
  public function plans() {
    $plans = $this->database->select('niftybot_subscription_plans', 'sp')
      ->fields('sp')
      ->condition('status', 1)
      ->orderBy('weight')
      ->execute()
      ->fetchAll();

    foreach ($plans as &$plan) {
      $plan->features_list = json_decode($plan->features, TRUE) ?? [];
    }

    $current_sub = $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us')
      ->condition('uid', $this->currentUser()->id())
      ->condition('status', 'active')
      ->execute()
      ->fetchObject();

    return [
      '#theme' => 'niftybot_subscription_plans',
      '#plans' => $plans,
      '#current_subscription' => $current_sub,
    ];
  }

  /**
   * Display user's current subscription.
   */
  public function mySubscription() {
    $uid = $this->currentUser()->id();

    $subscription = $this->database->select('niftybot_user_subscriptions', 'us')
      ->fields('us')
      ->condition('uid', $uid)
      ->condition('status', 'active')
      ->execute()
      ->fetchObject();

    $plan = NULL;
    $days_remaining = 0;

    if ($subscription) {
      $plan = $this->database->select('niftybot_subscription_plans', 'sp')
        ->fields('sp')
        ->condition('plan_id', $subscription->plan_id)
        ->execute()
        ->fetchObject();

      if ($plan) {
        $plan->features_list = json_decode($plan->features, TRUE) ?? [];
      }

      $days_remaining = max(0, (int) ceil(($subscription->end_date - \Drupal::time()->getRequestTime()) / 86400));
    }

    return [
      '#theme' => 'niftybot_my_subscription',
      '#subscription' => $subscription,
      '#plan' => $plan,
      '#days_remaining' => $days_remaining,
    ];
  }

}
