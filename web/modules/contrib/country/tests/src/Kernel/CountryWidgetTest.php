<?php

declare(strict_types=1);

// AI generated.
namespace Drupal\Tests\country\Kernel;

use Drupal\country\Plugin\Field\FieldWidget\CountryAutocompleteWidget;
use Drupal\Core\Form\FormState;
use Drupal\entity_test\Entity\EntityTest;

/**
 * Tests the Country field widgets.
 *
 * @group country
 */
class CountryWidgetTest extends CountryKernelTestBase {

  /**
   * The entity form display.
   *
   * @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface
   */
  protected $formDisplay;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['field', 'system']);

    // Create a form display for the entity type.
    $this->formDisplay = \Drupal::service('entity_display.repository')
      ->getFormDisplay($this->entityType, $this->bundle)
      ->setComponent('field_country', [
        'type' => 'country_default',
      ]);
    $this->formDisplay->save();
  }

  /**
   * Tests the default widget (select dropdown).
   *
   * @covers \Drupal\country\Plugin\Field\FieldWidget\CountryDefaultWidget
   */
  public function testDefaultWidget(): void {
    $widget = $this->formDisplay->getRenderer('field_country');

    // Verify the widget type.
    $this->assertEquals('country_default', $widget->getPluginId());

    // Create an entity and verify the field is using the correct widget.
    $entity = EntityTest::create([
      'field_country' => 'US',
    ]);
    $entity->save();

    // Reload and verify.
    $loaded_entity = EntityTest::load($entity->id());
    $this->assertEquals('US', $loaded_entity->get('field_country')->value);
  }

  /**
   * Tests the autocomplete widget.
   *
   * @covers \Drupal\country\Plugin\Field\FieldWidget\CountryAutocompleteWidget
   */
  public function testAutocompleteWidget(): void {
    // Change widget to autocomplete.
    $this->formDisplay->setComponent('field_country', [
      'type' => 'country_autocomplete',
    ]);
    $this->formDisplay->save();

    $entity = EntityTest::create([
      'field_country' => 'DE',
    ]);
    $entity->save();

    // Verify the widget type.
    $widget = $this->formDisplay->getRenderer('field_country');
    $this->assertEquals('country_autocomplete', $widget->getPluginId());

    // Verify the country value is saved correctly.
    $loaded_entity = EntityTest::load($entity->id());
    $this->assertEquals('DE', $loaded_entity->get('field_country')->value);
  }

  /**
   * Tests autocomplete widget default settings.
   *
   * @covers \Drupal\country\Plugin\Field\FieldWidget\CountryAutocompleteWidget::defaultSettings
   */
  public function testAutocompleteWidgetDefaultSettings(): void {
    $this->formDisplay->setComponent('field_country', [
      'type' => 'country_autocomplete',
    ]);
    $this->formDisplay->save();

    $widget = $this->formDisplay->getRenderer('field_country');
    $settings = $widget->getSettings();

    $this->assertEquals('60', $settings['size']);
    $this->assertEquals('country.autocomplete', $settings['autocomplete_route_name']);
    $this->assertNotEmpty($settings['placeholder']);
  }

  /**
   * Tests autocomplete widget custom settings.
   *
   * @covers \Drupal\country\Plugin\Field\FieldWidget\CountryAutocompleteWidget::settingsForm
   */
  public function testAutocompleteWidgetCustomSettings(): void {
    $this->formDisplay->setComponent('field_country', [
      'type' => 'country_autocomplete',
      'settings' => [
        'size' => '80',
        'placeholder' => 'Enter country name',
      ],
    ]);
    $this->formDisplay->save();

    $widget = $this->formDisplay->getRenderer('field_country');
    $settings = $widget->getSettings();

    // Verify custom settings.
    $this->assertEquals('80', $settings['size']);
    $this->assertEquals('Enter country name', $settings['placeholder']);
  }

  /**
   * Tests autocomplete widget validation with valid country.
   *
   * @covers \Drupal\country\Plugin\Field\FieldWidget\CountryAutocompleteWidget::validateElement
   */
  public function testAutocompleteWidgetValidationValid(): void {
    $this->formDisplay->setComponent('field_country', [
      'type' => 'country_autocomplete',
    ]);
    $this->formDisplay->save();

    $form_state = new FormState();

    // Simulate form element.
    $element = [
      '#value' => 'Netherlands',
      '#selectable_countries' => \Drupal::service('country.field.manager')->getList(),
      '#parents' => ['field_country', 0, 'value'],
    ];

    // Call validation method.
    CountryAutocompleteWidget::validateElement($element, $form_state);

    // Should convert country name to ISO code.
    $value = $form_state->getValue(['field_country', 0, 'value']);
    $this->assertEquals('NL', $value);
  }

  /**
   * Tests autocomplete widget validation with invalid country.
   *
   * @covers \Drupal\country\Plugin\Field\FieldWidget\CountryAutocompleteWidget::validateElement
   */
  public function testAutocompleteWidgetValidationInvalid(): void {
    $this->formDisplay->setComponent('field_country', [
      'type' => 'country_autocomplete',
    ]);
    $this->formDisplay->save();

    $form_state = new FormState();

    // Simulate form element with invalid country.
    $element = [
      '#value' => 'Invalid Country Name',
      '#selectable_countries' => \Drupal::service('country.field.manager')->getList(),
      '#parents' => ['field_country', 0, 'value'],
    ];

    // Call validation method.
    CountryAutocompleteWidget::validateElement($element, $form_state);

    // Should set an error.
    $errors = $form_state->getErrors();
    $this->assertNotEmpty($errors);
  }

}
