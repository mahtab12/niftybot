/**
 * @file
 * Live market watch and option chain drill-down.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const settings = drupalSettings.niftybotMarket || {};
  const RECONNECT_MS = 5000;
  const OPTION_STRIKE_COUNT = 20;

  function formatPrice(value) {
    if (value === null || value === undefined || value === '') {
      return '—';
    }
    return Number(value).toLocaleString('en-IN', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  function formatNum(value) {
    if (value === null || value === undefined) {
      return '—';
    }
    return Number(value).toLocaleString('en-IN');
  }

  function formatOiChangePct(value) {
    if (value === null || value === undefined || value === '') {
      return '—';
    }
    const num = Number(value);
    const sign = num > 0 ? '+' : '';
    return sign + num.toFixed(2) + '%';
  }

  function formatOiCell(oi, changePct) {
    const oiText = formatNum(oi);
    const hasOi = oi !== null && oi !== undefined;
    const hasChg = changePct !== null && changePct !== undefined && changePct !== '';

    if (!hasOi && !hasChg) {
      return '—';
    }
    if (hasOi && hasChg) {
      return oiText + ' (' + formatOiChangePct(changePct) + ')';
    }
    if (hasOi) {
      return oiText;
    }
    return '(' + formatOiChangePct(changePct) + ')';
  }

  function formatExpiryLabel(iso) {
    const parts = iso.split('-');
    if (parts.length !== 3) {
      return iso;
    }
    const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    return d.toLocaleDateString('en-IN', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    });
  }

  function changeClass(value) {
    const num = Number(value) || 0;
    if (num > 0) {
      return 'niftybot-pnl--positive';
    }
    if (num < 0) {
      return 'niftybot-pnl--negative';
    }
    return '';
  }

  function setLiveStatus(root, state, message) {
    const badge = root.querySelector('.niftybot-live-status');
    if (!badge) {
      return;
    }
    badge.classList.remove(
      'niftybot-live-status--connected',
      'niftybot-live-status--reconnecting',
      'niftybot-live-status--error'
    );
    badge.classList.add('niftybot-live-status--' + state);
    badge.textContent = message;
  }

  function updateCards(root, items) {
    (items || []).forEach(function (item) {
      const priceEl = root.querySelector('[data-niftybot-field="price-' + item.id + '"]');
      const changeEl = root.querySelector('[data-niftybot-field="change-' + item.id + '"]');
      if (priceEl) {
        priceEl.textContent = formatPrice(item.last_price);
      }
      if (changeEl) {
        changeEl.classList.remove('niftybot-pnl--positive', 'niftybot-pnl--negative');
        if (item.day_change != null) {
          changeEl.textContent =
            formatPrice(item.day_change) + ' (' + formatPrice(item.day_change_perc) + '%)';
          const cls = changeClass(item.day_change);
          if (cls) {
            changeEl.classList.add(cls);
          }
        }
        else if (item.message) {
          changeEl.textContent = item.message;
        }
      }
    });
    const updated = root.querySelector('.niftybot-live-updated__time');
    if (updated) {
      updated.textContent = new Date().toLocaleTimeString();
    }
  }

  function apiFetch(path, query) {
    const params = new URLSearchParams(query || {});
    params.set('broker', settings.broker || 'groww');
    const url = settings.restUrl + path + '?' + params.toString();
    return fetch(url, {
      headers: {
        'X-API-Key': settings.apiKey,
      },
    }).then(function (response) {
      return response.json();
    });
  }

  function selectStrikesNearAtm(allKeys, underlyingLtp) {
    if (!allKeys.length) {
      return { keys: [], atmStrike: null };
    }
    if (allKeys.length <= OPTION_STRIKE_COUNT) {
      const atmStrike = findAtmStrike(allKeys, underlyingLtp);
      return { keys: allKeys, atmStrike: atmStrike };
    }

    const atmIndex = findAtmIndex(allKeys, underlyingLtp);
    const below = Math.floor((OPTION_STRIKE_COUNT - 1) / 2);
    let start = atmIndex - below;
    let end = start + OPTION_STRIKE_COUNT;

    if (start < 0) {
      start = 0;
      end = OPTION_STRIKE_COUNT;
    }
    if (end > allKeys.length) {
      end = allKeys.length;
      start = Math.max(0, end - OPTION_STRIKE_COUNT);
    }

    return {
      keys: allKeys.slice(start, end),
      atmStrike: allKeys[atmIndex],
    };
  }

  function findAtmIndex(keys, underlyingLtp) {
    if (underlyingLtp == null || !keys.length) {
      return Math.floor(keys.length / 2);
    }
    let atmIndex = 0;
    let minDiff = Infinity;
    keys.forEach(function (key, index) {
      const diff = Math.abs(Number(key) - Number(underlyingLtp));
      if (diff < minDiff) {
        minDiff = diff;
        atmIndex = index;
      }
    });
    return atmIndex;
  }

  function findAtmStrike(keys, underlyingLtp) {
    if (!keys.length) {
      return null;
    }
    return keys[findAtmIndex(keys, underlyingLtp)];
  }

  function renderOptionRow(strike, row, isAtm) {
    const ce = row.CE || {};
    const pe = row.PE || {};
    const ceGreeks = ce.greeks || {};
    const peGreeks = pe.greeks || {};
    const ceOiClass = changeClass(ce.oi_change_percentage);
    const peOiClass = changeClass(pe.oi_change_percentage);
    const rowClass = isAtm ? ' class="niftybot-option-chain__row--atm"' : '';
    return (
      '<tr' + rowClass + '>' +
      '<td class="niftybot-option-chain__cell-ce">' + formatPrice(ce.ltp) + '</td>' +
      '<td class="niftybot-option-chain__cell-ce ' + ceOiClass + '">' + formatOiCell(ce.open_interest, ce.oi_change_percentage) + '</td>' +
      '<td class="niftybot-option-chain__cell-ce">' + (ceGreeks.iv != null ? formatPrice(ceGreeks.iv) : '—') + '</td>' +
      '<td class="niftybot-option-chain__cell-strike"><strong>' + Drupal.checkPlain(strike) + '</strong></td>' +
      '<td class="niftybot-option-chain__cell-pe">' + formatPrice(pe.ltp) + '</td>' +
      '<td class="niftybot-option-chain__cell-pe ' + peOiClass + '">' + formatOiCell(pe.open_interest, pe.oi_change_percentage) + '</td>' +
      '<td class="niftybot-option-chain__cell-pe">' + (peGreeks.iv != null ? formatPrice(peGreeks.iv) : '—') + '</td>' +
      '</tr>'
    );
  }

  function loadOptionChain(root, symbol, exchange, expiry) {
    const status = root.querySelector('#niftybot-option-chain-status');
    const tbody = root.querySelector('#niftybot-option-chain-tbody');
    const ltpEl = root.querySelector('#niftybot-option-underlying-ltp');

    if (status) {
      status.textContent = Drupal.t('Loading option chain…');
    }
    if (tbody) {
      tbody.innerHTML = '';
    }

    return apiFetch('/api/v1/market/option-chain', {
      underlying: symbol,
      exchange: exchange,
      expiry_date: expiry,
      strike_window: OPTION_STRIKE_COUNT,
    }).then(function (data) {
      if (!data || !data.success) {
        if (status) {
          status.textContent = data && data.message ? data.message : Drupal.t('Option chain unavailable.');
        }
        return;
      }
      if (ltpEl && data.underlying_ltp != null) {
        ltpEl.textContent = Drupal.t('Underlying LTP: @price', { '@price': formatPrice(data.underlying_ltp) });
      }
      const strikes = data.strikes || {};
      const allKeys = Object.keys(strikes).sort(function (a, b) {
        return Number(a) - Number(b);
      });
      const selected = selectStrikesNearAtm(allKeys, data.underlying_ltp);
      const keys = selected.keys;
      const atmStrike = selected.atmStrike;

      if (tbody) {
        tbody.innerHTML = keys.map(function (k) {
          return renderOptionRow(k, strikes[k], k === atmStrike);
        }).join('');
      }
      if (status) {
        const atmLabel = atmStrike != null ? formatPrice(atmStrike) : '—';
        status.textContent = Drupal.t('Showing @count strikes near ATM (@atm). CE = Call, PE = Put.', {
          '@count': keys.length,
          '@atm': atmLabel,
        });
      }
    }).catch(function () {
      if (status) {
        status.textContent = Drupal.t('Failed to load option chain.');
      }
    });
  }

  function initOptionChain(root) {
    const panel = root.querySelector('#niftybot-option-chain');
    const title = root.querySelector('#niftybot-option-chain-title');
    const expirySelect = root.querySelector('#niftybot-option-expiry');
    const closeBtn = root.querySelector('#niftybot-option-chain-close');
    let active = null;

    function openChain(card) {
      if (!panel || !expirySelect) {
        return;
      }
      active = {
        symbol: card.getAttribute('data-niftybot-symbol'),
        exchange: card.getAttribute('data-niftybot-option-exchange') || card.getAttribute('data-niftybot-exchange'),
        label: card.querySelector('.niftybot-market-card__label').textContent,
      };
      if (title) {
        title.textContent = active.label + ' — ' + Drupal.t('Option Chain');
      }
      panel.hidden = false;
      expirySelect.innerHTML = '';
      const status = root.querySelector('#niftybot-option-chain-status');
      if (status) {
        status.textContent = Drupal.t('Loading expiries…');
      }

      apiFetch('/api/v1/market/expiries', {
        underlying: active.symbol,
        exchange: active.exchange,
        expiry_type: 'weekly',
      }).then(function (data) {
        if (!data || !data.success || !data.expiries || !data.expiries.length) {
          if (status) {
            status.textContent = data && data.message ? data.message : Drupal.t('No expiries found.');
          }
          return;
        }
        data.expiries.forEach(function (exp) {
          const opt = document.createElement('option');
          opt.value = exp;
          opt.textContent = formatExpiryLabel(exp);
          expirySelect.appendChild(opt);
        });
        const nearest = data.nearest_expiry || data.expiries[0];
        expirySelect.value = nearest;
        return loadOptionChain(root, active.symbol, active.exchange, nearest);
      });
    }

    root.querySelectorAll('.niftybot-market-card--clickable').forEach(function (card) {
      card.addEventListener('click', function () {
        openChain(card);
      });
    });

    if (expirySelect) {
      expirySelect.addEventListener('change', function () {
        if (!active) {
          return;
        }
        loadOptionChain(root, active.symbol, active.exchange, expirySelect.value);
      });
    }

    if (closeBtn && panel) {
      closeBtn.addEventListener('click', function () {
        panel.hidden = true;
        active = null;
      });
    }
  }

  function connectWebSocket(root) {
    if (!settings.wsUrl || !settings.apiKey) {
      return null;
    }

    const url =
      settings.wsUrl +
      '/ws/market?broker=' +
      encodeURIComponent(settings.broker || 'groww') +
      '&api_key=' +
      encodeURIComponent(settings.apiKey);

    let socket = null;
    let reconnectTimer = null;
    let closedByUser = false;

    function connect() {
      setLiveStatus(root, 'reconnecting', Drupal.t('Connecting…'));
      socket = new WebSocket(url);

      socket.addEventListener('open', function () {
        setLiveStatus(root, 'connected', Drupal.t('Live'));
      });

      socket.addEventListener('message', function (event) {
        try {
          const payload = JSON.parse(event.data);
          if (payload && payload.success) {
            updateCards(root, payload.items);
          }
        }
        catch (e) {
          // Ignore malformed payloads.
        }
      });

      socket.addEventListener('close', function () {
        setLiveStatus(root, 'error', Drupal.t('Disconnected'));
        if (!closedByUser) {
          reconnectTimer = window.setTimeout(connect, RECONNECT_MS);
        }
      });

      socket.addEventListener('error', function () {
        setLiveStatus(root, 'error', Drupal.t('Connection error'));
      });
    }

    connect();

    return function cleanup() {
      closedByUser = true;
      if (reconnectTimer) {
        window.clearTimeout(reconnectTimer);
      }
      if (socket) {
        socket.close();
      }
    };
  }

  Drupal.behaviors.niftybotMarketRealtime = {
    attach: function (context) {
      once('niftybot-market-realtime', '.niftybot-market--live', context).forEach(function (root) {
        initOptionChain(root);
        if (!settings.enabled) {
          return;
        }
        const cleanup = connectWebSocket(root);
        if (cleanup) {
          root.addEventListener('drupalDetach', function () {
            cleanup();
          }, { once: true });
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
