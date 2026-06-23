(function ($, Drupal) {
  "use strict";

  Drupal.behaviors.customEasing = {
    attach: function (context, settings) {
      // Ensure this runs only once per context
      $(context).once('custom-easing').each(function () {
        $.easing.jswing = $.easing.swing;
        $.extend($.easing, {
          def: "easeOutQuad",
          swing: function(e, f, a, h, g) {
              return $.easing[$.easing.def](e, f, a, h, g);
          },
          easeInQuad: function(e, f, a, h, g) {
              return h * (f /= g) * f + a;
          },
          easeOutQuad: function(e, f, a, h, g) {
              return -h * (f /= g) * (f - 2) + a;
          },
          easeInOutQuad: function(e, f, a, h, g) {
              if ((f /= g / 2) < 1) {
                  return h / 2 * f * f + a;
              }
              return -h / 2 * ((--f) * (f - 2) - 1) + a;
          },
          easeInCubic: function(e, f, a, h, g) {
              return h * (f /= g) * f * f + a;
          },
          easeOutCubic: function(e, f, a, h, g) {
              return h * ((f = f / g - 1) * f * f + 1) + a;
          },
          easeInOutCubic: function(e, f, a, h, g) {
              if ((f /= g / 2) < 1) {
                  return h / 2 * f * f * f + a;
              }
              return h / 2 * ((f -= 2) * f * f + 2) + a;
          },
          easeInQuart: function(e, f, a, h, g) {
              return h * (f /= g) * f * f * f + a;
          }
        });
      });
    }
  };

})(jQuery, Drupal);
