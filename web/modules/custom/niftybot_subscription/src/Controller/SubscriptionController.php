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
      '#attached' => [
        'library' => ['niftybot_subscription/subscription'],
      ],
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

    $pending_subscription = NULL;
    if (!$subscription) {
      $pending_subscription = $this->database->select('niftybot_user_subscriptions', 'us')
        ->fields('us')
        ->condition('uid', $uid)
        ->condition('status', 'pending_payment')
        ->orderBy('created', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchObject();
    }

    $display_subscription = $subscription ?: $pending_subscription;
    $plan = NULL;
    $days_remaining = 0;

    if ($display_subscription) {
      $plan = $this->database->select('niftybot_subscription_plans', 'sp')
        ->fields('sp')
        ->condition('plan_id', $display_subscription->plan_id)
        ->execute()
        ->fetchObject();

      if ($plan) {
        $plan->features_list = json_decode($plan->features, TRUE) ?? [];
      }

      if ($subscription) {
        $days_remaining = max(0, (int) ceil(($subscription->end_date - \Drupal::time()->getRequestTime()) / 86400));
      }
    }

    return [
      '#theme' => 'niftybot_my_subscription',
      '#subscription' => $display_subscription,
      '#plan' => $plan,
      '#days_remaining' => $days_remaining,
      '#attached' => [
        'library' => ['niftybot_subscription/subscription'],
      ],
    ];
  }

}
