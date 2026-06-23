<?php

namespace Drupal\user_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Access\AccessResult;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class NodeEditController extends ControllerBase {

  /**
   * Displays the node edit form for a given node.
   */
  public function edit(NodeInterface $node) {
    // Check access: only allow editing of specific content types if needed
    if (!$node->access('update')) {
      throw new AccessDeniedHttpException();
    }

    // Load the form using the entity form builder
    $form = $this->entityFormBuilder()->getForm($node, 'default');

    return [
      '#theme' => 'node_seller_products_edit_form_frontend',
      '#form' => $form,
      '#attached' => [
        'library' => [
          'core/drupal.dialog.ajax', // or any custom libraries
        ],
      ],
    ];
  }

}
