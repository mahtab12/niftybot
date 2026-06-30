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

    setText('niftybot-auto-trade-ai-headline', suggestion.headline || '');
    setText('niftybot-auto-trade-ai-summary', suggestion.summary || '');
    setText('niftybot-auto-trade-ai-hint', suggestion.manual_hint || '');
    setText('niftybot-auto-trade-ai-disclaimer', suggestion.disclaimer || '');

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
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
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
    const modeInputs = document.querySelectorAll('input[name="niftybot-auto-trade-mode"]');
    const accessBlocked = settings.access && settings.access.can_access === false;
    if (activateBtn) {
      activateBtn.disabled = !!active || !!accessBlocked;
    }
    modeInputs.forEach(function (input) {
      input.disabled = !!active;
    });
    setQuantityControls(active);
  }

  function updateState(payload) {
    if (!payload) {
      return;
    }

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
    setText(
      'niftybot-auto-trade-signal-confidence',
      signal.confidence != null ? (Number(signal.confidence) * 100).toFixed(0) + '%' : '—'
    );
    setText('niftybot-auto-trade-oi', indicators.oi_buildup || '—');
    setText('niftybot-auto-trade-ema', indicators.ema_signal || '—');
    const rsiLabel = indicators.rsi_divergence || indicators.rsi;
    setText('niftybot-auto-trade-rsi', rsiLabel != null ? String(rsiLabel) : '—');
    const coreBull = indicators.core_setup_bull;
    const coreBear = indicators.core_setup_bear;
    let coreLabel = '—';
    if (coreBull) {
      coreLabel = 'OI+EMA+RSI → CE';
    } else if (coreBear) {
      coreLabel = 'OI+EMA+RSI → PE';
    } else if (indicators.oi_aligned_bull || indicators.oi_aligned_bear || indicators.ema_aligned_bull || indicators.ema_aligned_bear) {
      const parts = [];
      if (indicators.oi_aligned_bull || indicators.oi_aligned_bear) {
        parts.push('OI');
      } else {
        parts.push('OI ✗');
      }
      if (indicators.ema_aligned_bull || indicators.ema_aligned_bear) {
        parts.push('EMA');
      } else {
        parts.push('EMA ✗');
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
    if (indicators.volume_spike_bull) {
      volLabel = Drupal.t('Bullish spike');
    } else if (indicators.volume_spike_bear) {
      volLabel = Drupal.t('Bearish spike');
    }
    setText('niftybot-auto-trade-volume', volLabel);
    setText('niftybot-auto-trade-chain-oi', indicators.chain_oi_summary || '—');

    let advancedLabel = '—';
    if (indicators.advanced_bull) {
      advancedLabel = 'VWAP/Vol/Chain → CE';
    } else if (indicators.advanced_bear) {
      advancedLabel = 'VWAP/Vol/Chain → PE';
    } else {
      const advParts = [];
      if (indicators.vwap_bull || indicators.vwap_bear) {
        advParts.push('VWAP');
      } else {
        advParts.push('VWAP ✗');
      }
      if (indicators.volume_spike_bull || indicators.volume_spike_bear) {
        advParts.push('Vol');
      } else {
        advParts.push('Vol ✗');
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
          if (data.last_signal) {
            updateState({
              success: true,
              active: false,
              underlying_ltp: data.underlying_ltp,
              last_signal: data.last_signal,
              last_check_at: data.last_check_at,
            });
          }
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
      crude_oil_quantity: instrument === 'crude_oil' ? qty : (Number(quantitySettings.crude_oil_quantity) || 100),
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
        if (!settings.enabled) {
          return;
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
