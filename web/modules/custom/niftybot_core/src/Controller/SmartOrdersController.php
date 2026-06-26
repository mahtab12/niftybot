<?php

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_core\Service\BrokerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists and manages Groww GTT / OCO smart orders.
 */
class SmartOrdersController extends ControllerBase {

  /**
   * The broker manager.
   *
   * @var \Drupal\niftybot_core\Service\BrokerManager
   */
  protected $brokerManager;

  /**
   * Constructs a SmartOrdersController object.
   */
  public function __construct(BrokerManager $broker_manager) {
    $this->brokerManager = $broker_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_core.broker_manager'),
    );
  }

  /**
   * Smart orders list page.
   */
  public function smartOrders() {
    $api_configured = $this->brokerManager->hasApiKey();
    $oco_orders = [];
    $gtt_orders = [];
    $message = '';

    if ($api_configured) {
      $oco = $this->brokerManager->listSmartOrders('OCO', 'FNO');
      $gtt = $this->brokerManager->listSmartOrders('GTT', 'FNO');
      $oco_orders = is_array($oco) ? ($oco['orders'] ?? []) : [];
      $gtt_orders = is_array($gtt) ? ($gtt['orders'] ?? []) : [];
      if (empty($oco['success']) && empty($gtt['success'])) {
        $message = $oco['message'] ?? $gtt['message'] ?? 'Could not load smart orders.';
      }
    }

    return [
      '#theme' => 'niftybot_smart_orders',
      '#api_configured' => $api_configured,
      '#oco_orders' => $oco_orders,
      '#gtt_orders' => $gtt_orders,
      '#message' => $message,
      '#attached' => [
        'library' => ['niftybot_core/admin'],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
