<?php

namespace Drupal\user_dashboard\Service;

use Drupal\Core\Menu\MenuLinkTree;
use Drupal\Core\Menu\MenuTreeParameters;

class MenuLoader {

  protected $menuTree;

  public function __construct(MenuLinkTree $menu_tree) {
    $this->menuTree = $menu_tree;
  }

  public function loadMenu($menu_name = 'dashboard-menu') {
    // Define parameters
    $parameters = new MenuTreeParameters();
    $parameters->setMaxDepth(NULL);

    // Load the menu tree
    $tree = $this->menuTree->load($menu_name, $parameters);

    // Apply manipulators
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $this->menuTree->transform($tree, $manipulators);

    // Return a render array
    return $this->menuTree->build($tree);
  }

}
