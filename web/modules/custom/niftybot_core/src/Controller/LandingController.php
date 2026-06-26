<?php

declare(strict_types=1);

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Empty landing page — layout is provided by the niftyoption theme.
 */
class LandingController extends ControllerBase {

  /**
   * Front page content (theme supplies the hero layout).
   */
  public function content(): array {
    return [
      '#cache' => [
        'contexts' => ['url.path.is_front'],
      ],
    ];
  }

}
