<?php

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * User broker connection page.
 */
class UserBrokerController extends ControllerBase {

  /**
   * Broker connection form and status.
   */
  public function connection() {
    $form = $this->formBuilder()->getForm('Drupal\niftybot_core\Form\BrokerConnectionForm');
    $form['#cache'] = [
      'max-age' => 0,
      'contexts' => ['user'],
    ];
    return $form;
  }

}
