/**
 * @file
 * Live auto-trade dashboard with activate/deactivate controls.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const settings = drupalSettings.niftybotAutoTrade || {};
  const RECONNECT_MS = 5000;
  const POLL_MS = 5000;
  const SUGGESTIONS_POLL_MS = 60000;
  const SUGGESTIONS_START_DELAY_MS = 20000;
  const STATUS_TIMEOUT_MS = 45000;
  const SUGGESTIONS_TIMEOUT_MS = 120000;
  const growwTradingSupported = settings.growwTradingSupported !== false;
  const growwUnsupportedMessage = settings.growwUnsupportedMessage
    || 'Groww Trading APIs support equity (CASH) and derivatives (FNO) only. MCX commodity trading is not available at this time.';
  const isIndexInstrument = settings.isIndexInstrument === true;
  let marketOpen = settings.marketOpen !== false;
  let marketClosedMessage = settings.marketClosedMessage || '';
  const instrument = settings.instrument || 'nifty';
  const useDrupalApi = !!settings.useDrupalApi;
  const lotStep = Number(settings.lotStep) || 65;
  const quantitySettings = settings.quantities || {};

  const QUANTITY_KEYS = {
    nifty: 'nifty_quantity',
    sensex: 'sensex_quantity',
    crude_oil: 'crude_oil_quantity',
    gold: 'gold_quantity',
  };

  function quantityFieldForInstrument(inst) {
    return QUANTITY_KEYS[inst] || 'nifty_quantity';
  }

  function formatPrice(value) {
    if (value === null || value === undefined || value === '') {
      return '—';
    }
    return '₹' + Number(value).toLocaleString('en-IN', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
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

  function setText(id, text) {
    const el = document.getElementById(id);
    if (el) {
      el.textContent = text;
    }
  }

  function formatPoints(value) {
    if (value === null || value === undefined || value === '') {
      return '';
    }
    return Number(value).toFixed(1) + ' pts';
  }

  function formatSlTargetCell(price, points) {
    if (price === null || price === undefined || price === '') {
      return '—';
    }
    let text = formatPrice(price);
    if (points !== null && points !== undefined && points !== '') {
      text += ' (' + Number(points).toFixed(0) + ' pts)';
    }
    return text;
  }

  function formatAwayLabel(distance, kind) {
    if (distance === null || distance === undefined || distance === '') {
      return '';
    }
    const pts = Number(distance);
    if (kind === 'sl') {
      return pts >= 0
        ? pts.toFixed(1) + ' pts away'
        : Math.abs(pts).toFixed(1) + ' pts past SL';
    }
    return pts >= 0
      ? pts.toFixed(1) + ' pts to target'
      : Math.abs(pts).toFixed(1) + ' pts past target';
  }

  function updateExitLevels(trade) {
    const levelsBox = document.getElementById('niftybot-auto-trade-levels');
    if (levelsBox) {
      levelsBox.hidden = !(trade && trade.status === 'open');
    }
    if (!trade || trade.status !== 'open') {
      return;
    }

    setText(
      'niftybot-auto-trade-sl-price',
      trade.stop_loss != null ? formatPrice(trade.stop_loss) : '—'
    );
    setText(
      'niftybot-auto-trade-target-price',
      trade.target != null ? formatPrice(trade.target) : '—'
    );
    setText(
      'niftybot-auto-trade-level-entry',
      trade.entry_price != null ? formatPrice(trade.entry_price) : '—'
    );
    setText(
      'niftybot-auto-trade-sl-points',
      trade.sl_points != null ? Number(trade.sl_points).toFixed(0) + ' pts' : ''
    );
    setText(
      'niftybot-auto-trade-target-points',
      trade.target_points != null ? Number(trade.target_points).toFixed(0) + ' pts' : ''
    );
    setText('niftybot-auto-trade-sl-away', formatAwayLabel(trade.sl_distance, 'sl'));
    setText('niftybot-auto-trade-target-away', formatAwayLabel(trade.target_distance, 'target'));
  }

  function setLiveStatus(state, message) {
    const badge = document.getElementById('niftybot-auto-trade-live-badge');
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

  function formatIndexPrice(value) {
    if (value === null || value === undefined || value === '') {
      return '—';
    }
    return Number(value).toLocaleString('en-IN', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  function formatSetupPoints(value) {
    if (value === null || value === undefined || value === '') {
      return '—';
    }
    const num = Number(value);
    if (Math.abs(num - Math.round(num)) < 0.05) {
      return String(Math.round(num));
    }
    return num.toFixed(1);
  }

  function setupStatusLabel(status) {
    const labels = {
      active: Drupal.t('Trigger hit'),
      at_trigger: Drupal.t('At trigger'),
      waiting: Drupal.t('Waiting'),
    };
    return labels[status] || Drupal.t('Waiting');
  }

  let aiActiveTab = 'overview';

  function switchAiTab(tabId) {
    if (!tabId) {
      return;
    }
    aiActiveTab = tabId;
    document.querySelectorAll('[data-ai-tab]').forEach(function (btn) {
      const active = btn.getAttribute('data-ai-tab') === tabId;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('[data-ai-tabpanel]').forEach(function (panelEl) {
      const active = panelEl.getAttribute('data-ai-tabpanel') === tabId;
      panelEl.classList.toggle('is-active', active);
      panelEl.hidden = !active;
    });
  }

  function bindAiTabs(context) {
    once('niftybot-ai-tabs', '.niftybot-auto-trade-user__ai-tabs', context).forEach(function (nav) {
      nav.querySelectorAll('[data-ai-tab]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          switchAiTab(btn.getAttribute('data-ai-tab'));
        });
      });
    });
  }

  function renderAiOverview(suggestion) {
    const grid = document.getElementById('niftybot-auto-trade-ai-overview');
    if (!grid) {
      return;
    }
    const call = suggestion.call_setup || {};
    const put = suggestion.put_setup || {};
    const pattern = suggestion.market_pattern || {};
    const topPick = (suggestion.trade_suggestions || [])[0];
    const cards = [
      {
        title: Drupal.t('Market pattern'),
        value: pattern.label || Drupal.t('Scanning…'),
        note: pattern.pattern === 'bullish'
          ? Drupal.t('OI + indicators lean bullish')
          : (pattern.pattern === 'bearish'
            ? Drupal.t('OI + indicators lean bearish')
            : Drupal.t('Mixed signals — wait for clarity')),
        tone: pattern.pattern || 'neutral',
      },
      {
        title: Drupal.t('Call trigger'),
        value: call.trigger_price != null ? formatIndexPrice(call.trigger_price) : '—',
        note: call.points_away != null && call.ltp != null
          ? Drupal.t('+@pts pts above @ltp', {
            '@pts': formatSetupPoints(call.points_away),
            '@ltp': formatIndexPrice(call.ltp),
          })
          : (call.message || '—'),
        tone: 'call',
      },
      {
        title: Drupal.t('Put trigger'),
        value: put.trigger_price != null ? formatIndexPrice(put.trigger_price) : '—',
        note: put.points_away != null && put.ltp != null
          ? Drupal.t('-@pts pts below @ltp', {
            '@pts': formatSetupPoints(put.points_away),
            '@ltp': formatIndexPrice(put.ltp),
          })
          : (put.message || '—'),
        tone: 'put',
      },
      {
        title: Drupal.t('Top strike idea'),
        value: topPick ? topPick.contract : (call.recommended_contract || put.recommended_contract || '—'),
        note: topPick
          ? [topPick.tier_label, topPick.delta != null ? 'Δ ' + Number(topPick.delta).toFixed(2) : ''].filter(Boolean).join(' · ')
          : Drupal.t('Open Strikes tab for full list'),
        tone: 'strike',
      },
    ];

    grid.innerHTML = cards.map(function (card) {
      return (
        '<article class="niftybot-auto-trade-user__ai-overview-card niftybot-auto-trade-user__ai-overview-card--'
        + Drupal.checkPlain(card.tone)
        + '">'
        + '<h4 class="niftybot-auto-trade-user__ai-overview-card-title">' + Drupal.checkPlain(card.title) + '</h4>'
        + '<p class="niftybot-auto-trade-user__ai-overview-card-value">' + Drupal.checkPlain(card.value) + '</p>'
        + '<p class="niftybot-auto-trade-user__ai-overview-card-note">' + Drupal.checkPlain(card.note) + '</p>'
        + '</article>'
      );
    }).join('');
  }

  function renderAiSetup(side, setup) {
    if (!setup) {
      return;
    }
    const prefix = side === 'call' ? 'call' : 'put';
    const card = document.getElementById('niftybot-auto-trade-ai-' + prefix + '-setup');
    if (card) {
      card.classList.remove(
        'niftybot-auto-trade-user__ai-setup--active',
        'niftybot-auto-trade-user__ai-setup--at-trigger',
        'niftybot-auto-trade-user__ai-setup--waiting'
      );
      card.classList.add('niftybot-auto-trade-user__ai-setup--' + (setup.status || 'waiting'));
    }
    setText('niftybot-auto-trade-ai-' + prefix + '-message', setup.message || '');
    setText('niftybot-auto-trade-ai-' + prefix + '-status', setupStatusLabel(setup.status));
    setText(
      'niftybot-auto-trade-ai-' + prefix + '-trigger',
      setup.trigger_price != null
        ? formatIndexPrice(setup.trigger_price) + (setup.trigger_label ? ' · ' + setup.trigger_label : '')
        : '—'
    );
    if (setup.points_away != null && setup.ltp != null) {
      const pointsText = side === 'call'
        ? Drupal.t('+@pts pts above @ltp', {
          '@pts': formatSetupPoints(setup.points_away),
          '@ltp': formatIndexPrice(setup.ltp),
        })
        : Drupal.t('-@pts pts below @ltp', {
          '@pts': formatSetupPoints(setup.points_away),
          '@ltp': formatIndexPrice(setup.ltp),
        });
      setText('niftybot-auto-trade-ai-' + prefix + '-points', pointsText);
    }
    else {
      setText('niftybot-auto-trade-ai-' + prefix + '-points', '—');
    }
    if (setup.rules_met != null && setup.rules_total != null) {
      setText(
        'niftybot-auto-trade-ai-' + prefix + '-rules',
        setup.rules_met + '/' + setup.rules_total + ' · ' + (setup.confidence_pct != null ? setup.confidence_pct + '%' : '—')
      );
    }
    else {
      setText('niftybot-auto-trade-ai-' + prefix + '-rules', '—');
    }
    const recEl = document.getElementById('niftybot-auto-trade-ai-' + prefix + '-recommended');
    if (recEl) {
      if (setup.recommended_contract) {
        let recText = setup.recommended_contract;
        if (setup.recommended_delta != null) {
          recText += ' · Δ ' + Number(setup.recommended_delta).toFixed(2);
        }
        if (setup.recommended_premium != null) {
          recText += ' · ' + formatPrice(setup.recommended_premium);
        }
        recEl.textContent = recText;
      }
      else {
        recEl.textContent = '—';
      }
    }
  }

  function renderMarketPattern(suggestion) {
    const panel = document.getElementById('niftybot-auto-trade-ai-pattern');
    if (!panel) {
      return;
    }
    const pattern = suggestion.market_pattern || {};
    const label = pattern.label || '';
    const signals = pattern.signals || [];
    if (!label) {
      panel.hidden = true;
      panel.innerHTML = '';
      return;
    }
    panel.hidden = false;
    panel.classList.remove(
      'niftybot-auto-trade-user__ai-pattern--bullish',
      'niftybot-auto-trade-user__ai-pattern--bearish',
      'niftybot-auto-trade-user__ai-pattern--neutral'
    );
    const kind = pattern.pattern || suggestion.bias || 'neutral';
    panel.classList.add('niftybot-auto-trade-user__ai-pattern--' + kind);
    let html = '<strong>' + Drupal.checkPlain(label) + '</strong>';
    if (signals.length) {
      html += '<ul class="niftybot-auto-trade-user__ai-pattern-list">';
      signals.forEach(function (item) {
        html += '<li>' + Drupal.checkPlain(String(item)) + '</li>';
      });
      html += '</ul>';
    }
    panel.innerHTML = html;
  }

  function renderTradeSuggestions(suggestion) {
    const list = document.getElementById('niftybot-auto-trade-ai-contracts');
    const empty = document.getElementById('niftybot-auto-trade-ai-contracts-empty');
    if (!list) {
      return;
    }
    const items = suggestion.trade_suggestions || [];
    if (!items.length) {
      list.innerHTML = '';
      list.hidden = true;
      if (empty) {
        empty.hidden = false;
      }
      return;
    }
    if (empty) {
      empty.hidden = true;
    }
    list.hidden = false;
    list.innerHTML = items.map(function (item) {
      const priority = item.priority || 'low';
      const deltaText = item.delta != null
        ? Drupal.t('Δ @delta', { '@delta': Number(item.delta).toFixed(2) })
        : '';
      const premiumText = item.premium != null ? formatPrice(item.premium) : '';
      const meta = [item.tier_label || '', deltaText, premiumText].filter(Boolean).join(' · ');
      return (
        '<li class="niftybot-auto-trade-user__ai-contract niftybot-auto-trade-user__ai-contract--'
        + Drupal.checkPlain(String(item.option_type || '').toLowerCase())
        + ' niftybot-auto-trade-user__ai-contract--'
        + Drupal.checkPlain(String(priority))
        + '">'
        + '<span class="niftybot-auto-trade-user__ai-contract-name">' + Drupal.checkPlain(item.contract || '') + '</span>'
        + '<span class="niftybot-auto-trade-user__ai-contract-meta">' + Drupal.checkPlain(meta) + '</span>'
        + '<span class="niftybot-auto-trade-user__ai-contract-note">' + Drupal.checkPlain(item.rationale || '') + '</span>'
        + '</li>'
      );
    }).join('');
  }

  function updateAiSuggestion(payload) {
    const panel = document.getElementById('niftybot-auto-trade-ai-panel');
    if (!panel || !settings.brokerConnected) {
      return;
    }

    if (payload && payload.active) {
      panel.hidden = true;
      return;
    }

    const suggestion = payload && payload.ai_suggestion;
    if (!suggestion) {
      panel.hidden = true;
      return;
    }

    panel.hidden = false;
    panel.classList.remove(
      'niftybot-auto-trade-user__ai-suggestions--bullish',
      'niftybot-auto-trade-user__ai-suggestions--bearish',
      'niftybot-auto-trade-user__ai-suggestions--neutral',
      'niftybot-auto-trade-user__ai-suggestions--high',
      'niftybot-auto-trade-user__ai-suggestions--medium',
      'niftybot-auto-trade-user__ai-suggestions--wait'
    );
    panel.classList.add('niftybot-auto-trade-user__ai-suggestions--' + (suggestion.bias || 'neutral'));
    panel.classList.add('niftybot-auto-trade-user__ai-suggestions--' + (suggestion.confidence_tier || 'wait'));

    setText('niftybot-auto-trade-ai-headline', suggestion.headline || Drupal.t('Live trade setups'));
    setText('niftybot-auto-trade-ai-summary', suggestion.summary || '');
    setText('niftybot-auto-trade-ai-hint', suggestion.manual_hint || '');
    setText('niftybot-auto-trade-ai-disclaimer', suggestion.disclaimer || '');
    renderAiSetup('call', suggestion.call_setup);
    renderAiSetup('put', suggestion.put_setup);
    renderMarketPattern(suggestion);
    renderTradeSuggestions(suggestion);
    renderAiOverview(suggestion);

    if (!panel.dataset.aiTabInit) {
      const patternKind = suggestion.market_pattern && suggestion.market_pattern.pattern;
      if (patternKind === 'bullish') {
        switchAiTab('call');
      }
      else if (patternKind === 'bearish') {
        switchAiTab('put');
      }
      else {
        switchAiTab('overview');
      }
      panel.dataset.aiTabInit = '1';
    }
    else {
      switchAiTab(aiActiveTab);
    }

    const confEl = document.getElementById('niftybot-auto-trade-ai-confidence');
    if (confEl) {
      const tierLabels = {
        high: Drupal.t('High confidence'),
        medium: Drupal.t('Developing'),
        low: Drupal.t('Low confidence'),
        wait: Drupal.t('Wait'),
      };
      const tier = suggestion.confidence_tier || 'wait';
      confEl.textContent = (suggestion.confidence_pct != null ? suggestion.confidence_pct + '% · ' : '') +
        (tierLabels[tier] || tier);
    }

    const biasEl = document.getElementById('niftybot-auto-trade-ai-bias');
    if (biasEl) {
      const biasMap = {
        bullish: Drupal.t('Bullish bias'),
        bearish: Drupal.t('Bearish bias'),
        neutral: Drupal.t('Neutral'),
      };
      biasEl.textContent = biasMap[suggestion.bias] || '';
    }

    const updatedEl = document.getElementById('niftybot-auto-trade-ai-updated');
    if (updatedEl) {
      updatedEl.textContent = payload.last_check_at
        ? Drupal.t('Updated @time', { '@time': new Date().toLocaleTimeString() })
        : '';
    }

    const factorsEl = document.getElementById('niftybot-auto-trade-ai-factors');
    if (factorsEl) {
      const items = suggestion.factors || suggestion.reasons || [];
      factorsEl.innerHTML = items.map(function (item) {
        return '<li>' + Drupal.checkPlain(String(item)) + '</li>';
      }).join('');
      factorsEl.hidden = items.length === 0;
    }
  }

  function drupalApi(method, path, body, timeoutMs) {
    const controller = new AbortController();
    const wait = timeoutMs || STATUS_TIMEOUT_MS;
    const timeoutId = window.setTimeout(function () {
      controller.abort();
    }, wait);

    const options = {
      method: method,
      headers: {
        'X-CSRF-Token': settings.csrfToken,
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
      signal: controller.signal,
    };
    if (body) {
      options.body = JSON.stringify(body);
    }
    return fetch((settings.apiBase || '/niftybot/api/auto-trade') + path, options).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) {
          if (data && typeof data === 'object') {
            return data;
          }
          throw new Error('HTTP ' + response.status);
        }
        return data;
      }).catch(function (parseError) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        throw parseError;
      });
    }).finally(function () {
      window.clearTimeout(timeoutId);
    });
  }

  function apiPost(path, body) {
    if (useDrupalApi) {
      const action = path.split('/').pop();
      const drupalPath = '/' + instrument + '/' + action;
      return drupalApi('POST', drupalPath, body);
    }

    const options = {
      method: 'POST',
      headers: {
        'X-API-Key': settings.apiKey,
        'Content-Type': 'application/json',
      },
    };
    if (body) {
      options.body = JSON.stringify(body);
    }
    return fetch(settings.restUrl + path, options).then(function (response) {
      return response.json();
    });
  }

  function getQuantityValue() {
    const input = document.getElementById('niftybot-auto-trade-quantity');
    return input ? Number(input.value) : Number(settings.quantity) || lotStep;
  }

  function validateQuantity(value) {
    const qty = Number(value);
    if (!qty || qty < lotStep) {
      return Drupal.t('Quantity must be at least @step.', { '@step': lotStep });
    }
    if (qty % lotStep !== 0) {
      return Drupal.t('Quantity must be a multiple of @step.', { '@step': lotStep });
    }
    return '';
  }

  function setQuantityHint(message, type) {
    const hint = document.getElementById('niftybot-auto-trade-quantity-hint');
    if (!hint) {
      return;
    }
    hint.textContent = message || '';
    hint.classList.remove('is-error', 'is-success');
    if (type) {
      hint.classList.add('is-' + type);
    }
  }

  function setQuantityControls(active) {
    const qtyInput = document.getElementById('niftybot-auto-trade-quantity');
    const saveBtn = document.getElementById('niftybot-auto-trade-save-qty');
    if (qtyInput) {
      qtyInput.disabled = !!active;
    }
    if (saveBtn) {
      saveBtn.disabled = !!active;
    }
  }

  function getSelectedMode() {
    const selected = document.querySelector('input[name="niftybot-auto-trade-mode"]:checked');
    return selected ? selected.value : 'buy';
  }

  function setModeControls(active) {
    const activateBtn = document.getElementById('niftybot-auto-trade-activate');
    const deactivateBtn = document.getElementById('niftybot-auto-trade-deactivate');
    const modeInputs = document.querySelectorAll('input[name="niftybot-auto-trade-mode"]');
    const accessBlocked = settings.access && settings.access.can_access === false;
    const tradingBlocked = !growwTradingSupported;
    const marketBlocked = isIndexInstrument && !marketOpen;
    if (activateBtn) {
      activateBtn.disabled = !!active || !!accessBlocked || tradingBlocked || marketBlocked;
    }
    if (deactivateBtn) {
      deactivateBtn.classList.toggle('is-active-trade', !!active);
    }
    modeInputs.forEach(function (input) {
      input.disabled = !!active || tradingBlocked;
    });
    setQuantityControls(active || tradingBlocked);
  }

  function updateMarketHoursBanner(open, message) {
    if (!isIndexInstrument) {
      return;
    }

    const closedBanner = document.getElementById('niftybot-auto-trade-market-closed');
    const openBanner = document.getElementById('niftybot-auto-trade-market-open');
    const hoursFallback = Drupal.t('Market is open · Nifty & Sensex session 9:15 AM – 3:30 PM IST.');
    const closedFallback = Drupal.t('Market is closed — Nifty & Sensex session hours: 9:15 AM – 3:30 PM IST.');

    if (open) {
      if (closedBanner) {
        closedBanner.hidden = true;
      }
      const text = message || marketClosedMessage || hoursFallback;
      if (openBanner) {
        openBanner.hidden = false;
        const textNode = openBanner.querySelector('.niftybot-auto-trade-market-open__text');
        if (textNode) {
          textNode.textContent = text;
        }
      }
      return;
    }

    if (openBanner) {
      openBanner.hidden = true;
    }

    const text = message || marketClosedMessage || closedFallback;
    let banner = closedBanner;
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'niftybot-auto-trade-market-closed';
      banner.className = 'niftybot-auto-trade-user__market-banner niftybot-auto-trade-user__market-banner--closed';
      banner.setAttribute('role', 'status');
      const icon = document.createElement('i');
      icon.className = 'bi bi-moon-stars me-2';
      icon.setAttribute('aria-hidden', 'true');
      const textEl = document.createElement('span');
      textEl.className = 'niftybot-auto-trade-market-closed__text';
      banner.appendChild(icon);
      banner.appendChild(textEl);
      const hero = document.querySelector('.niftybot-auto-trade-user__hero');
      if (hero && hero.parentNode) {
        hero.parentNode.insertBefore(banner, hero.nextSibling);
      }
    }
    banner.hidden = false;
    const textEl = banner.querySelector('.niftybot-auto-trade-market-closed__text');
    if (textEl) {
      textEl.textContent = text;
    }
    else {
      banner.textContent = text;
    }
  }

  function updateState(payload) {
    if (!payload) {
      return;
    }

    if (typeof payload.market_open === 'boolean') {
      marketOpen = payload.market_open;
    }
    if (typeof payload.market_message === 'string' && payload.market_message !== '') {
      marketClosedMessage = payload.market_message;
    }
    updateMarketHoursBanner(marketOpen, marketClosedMessage);

    setText('niftybot-auto-trade-index-ltp', payload.underlying_ltp != null
      ? Number(payload.underlying_ltp).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
      : (payload.nifty_ltp != null
        ? Number(payload.nifty_ltp).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : '—'));
    setText('niftybot-auto-trade-message', payload.message || '');
    setText('niftybot-auto-trade-updated', new Date().toLocaleTimeString());
    setText('niftybot-auto-trade-trade-mode', (payload.trade_mode || 'buy').toUpperCase());
    setModeControls(!!payload.active);

    const stateEl = document.getElementById('niftybot-auto-trade-state');
    if (stateEl) {
      stateEl.innerHTML = payload.active
        ? '<span class="niftybot-auto-trade__state--on">' + Drupal.t('ACTIVE') + '</span>'
        : '<span class="niftybot-auto-trade__state--off">' + Drupal.t('INACTIVE') + '</span>';
    }

    const signal = payload.last_signal || {};
    const indicators = signal.indicators || {};
    setText('niftybot-auto-trade-signal-action', signal.action || 'HOLD');
    let confidenceText = '—';
    if (signal.confidence != null) {
      confidenceText = (Number(signal.confidence) * 100).toFixed(0) + '%';
      const ceMet = indicators.ce_rules_met;
      const ceTotal = indicators.ce_rules_total;
      const peMet = indicators.pe_rules_met;
      const peTotal = indicators.pe_rules_total;
      if (ceMet != null && ceTotal != null && peMet != null && peTotal != null) {
        const useCe = ceMet >= peMet;
        const met = useCe ? ceMet : peMet;
        const total = useCe ? ceTotal : peTotal;
        const side = useCe ? 'CALL' : 'PUT';
        confidenceText += ' (' + side + ' ' + met + '/' + total + ')';
      }
    }
    setText('niftybot-auto-trade-signal-confidence', confidenceText);
    setText('niftybot-auto-trade-oi', indicators.oi_buildup || '—');
    const emaParts = [];
    if (indicators.ema_signal) {
      emaParts.push(indicators.ema_signal);
    }
    if (indicators.ema_20 != null && indicators.ema_50 != null) {
      emaParts.push(
        Number(indicators.ema_20).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        + ' / '
        + Number(indicators.ema_50).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
      );
    }
    if (indicators.ema_200 != null) {
      emaParts.push(
        '200: ' + Number(indicators.ema_200).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
      );
    }
    setText('niftybot-auto-trade-ema', emaParts.length ? emaParts.join(' · ') : '—');
    const adxParts = [];
    if (indicators.adx != null) {
      adxParts.push(String(indicators.adx));
    }
    const rsiLabel = indicators.rsi_divergence || indicators.rsi;
    if (rsiLabel != null) {
      adxParts.push('RSI ' + String(rsiLabel));
    }
    setText('niftybot-auto-trade-rsi', adxParts.length ? adxParts.join(' · ') : '—');
    const coreBull = indicators.core_setup_bull;
    const coreBear = indicators.core_setup_bear;
    let coreLabel = '—';
    if (coreBull) {
      coreLabel = 'Rules+OI+RSI → CE';
    } else if (coreBear) {
      coreLabel = 'Rules+OI+RSI → PE';
    } else if (indicators.ce_rules_met != null || indicators.pe_rules_met != null) {
      const parts = [];
      if (indicators.ce_rules_met != null && indicators.ce_rules_total != null) {
        parts.push('CALL ' + indicators.ce_rules_met + '/' + indicators.ce_rules_total);
      }
      if (indicators.pe_rules_met != null && indicators.pe_rules_total != null) {
        parts.push('PUT ' + indicators.pe_rules_met + '/' + indicators.pe_rules_total);
      }
      if (indicators.oi_aligned_bull || indicators.oi_aligned_bear) {
        parts.push('OI');
      } else {
        parts.push('OI ✗');
      }
      if (indicators.rsi_aligned_bull || indicators.rsi_aligned_bear) {
        parts.push('RSI');
      } else {
        parts.push('RSI ✗');
      }
      coreLabel = parts.join(' · ');
    }
    setText('niftybot-auto-trade-core-setup', coreLabel);

    const vwap = indicators.session_vwap;
    let vwapLabel = '—';
    if (vwap != null) {
      vwapLabel = Number(vwap).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      if (indicators.vwap_bull) {
        vwapLabel += ' (above)';
      } else if (indicators.vwap_bear) {
        vwapLabel += ' (below)';
      }
    }
    setText('niftybot-auto-trade-vwap', vwapLabel);

    let volLabel = '—';
    if (indicators.volume_above_20ma) {
      volLabel = Drupal.t('Above 20 MA');
    } else if (indicators.volume_above_average) {
      volLabel = Drupal.t('Above average');
    } else if (indicators.volume_spike_bull) {
      volLabel = Drupal.t('Bullish spike');
    } else if (indicators.volume_spike_bear) {
      volLabel = Drupal.t('Bearish spike');
    }
    setText('niftybot-auto-trade-volume', volLabel);
    setText('niftybot-auto-trade-chain-oi', indicators.chain_oi_summary || '—');

    let advancedLabel = '—';
    if (indicators.advanced_bull) {
      advancedLabel = 'Chain OI → CE';
    } else if (indicators.advanced_bear) {
      advancedLabel = 'Chain OI → PE';
    } else {
      const advParts = [];
      if (indicators.pullback_near_ema20) {
        advParts.push(Drupal.t('Pullback'));
      } else {
        advParts.push(Drupal.t('Pullback ✗'));
      }
      if (indicators.bullish_confirmation_candle) {
        advParts.push(Drupal.t('Confirm'));
      } else {
        advParts.push(Drupal.t('Confirm ✗'));
      }
      const chainOk = indicators.bullish_factors && indicators.bullish_factors.some(function (f) {
        return f.indexOf('chain_') === 0;
      }) || indicators.bearish_factors && indicators.bearish_factors.some(function (f) {
        return f.indexOf('chain_') === 0;
      });
      if (chainOk) {
        advParts.push('Chain');
      } else if (indicators.chain_oi_summary && indicators.chain_oi_summary !== 'unavailable') {
        advParts.push('Chain ✗');
      } else {
        advParts.push('Chain —');
      }
      advancedLabel = advParts.join(' · ');
    }
    setText('niftybot-auto-trade-advanced', advancedLabel);
    setText('niftybot-auto-trade-reasons', (signal.reasons && signal.reasons.length) ? signal.reasons.join(', ') : '—');

    if (payload.ai_suggestion && settings.brokerConnected && !payload.active) {
      updateAiSuggestion(payload);
    }

    const trade = payload.current_trade;
    const noPos = document.getElementById('niftybot-auto-trade-no-position');
    const posTable = document.getElementById('niftybot-auto-trade-position-table');
    const exitBtn = document.getElementById('niftybot-auto-trade-exit');
    const hasOpenTrade = trade && trade.status === 'open';

    if (exitBtn) {
      exitBtn.hidden = !hasOpenTrade;
    }

    if (hasOpenTrade) {
      if (noPos) {
        noPos.hidden = true;
      }
      if (posTable) {
        posTable.hidden = false;
      }
      setText('niftybot-auto-trade-symbol', trade.symbol || '—');
      setText('niftybot-auto-trade-position-side', (trade.position_side || '—').toUpperCase());
      setText('niftybot-auto-trade-type', trade.option_type || '—');
      setText('niftybot-auto-trade-strike', (trade.strike || '—') + ' / ' + (trade.expiry_date || '—'));
      setText('niftybot-auto-trade-qty', String(trade.quantity || '—'));
      setText('niftybot-auto-trade-entry', trade.entry_price != null ? formatPrice(trade.entry_price) : '—');
      setText('niftybot-auto-trade-current', trade.current_price != null ? formatPrice(trade.current_price) : '—');
      setText('niftybot-auto-trade-sl', formatSlTargetCell(trade.stop_loss, trade.sl_points));
      setText('niftybot-auto-trade-target', formatSlTargetCell(trade.target, trade.target_points));
      updateExitLevels(trade);

      const ocoEl = document.getElementById('niftybot-auto-trade-oco');
      if (ocoEl) {
        if (trade.smart_order_id) {
          ocoEl.innerHTML = '<code>' + Drupal.checkPlain(trade.smart_order_id) + '</code> (' +
            Drupal.checkPlain(trade.smart_order_status || 'ACTIVE') + ')';
        }
        else {
          ocoEl.textContent = '—';
        }
      }

      const pnlEl = document.getElementById('niftybot-auto-trade-pnl');
      if (pnlEl) {
        pnlEl.classList.remove('niftybot-pnl--positive', 'niftybot-pnl--negative');
        const cls = pnlClass(trade.pnl);
        if (cls) {
          pnlEl.classList.add(cls);
        }
        if (trade.pnl != null) {
          pnlEl.textContent = formatPrice(trade.pnl) + ' (' + Number(trade.pnl_percentage || 0).toFixed(2) + '%)';
        }
        else {
          pnlEl.textContent = '—';
        }
      }
    }
    else {
      if (noPos) {
        noPos.hidden = false;
      }
      if (posTable) {
        posTable.hidden = true;
      }
      updateExitLevels(null);
    }

    const historyBody = document.getElementById('niftybot-auto-trade-history-body');
    if (historyBody && settings.isUser) {
      return;
    }
    const history = payload.trade_history || [];
    if (historyBody) {
      historyBody.innerHTML = history.map(function (item) {
        const cls = pnlClass(item.pnl);
        return (
          '<tr>' +
          '<td><code>' + Drupal.checkPlain(item.symbol || '') + '</code></td>' +
          '<td>' + Drupal.checkPlain(item.option_type || '') + '</td>' +
          '<td>' + formatPrice(item.entry_price) + '</td>' +
          '<td>' + formatPrice(item.current_price) + '</td>' +
          '<td class="' + cls + '">' + formatPrice(item.pnl) + '</td>' +
          '<td>' + Drupal.checkPlain(item.exit_reason || '—') + '</td>' +
          '</tr>'
        );
      }).join('');
    }
  }

  function connectPolling() {
    let timer = null;
    let stopped = false;
    let inFlight = false;

    function poll() {
      if (stopped || inFlight) {
        if (!stopped) {
          timer = window.setTimeout(poll, POLL_MS);
        }
        return;
      }
      inFlight = true;
      drupalApi('GET', '/' + instrument + '/status').then(function (data) {
        if (data && data.success !== false) {
          updateState(data);
          if (data.active) {
            updateAiSuggestion({ active: true });
          }
          setLiveStatus('connected', Drupal.t('Live'));
        }
        else {
          setLiveStatus('error', Drupal.t('Unavailable'));
        }
      }).catch(function (error) {
        if (error && error.name === 'AbortError') {
          setLiveStatus('reconnecting', Drupal.t('Updating…'));
        }
        else {
          setLiveStatus('error', Drupal.t('Connection error'));
        }
      }).finally(function () {
        inFlight = false;
        if (!stopped) {
          timer = window.setTimeout(poll, POLL_MS);
        }
      });
    }

    poll();
    return function () {
      stopped = true;
      if (timer) {
        window.clearTimeout(timer);
      }
    };
  }

  function connectSuggestionsPolling() {
    let timer = null;
    let stopped = false;
    let inFlight = false;

    function pollSuggestions() {
      if (stopped || !settings.brokerConnected) {
        return;
      }
      const stateOn = document.querySelector('#niftybot-auto-trade-state .niftybot-auto-trade__state--on');
      if (stateOn) {
        updateAiSuggestion({ active: true });
        if (!stopped) {
          timer = window.setTimeout(pollSuggestions, SUGGESTIONS_POLL_MS);
        }
        return;
      }
      if (inFlight) {
        if (!stopped) {
          timer = window.setTimeout(pollSuggestions, SUGGESTIONS_POLL_MS);
        }
        return;
      }
      inFlight = true;
      drupalApi('GET', '/' + instrument + '/suggestions', null, SUGGESTIONS_TIMEOUT_MS).then(function (data) {
        if (data && data.success !== false) {
          updateAiSuggestion({
            active: false,
            ai_suggestion: data.ai_suggestion || data.suggestion,
            last_check_at: data.last_check_at,
          });
          updateState({
            success: true,
            active: false,
            underlying_ltp: data.underlying_ltp,
            last_signal: data.last_signal || undefined,
            last_check_at: data.last_check_at,
          });
        }
      }).catch(function () {
        // Suggestions are optional; status polling keeps the dashboard usable.
      }).finally(function () {
        inFlight = false;
        if (!stopped) {
          timer = window.setTimeout(pollSuggestions, SUGGESTIONS_POLL_MS);
        }
      });
    }

    const panel = document.getElementById('niftybot-auto-trade-ai-panel');
    if (panel && settings.brokerConnected) {
      panel.hidden = false;
    }
    timer = window.setTimeout(pollSuggestions, SUGGESTIONS_START_DELAY_MS);
    return function () {
      stopped = true;
      if (timer) {
        window.clearTimeout(timer);
      }
    };
  }

  function connectWebSocket() {
    if (!settings.wsUrl || !settings.apiKey) {
      return null;
    }

    const url = settings.wsUrl + '/ws/auto-trade?instrument=' + encodeURIComponent(instrument) +
      '&api_key=' + encodeURIComponent(settings.apiKey);
    let socket = null;
    let reconnectTimer = null;
    let closedByUser = false;

    function connect() {
      setLiveStatus('reconnecting', Drupal.t('Connecting…'));
      socket = new WebSocket(url);

      socket.addEventListener('open', function () {
        setLiveStatus('connected', Drupal.t('Live'));
      });

      socket.addEventListener('message', function (event) {
        try {
          updateState(JSON.parse(event.data));
        }
        catch (e) {
          // Ignore malformed payloads.
        }
      });

      socket.addEventListener('close', function () {
        setLiveStatus('error', Drupal.t('Disconnected'));
        if (!closedByUser) {
          reconnectTimer = window.setTimeout(connect, RECONNECT_MS);
        }
      });

      socket.addEventListener('error', function () {
        setLiveStatus('error', Drupal.t('Connection error'));
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

  function saveQuantitySettings() {
    const qty = getQuantityValue();
    const error = validateQuantity(qty);
    if (error) {
      setQuantityHint(error, 'error');
      return Promise.resolve(null);
    }

    const payload = {
      nifty_quantity: instrument === 'nifty' ? qty : (Number(quantitySettings.nifty_quantity) || 65),
      sensex_quantity: instrument === 'sensex' ? qty : (Number(quantitySettings.sensex_quantity) || 20),
      crude_oil_quantity: instrument === 'crude_oil' ? qty : (Number(quantitySettings.crude_oil_quantity) || 10),
      gold_quantity: instrument === 'gold' ? qty : (Number(quantitySettings.gold_quantity) || 100),
    };

    const saveBtn = document.getElementById('niftybot-auto-trade-save-qty');
    if (saveBtn) {
      saveBtn.disabled = true;
    }

    return drupalApi('POST', '/settings', payload).then(function (data) {
      if (data && data.success) {
        Object.assign(quantitySettings, data.settings);
        settings.quantity = data.settings[quantityFieldForInstrument(instrument)];
        setQuantityHint(Drupal.t('Quantity saved.'), 'success');
      }
      else if (data && data.message) {
        setQuantityHint(data.message, 'error');
      }
      return data;
    }).finally(function () {
      const isActive = document.querySelector('#niftybot-auto-trade-state .niftybot-auto-trade__state--on');
      if (saveBtn) {
        saveBtn.disabled = !!isActive;
      }
    });
  }

  function bindControls() {
    const activateBtn = document.getElementById('niftybot-auto-trade-activate');
    const deactivateBtn = document.getElementById('niftybot-auto-trade-deactivate');
    const exitBtn = document.getElementById('niftybot-auto-trade-exit');
    const qtyInput = document.getElementById('niftybot-auto-trade-quantity');
    const saveQtyBtn = document.getElementById('niftybot-auto-trade-save-qty');

    setModeControls(!!document.querySelector('#niftybot-auto-trade-state .niftybot-auto-trade__state--on'));

    if (qtyInput) {
      qtyInput.addEventListener('input', function () {
        const error = validateQuantity(qtyInput.value);
        setQuantityHint(error, error ? 'error' : '');
      });
    }

    if (saveQtyBtn) {
      saveQtyBtn.addEventListener('click', function () {
        saveQuantitySettings();
      });
    }

    if (exitBtn) {
      exitBtn.addEventListener('click', function () {
        if (!window.confirm(Drupal.t('Close this position at market price?'))) {
          return;
        }
        exitBtn.disabled = true;
        apiPost('/api/v1/auto-trade/' + instrument + '/exit').then(function (data) {
          if (data) {
            updateState(data);
          }
        }).finally(function () {
          exitBtn.disabled = false;
        });
      });
    }

    if (activateBtn) {
      activateBtn.addEventListener('click', function () {
        const qty = getQuantityValue();
        const error = validateQuantity(qty);
        if (error) {
          setQuantityHint(error, 'error');
          return;
        }

        activateBtn.disabled = true;
        const body = { mode: getSelectedMode() };
        if (useDrupalApi) {
          body.quantity = qty;
        }
        apiPost('/api/v1/auto-trade/' + instrument + '/activate', body).then(function (data) {
          if (data) {
            if (data.success === false && data.message) {
              setText('niftybot-auto-trade-message', data.message);
              setQuantityHint(data.message, 'error');
            }
            else if (data.message) {
              setText('niftybot-auto-trade-message', data.message);
              setQuantityHint('', '');
            }
            if (data.trade_mode) {
              setText('niftybot-auto-trade-trade-mode', data.trade_mode.toUpperCase());
            }
            if (typeof data.active === 'boolean') {
              const stateEl = document.getElementById('niftybot-auto-trade-state');
              if (stateEl) {
                stateEl.innerHTML = data.active
                  ? '<span class="niftybot-auto-trade__state--on">' + Drupal.t('ACTIVE') + '</span>'
                  : '<span class="niftybot-auto-trade__state--off">' + Drupal.t('INACTIVE') + '</span>';
              }
              setModeControls(data.active);
            }
          }
        }).catch(function (err) {
          const message = err && err.message ? err.message : Drupal.t('Activation failed. Please try again.');
          setText('niftybot-auto-trade-message', message);
          setQuantityHint(message, 'error');
        }).finally(function () {
          const isActive = document.querySelector('#niftybot-auto-trade-state .niftybot-auto-trade__state--on');
          activateBtn.disabled = !!isActive;
        });
      });
    }

    if (deactivateBtn) {
      deactivateBtn.addEventListener('click', function () {
        deactivateBtn.disabled = true;
        apiPost('/api/v1/auto-trade/' + instrument + '/deactivate').then(function (data) {
          if (data) {
            if (data.message) {
              setText('niftybot-auto-trade-message', data.message);
            }
            if (typeof data.active === 'boolean') {
              const stateEl = document.getElementById('niftybot-auto-trade-state');
              if (stateEl) {
                stateEl.innerHTML = data.active
                  ? '<span class="niftybot-auto-trade__state--on">' + Drupal.t('ACTIVE') + '</span>'
                  : '<span class="niftybot-auto-trade__state--off">' + Drupal.t('INACTIVE') + '</span>';
              }
              setModeControls(data.active);
            }
          }
        }).finally(function () {
          deactivateBtn.disabled = false;
        });
      });
    }
  }

  Drupal.behaviors.niftybotAutoTradeRealtime = {
    attach: function (context) {
      once('niftybot-auto-trade-realtime', '.niftybot-auto-trade--live', context).forEach(function (root) {
        bindControls();
        bindAiTabs(document);
        if (!settings.enabled) {
          return;
        }
        if (!growwTradingSupported) {
          setText('niftybot-auto-trade-message', growwUnsupportedMessage);
          setModeControls(false);
          setLiveStatus('error', Drupal.t('Unavailable'));
          return;
        }
        if (isIndexInstrument) {
          updateMarketHoursBanner(marketOpen, marketClosedMessage);
          setModeControls(false);
        }
        let cleanup = null;
        let suggestionsCleanup = null;
        if (useDrupalApi) {
          cleanup = connectPolling();
          if (settings.brokerConnected) {
            suggestionsCleanup = connectSuggestionsPolling();
          }
        }
        else {
          cleanup = connectWebSocket();
        }
        if (cleanup || suggestionsCleanup) {
          root.addEventListener('drupalDetach', function () {
            if (cleanup) {
              cleanup();
            }
            if (suggestionsCleanup) {
              suggestionsCleanup();
            }
          }, { once: true });
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
