<?php

declare(strict_types=1);

// AI generated.
namespace Drupal\Tests\country\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the country autocomplete controller.
 *
 * @group country
 * @coversDefaultClass \Drupal\country\Controller\CountryAutocompleteController
 */
class CountryAutocompleteTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['country', 'node', 'field'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a content type.
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);

    // Create a country field.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_country',
      'entity_type' => 'node',
      'type' => 'country',
    ]);
    $field_storage->save();

    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
      'label' => 'Country',
    ]);
    $field->save();
  }

  /**
   * Tests global autocomplete returns matching countries.
   *
   * @covers ::autocomplete
   */
  public function testGlobalAutocomplete(): void {
    // Search for "United".
    $this->drupalGet('country/autocomplete/node/global/field_country', [
      'query' => ['q' => 'United'],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $response = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    $this->assertIsArray($response);
    $this->assertNotEmpty($response);

    // Should contain United States and United Kingdom.
    $values = array_column($response, 'value');
    $this->assertContains('United States', $values);
    $this->assertContains('United Kingdom', $values);
  }

  /**
   * Tests autocomplete with bundle-specific field.
   *
   * @covers ::autocomplete
   */
  public function testBundleAutocomplete(): void {
    // Search for "Canada".
    $this->drupalGet('country/autocomplete/node/article/field_country', [
      'query' => ['q' => 'Canada'],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $response = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    $this->assertIsArray($response);
    $this->assertCount(1, $response);
    $this->assertEquals('Canada', $response[0]['value']);
  }

  /**
   * Tests autocomplete with restricted countries.
   *
   * @covers ::autocomplete
   */
  public function testRestrictedAutocomplete(): void {
    // Create a restricted country field.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_country_restricted',
      'entity_type' => 'node',
      'type' => 'country',
      'settings' => [
        'selectable_countries' => ['US' => 'US', 'CA' => 'CA'],
      ],
    ]);
    $field_storage->save();

    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
      'label' => 'Restricted Country',
    ])->save();

    // Search for "United" - should only return US, not UK.
    $this->drupalGet('country/autocomplete/node/article/field_country_restricted', [
      'query' => ['q' => 'United'],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $response = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    $this->assertIsArray($response);
    $this->assertCount(1, $response);
    $this->assertEquals('United States', $response[0]['value']);
  }

  /**
   * Tests autocomplete with no matching results.
   *
   * @covers ::autocomplete
   */
  public function testAutocompleteNoResults(): void {
    $this->drupalGet('country/autocomplete/node/global/field_country', [
      'query' => ['q' => 'XYZNonExistentCountry'],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $response = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    $this->assertIsArray($response);
    $this->assertEmpty($response);
  }

  /**
   * Tests autocomplete with empty query.
   *
   * @covers ::autocomplete
   */
  public function testAutocompleteEmptyQuery(): void {
    $this->drupalGet('country/autocomplete/node/global/field_country', [
      'query' => ['q' => ''],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $response = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    $this->assertIsArray($response);
    $this->assertEmpty($response);
  }

  /**
   * Tests case-insensitive autocomplete.
   *
   * @covers ::autocomplete
   */
  public function testCaseInsensitiveAutocomplete(): void {
    // Search with lowercase.
    $this->drupalGet('country/autocomplete/node/global/field_country', [
      'query' => ['q' => 'germany'],
    ]);

    $response = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertNotEmpty($response);
    $this->assertEquals('Germany', $response[0]['value']);

    // Search with uppercase.
    $this->drupalGet('country/autocomplete/node/global/field_country', [
      'query' => ['q' => 'GERMANY'],
    ]);

    $response = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertNotEmpty($response);
    $this->assertEquals('Germany', $response[0]['value']);
  }

  /**
   * Tests autocomplete with partial match.
   *
   * @covers ::autocomplete
   */
  public function testPartialMatchAutocomplete(): void {
    // Search for "land" - should match multiple countries.
    $this->drupalGet('country/autocomplete/node/global/field_country', [
      'query' => ['q' => 'land'],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $response = json_decode($this->getSession()->getPage()->getContent(), TRUE);

    $this->assertIsArray($response);
    $this->assertNotEmpty($response);

    $values = array_column($response, 'value');
    // Countries containing "land": Finland, Iceland, Ireland, Netherlands,
    // New Zealand, Poland, Switzerland, Thailand, etc.
    $this->assertTrue(count($values) > 1);
  }

}
