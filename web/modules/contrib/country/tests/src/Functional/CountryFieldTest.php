<?php

namespace Drupal\Tests\country\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests country field functionality on nodes.
 *
 * @group country
 */
class CountryFieldTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['country', 'node', 'field', 'field_ui'];

  /**
   * A user with permission to administer content types and nodes.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a content type.
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);

    // Create an admin user.
    $this->adminUser = $this->drupalCreateUser([
      'administer content types',
      'administer node fields',
      'administer node display',
      'create article content',
      'edit any article content',
      'access content',
    ]);
    $this->drupalLogin($this->adminUser);
  }

  /**
   * Tests adding a country field to a node and verifying it on the edit form.
   */
  public function testCountryFieldOnNode() {
    // Create a country field storage.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_test_country',
      'entity_type' => 'node',
      'type' => 'country',
    ]);
    $field_storage->save();

    // Create a country field instance on the article content type.
    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
      'label' => 'Test Country',
      'required' => FALSE,
    ]);
    $field->save();

    // Set the form display for the country field.
    \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', 'article')
      ->setComponent('field_test_country', [
        'type' => 'country_default',
      ])
      ->save();

    // Set the view display for the country field.
    \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'article')
      ->setComponent('field_test_country', [
        'type' => 'country_default',
      ])
      ->save();

    // Go to the node creation form.
    $this->drupalGet('node/add/article');
    $this->assertSession()->statusCodeEquals(200);

    // Verify that the country field is present on the form.
    $this->assertSession()->fieldExists('field_test_country');
    $this->assertSession()->pageTextContains('Test Country');

    // Create a node with a country value.
    $edit = [
      'title[0][value]' => 'Test Article with Country',
      'field_test_country' => 'US',
    ];
    $this->submitForm($edit, 'Save');

    // Verify the node was created successfully.
    $this->assertSession()->pageTextContains('Article Test Article with Country has been created.');

    // Load the created node.
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => 'Test Article with Country']);
    $node = reset($nodes);
    $this->assertNotEmpty($node);

    // Verify the country field value was saved correctly.
    $this->assertEquals('US', $node->get('field_test_country')->value);

    // Go to the edit form for the created node.
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);

    // Verify that the country field is present and has the correct value.
    $this->assertSession()->fieldExists('field_test_country');
    $this->assertSession()->fieldValueEquals('field_test_country', 'US');

    // Change the country value and save.
    $edit = [
      'field_test_country' => 'CA',
    ];
    $this->submitForm($edit, 'Save');

    // Verify the update was successful.
    $this->assertSession()->pageTextContains('Article Test Article with Country has been updated.');

    // Reload the node and verify the country field was updated.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
    $updated_node = \Drupal::entityTypeManager()->getStorage('node')->load($node->id());
    $this->assertEquals('CA', $updated_node->get('field_test_country')->value);

    // Verify the country is displayed on the node view page.
    $this->drupalGet('node/' . $node->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Canada');
  }

  /**
   * Tests the country field with autocomplete widget.
   */
  public function testCountryDefaultWidget() {
    // Create a country field storage.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_autocomplete_country',
      'entity_type' => 'node',
      'type' => 'country',
    ]);
    $field_storage->save();

    // Create a country field instance with autocomplete widget.
    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
      'label' => 'Autocomplete Country',
      'required' => FALSE,
    ]);
    $field->save();

    // Set the form display to use autocomplete widget.
    \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', 'article')
      ->setComponent('field_autocomplete_country', [
        'type' => 'country_autocomplete',
        'settings' => [
          'size' => '60',
          'autocomplete_route_name' => 'country.autocomplete',
          'placeholder' => 'Start typing a country name ...',
        ],
      ])
      ->save();

    // Set the view display for the autocomplete country field.
    \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'article')
      ->setComponent('field_autocomplete_country', [
        'type' => 'country_default',
      ])
      ->save();

    // Go to the node creation form.
    $this->drupalGet('node/add/article');
    $this->assertSession()->statusCodeEquals(200);

    // Verify that the autocomplete field is present.
    $this->assertSession()->fieldExists('field_autocomplete_country[0][value]');
    $this->assertSession()->pageTextContains('Autocomplete Country');

    // Test that the field accepts a country name.
    $edit = [
      'title[0][value]' => 'Test Autocomplete Country',
      'field_autocomplete_country[0][value]' => 'United Kingdom',
    ];
    $this->submitForm($edit, 'Save');

    // Verify the node was created successfully.
    $this->assertSession()->pageTextContains('Article Test Autocomplete Country has been created.');

    // Load the created node and verify the country code was saved.
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => 'Test Autocomplete Country']);
    $node = reset($nodes);
    $this->assertNotEmpty($node);

    // Verify the country field value was saved as ISO code.
    $this->assertEquals('GB', $node->get('field_autocomplete_country')->value);

    // Verify the country is displayed on the node view page.
    $this->drupalGet('node/' . $node->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('United Kingdom');
  }

}
