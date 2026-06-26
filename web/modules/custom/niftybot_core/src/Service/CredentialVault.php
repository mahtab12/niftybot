<?php

namespace Drupal\niftybot_core\Service;

use Drupal\Core\Site\Settings;

/**
 * Encrypts and decrypts broker API secrets at rest.
 */
class CredentialVault {

  /**
   * Encrypts a plaintext string.
   */
  public function encrypt(string $plain): string {
    if ($plain === '') {
      return '';
    }
    $key = $this->deriveKey();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
    return base64_encode($nonce . $cipher);
  }

  /**
   * Decrypts a value produced by encrypt().
   */
  public function decrypt(string $encoded): string {
    if ($encoded === '') {
      return '';
    }
    $decoded = base64_decode($encoded, TRUE);
    if ($decoded === FALSE) {
      return '';
    }
    $nonce_length = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if (strlen($decoded) <= $nonce_length) {
      return '';
    }
    $nonce = substr($decoded, 0, $nonce_length);
    $cipher = substr($decoded, $nonce_length);
    $key = $this->deriveKey();
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    return $plain === FALSE ? '' : $plain;
  }

  /**
   * Derives a symmetric key from the site hash salt.
   */
  protected function deriveKey(): string {
    return hash('sha256', Settings::getHashSalt(), TRUE);
  }

}
