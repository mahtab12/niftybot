<?php

namespace Drupal\user_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user_dashboard\Service\MenuLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns the User Dashboard page.
 */
class UserDashboardController extends ControllerBase {

  protected MenuLoader $menuLoader;

  public function __construct(MenuLoader $menu_loader) {
    $this->menuLoader = $menu_loader;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user_dashboard.menu_loader')
    );
  }

  /**
   * Dashboard page callback.
   */
  public function dashboard() {
    $data = [
      $this->t('Welcome to your dashboard!'),
      $this->t('Here you can manage your account, view orders, and update settings.'),
    ];

    return [
      '#theme' => 'user_dashboard',
      '#data' => $data,
    ];
  }

  /**
   * Profile page callback.
   */
  public function profile() {
    $data = [
      $this->t('Welcome to your dashboard!'),
      $this->t('Here you can manage your account, view orders, and update settings.'),
    ];

    return [
      '#theme' => 'user_profile',
      '#data' => $data,
    ];
  }

}
