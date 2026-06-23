<?php

namespace Drupal\user_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserProfileController extends ControllerBase {

  public function editProfile() {
    $uid = $this->currentUser()->id();

    // Load existing seller profile for the user.
    $profiles = \Drupal::entityTypeManager()
      ->getStorage('profile')
      ->loadByProperties([
        'uid' => $uid,
        'type' => 'seller_profile', // <-- Change this to your actual profile type machine name
      ]);

    $profile = reset($profiles);

    // If no profile exists, create one (optional)
    if (!$profile) {
      $profile = Profile::create([
        'type' => 'seller_profile',
        'uid' => $uid,
      ]);
    }

    // Ensure only the owner can edit
    if ($profile->getOwnerId() != $uid) {
      throw new AccessDeniedHttpException();
    }

    // Load the edit form
    $form = \Drupal::entityTypeManager()
      ->getFormObject('profile', 'default')
      ->setEntity($profile);

    $form_render = \Drupal::formBuilder()->getForm($form);

    // Return with custom theming
    return [
      '#theme' => 'user_profile',
      '#data' => [
        'form' => $form_render,
      ],
    ];
  }
}
