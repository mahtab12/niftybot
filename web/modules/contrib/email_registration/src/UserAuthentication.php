<?php

declare(strict_types=1);

namespace Drupal\email_registration;

use Drupal\user\UserAuthentication as CoreUserAuthentication;
use Drupal\user\UserInterface;

/**
 * Extends the core authentication to check email or username, and password.
 *
 * Core authentication only checks username and password, but not email, so
 * we need to adapt the query to cater for both.
 */
class UserAuthentication extends CoreUserAuthentication {

  /**
   * {@inheritdoc}
   */
  public function lookupAccount($identifier): UserInterface|false {
    if (!empty($identifier)) {

      /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage('user');

      $query = $storage->getQuery();
      $query->accessCheck(TRUE);

      $or = $query->orConditionGroup();
      $or->condition('name', $identifier);
      $or->condition('mail', $identifier);

      $query->condition($or);

      $result = $query->execute();

      $account_search = $storage->loadMultiple($result);
      if ($account = reset($account_search)) {
        return $account;
      }
    }
    return FALSE;
  }

}
