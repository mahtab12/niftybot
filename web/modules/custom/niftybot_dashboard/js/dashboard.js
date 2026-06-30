(function (Drupal, drupalSettings, once) {
  'use strict';

  const settings = drupalSettings.niftybotAvatar || {};

  function updateAvatarImages(url) {
    const dashboardImg = document.getElementById('niftybot-dashboard-avatar-img');
    if (dashboardImg) {
      dashboardImg.src = url;
    }
    document.querySelectorAll('.header-profile .avatar img, .niftybot-dashboard__avatar img').forEach(function (img) {
      if (img.id !== 'niftybot-dashboard-avatar-img' || !dashboardImg) {
        img.src = url;
      }
    });
  }

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

      once('niftybot-avatar-picker', '#niftybot-avatar-grid', context).forEach(function (grid) {
        const saveBtn = document.getElementById('niftybot-avatar-save');
        const statusEl = document.getElementById('niftybot-avatar-status');
        let selectedId = settings.currentId || '';
        let selectedUrl = settings.currentUrl || '';

        function setStatus(message, type) {
          if (!statusEl) {
            return;
          }
          statusEl.textContent = message;
          statusEl.hidden = !message;
          statusEl.classList.remove('is-error', 'is-success');
          if (type) {
            statusEl.classList.add('is-' + type);
          }
        }

        grid.querySelectorAll('[data-avatar-id]').forEach(function (button) {
          button.addEventListener('click', function () {
            selectedId = button.getAttribute('data-avatar-id') || '';
            selectedUrl = button.getAttribute('data-avatar-url') || '';
            grid.querySelectorAll('.niftybot-dashboard__avatar-option').forEach(function (option) {
              const isSelected = option === button;
              option.classList.toggle('is-selected', isSelected);
              option.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            });
            if (saveBtn) {
              saveBtn.disabled = selectedId === (settings.currentId || '');
            }
            setStatus('', '');
          });
        });

        if (saveBtn) {
          saveBtn.addEventListener('click', function () {
            if (!selectedId || !settings.saveUrl) {
              return;
            }
            saveBtn.disabled = true;
            setStatus(Drupal.t('Saving…'), '');

            fetch(settings.saveUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': settings.csrfToken || '',
              },
              credentials: 'same-origin',
              body: JSON.stringify({ avatar_id: selectedId }),
            }).then(function (response) {
              return response.json();
            }).then(function (data) {
              if (!data || !data.success) {
                throw new Error((data && data.message) ? data.message : Drupal.t('Could not save avatar.'));
              }
              settings.currentId = data.avatar_id;
              settings.currentUrl = data.avatar_url;
              updateAvatarImages(data.avatar_url);
              setStatus(data.message || Drupal.t('Profile picture updated.'), 'success');
              window.setTimeout(function () {
                const modalEl = document.getElementById('niftybot-avatar-modal');
                if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                  const instance = window.bootstrap.Modal.getInstance(modalEl);
                  if (instance) {
                    instance.hide();
                  }
                }
                setStatus('', '');
              }, 700);
            }).catch(function (error) {
              setStatus(error.message || Drupal.t('Could not save avatar.'), 'error');
            }).finally(function () {
              saveBtn.disabled = selectedId === (settings.currentId || '');
            });
          });
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
