<?php

declare(strict_types=1);

// AI generated.
namespace Drupal\Tests\country\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the CountryFieldManager service.
 *
 * @group country
 * @coversDefaultClass \Drupal\country\CountryFieldManager
 */
class CountryFieldManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'country',
    'field',
    'node',
    'system',
    'user',
    'text',
  ];

  /**
   * The entity type to use for testing.
   *
   * @var string
   */
  protected $entityType = 'node';

  /**
   * The bundle to use for testing.
   *
   * @var string
   */
  protected $bundle = 'test_type';

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
   * The country field manager.
   *
   * @var \Drupal\country\CountryFieldManager
   */
  protected $countryFieldManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installConfig(['field', 'node', 'system']);

    $this->createContentType();

    $this->countryFieldManager = $this->container->get('country.field.manager');

    // Create the basic field.
    $this->createCountryField('field_country', [], []);
  }

  /**
   * Tests getList() returns all countries.
   *
   * @covers ::getList
   */
  public function testGetListReturnsAllCountries(): void {
    $countries = $this->countryFieldManager->getList();

    $this->assertIsArray($countries);
    $this->assertNotEmpty($countries);
    // Check for some well-known countries.
    $this->assertArrayHasKey('US', $countries);
    $this->assertArrayHasKey('GB', $countries);
    $this->assertArrayHasKey('DE', $countries);
    $this->assertArrayHasKey('FR', $countries);
    $this->assertArrayHasKey('NL', $countries);
  }

  /**
   * Tests country names are returned correctly.
   *
   * @covers ::getList
   */
  public function testGetListReturnsCountryNames(): void {
    $countries = $this->countryFieldManager->getList();

    // Verify country names (these are stable ISO country names).
    $this->assertEquals('United States', (string) $countries['US']);
    $this->assertEquals('United Kingdom', (string) $countries['GB']);
    $this->assertEquals('Germany', (string) $countries['DE']);
    $this->assertEquals('Netherlands', (string) $countries['NL']);
  }

  /**
   * Tests getSelectableCountries() with no restrictions.
   *
   * @covers ::getSelectableCountries
   */
  public function testGetSelectableCountriesNoRestrictions(): void {
    // Use the field created in setUp with no restrictions.
    $countries = $this->countryFieldManager->getSelectableCountries($this->field);

    // Should return all countries.
    $this->assertArrayHasKey('US', $countries);
    $this->assertArrayHasKey('GB', $countries);
    $this->assertArrayHasKey('NL', $countries);
  }

  /**
   * Tests getSelectableCountries() with storage-level restrictions.
   *
   * @covers ::getSelectableCountries
   */
  public function testGetSelectableCountriesWithStorageRestrictions(): void {
    // Create a country field with storage-level restrictions.
    $field = $this->createRestrictedCountryField(['US' => 'US', 'CA' => 'CA', 'MX' => 'MX']);

    $countries = $this->countryFieldManager->getSelectableCountries($field);

    // Should only return allowed countries.
    $this->assertCount(3, $countries);
    $this->assertArrayHasKey('US', $countries);
    $this->assertArrayHasKey('CA', $countries);
    $this->assertArrayHasKey('MX', $countries);
    $this->assertArrayNotHasKey('GB', $countries);
    $this->assertArrayNotHasKey('DE', $countries);
  }

  /**
   * Tests getSelectableCountries() with field-level restrictions.
   *
   * @covers ::getSelectableCountries
   */
  public function testGetSelectableCountriesWithFieldRestrictions(): void {
    // Create a country field with field-level restrictions (overrides storage).
    $field = $this->createRestrictedCountryField(['NL' => 'NL', 'BE' => 'BE'], 'field_country_field_restricted', FALSE);

    $countries = $this->countryFieldManager->getSelectableCountries($field);

    // Field-level settings should take precedence.
    $this->assertCount(2, $countries);
    $this->assertArrayHasKey('NL', $countries);
    $this->assertArrayHasKey('BE', $countries);
    $this->assertArrayNotHasKey('US', $countries);
  }

  /**
   * Creates a test content type.
   */
  protected function createContentType(): void {
    $this->container->get('entity_type.manager')
      ->getStorage('node_type')
      ->create([
        'type' => 'test_type',
        'name' => 'Test Type',
      ])
      ->save();
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
   * @param string $field_name
   *   The field name.
   * @param bool $storage_level
   *   Whether to apply restrictions at storage level or field level.
   *
   * @return \Drupal\field\Entity\FieldConfig
   *   The created field.
   */
  protected function createRestrictedCountryField(array $countries, string $field_name = 'field_country_restricted', bool $storage_level = TRUE): FieldConfig {
    $storage_settings = $storage_level ? ['selectable_countries' => $countries] : [];
    $field_settings = !$storage_level ? ['selectable_countries' => $countries] : [];

    return $this->createCountryField($field_name, $storage_settings, $field_settings);
  }

}
