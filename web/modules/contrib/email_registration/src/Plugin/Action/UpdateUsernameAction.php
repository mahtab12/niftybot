<?php

declare(strict_types=1);

namespace Drupal\email_registration\Plugin\Action;

use Drupal\Core\Action\Plugin\Action\EntityActionBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\email_registration\UsernameGenerator;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Auto username rename bulk action.
 *
 * @Action(
 *   id = "email_registration_update_username",
 *   label = @Translation("Update username (from email_registration)"),
 *   type = "user",
 * )
 */
class UpdateUsernameAction extends EntityActionBase {

  /**
   * The username generator service.
   *
   * @var \Drupal\email_registration\UsernameGenerator
   */
  protected $usernameGenerator;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, string $plugin_id, array $plugin_definition, EntityTypeManagerInterface $entity_type_manager, UsernameGenerator $usernameGenerator) {
    $this->usernameGenerator = $usernameGenerator;
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('email_registration.username_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($account = NULL) {
    // Rename the given user:
    if (!empty($account) && $account instanceof UserInterface) {
      // Give the user a temporary 'email_registration_' username, so that
      // our "email_registration_user_presave()" hook can execute:
      $account->setUsername($this->usernameGenerator->generateRandomUsername())->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\user\UserInterface $object */
    $access = $object->status->access('edit', $account, TRUE)
      ->andIf($object->access('update', $account, TRUE));

    return $return_as_object ? $access : $access->isAllowed();
  }

}
