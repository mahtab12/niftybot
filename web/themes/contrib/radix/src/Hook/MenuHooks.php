<?php

namespace Drupal\radix\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for radix.
 */
class MenuHooks {

  /**
   * Implements hook_preprocess_menu().
   */
  #[Hook('preprocess_menu')]
  public static function preprocessMenu(&$variables, $hook) {
    // No changes for menu toolbar.
    if ($hook == 'menu__toolbar') {
      return;
    }
    // Get the current path.
    $current_path = \Drupal::request()->getRequestUri();
    $items = $variables['items'];
    foreach ($items as $key => $item) {
      if (isset($item['url']) && is_object($item['url']) && $item['url']->toString() == $current_path) {
        $variables['items'][$key]['in_active_trail'] = TRUE;
      }
      if (isset($item['url']) && is_object($item['url']) && $item['url']->isRouted() && $item['url']->getRouteName() === '<nolink>') {
        $variables['items'][$key]['attributes']->addClass('navbar-text');
      }
    }
  }

}
