<?php

declare(strict_types=1);

// AI generated.
namespace Drupal\Tests\country\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;

/**
 * Base class for Country kernel tests.
 *
 * Provides common setup and helper methods for testing country field
 * functionality.
 */
abstract class CountryKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'country',
    'entity_test',
    'field',
    'system',
    'user',
  ];

  /**
   * The field storage.
   *
   * @var \Drupal\field\Entity\FieldStorageConfig
   */
  protected $fieldStorage;

  /**
   * The field.
   *
   * @var \Drupal\field\Entity\FieldConfig
   */
  protected $field;

  /**
   * The entity type to use for testing.
   *
   * @var string
   */
  protected $entityType = 'entity_test';

  /**
   * The bundle to use for testing.
   *
   * @var string
   */
  protected $bundle = 'entity_test';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema($this->entityType);
    $this->installEntitySchema('user');

    // Create a basic country field.
    $this->createCountryField('field_country', [], []);
  }

  /**
   * Creates a country field with the given settings.
   *
   * @param string $field_name
   *   The field name.
   * @param array $storage_settings
   *   The field storage settings.
   * @param array $field_settings
   *   The field settings.
   *
   * @return \Drupal\field\Entity\FieldConfig
   *   The created field.
   */
  protected function createCountryField(string $field_name, array $storage_settings = [], array $field_settings = []): FieldConfig {
    $this->fieldStorage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $this->entityType,
      'type' => 'country',
      'settings' => $storage_settings,
    ]);
    $this->fieldStorage->save();

    $this->field = FieldConfig::create([
      'field_storage' => $this->fieldStorage,
      'bundle' => $this->bundle,
      'label' => 'Country',
      'settings' => $field_settings,
    ]);
    $this->field->save();

    return $this->field;
  }

  /**
   * Creates a country field restricted to specific countries.
   *
   * @param array $countries
   *   Array of country codes to restrict to.
   *   Example: ['US' => 'US', 'CA' => 'CA'].
   * @param string $field_name
   *   The field name.
   * @param bool $storage_level
   *   Whether to apply restrictions at storage level (TRUE) or field
   *   level (FALSE).
   *
   * @return \Drupal\field\Entity\FieldConfig
   *   The created field.
   */
  protected function createRestrictedCountryField(array $countries, string $field_name = 'field_country_restricted', bool $storage_level = TRUE): FieldConfig {
    $storage_settings = $storage_level ? ['selectable_countries' => $countries] : [];
    $field_settings = !$storage_level ? ['selectable_countries' => $countries] : [];

    return $this->createCountryField($field_name, $storage_settings, $field_settings);
  }

  /**
   * Creates a multi-value country field.
   *
   * @param int $cardinality
   *   The field cardinality (-1 for unlimited).
   * @param string $field_name
   *   The field name.
   *
   * @return \Drupal\field\Entity\FieldConfig
   *   The created field.
   */
  protected function createMultiValueCountryField(int $cardinality = -1, string $field_name = 'field_countries'): FieldConfig {
    $this->fieldStorage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $this->entityType,
      'type' => 'country',
      'cardinality' => $cardinality,
    ]);
    $this->fieldStorage->save();

    $this->field = FieldConfig::create([
      'field_storage' => $this->fieldStorage,
      'bundle' => $this->bundle,
      'label' => 'Countries',
    ]);
    $this->field->save();

    return $this->field;
  }

}
