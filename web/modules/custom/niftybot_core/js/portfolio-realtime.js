/**
 * @file
 * Live portfolio updates via WebSocket from the trading service.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const settings = drupalSettings.niftybotPortfolio || {};
  const POLL_FALLBACK_MS = 5000;

  function formatMoney(value) {
    const num = Number(value) || 0;
    return '₹' + num.toLocaleString('en-IN', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  function formatPct(value) {
    const num = Number(value) || 0;
    return num.toFixed(2) + '%';
  }

  function pnlClass(value) {
    const num = Number(value) || 0;
    if (num > 0) {
      return 'niftybot-pnl--positive';
    }
    if (num < 0) {
      return 'niftybot-pnl--negative';
    }
    return '';
  }

  function setField(root, field, html, className) {
    const el = root.querySelector('[data-niftybot-field="' + field + '"]');
    if (!el) {
      return;
    }
    el.innerHTML = html;
    el.classList.remove('niftybot-pnl--positive', 'niftybot-pnl--negative');
    if (className) {
      el.classList.add(className);
    }
  }

  function updateSummary(root, summary) {
    if (!summary) {
      return;
    }

    const fnoTotalClass = pnlClass(summary.fno_total_pnl);
    setField(
      root,
      'fno_total_pnl',
      formatMoney(summary.fno_total_pnl) +
        ' <span class="niftybot-fno-hero__pct">(' + formatPct(summary.fno_total_pnl_percentage) + ')</span>',
      fnoTotalClass
    );
    setField(root, 'fno_realised_pnl', formatMoney(summary.fno_realised_pnl), pnlClass(summary.fno_realised_pnl));
    setField(root, 'fno_unrealised_pnl', formatMoney(summary.fno_unrealised_pnl), pnlClass(summary.fno_unrealised_pnl));
    setField(
      root,
      'fno_day_return',
      formatMoney(summary.fno_day_return) +
        ' <span class="niftybot-fno-hero__pct">(' + formatPct(summary.fno_day_return_percentage) + ')</span>',
      pnlClass(summary.fno_day_return)
    );

    setField(root, 'total_balance_available', formatMoney(summary.total_balance_available));
    setField(root, 'total_portfolio_value', formatMoney(summary.total_portfolio_value));
    setField(
      root,
      'overall_pnl',
      formatMoney(summary.overall_pnl) + ' (' + formatPct(summary.overall_pnl_percentage) + ')',
      pnlClass(summary.overall_pnl)
    );
    setField(
      root,
      'day_return',
      formatMoney(summary.day_return) + ' (' + formatPct(summary.day_return_percentage) + ')',
      pnlClass(summary.day_return)
    );

    const marginFields = [
      'clear_cash',
      'collateral_available',
      'net_margin_used',
      'collateral_used',
      'brokerage_and_charges',
      'adhoc_margin',
      'equity_cnc_available',
      'equity_mis_available',
      'fno_future_available',
      'fno_option_buy_available',
      'fno_option_sell_available',
      'commodity_unrealised_m2m',
      'commodity_realised_m2m',
      'holdings_invested',
      'holdings_current_value',
    ];
    marginFields.forEach((field) => {
      setField(root, field, formatMoney(summary[field]));
    });

    setField(
      root,
      'holdings_pnl',
      formatMoney(summary.holdings_pnl) + ' (' + formatPct(summary.holdings_pnl_percentage) + ')',
      pnlClass(summary.holdings_pnl)
    );
    setField(
      root,
      'holdings_day_return',
      formatMoney(summary.holdings_day_return) + ' (' + formatPct(summary.holdings_day_return_percentage) + ')',
      pnlClass(summary.holdings_day_return)
    );
    setField(
      root,
      'positions_realised_pnl',
      formatMoney(summary.positions_realised_pnl),
      pnlClass(summary.positions_realised_pnl)
    );
    setField(
      root,
      'positions_unrealised_pnl',
      formatMoney(summary.positions_unrealised_pnl),
      pnlClass(summary.positions_unrealised_pnl)
    );
    setField(
      root,
      'positions_day_return',
      formatMoney(summary.positions_day_return) + ' (' + formatPct(summary.positions_day_return_percentage) + ')',
      pnlClass(summary.positions_day_return)
    );
  }

  function renderHoldingsRow(item) {
    return (
      '<tr>' +
      '<td><code>' + Drupal.checkPlain(item.symbol) + '</code></td>' +
      '<td>' + Drupal.checkPlain(item.isin || '-') + '</td>' +
      '<td>' + Drupal.checkPlain(item.exchange) + '</td>' +
      '<td>' + item.quantity + '</td>' +
      '<td>' + formatMoney(item.average_price) + '</td>' +
      '<td>' + (item.current_price != null ? formatMoney(item.current_price) : '-') + '</td>' +
      '<td>' + formatMoney(item.invested_value) + '</td>' +
      '<td>' + formatMoney(item.current_value) + '</td>' +
      '<td class="' + pnlClass(item.pnl) + '">' + formatMoney(item.pnl) + ' (' + formatPct(item.pnl_percentage) + ')</td>' +
      '<td class="' + pnlClass(item.day_return) + '">' + formatMoney(item.day_return) + ' (' + formatPct(item.day_return_percentage) + ')</td>' +
      '</tr>'
    );
  }

  function renderPositionRow(item) {
    return (
      '<tr>' +
      '<td><code>' + Drupal.checkPlain(item.symbol) + '</code></td>' +
      '<td>' + Drupal.checkPlain(item.segment || '-') + '</td>' +
      '<td>' + Drupal.checkPlain(item.exchange) + '</td>' +
      '<td>' + Drupal.checkPlain(item.product_type) + '</td>' +
      '<td>' + item.quantity + '</td>' +
      '<td>' + formatMoney(item.average_price) + '</td>' +
      '<td>' + (item.current_price != null ? formatMoney(item.current_price) : '-') + '</td>' +
      '<td class="' + pnlClass(item.pnl) + '">' + formatMoney(item.pnl) + '</td>' +
      '<td class="' + pnlClass(item.unrealised_pnl) + '">' + formatMoney(item.unrealised_pnl) + '</td>' +
      '<td class="' + pnlClass(item.day_return) + '">' + formatMoney(item.day_return) + ' (' + formatPct(item.day_return_percentage) + ')</td>' +
      '</tr>'
    );
  }

  function updateTables(root, payload) {
    const holdingsBody = root.querySelector('#niftybot-holdings-tbody');
    const positionsBody = root.querySelector('#niftybot-positions-tbody');
    const holdingsCount = root.querySelector('#niftybot-holdings-count');
    const positionsCount = root.querySelector('#niftybot-positions-count');
    const rawPre = root.querySelector('#niftybot-portfolio-raw');

    const holdings = payload.holdings || [];
    const positions = payload.positions || [];

    if (holdingsCount) {
      holdingsCount.textContent = '(' + holdings.length + ')';
    }
    if (positionsCount) {
      positionsCount.textContent = '(' + positions.length + ')';
    }
    if (holdingsBody) {
      holdingsBody.innerHTML = holdings.length
        ? holdings.map(renderHoldingsRow).join('')
        : '';
    }
    if (positionsBody) {
      positionsBody.innerHTML = positions.length
        ? positions.map(renderPositionRow).join('')
        : '';
    }
    if (rawPre) {
      rawPre.textContent = JSON.stringify(payload, null, 2);
    }
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

  function applyPayload(root, payload) {
    if (!payload || !payload.success) {
      return;
    }
    updateSummary(root, payload.summary || {});
    updateTables(root, payload);
    const updated = root.querySelector('.niftybot-live-updated__time');
    if (updated) {
      updated.textContent = new Date().toLocaleTimeString();
    }
  }

  function connectWebSocket(root) {
    if (!settings.wsUrl || !settings.apiKey) {
      return null;
    }

    const url =
      settings.wsUrl +
      '/ws/portfolio?broker=' +
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
          applyPayload(root, JSON.parse(event.data));
        }
        catch (e) {
          // Ignore malformed payloads.
        }
      });

      socket.addEventListener('close', function () {
        setLiveStatus(root, 'error', Drupal.t('Disconnected'));
        if (!closedByUser) {
          reconnectTimer = window.setTimeout(connect, POLL_FALLBACK_MS);
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

  Drupal.behaviors.niftybotPortfolioRealtime = {
    attach: function (context) {
      once('niftybot-portfolio-realtime', '.niftybot-portfolio--live', context).forEach(function (root) {
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
