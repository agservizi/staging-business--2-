(function(){"use strict";const g="cs_consent_v1",h="cs_consent",i={necessary:!0,preferences:!1,statistics:!1,marketing:!1,updatedAt:null,version:1},v=50,d=[];let c=!1;const r=(...e)=>{const t={ts:Date.now(),args:e};d.push(t),d.length>v&&d.shift(),c&&console.info("[CSConsent]",...e)},y=e=>{if(!e)return null;try{return JSON.parse(e)}catch{return null}},S=e=>{try{localStorage.setItem(g,JSON.stringify(e))}catch{}try{const t=btoa(JSON.stringify(e));document.cookie=`${h}=${t}; path=/; max-age=31536000; samesite=lax`}catch{}},C=e=>{const t=new CustomEvent("cs-consent-changed",{detail:e});window.dispatchEvent(t),r("dispatchChange",e)},a=(()=>{const e=(()=>{try{return localStorage.getItem(g)}catch{return null}})(),t=y(e);return!t||t.version!==i.version?{...i}:{...i,...t}})();r("loadState",a);const w=e=>{const t={...i,...e,updatedAt:Date.now()};window.CSConsent.state=t,r("saveState",t),S(t),C(t),p()},f=e=>e==="necessary"?!0:!!(window.CSConsent.state&&window.CSConsent.state[e]),p=()=>{document.querySelectorAll('script[type="text/plain"][data-cs-category]:not([data-cs-processed])').forEach(t=>{const s=t.getAttribute("data-cs-category");if(!f(s))return;const n=document.createElement("script");Array.from(t.attributes).forEach(o=>{o.name!=="type"&&n.setAttribute(o.name,o.value)}),n.text=t.text,t.setAttribute("data-cs-processed","1"),t.parentNode.insertBefore(n,t.nextSibling),r("script-activated",{category:s,src:n.src||"inline"})})},b=()=>{if(document.getElementById("cs-consent-banner"))return;const e=document.createElement("div");e.id="cs-consent-banner",e.innerHTML=`
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
    `;const t=e.querySelector('input[data-cat="preferences"]'),s=e.querySelector('input[data-cat="statistics"]'),n=e.querySelector('input[data-cat="marketing"]');t&&(t.checked=!!a.preferences),s&&(s.checked=!!a.statistics),n&&(n.checked=!!a.marketing);const o=l=>{w(l),e.remove()};e.addEventListener("click",l=>{const m=l.target;if(!(m instanceof HTMLElement))return;const u=m.getAttribute("data-action");if(u){if(l.preventDefault(),u==="accept"){o({preferences:!0,statistics:!0,marketing:!0});return}if(u==="reject"){o({preferences:!1,statistics:!1,marketing:!1});return}u==="save"&&o({preferences:t?t.checked:!1,statistics:s?s.checked:!1,marketing:n?n.checked:!1})}}),document.body.appendChild(e)};window.CSConsent={state:a,hasConsent:f,getLog:()=>[...d],setDebug:e=>{c=!!e,window.CSConsent.debug=c,r("debug",c)},onChange:e=>{typeof e=="function"&&window.addEventListener("cs-consent-changed",t=>e(t.detail))},run:(e,t)=>{if(typeof t!="function")return;if(f(e)){t();return}const s=n=>{n.detail&&n.detail[e]&&(window.removeEventListener("cs-consent-changed",s),t())};window.addEventListener("cs-consent-changed",s)}};try{c=localStorage.getItem("cs-consent-debug")==="1"}catch{}window.CSConsent&&window.CSConsent.debug===!0&&(c=!0),window.CSConsent.debug=c,a.updatedAt?document.readyState==="loading"?document.addEventListener("DOMContentLoaded",p):p():document.readyState==="loading"?document.addEventListener("DOMContentLoaded",b):b(),r("init-complete",a)})();
//# sourceMappingURL=cookie-consent.js.map
