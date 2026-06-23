<?php

declare(strict_types=1);

// AI generated.
namespace Drupal\Tests\country\Kernel;

use Drupal\entity_test\Entity\EntityTest;

/**
 * Tests the Country field type.
 *
 * @group country
 * @coversDefaultClass \Drupal\country\Plugin\Field\FieldType\CountryItem
 */
class CountryItemTest extends CountryKernelTestBase {

  /**
   * Tests isEmpty() method.
   *
   * @covers ::isEmpty
   */
  public function testIsEmpty(): void {
    // Test with NULL value.
    $entity = EntityTest::create([
      'field_country' => NULL,
    ]);
    $this->assertTrue($entity->get('field_country')->isEmpty());

    // Test with empty string.
    $entity = EntityTest::create([
      'field_country' => '',
    ]);
    $this->assertTrue($entity->get('field_country')->isEmpty());

    // Test with valid country code.
    $entity = EntityTest::create([
      'field_country' => 'US',
    ]);
    $this->assertFalse($entity->get('field_country')->isEmpty());
  }

  /**
   * Tests the length constraint.
   *
   * @covers ::getConstraints
   */
  public function testLengthConstraint(): void {
    $entity = EntityTest::create([
      'field_country' => 'USA',
    ]);

    $violations = $entity->get('field_country')->validate();
    $this->assertGreaterThan(0, $violations->count());

    // Valid 2-char code should pass.
    $entity = EntityTest::create([
      'field_country' => 'US',
    ]);
    $violations = $entity->get('field_country')->validate();
    $this->assertEquals(0, $violations->count());
  }

  /**
   * Tests getPossibleOptions().
   *
   * @covers ::getPossibleOptions
   */
  public function testGetPossibleOptions(): void {
    $entity = EntityTest::create([
      'field_country' => 'US',
    ]);

    /** @var \Drupal\country\Plugin\Field\FieldType\CountryItem $field_item */
    $field_item = $entity->get('field_country')->first();
    $options = $field_item->getPossibleOptions();

    $this->assertIsArray($options);
    $this->assertNotEmpty($options);
    $this->assertArrayHasKey('US', $options);
    $this->assertArrayHasKey('GB', $options);
  }

  /**
   * Tests getPossibleValues().
   *
   * @covers ::getPossibleValues
   */
  public function testGetPossibleValues(): void {
    $entity = EntityTest::create([
      'field_country' => 'US',
    ]);

    /** @var \Drupal\country\Plugin\Field\FieldType\CountryItem $field_item */
    $field_item = $entity->get('field_country')->first();
    $values = $field_item->getPossibleValues();

    $this->assertIsArray($values);
    $this->assertNotEmpty($values);
    $this->assertContains('US', $values);
    $this->assertContains('GB', $values);
  }

}
