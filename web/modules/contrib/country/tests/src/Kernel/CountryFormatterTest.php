<?php

declare(strict_types=1);

// AI generated.
namespace Drupal\Tests\country\Kernel;

use Drupal\entity_test\Entity\EntityTest;

/**
 * Tests the Country field formatters.
 *
 * @group country
 */
class CountryFormatterTest extends CountryKernelTestBase {

  /**
   * The entity view display.
   *
   * @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface
   */
  protected $display;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['field']);

    // Create a display for the entity type.
    $this->display = \Drupal::service('entity_display.repository')
      ->getViewDisplay($this->entityType, $this->bundle)
      ->setComponent('field_country', [
        'type' => 'country_default',
      ]);
    $this->display->save();
  }

  /**
   * Tests the default formatter displays country name.
   *
   * @covers \Drupal\country\Plugin\Field\FieldFormatter\CountryDefaultFormatter::viewElements
   */
  public function testDefaultFormatter(): void {
    $entity = EntityTest::create([
      'field_country' => 'US',
    ]);
    $entity->save();

    $build = $this->display->build($entity);
    $output = \Drupal::service('renderer')->renderRoot($build);

    // Should display "United States", not "US".
    $this->assertStringContainsString('United States', (string) $output);
    $this->assertStringNotContainsString('>US<', (string) $output);
  }

  /**
   * Tests the default formatter with various countries.
   *
   * @dataProvider countryDataProvider
   */
  public function testDefaultFormatterVariousCountries(string $code, string $expected_name): void {
    $entity = EntityTest::create([
      'field_country' => $code,
    ]);
    $entity->save();

    $build = $this->display->build($entity);
    $output = \Drupal::service('renderer')->renderRoot($build);

    $this->assertStringContainsString($expected_name, (string) $output);
  }

  /**
   * Provides country code and expected name pairs.
   */
  public static function countryDataProvider(): array {
    return [
      'United States' => ['US', 'United States'],
      'United Kingdom' => ['GB', 'United Kingdom'],
      'Netherlands' => ['NL', 'Netherlands'],
    ];
  }

  /**
   * Tests the ISO code formatter displays country code.
   *
   * @covers \Drupal\country\Plugin\Field\FieldFormatter\CountryCodeFormatter::viewElements
   */
  public function testIsoCodeFormatter(): void {
    // Change formatter to ISO code.
    $this->display->setComponent('field_country', [
      'type' => 'country_iso_code',
    ]);
    $this->display->save();

    $entity = EntityTest::create([
      'field_country' => 'NL',
    ]);
    $entity->save();

    $build = $this->display->build($entity);
    $output = \Drupal::service('renderer')->renderRoot($build);

    // Should display "NL", not "Netherlands".
    $this->assertStringContainsString('NL', (string) $output);
  }

  /**
   * Tests the default formatter with empty value.
   */
  public function testDefaultFormatterEmptyValue(): void {
    $entity = EntityTest::create([
      'field_country' => NULL,
    ]);
    $entity->save();

    $build = $this->display->build($entity);
    $output = \Drupal::service('renderer')->renderRoot($build);

    // Should not have any country name in output.
    $this->assertStringNotContainsString('United States', (string) $output);
    $this->assertStringNotContainsString('Germany', (string) $output);
  }

  /**
   * Tests the default formatter with invalid country code.
   */
  public function testDefaultFormatterInvalidCode(): void {
    $entity = EntityTest::create([
      'field_country' => 'XX',
    ]);
    $entity->save();

    $build = $this->display->build($entity);
    $output = \Drupal::service('renderer')->renderRoot($build);

    // Invalid code should not display anything.
    $this->assertStringNotContainsString('XX', (string) $output);
  }

  /**
   * Tests the ISO code formatter with multiple values.
   */
  public function testIsoCodeFormatterMultipleValues(): void {
    // Create a multi-value field.
    $this->createMultiValueCountryField();

    // Create a new display for the multi-value field.
    $display = \Drupal::service('entity_display.repository')
      ->getViewDisplay($this->entityType, $this->bundle)
      ->setComponent('field_countries', [
        'type' => 'country_iso_code',
      ]);
    $display->save();

    $entity = EntityTest::create([
      'field_countries' => [
        ['value' => 'US'],
        ['value' => 'CA'],
        ['value' => 'MX'],
      ],
    ]);
    $entity->save();

    $build = $display->build($entity);
    $output = \Drupal::service('renderer')->renderRoot($build);

    $this->assertStringContainsString('US', (string) $output);
    $this->assertStringContainsString('CA', (string) $output);
    $this->assertStringContainsString('MX', (string) $output);
  }

}
