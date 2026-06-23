<?php

declare(strict_types=1);

// AI generated.
namespace Drupal\Tests\country\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\country\Plugin\views\sort\CountryItem;

/**
 * Tests the Country views sort plugin.
 *
 * @group country
 * @coversDefaultClass \Drupal\country\Plugin\views\sort\CountryItem
 */
class CountryViewsSortPluginTest extends CountryKernelTestBase {

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
   * Tests buildOptionsForm contains sort type selection.
   *
   * @covers ::buildOptionsForm
   */
  public function testBuildOptionsForm(): void {
    $configuration = [];
    $plugin_id = 'country_item';
    $plugin_definition = [
      'entity_type' => 'entity_test',
      'field_name' => 'field_country',
    ];

    $sort = CountryItem::create(
      $this->container,
      $configuration,
      $plugin_id,
      $plugin_definition
    );

    $sort->options = [
      'default_sort' => 0,
      'admin_label' => '',
      'order' => 'ASC',
    ];
    $form = [];
    $form_state = new FormState();

    $sort->buildOptionsForm($form, $form_state);

    $this->assertArrayHasKey('default_sort', $form);
    $this->assertEquals('radios', $form['default_sort']['#type']);
    $this->assertArrayHasKey(0, $form['default_sort']['#options']);
    $this->assertArrayHasKey(1, $form['default_sort']['#options']);
  }

}
