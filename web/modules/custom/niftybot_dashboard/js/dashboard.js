(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.niftybotDashboard = {
    attach(context) {
      once('niftybot-dashboard-copy', '[data-copy-target]', context).forEach(function (button) {
        button.addEventListener('click', function () {
          const targetId = button.getAttribute('data-copy-target');
          const input = targetId ? document.getElementById(targetId) : null;
          if (!input) {
            return;
          }
          input.select();
          input.setSelectionRange(0, input.value.length);
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value);
          }
        });
      });
    },
  };
})(Drupal, once);
