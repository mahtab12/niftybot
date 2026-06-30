<?php

namespace Drupal\niftybot_user\Service;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\user\UserInterface;

/**
 * Preset avatar images for user profiles.
 */
class AvatarService {

  public const AVATAR_COUNT = 9;

  public const DEFAULT_AVATAR_ID = '1';

  public function __construct(
    protected ThemeExtensionList $themeList,
  ) {}

  /**
   * Normalizes an avatar id to a valid preset (1–9).
   */
  public function normalizeAvatarId(?string $avatar_id): string {
    $id = (int) $avatar_id;
    if ($id >= 1 && $id <= self::AVATAR_COUNT) {
      return (string) $id;
    }
    return self::DEFAULT_AVATAR_ID;
  }

  /**
   * Returns the public URL for a preset avatar image.
   */
  public function getAvatarUrl(?string $avatar_id): string {
    $id = $this->normalizeAvatarId($avatar_id);
    $theme_path = $this->themeList->getPath('niftyoption');
    return '/' . $theme_path . '/assets/images/avatars/avatar-' . $id . '.png';
  }

  /**
   * Returns the selected avatar id for a user.
   */
  public function getUserAvatarId(UserInterface $user): string {
    if ($user->hasField('field_avatar_id') && !$user->get('field_avatar_id')->isEmpty()) {
      return $this->normalizeAvatarId($user->get('field_avatar_id')->value);
    }
    return self::DEFAULT_AVATAR_ID;
  }

  /**
   * Returns the avatar image URL for a user.
   */
  public function getUserAvatarUrl(UserInterface $user): string {
    return $this->getAvatarUrl($this->getUserAvatarId($user));
  }

  /**
   * All preset choices keyed by avatar id.
   *
   * @return array<string, array{id: string, url: string, label: string}>
   */
  public function getChoices(): array {
    $choices = [];
    for ($i = 1; $i <= self::AVATAR_COUNT; $i++) {
      $id = (string) $i;
      $choices[$id] = [
        'id' => $id,
        'url' => $this->getAvatarUrl($id),
        'label' => (string) t('Avatar @num', ['@num' => $id]),
      ];
    }
    return $choices;
  }

  /**
   * Saves a preset avatar selection for a user.
   */
  public function setUserAvatar(UserInterface $user, string $avatar_id): bool {
    if (!$user->hasField('field_avatar_id')) {
      return FALSE;
    }
    $user->set('field_avatar_id', $this->normalizeAvatarId($avatar_id));
    $user->save();
    return TRUE;
  }

  /**
   * Picks a random preset avatar id.
   */
  public function randomAvatarId(): string {
    return (string) random_int(1, self::AVATAR_COUNT);
  }

}
