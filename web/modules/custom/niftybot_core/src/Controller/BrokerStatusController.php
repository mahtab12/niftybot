<?php

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_core\Service\BrokerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays broker connection status and Groww user profile.
 */
class BrokerStatusController extends ControllerBase {

  /**
   * The broker manager.
   *
   * @var \Drupal\niftybot_core\Service\BrokerManager
   */
  protected $brokerManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a BrokerStatusController object.
   */
  public function __construct(
    BrokerManager $broker_manager,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->brokerManager = $broker_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_core.broker_manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * Broker connection status page.
   */
  public function status() {
    $config = $this->configFactory->get('niftybot_core.settings');
    $broker = 'groww';
    $api_configured = $this->brokerManager->hasApiKey();
    $base_url = $config->get('trading_api_base_url') ?? 'http://trading:8000';

    $health = $this->brokerManager->getBrokerHealth($broker);
    $profile = $api_configured ? $this->brokerManager->getUserProfile($broker) : NULL;

    $connected = !empty($health['connected']);
    $status_message = $health['message'] ?? $this->t('Unable to reach the trading service.');

    if (!$api_configured) {
      $status_message = $this->t('Trading Service API Key is not configured. Add it in NiftyBot Settings.');
    }

    return [
      '#theme' => 'niftybot_broker_status',
      '#broker' => $broker,
      '#connected' => $connected,
      '#status_message' => $status_message,
      '#api_configured' => $api_configured,
      '#base_url' => $base_url,
      '#profile' => is_array($profile) ? $profile : [],
      '#attached' => [
        'library' => ['niftybot_core/admin'],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
