/**
 * @file
 * Dashboard header trade-setup notifications.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const POLL_MS = 60000;
  const csrfToken = (drupalSettings.niftybotNotifications && drupalSettings.niftybotNotifications.csrfToken) || '';

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
  }

  function formatTime(timestamp) {
    if (!timestamp) {
      return '';
    }
    const date = new Date(Number(timestamp) * 1000);
    return date.toLocaleString(undefined, {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  }

  function tradeRoute(instrument) {
    if (instrument === 'sensex') {
      return '/niftybot/auto-trade/sensex';
    }
    return '/niftybot/auto-trade/nifty';
  }

  function renderAlerts(root, alerts) {
    const list = root.querySelector('[data-niftybot-notifications-list]');
    if (!list) {
      return;
    }
    if (!alerts.length) {
      list.innerHTML = '<li class="niftybot-notifications__empty">' + Drupal.t('No trade alerts yet.') + '</li>';
      return;
    }
    list.innerHTML = alerts.map(function (alert) {
      const unreadClass = alert.is_read ? '' : ' niftybot-notifications__item--unread';
      return (
        '<li class="niftybot-notifications__item' + unreadClass + '" data-alert-id="' + alert.alert_id + '">'
        + '<a class="niftybot-notifications__link" href="' + tradeRoute(alert.instrument) + '">'
        + '<strong class="niftybot-notifications__title">' + escapeHtml(alert.title) + '</strong>'
        + '<span class="niftybot-notifications__message">' + escapeHtml(alert.message) + '</span>'
        + '<span class="niftybot-notifications__time">' + escapeHtml(formatTime(alert.created)) + '</span>'
        + '</a></li>'
      );
    }).join('');
  }

  function setBadge(root, count) {
    const badge = root.querySelector('[data-niftybot-notifications-badge]');
    if (!badge) {
      return;
    }
    if (count > 0) {
      badge.hidden = false;
      badge.textContent = count > 9 ? '9+' : String(count);
    }
    else {
      badge.hidden = true;
      badge.textContent = '0';
    }
  }

  function markReadUrl(alertId) {
    const template = (drupalSettings.niftybotNotifications && drupalSettings.niftybotNotifications.readUrlTemplate) || '';
    if (!template || !alertId) {
      return '';
    }
    return template.replace(/\/0\/read$/, '/' + encodeURIComponent(alertId) + '/read');
  }

  function fetchJson(url, options) {
    const headers = {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };
    if (csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }
    return fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: headers,
    }, options || {})).then(function (response) {
      if (!response.ok) {
        throw new Error('Request failed');
      }
      return response.json();
    });
  }

  function refresh(root) {
    const listUrl = root.getAttribute('data-list-url');
    if (!listUrl) {
      return;
    }
    fetchJson(listUrl)
      .then(function (data) {
        if (!data || !data.success) {
          return;
        }
        setBadge(root, data.unread_count || 0);
        renderAlerts(root, data.alerts || []);
      })
      .catch(function () {
        // Ignore transient failures.
      });
  }

  Drupal.behaviors.niftybotNotifications = {
    attach: function (context) {
      once('niftybot-notifications', '[data-niftybot-notifications]', context).forEach(function (root) {
        refresh(root);
        window.setInterval(function () {
          refresh(root);
        }, POLL_MS);

        const markAll = root.querySelector('[data-niftybot-notifications-mark-all]');
        if (markAll) {
          markAll.addEventListener('click', function (event) {
            event.preventDefault();
            const url = root.getAttribute('data-read-all-url');
            if (!url) {
              return;
            }
            fetchJson(url, { method: 'POST' }).then(function () {
              refresh(root);
            });
          });
        }

        const list = root.querySelector('[data-niftybot-notifications-list]');
        if (list) {
          list.addEventListener('click', function (event) {
            const item = event.target.closest('[data-alert-id]');
            if (!item) {
              return;
            }
            const alertId = item.getAttribute('data-alert-id');
            const readUrl = markReadUrl(alertId);
            if (!readUrl) {
              return;
            }
            fetchJson(readUrl, { method: 'POST' }).then(function () {
              item.classList.remove('niftybot-notifications__item--unread');
              const badge = root.querySelector('[data-niftybot-notifications-badge]');
              fetchJson(root.getAttribute('data-unread-url')).then(function (data) {
                if (data && data.success) {
                  setBadge(root, data.unread_count || 0);
                }
              });
            });
          });
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
