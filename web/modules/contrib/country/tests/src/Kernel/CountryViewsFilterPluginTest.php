<?php

declare(strict_types=1);

// AI generated.
namespace Drupal\Tests\country\Kernel;

use Drupal\country\Plugin\views\filter\CountryItem;

/**
 * Tests the Country views filter plugin.
 *
 * @group country
 * @coversDefaultClass \Drupal\country\Plugin\views\filter\CountryItem
 */
class CountryViewsFilterPluginTest extends CountryKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'country',
    'entity_test',
    'field',
    'system',
    'user',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['field', 'views']);
  }

  /**
   * Tests hasExtraOptions returns TRUE.
   *
   * @covers ::hasExtraOptions
   */
  public function testHasExtraOptions(): void {
    $configuration = [];
    $plugin_id = 'country_item';
    $plugin_definition = [
      'entity_type' => 'entity_test',
      'field_name' => 'field_country',
    ];

    $filter = CountryItem::create(
      $this->container,
      $configuration,
      $plugin_id,
      $plugin_definition
    );

    $this->assertTrue($filter->hasExtraOptions());
  }

  /**
   * Tests getValueOptions with global setting.
   *
   * @covers ::getValueOptions
   */
  public function testGetValueOptionsGlobal(): void {
    $configuration = [
      'country_target_bundle' => 'global',
    ];
    $plugin_id = 'country_item';
    $plugin_definition = [
      'entity_type' => 'entity_test',
      'field_name' => 'field_country',
    ];

    $filter = CountryItem::create(
      $this->container,
      $configuration,
      $plugin_id,
      $plugin_definition
    );

    $filter->options = ['country_target_bundle' => 'global'];
    $options = $filter->getValueOptions();

    // Should return all countries.
    $this->assertIsArray($options);
    $this->assertNotEmpty($options);
    $this->assertArrayHasKey('US', $options);
    $this->assertArrayHasKey('GB', $options);
    $this->assertArrayHasKey('DE', $options);
  }

  /**
   * Tests getValueOptions with bundle-specific restrictions.
   *
   * @covers ::getValueOptions
   */
  public function testGetValueOptionsBundleSpecific(): void {
    // Create a restricted field.
    $this->createRestrictedCountryField(['US' => 'US', 'CA' => 'CA']);

    $configuration = [
      'country_target_bundle' => 'entity_test',
    ];
    $plugin_id = 'country_item';
    $plugin_definition = [
      'entity_type' => 'entity_test',
      'field_name' => 'field_country_restricted',
    ];

    $filter = CountryItem::create(
      $this->container,
      $configuration,
      $plugin_id,
      $plugin_definition
    );

    $filter->options = ['country_target_bundle' => 'entity_test'];
    $options = $filter->getValueOptions();

    // Should only return restricted countries.
    $this->assertCount(2, $options);
    $this->assertArrayHasKey('US', $options);
    $this->assertArrayHasKey('CA', $options);
    $this->assertArrayNotHasKey('GB', $options);
  }

}
