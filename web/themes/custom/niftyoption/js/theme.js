/**
 * @file
 * NiftyOption theme behaviors (Drupal jQuery).
 */
(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.niftyoptionLoading = {
    attach(context) {
      once('niftyoption-loading', '#loading-wrapper', context).forEach((el) => {
        $(el).fadeOut(400);
      });
    },
  };

  /**
   * True when a dropdown contains the currently active submenu page.
   */
  function sidebarDropdownHasActiveSubmenu($dropdown) {
    return $dropdown.find('.sidebar-submenu .menu-item--active, .sidebar-submenu a.current-page').length > 0;
  }

  function collapseSidebarDropdowns($except) {
    $('.sidebar-dropdown').each(function () {
      const $dropdown = $(this);
      if ($dropdown.is($except) || sidebarDropdownHasActiveSubmenu($dropdown)) {
        return;
      }
      $dropdown.removeClass('active');
      $dropdown.find('.sidebar-submenu').slideUp(200);
    });
  }

  function openSidebarDropdown($dropdown, animate) {
    $dropdown.addClass('active');
    const $submenu = $dropdown.find('.sidebar-submenu');
    $submenu.stop(true, true);
    if (animate) {
      $submenu.slideDown(200);
    }
    else {
      $submenu.show();
    }
  }

  Drupal.behaviors.niftyoptionSidebar = {
    attach(context) {
      once('niftyoption-toggle-sidebar', '#toggle-sidebar', context).forEach((el) => {
        $(el).on('click', () => {
          $('.page-wrapper').toggleClass('toggled');
        });
      });

      once('niftyoption-sidebar-keep-open', '.sidebar-dropdown', context).forEach((el) => {
        const $dropdown = $(el);
        if (sidebarDropdownHasActiveSubmenu($dropdown)) {
          openSidebarDropdown($dropdown);
        }
      });

      once('niftyoption-sidebar-dropdown', '.sidebar-dropdown > a', context).forEach((el) => {
        $(el).on('click', (event) => {
          const $link = $(event.currentTarget);
          const $parent = $link.parent();

          if (sidebarDropdownHasActiveSubmenu($parent)) {
            event.preventDefault();
            openSidebarDropdown($parent);
            return;
          }

          if ($parent.hasClass('active')) {
            collapseSidebarDropdowns();
            $parent.removeClass('active');
            $parent.find('.sidebar-submenu').slideUp(200);
          }
          else {
            collapseSidebarDropdowns($parent);
            openSidebarDropdown($parent, true);
          }
          event.preventDefault();
        });
      });
    },
  };

  Drupal.behaviors.niftyoptionOverlayScroll = {
    attach(context) {
      if (typeof $.fn.overlayScrollbars !== 'function') {
        return;
      }
      const scrollOptions = {
        scrollbars: {
          visibility: 'auto',
          autoHide: 'scroll',
          autoHideDelay: 200,
          dragScrolling: true,
          clickScrolling: false,
          touchSupport: true,
          snapHandle: false,
        },
      };
      once('niftyoption-sidebar-scroll', '.sidebarMenuScroll', context).forEach((el) => {
        $(el).overlayScrollbars(scrollOptions);
      });
    },
  };
})(jQuery, Drupal, once);
