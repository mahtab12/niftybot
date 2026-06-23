<?php

namespace Drupal\radix\Hook;

use Drupal\node\NodeInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for radix.
 */
class HtmlHooks {

  /**
   * Implements hook_theme_suggestions_HOOK_alter().
   *
   * Add additional template suggestion based on node type.
   */
  #[Hook('theme_suggestions_html_alter')]
  public static function themeSuggestionsHtmlAlter(array &$suggestions, array $variables) {
    /** @var \Drupal\node\Entity\Node $node */
    $node = \Drupal::routeMatch()->getParameter('node');
    if ($node instanceof NodeInterface) {
      $suggestions[] = 'html__node__' . $node->getType();
    }
  }

}
