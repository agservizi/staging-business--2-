(function () {
  'use strict';

  const STORAGE_KEY = 'cs_consent_v1';
  const COOKIE_NAME = 'cs_consent';
  const ONE_YEAR = 60 * 60 * 24 * 365;
  const DEFAULT_STATE = {
    necessary: true,
    preferences: false,
    statistics: false,
    marketing: false,
    updatedAt: null,
    version: 1,
  };

  const LOG_LIMIT = 50;
  const logs = [];
  let debug = false;

  const log = (...args) => {
    const entry = { ts: Date.now(), args };
    logs.push(entry);
    if (logs.length > LOG_LIMIT) logs.shift();
    if (debug) {
      // eslint-disable-next-line no-console
      console.info('[CSConsent]', ...args);
    }
  };

  const safeParse = (raw) => {
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  };

  const persist = (state) => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (e) {
      /* ignore */
    }
    try {
      const encoded = btoa(JSON.stringify(state));
      document.cookie = `${COOKIE_NAME}=${encoded}; path=/; max-age=${ONE_YEAR}; samesite=lax`;
    } catch (e) {
      /* ignore */
    }
  };

  const dispatchChange = (state) => {
    const event = new CustomEvent('cs-consent-changed', { detail: state });
    window.dispatchEvent(event);
    log('dispatchChange', state);
  };

  const loadState = () => {
    const raw = (() => {
      try {
        return localStorage.getItem(STORAGE_KEY);
      } catch (e) {
        return null;
      }
    })();
    const parsed = safeParse(raw);
    if (!parsed || parsed.version !== DEFAULT_STATE.version) {
      return { ...DEFAULT_STATE };
    }
    return { ...DEFAULT_STATE, ...parsed };
  };

  const state = loadState();
  log('loadState', state);

  const saveState = (next) => {
    const merged = { ...DEFAULT_STATE, ...next, updatedAt: Date.now() };
    window.CSConsent.state = merged;
    log('saveState', merged);
    persist(merged);
    dispatchChange(merged);
    processDeferredScripts();
  };

  const hasConsent = (category) => {
    if (category === 'necessary') return true;
    return !!(window.CSConsent.state && window.CSConsent.state[category]);
  };

  const processDeferredScripts = () => {
    const scripts = document.querySelectorAll('script[type="text/plain"][data-cs-category]:not([data-cs-processed])');
    scripts.forEach((script) => {
      const category = script.getAttribute('data-cs-category');
      if (!hasConsent(category)) return;

      const clone = document.createElement('script');
      Array.from(script.attributes).forEach((attr) => {
        if (attr.name === 'type') return;
        clone.setAttribute(attr.name, attr.value);
      });
      clone.text = script.text;
      script.setAttribute('data-cs-processed', '1');
      script.parentNode.insertBefore(clone, script.nextSibling);
      log('script-activated', { category, src: clone.src || 'inline' });
    });
  };

  const createBanner = () => {
    if (document.getElementById('cs-consent-banner')) return;

    const banner = document.createElement('div');
    banner.id = 'cs-consent-banner';
    banner.innerHTML = `
      <div class="cs-consent-inner">
        <div class="cs-consent-text">
          <h3>Gestione cookie</h3>
          <p>Usiamo cookie per garantire il funzionamento del CRM, salvare preferenze e migliorare i servizi. Puoi scegliere per categoria.</p>
        </div>
        <div class="cs-consent-actions">
          <div class="cs-consent-options">
            <label><input type="checkbox" disabled checked> Necessari</label>
            <label><input type="checkbox" data-cat="preferences"> Preferenze</label>
            <label><input type="checkbox" data-cat="statistics"> Statistici</label>
            <label><input type="checkbox" data-cat="marketing"> Marketing</label>
          </div>
          <div class="cs-consent-buttons">
            <button type="button" class="btn-secondary" data-action="reject">Rifiuta non essenziali</button>
            <button type="button" class="btn-secondary" data-action="save">Salva preferenze</button>
            <button type="button" class="btn-primary" data-action="accept">Accetta tutto</button>
          </div>
        </div>
      </div>
    `;

    const prefs = banner.querySelector('input[data-cat="preferences"]');
    const stats = banner.querySelector('input[data-cat="statistics"]');
    const marketing = banner.querySelector('input[data-cat="marketing"]');
    if (prefs) prefs.checked = !!state.preferences;
    if (stats) stats.checked = !!state.statistics;
    if (marketing) marketing.checked = !!state.marketing;

    const applyChoice = (nextState) => {
      saveState(nextState);
      banner.remove();
    };

    banner.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      const action = target.getAttribute('data-action');
      if (!action) return;
      event.preventDefault();

      if (action === 'accept') {
        applyChoice({ preferences: true, statistics: true, marketing: true });
        return;
      }
      if (action === 'reject') {
        applyChoice({ preferences: false, statistics: false, marketing: false });
        return;
      }
      if (action === 'save') {
        applyChoice({
          preferences: prefs ? prefs.checked : false,
          statistics: stats ? stats.checked : false,
          marketing: marketing ? marketing.checked : false,
        });
      }
    });

    document.body.appendChild(banner);
  };

  window.CSConsent = {
    state,
    hasConsent,
    getLog: () => [...logs],
    setDebug: (value) => {
      debug = !!value;
      window.CSConsent.debug = debug;
      log('debug', debug);
    },
    onChange: (cb) => {
      if (typeof cb === 'function') {
        window.addEventListener('cs-consent-changed', (e) => cb(e.detail));
      }
    },
    run: (category, fn) => {
      if (typeof fn !== 'function') return;
      if (hasConsent(category)) {
        fn();
        return;
      }
      const handler = (e) => {
        if (e.detail && e.detail[category]) {
          window.removeEventListener('cs-consent-changed', handler);
          fn();
        }
      };
      window.addEventListener('cs-consent-changed', handler);
    },
  };

  try {
    const storedDebug = localStorage.getItem('cs-consent-debug');
    debug = storedDebug === '1';
  } catch (e) {
    /* ignore */
  }
  if (window.CSConsent && window.CSConsent.debug === true) {
    debug = true;
  }
  window.CSConsent.debug = debug;

  // If no prior decision, show banner
  if (!state.updatedAt) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', createBanner);
    } else {
      createBanner();
    }
  } else {
    // Apply consent to deferred scripts immediately
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', processDeferredScripts);
    } else {
      processDeferredScripts();
    }
  }

  log('init-complete', state);
})();
