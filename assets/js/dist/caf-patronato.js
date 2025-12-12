const V=window.CAFPatronatoHelpers||{},m=V.escapeHtml||(t=>String(t!=null?t:"")),N=V.formatDateTime||(t=>String(t!=null?t:"")),kt=V.formatBytes||(t=>`${t||0} B`),H=V.coerceBoolean||(t=>t===!0||t==="true"||t===1||t==="1"),Tt=V.isValidEmail||(t=>/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(t||"").trim())),oe=V.resolveDocumentUrl||(t=>{if(typeof t!="string")return"#";let a=t.trim();if(!a)return"#";if(a=a.replace(/\\/g,"/"),/^[a-z][a-z0-9+.-]*:\/\//i.test(a))return a;const e=typeof window!="undefined"&&window.CS&&typeof window.CS.assetsBaseUrl=="string"?window.CS.assetsBaseUrl.replace(/\\/g,"/").replace(/\/+$/,"/"):null;return e&&a.startsWith("assets/")?e+a.slice(7):a.startsWith("/")?a:e?e+a:`/${a}`}),ft="assets/uploads/caf-patronato";function ie(t,a){if(typeof t!="string")return"";let e=t.trim().replace(/\\/g,"/");if(!e)return"";if(/^[a-z][a-z0-9+.-]*:\/\//i.test(e))return e;for(e=e.replace(/^\.\/+/u,"");e.startsWith("../");)e=e.slice(3);if(e.startsWith("/")||e.startsWith("assets/"))return e;if(e.startsWith("uploads/"))return`${ft}/${e.slice(8)}`;const o=a?String(a).trim():"";return o?`${ft}/${o}/${e}`:`${ft}/${e}`}function pt(t,a){if(!t||typeof t!="object")return"#";const e=typeof t.download_url=="string"&&t.download_url.trim()!==""?t.download_url:t.file_path,o=ie(e||"",a);return oe(o||e||"")}let st="/api/caf-patronato/index.php",$={canConfigureModule:!1,canManagePractices:!1,canCreatePractices:!1,isPatronato:!1,operatorId:null,useLegacyCreate:!1,createUrl:"create.php",trackingBaseUrl:""};const x={statuses:null,types:null,operators:null},D={};let gt=new Set,rt=!1;function M(t){return typeof t!="string"?"":t.trim().toUpperCase()}function At(t){if(typeof t!="string")return"";const a=t.trim();return a?a.replace(/[^a-z0-9#(),.%\s-]/gi," ").replace(/\s+/g," ").slice(0,64).trim():""}function G(t){const a=M(t);return a&&Object.prototype.hasOwnProperty.call(D,a)?D[a]:{label:typeof t=="string"&&t.trim()!==""?t.trim():"Sconosciuto",color:""}}const ne=["warning","info","light","soft","yellow","accent","white","muted","gray","grey","sand","beige","cream","silver","pastel","peach","sky","mint","lime"],Ke={slate:"#475569",gray:"#6b7280",zinc:"#71717a",neutral:"#737373",stone:"#78716c",red:"#dc2626",orange:"#f97316",amber:"#f59e0b",yellow:"#eab308",lime:"#84cc16",green:"#16a34a",emerald:"#059669",teal:"#0d9488",cyan:"#06b6d4",sky:"#0ea5e9",blue:"#2563eb",indigo:"#4f46e5",violet:"#7c3aed",purple:"#8b5cf6",fuchsia:"#d946ef",pink:"#ec4899",rose:"#f43f5e",bronze:"#b45309",gold:"#d97706",silver:"#94a3b8",platinum:"#d1d5db"};function ta(t){if(typeof t!="string")return!1;const a=t.trim().toLowerCase();return a?a.startsWith("soft-")?!0:ne.some(e=>a.includes(e)):!1}function se(t){let a=t.replace("#","").trim();if(a.length===3&&(a=a.split("").map(s=>s+s).join("")),a.length!==6&&a.length!==8)return null;const e=parseInt(a.slice(0,2),16),o=parseInt(a.slice(2,4),16),i=parseInt(a.slice(4,6),16);return[e,o,i].some(s=>Number.isNaN(s))?null:{r:e,g:o,b:i}}function re(t){const a=t.match(/^rgba?\(([^)]+)\)$/i);if(!a)return null;const e=a[1].split(",").map(c=>c.trim());if(e.length<3)return null;const[o,i,s]=e,n=c=>{if(c.endsWith("%")){const u=parseFloat(c.slice(0,-1));return Number.isNaN(u)?null:Math.round(Math.min(Math.max(u,0),100)*2.55)}const p=parseFloat(c);return Number.isNaN(p)?null:Math.min(Math.max(Math.round(p),0),255)},l=n(o),r=n(i),d=n(s);return[l,r,d].some(c=>c===null)?null:{r:l,g:r,b:d}}function ea({r:t,g:a,b:e}){const o=i=>i.toString(16).padStart(2,"0");return`#${o(t)}${o(a)}${o(e)}`}function le({r:t,g:a,b:e}){return .299*t+.587*a+.114*e>155}function lt(t){if(!t)return"";if(t.style)return t.style;const a=t.accentColor;if(!a)return"";let e=null;typeof a=="string"&&(a.trim().startsWith("#")?e=se(a.trim()):a.toLowerCase().startsWith("rgb")&&(e=re(a.trim())));const o=e&&le(e)?"#212529":"#ffffff";return`background-color: ${a}; color: ${o};`}function K(){return{className:"bg-secondary",textClass:"text-white",style:"",accentColor:null,rawColor:null}}const b={root:null,instance:null,title:null,body:null,footer:null,confirmRoot:null,confirmInstance:null,confirmBody:null,confirmAction:null};let B=null,ct=[],W=null,It={},tt=1,bt=null;const ce=6e4;document.addEventListener("DOMContentLoaded",()=>{const t=document.getElementById("caf-patronato-context");if(t){let e=t.dataset.operatorId?parseInt(t.dataset.operatorId,10):null;Number.isNaN(e)&&(e=null),$={...$,canConfigureModule:t.dataset.canConfigure==="1",canManagePractices:t.dataset.canManagePractices==="1",canCreatePractices:t.dataset.canCreatePractices==="1",isPatronato:t.dataset.isPatronato==="1",operatorId:e,useLegacyCreate:t.dataset.useLegacyCreate==="1",createUrl:t.dataset.createUrl||$.createUrl,trackingBaseUrl:t.dataset.trackingBaseUrl||$.trackingBaseUrl},t.dataset.apiBase&&(st=t.dataset.apiBase)}Fe();const a=de();a.has("dashboard")&&Se(),a.has("practices")&&xe(),a.has("practice-view")&&Ee(),a.has("practice-status")&&Ae(),a.has("practice-edit")&&ke(),a.has("admin")&&Pe(),a.has("operators")&&Me(),_t(),ue(),Bt()});function de(){const t=new Set;return document.getElementById("caf-patronato-dashboard")&&t.add("dashboard"),document.getElementById("caf-patronato-practices")&&t.add("practices"),document.getElementById("caf-patronato-practice-view")&&t.add("practice-view"),document.getElementById("caf-patronato-practice-status")&&t.add("practice-status"),document.getElementById("caf-patronato-practice-edit")&&t.add("practice-edit"),document.getElementById("caf-patronato-admin")&&t.add("admin"),document.getElementById("caf-patronato-operators")&&t.add("operators"),t}function ue(){const t=document.getElementById("new-practice-inline");t&&($.canCreatePractices?$.useLegacyCreate?$.createUrl&&t.setAttribute("href",$.createUrl):t.addEventListener("click",a=>{a.preventDefault(),Ct()}):t.classList.add("d-none")),document.querySelectorAll("[data-open-notifications]").forEach(a=>{a.addEventListener("click",e=>{e.preventDefault(),be()})}),document.querySelectorAll("[data-open-practice-recap]").forEach(a=>{a.addEventListener("click",e=>{e.preventDefault(),ge()})})}function _t(){const t=document.querySelectorAll("[data-quick-filter]");if(!t.length)return;let a=null;t.forEach(e=>{e.dataset.quickFilterBound!=="1"&&(e.dataset.quickFilterBound="1",e.addEventListener("click",o=>{o.preventDefault();const i=e.dataset.quickFilter||null;if(!i)return;let s={};const n=e.dataset.filters;if(n)try{s=JSON.parse(n)}catch(l){console.warn("Impossibile analizzare i filtri rapidi",l)}me(s,i)}),e.classList.contains("active")&&a===null&&(a=e.dataset.quickFilter||null))}),a&&dt(a)}function me(t,a){const e=document.getElementById("practices-filters-form");if(!e)return;const o=["per_page"],i={};o.forEach(s=>{const n=e.elements.namedItem(s);n&&"value"in n&&(i[s]=n.value)}),e.reset(),Xe(e,t),o.forEach(s=>{if(!(s in t)&&i[s]!==void 0){const n=e.elements.namedItem(s);n&&"value"in n&&(n.value=i[s])}}),W=a,dt(a),P()}function dt(t){const a=document.querySelectorAll("[data-quick-filter]");a.forEach(o=>{const i=o.dataset.quickFilter===t;o.classList.toggle("active",i),i?o.setAttribute("aria-current","true"):o.removeAttribute("aria-current")}),W=t;const e=document.getElementById("active-quick-filter");if(e){const o=Array.from(a).find(s=>s.dataset.quickFilter===t);!!(t&&t!=="all"&&o)?(e.textContent=o.dataset.quickFilterLabel||o.textContent.trim(),e.style.display="inline-flex"):e.style.display="none"}}function Nt(t={}){const{per_stato:a={}}=t,e={};Object.entries(a).forEach(([i,s])=>{const n=M(i);n&&(e[n]=s)}),document.querySelectorAll("[data-quick-filter]").forEach(i=>{var d;const s=i.dataset.quickFilterStatus;if(!s)return;const n=M(s),l=n&&(d=e[n])!=null?d:0,r=i.querySelector("[data-quick-filter-count]");r&&(r.textContent=String(l),r.classList.toggle("d-none",!1))})}function zt(t={}){var e,o;J("summary-total",(e=t.totale)!=null?e:0),J("summary-total-badge",(o=t.totale)!=null?o:0);const a=t.per_stato||{};Object.entries(a).forEach(([i,s])=>{var d;const n=G(i),l=K(n.color),r=pe(i,n,l);if(r){const c=r.closest(".hero-kpi-body"),p=c==null?void 0:c.querySelector(".hero-kpi-label");p&&(p.textContent=n.label);const u=(d=r.closest(".hero-kpi"))==null?void 0:d.querySelector(".hero-kpi-icon");u&&Lt(u,l),J(r.id,s)}})}function fe(t){if(t==null)return null;const a=M(t),e=typeof t=="string"?t.trim():String(t),o=[];a&&(o.push(`summary-status-${a}`),o.push(`summary-status-${a.toLowerCase()}`),o.push(`summary-status-${a.toLowerCase().replace(/[^a-z0-9]+/g,"_")}`)),e!==""&&(o.push(`summary-status-${e}`),o.push(`summary-status-${e.toLowerCase().replace(/[^a-z0-9]+/g,"_")}`));for(const i of o){const s=document.getElementById(i);if(s)return s}return null}function Lt(t,a){if(!(!t||!a))if(a.className){const e=a.className.startsWith("bg-")?a.className.slice(3):a.className,o=e.startsWith("soft-")?e.slice(5):e;t.className=`hero-kpi-icon hero-kpi-icon-services text-${o}`,t.style.color=""}else a.accentColor?(t.className="hero-kpi-icon hero-kpi-icon-services",t.style.color=a.accentColor):(t.className="hero-kpi-icon hero-kpi-icon-services text-secondary",t.style.color="")}function pe(t,a,e){const o=fe(t);if(o)return o;const i=typeof t=="string"?t.trim():String(t!=null?t:""),s=M(t)||i||"UNKNOWN",n=(s.toLowerCase()||"unknown").replace(/[^a-z0-9]+/g,"_")||"unknown",l=document.getElementById("hero-status-grid");if(!l)return null;const r=document.createElement("div");r.className="hero-kpi",r.dataset.heroStatus=s,r.innerHTML=`
        <div class="hero-kpi-icon hero-kpi-icon-services"><i class="fa-solid fa-circle"></i></div>
        <div class="hero-kpi-body">
            <span class="hero-kpi-label">${m(a.label)}</span>
            <span class="hero-kpi-value" id="summary-status-${n}">0</span>
        </div>
    `,l.appendChild(r);const d=r.querySelector(`#summary-status-${n}`),c=r.querySelector(".hero-kpi-icon");return c&&Lt(c,e),d}function J(t,a){const e=document.getElementById(t);e&&(e.textContent=typeof a=="number"?a.toString():String(a!=null?a:"0"))}function ge(){var n,l,r,d,c;const t=((l=(n=document.getElementById("summary-total"))==null?void 0:n.textContent)==null?void 0:l.trim())||"0",a=Object.entries(D).map(([p,u])=>{var g,y;const f=((y=(g=document.getElementById(`summary-status-${p}`))==null?void 0:g.textContent)==null?void 0:y.trim())||"0";return{code:p,label:u.label||p,value:f}});let e="";a.length>0?e=a.map(p=>`
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>${m(p.label)}</span>
                <span class="badge bg-secondary">${m(p.value)}</span>
            </li>`).join(""):e='<li class="list-group-item text-muted">Nessun dato di stato disponibile.</li>';const o=((r=document.getElementById("active-filters-list"))==null?void 0:r.innerHTML)||"",i=((c=(d=document.getElementById("active-quick-filter"))==null?void 0:d.textContent)==null?void 0:c.trim())||"",s=`
        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted">Totale pratiche</span>
                <span class="display-6 fw-semibold">${m(t)}</span>
            </div>
        </div>
        <div class="mb-3">
            <h6 class="text-uppercase text-muted fw-semibold small">Pratiche per stato</h6>
            <ul class="list-group list-group-flush">
                ${e}
            </ul>
        </div>
        <div>
            <h6 class="text-uppercase text-muted fw-semibold small">Filtri correnti</h6>
            <div class="d-flex flex-wrap gap-2">
                ${i?`<span class="badge bg-warning text-dark"><i class="fa-solid fa-bolt me-1"></i>${m(i)}</span>`:""}
                ${o||'<span class="text-muted small">Nessun filtro attivo</span>'}
            </div>
        </div>
    `;q({title:"Riepilogo pratiche",body:s,footer:"",size:"md"})}async function be(){try{const a=(await E("GET",{action:"list_notifications",show_read:1})).data.notifications||[],e=qt(a,{showActions:!1,compact:!1,emptyMessage:"Nessuna notifica disponibile."});q({title:"Notifiche CAF & Patronato",body:e,footer:"",size:"lg"})}catch(t){q({title:"Notifiche CAF & Patronato",body:`<div class="alert alert-danger" role="alert">${m(t.message||"Errore nel caricamento delle notifiche.")}</div>`,footer:""})}}function ve(t){return G(t).label}function ye(t){if(!x.types)return`Tipo #${t}`;const a=x.types.find(e=>String(e.id)===String(t));return a?a.nome:`Tipo #${t}`}function he(t){var e,o;if(!x.operators)return`Operatore #${t}`;const a=x.operators.find(i=>String(i.id)===String(t));return a?`${(e=a.nome)!=null?e:""} ${(o=a.cognome)!=null?o:""}`.trim()||`Operatore #${t}`:`Operatore #${t}`}function Pt(t){if(!t)return"";if(/^\d{4}-\d{2}-\d{2}$/.test(t)){const[a,e,o]=t.split("-");return`${o}/${e}/${a}`}return t}function Bt(){ut({target:"preview",limit:5,compact:!0})}async function ut(t={}){const{target:a="admin",showRead:e=!1,limit:o=5,compact:i=!1}=t,s=a==="admin"?"notifications-list":"notifications-preview",n=document.getElementById(s);if(n){a==="admin"?F(n):n.innerHTML='<div class="small text-muted">Caricamento notifiche...</div>';try{const l={action:"list_notifications"};e&&(l.show_read=1);const r=await E("GET",l),d=Array.isArray(r.data.notifications)?r.data.notifications:[];let c=d;a==="preview"&&o>0&&(c=d.slice(0,o)),n.innerHTML=qt(c,{showActions:a==="admin",compact:a==="preview"||i,emptyMessage:a==="admin"?"Nessuna notifica registrata.":"Nessuna notifica recente."}),ae(d)}catch(l){n.innerHTML=`<div class="alert alert-danger" role="alert">${m(l.message||"Errore nel caricamento delle notifiche.")}</div>`,ae([])}}}function qt(t,{showActions:a=!1,compact:e=!1,emptyMessage:o="Nessuna notifica disponibile."}={}){return!Array.isArray(t)||t.length===0?`<div class="${e?"small ":""}text-muted py-3">${m(o)}</div>`:`<div class="list-group list-group-flush">${t.map(s=>{const n=s.stato!=="letta",l=n?"badge bg-warning text-dark":"badge bg-secondary",r=s.pratica_id?`view.php?id=${s.pratica_id}`:"",d=s.pratica_id?`<a href="${r}" class="badge bg-primary-subtle text-primary ms-2"><i class="fa-solid fa-folder-open me-1"></i>#${s.pratica_id}</a>`:"",c=[];a&&s.stato!=="letta"&&c.push(`<button type="button" class="btn btn-link btn-sm text-success" data-action="mark-notification" data-notification-id="${s.id}"><i class="fa-solid fa-circle-check me-1"></i>Segna letta</button>`),a&&s.pratica_id&&c.push(`<a class="btn btn-link btn-sm" href="view.php?id=${s.pratica_id}"><i class="fa-solid fa-up-right-from-square me-1"></i>Apri pratica</a>`);const p=c.length?`<div class="mt-2 d-flex flex-wrap gap-2">${c.join("")}</div>`:"",u=`<div class="small text-muted mt-1"><i class="fa-regular fa-clock me-1"></i>${m(N(s.created_at))}</div>`;return`
            <div class="list-group-item py-3" data-notification-id="${s.id}">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="${l}">${m(s.tipo)}</span>
                            ${d}
                        </div>
                        <div class="fw-semibold mt-2">${m(s.messaggio)}</div>
                        ${u}
                        ${p}
                    </div>
                    ${n?'<span class="text-warning"><i class="fa-solid fa-circle" aria-hidden="true"></i><span class="visually-hidden">Nuova</span></span>':""}
                </div>
            </div>
        `}).join("")}</div>`}function we(t){const a=t.target.closest("[data-action]");if(!a)return;const e=parseInt(a.dataset.notificationId||"",10);if(!(Number.isNaN(e)||e<=0))switch(a.dataset.action){case"mark-notification":t.preventDefault(),$e(e);break;default:break}}async function $e(t){var a,e,o,i;try{await E("POST",{action:"mark_notification",id:t}),(a=window.CS)!=null&&a.showToast&&window.CS.showToast("Notifica segnata come letta.","success")}catch(s){(e=window.CS)!=null&&e.showToast&&window.CS.showToast(s.message||"Errore nel marcare la notifica come letta.","error")}finally{ut({target:"admin",showRead:(i=(o=document.getElementById("show-read-notifications"))==null?void 0:o.checked)!=null?i:!1}),Bt()}}function Se(){vt();const t=document.getElementById("refresh-summary");t&&t.addEventListener("click",vt);const a=document.getElementById("new-practice-link");a&&($.useLegacyCreate?$.createUrl&&a.setAttribute("href",$.createUrl):a.addEventListener("click",e=>{e.preventDefault(),Ct()}))}async function vt(){const t=document.getElementById("practices-summary-container");t&&F(t);try{const a=await E("GET",{action:"list_practices",per_page:1}),{summaries:e}=a.data;zt(e),Nt(e),t&&Ce(t,e)}catch(a){t&&L(t,"Errore nel caricamento del riepilogo: "+a.message)}}function Ce(t,a){const{totale:e,per_stato:o}=a,i={in_lavorazione:"primary",completata:"success",sospesa:"warning",archiviata:"secondary"},s={in_lavorazione:"In lavorazione",completata:"Completata",sospesa:"Sospesa",archiviata:"Archiviata"};let n=`
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                        <i class="fa-solid fa-folder-open"></i>
                    </div>
                    <div>
                        <div class="h5 mb-0">${e}</div>
                        <small class="text-muted">Pratiche totali</small>
                    </div>
                </div>
            </div>
    `;Object.entries(o).forEach(([l,r])=>{const d=i[l]||"secondary",c=s[l]||l;n+=`
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-${d} bg-opacity-10 text-${d} me-3">
                        <i class="fa-solid fa-circle"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">${r}</div>
                        <small class="text-muted">${c}</small>
                    </div>
                </div>
            </div>
        `}),n+="</div>",e>0?n+=`
            <div class="d-flex justify-content-center">
                <a href="index.php?page=practices" class="btn btn-outline-primary">
                    <i class="fa-solid fa-arrow-right me-2"></i>Visualizza tutte le pratiche
                </a>
            </div>
        `:n+=`
            <div class="text-center text-muted py-3">
                <i class="fa-solid fa-folder-open fa-2x mb-3 opacity-50"></i>
                <p>Nessuna pratica presente nel sistema.</p>
            </div>
        `,t.innerHTML=n}function xe(){ht(),P(),_t();const t=document.getElementById("practices-filters-form");t&&t.addEventListener("submit",i=>{i.preventDefault(),dt(null),P()});const a=document.getElementById("clear-filters");a&&a.addEventListener("click",()=>{t&&t.reset(),dt(null),P()});const e=document.getElementById("refresh-practices");e&&e.addEventListener("click",()=>{P(tt),Mt()});const o=document.getElementById("create-practice-btn");o&&($.useLegacyCreate?$.createUrl&&o.setAttribute("href",$.createUrl):o.addEventListener("click",i=>{i.preventDefault(),Ct()}))}function Mt(){bt&&clearInterval(bt),document.getElementById("practices-table-container")&&(bt=window.setInterval(()=>{P(tt,{silent:!0})},ce))}function Ee(){const t=document.getElementById("caf-patronato-practice-view");if(!t)return;const a=t.dataset.practiceId||"",e=parseInt(a,10);if(Number.isNaN(e)||e<=0){L(t,"ID pratica non valido.");return}const o=document.getElementById("page-edit-practice");o&&o.addEventListener("click",()=>Rt(e));const i=document.getElementById("page-refresh-practice");i&&i.addEventListener("click",()=>Ot(t,e)),Ot(t,e)}async function Ot(t,a){try{F(t);const[e,o]=await Promise.all([E("GET",{action:"get_practice",id:a}),O()]);B=e.data,Ft(B);const i=Array.isArray(o)&&o.length?o:x.statuses||[];t.innerHTML=Gt(B,i),Wt(t,B,i,{mode:"page",container:t})}catch(e){console.error("Errore nel caricamento pratica:",e),L(t,"Errore nel caricamento della pratica: "+e.message)}}function ke(){const t=document.getElementById("caf-patronato-practice-edit");if(!t)return;const a=t.dataset.practiceId||"",e=parseInt(a,10);if(Number.isNaN(e)||e<=0){L(t,"ID pratica non valido.");return}Te(t,e)}async function Te(t,a){try{F(t);const[e,o,i,s]=await Promise.all([E("GET",{action:"get_practice",id:a}),X(!0),O(!0),et(!0)]),n=e.data;B=n;const l=document.getElementById("practice-edit-title");l&&(l.textContent=n.titolo||`Pratica #${n.id}`),n.titolo&&(document.title=`Modifica pratica: ${n.titolo}`);const r=document.getElementById("practice-edit-code");r&&(r.textContent=`#${n.id}`);const d=document.getElementById("practice-edit-status");if(d){const u=G(n.stato);d.textContent=u.label;const f=K(u.color),g=["badge"];f.className&&g.push(f.className),f.className||g.push("bg-secondary"),f.textClass&&g.push(f.textClass),!f.textClass&&!f.style&&!f.accentColor&&g.push("text-white"),d.className=g.join(" ");const y=lt(f);y?d.setAttribute("style",y):d.removeAttribute("style")}const c=document.getElementById("practice-edit-category");if(c){const u=n.categoria||"CAF",f=u.toUpperCase()==="PATRONATO";c.textContent=u,c.className=`badge ${f?"bg-warning text-dark":"bg-info"}`}const p=document.getElementById("practice-edit-operator");if(p)if(n.assegnatario){const u=n.assegnatario.nome||"",f=n.assegnatario.cognome||"",g=`${u} ${f}`.trim();p.textContent=g||n.assegnatario.email||"N/D"}else p.textContent="Non assegnata";if(n.scadenza){const u=document.getElementById("practice-edit-deadline");if(u){u.textContent=N(n.scadenza);const f=u.closest('[data-role="practice-deadline"]');f&&f.classList.remove("d-none")}}if(n.data_aggiornamento){const u=document.getElementById("practice-edit-updated");u&&(u.textContent=N(n.data_aggiornamento))}if(n.data_creazione){const u=document.getElementById("practice-edit-created");u&&(u.textContent=N(n.data_creazione))}t.innerHTML=`
            <div class="card ag-card">
                <div class="card-body">
                    ${jt({mode:"edit",types:o,statuses:i,operators:s,practice:n})}
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a class="btn btn-outline-secondary" href="view.php?id=${encodeURIComponent(a)}">Annulla</a>
                        <button type="submit" form="caf-patronato-modal-form" id="caf-patronato-submit" class="btn btn-primary">Salva modifiche</button>
                    </div>
                </div>
            </div>
        `,Ht(t,{mode:"edit",types:o,statuses:i,operators:s,practice:n,submitButtonId:"caf-patronato-submit",onSuccess:({response:u})=>{var g;const f=((g=u==null?void 0:u.data)==null?void 0:g.id)||n.id;window.location.href=`view.php?id=${encodeURIComponent(f)}`}})}catch(e){console.error("Errore caricamento editor pratica:",e),L(t,"Impossibile caricare i dati della pratica: "+e.message)}}function Ae(){const t=document.getElementById("caf-patronato-practice-status");if(!t)return;const a=t.dataset.practiceId||"",e=parseInt(a,10);if(Number.isNaN(e)||e<=0){L(t,"ID pratica non valido.");return}yt(t,e)}async function yt(t,a){try{F(t);const[e,o]=await Promise.all([E("GET",{action:"get_practice",id:a}),O()]),i=e.data;B=i,Ie(i),t.innerHTML=_e(i,o),Ne(t,a)}catch(e){console.error("Errore caricamento pagina stato:",e),L(t,"Impossibile caricare i dati della pratica: "+e.message)}}function Ie(t){t.titolo&&(document.title=`Cambia stato: ${t.titolo}`);const a=document.getElementById("practice-status-title");a&&(a.textContent=t.titolo||`Pratica #${t.id}`);const e=document.getElementById("practice-status-code");e&&(e.textContent=`#${t.id}`);const o=document.getElementById("practice-status-badge");if(o){const r=G(t.stato),d=K(r.color),c=["badge"];d.className&&c.push(d.className),d.className||c.push("bg-secondary"),d.textClass&&c.push(d.textClass),!d.textClass&&!d.style&&!d.accentColor&&c.push("text-white"),o.textContent=r.label||t.stato,o.className=c.join(" ");const p=lt(d);p?o.setAttribute("style",p):o.removeAttribute("style")}const i=document.getElementById("practice-status-category");if(i){const r=t.categoria||"",d=r.toUpperCase()==="PATRONATO";i.textContent=r||"N/D",i.className=`badge ${d?"bg-warning text-dark":"bg-info"}`}const s=document.getElementById("practice-status-operator");if(s)if(t.assegnatario){const r=t.assegnatario.nome||"",d=t.assegnatario.cognome||"",c=`${r} ${d}`.trim();s.textContent=c||t.assegnatario.email||"N/D"}else s.textContent="Non assegnata";const n=document.getElementById("practice-status-updated");n&&(n.textContent=t.data_aggiornamento?N(t.data_aggiornamento):"N/D");const l=document.getElementById("practice-status-created");l&&(l.textContent=t.data_creazione?N(t.data_creazione):"N/D")}function _e(t,a){const e=$.canManagePractices,o=M(t.stato),i=a.map(d=>{const c=M(d.codice),p=c?c===o:d.codice===t.stato;return`
        <option value="${m(d.codice)}" ${p?"selected":""}>${m(d.nome)}</option>
    `}).join(""),s=e?`
        <form id="practice-status-update-form" class="row g-3 align-items-end">
            <div class="col-md-6 col-lg-4">
                <label class="form-label" for="practice-status-select">Nuovo stato</label>
                <select class="form-select" id="practice-status-select" name="status" required>
                    ${i}
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <button class="btn btn-primary w-100" type="submit">Aggiorna stato</button>
            </div>
        </form>
    `:`
        <div class="alert alert-secondary" role="alert">
            Solo gli operatori autorizzati possono aggiornare lo stato della pratica.
        </div>
    `,n=e?`
        <form id="practice-status-upload-form" class="row g-3 align-items-end" enctype="multipart/form-data">
            <div class="col-md-6 col-lg-5">
                <label class="form-label" for="practice-status-document">Carica documento elaborato</label>
                <input class="form-control" type="file" id="practice-status-document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
            </div>
            <div class="col-md-3 col-lg-2">
                <button class="btn btn-outline-primary w-100" type="submit">Carica file</button>
            </div>
        </form>
        <p class="small text-muted mb-0">Il documento sar\xE0 disponibile nell'elenco pratiche per il download.</p>
    `:`
        <div class="alert alert-secondary" role="alert">
            Solo gli operatori autorizzati possono caricare documenti elaborati.
        </div>
    `,l=Array.isArray(t.documenti)&&t.documenti.length?t.documenti:Array.isArray(t.allegati)?t.allegati:[],r=l.length?l.map(d=>{const c=pt(d,t.id);return`
        <div class="list-group-item d-flex justify-content-between align-items-start" data-document-id="${d.id}">
            <div>
                <div class="fw-semibold">${m(d.file_name)}</div>
                <div class="text-muted small">${kt(d.file_size)} \xB7 ${m(d.mime_type||"")} \xB7 ${N(d.created_at)}</div>
            </div>
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-primary" href="${m(c)}" target="_blank" rel="noopener noreferrer" title="Scarica">
                    <i class="fa-solid fa-download"></i>
                </a>
            </div>
        </div>
    `}).join(""):'<div class="list-group-item text-muted">Nessun documento caricato.</div>';return`
        <div class="card ag-card">
            <div class="card-body">
                <section class="mb-4">
                    <h5 class="mb-3">Aggiorna stato</h5>
                    ${s}
                </section>
                <hr>
                <section>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Documento pratica</h5>
                        <span class="badge bg-secondary">${l.length}</span>
                    </div>
                    ${n}
                    <div class="list-group mt-3" data-role="attachments-list">
                        ${r}
                    </div>
                </section>
            </div>
        </div>
    `}function Ne(t,a){const e=t.querySelector("#practice-status-update-form");e&&e.addEventListener("submit",async i=>{var r,d,c,p,u,f;i.preventDefault();const s=e.querySelector('select[name="status"]');if(!s||!s.value){(d=(r=window.CS)==null?void 0:r.showToast)==null||d.call(r,"Seleziona uno stato valido.","warning");return}const n=e.querySelector('button[type="submit"]');n&&(n.disabled=!0,n.dataset.originalText=n.dataset.originalText||n.textContent,n.textContent="Aggiornamento...");let l=!1;try{await E("POST",{action:"update_status",id:a,status:s.value}),l=!0,(p=(c=window.CS)==null?void 0:c.showToast)==null||p.call(c,"Stato pratica aggiornato.","success")}catch(g){(f=(u=window.CS)==null?void 0:u.showToast)==null||f.call(u,`Errore nell'aggiornamento dello stato: ${g.message}`,"error")}finally{n&&(n.disabled=!1,n.textContent=n.dataset.originalText||"Aggiorna stato")}l&&await yt(t,a)});const o=t.querySelector("#practice-status-upload-form");o&&o.addEventListener("submit",async i=>{var r,d,c,p,u,f;i.preventDefault();const s=o.querySelector('input[type="file"][name="document"]');if(!s||!s.files||!s.files.length){(d=(r=window.CS)==null?void 0:r.showToast)==null||d.call(r,"Seleziona un file da caricare.","warning");return}const n=o.querySelector('button[type="submit"]');n&&(n.disabled=!0,n.dataset.originalText=n.dataset.originalText||n.textContent,n.textContent="Caricamento...");let l=!1;try{await Jt(a,s.files[0]),l=!0,s.value="",(p=(c=window.CS)==null?void 0:c.showToast)==null||p.call(c,"Documento caricato con successo.","success")}catch(g){(f=(u=window.CS)==null?void 0:u.showToast)==null||f.call(u,`Errore durante l'upload del documento: ${g.message}`,"error")}finally{n&&(n.disabled=!1,n.textContent=n.dataset.originalText||"Carica file")}l&&await yt(t,a)})}function Ft(t){const a=document.getElementById("practice-page-title");a&&(a.textContent=t.titolo||`Pratica #${t.id}`);const e=document.getElementById("practice-page-code");e&&(e.textContent=`#${t.id}`);const o=document.getElementById("practice-page-status");if(o){const n=G(t.stato),l=K(n.color),r=["badge"];l.className&&r.push(l.className),l.className||r.push("bg-secondary"),l.textClass&&r.push(l.textClass),!l.textClass&&!l.style&&!l.accentColor&&r.push("text-white"),o.textContent=n.label,o.className=r.join(" ");const d=lt(l);d?o.setAttribute("style",d):o.removeAttribute("style")}const i=document.getElementById("practice-page-category");if(i){const n=t.categoria||"",l=n.toUpperCase()==="PATRONATO";i.textContent=n||"N/D",i.className=`badge ${l?"bg-warning text-dark":"bg-info"}`}const s=document.getElementById("practice-page-operator");s&&(t.assegnatario?s.textContent=`${t.assegnatario.nome||""} ${t.assegnatario.cognome||""}`.trim():s.textContent="Non assegnata")}async function ht(){try{const t=[O(),X()];$.canConfigureModule&&t.push(et());const[a,e,o]=await Promise.all(t);wt("filter-stato",a,"codice","nome"),wt("filter-tipo",e,"id","nome"),$.canConfigureModule&&wt("filter-operatore",o||[],"id",i=>`${i.nome} ${i.cognome}`.trim())}catch(t){console.error("Errore nel caricamento dei filtri:",t)}}function wt(t,a,e,o){const i=document.getElementById(t);if(!i)return;const s=i.value,n=i.querySelector('option[value=""]');i.innerHTML="",n&&i.appendChild(n),a.forEach(l=>{const r=document.createElement("option"),d=typeof e=="function"?e(l):l[e],c=typeof o=="function"?o(l):l[o];r.value=d,r.textContent=c,d==s&&(r.selected=!0),i.appendChild(r)})}function Q(t){(Array.isArray(t)?t:[t]).forEach(e=>{Object.prototype.hasOwnProperty.call(x,e)&&(x[e]=null)})}async function O(t=!1){if(!t&&Array.isArray(x.statuses))return Object.keys(D).length===0&&x.statuses.forEach(e=>{const o=M(e.codice);o&&(D[o]={label:e.nome||o,color:At(e.colore)})}),x.statuses;const a=await E("GET",{action:"list_statuses"});return x.statuses=a.data.statuses||[],Object.keys(D).forEach(e=>delete D[e]),x.statuses.forEach(e=>{const o=M(e.codice);o&&(D[o]={label:e.nome||o,color:At(e.colore)})}),x.statuses}async function X(t=!1){if(!t&&Array.isArray(x.types))return x.types;const a=await E("GET",{action:"list_types"});return x.types=a.data.types||[],x.types}async function et(t=!1){if(!$.canConfigureModule&&!$.canManagePractices)return[];if(!t&&Array.isArray(x.operators))return x.operators;try{const a={action:"list_operators",only_active:1};$.canConfigureModule||(a.categoria="PATRONATO");const e=await E("GET",a);x.operators=e.data.operators||[]}catch(a){console.error("Errore nel caricamento operatori:",a),x.operators=[]}return x.operators}async function P(t=1,a={}){const{silent:e=!1}=a,o=document.getElementById("practices-table-container"),i=document.getElementById("practices-pagination");if(o)try{e||F(o);const s=Ye("practices-filters-form");It={...s},tt=t;const n={...s,page:t,action:"list_practices"},l=await E("GET",n);await Promise.all([O(),X(),$.canConfigureModule?et():Promise.resolve([])]);const{items:r,pagination:d,summaries:c}=l.data;c&&(zt(c),Nt(c)),ze(o,r,{silent:e}),Ze(i,d,P),Le(It),e||Mt()}catch(s){L(o,"Errore nel caricamento delle pratiche: "+s.message),i&&(i.style.display="none")}}function ze(t,a,e={}){var d;const{silent:o=!1}=e;if(!a.length){t.innerHTML=`
            <div class="text-center text-muted py-4">
                <i class="fa-solid fa-folder-open fa-2x mb-3 opacity-50"></i>
                <p>Nessuna pratica trovata con i filtri selezionati.</p>
            </div>
        `,$.isPatronato&&$.operatorId&&(gt=new Set,rt=!0);return}let i=`
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Pratica</th>
                        <th>Tracking</th>
                        <th>Categoria</th>
                        <th>Stato</th>
                        <th>Documento</th>
                        <th>Assegnata a</th>
                        <th>Cliente</th>
                        <th>Email cliente</th>
                        <th>Aggiornata</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
    `;const s=!!($.isPatronato&&$.operatorId),n=s?new Set:null,l=[],r=$.operatorId;if(a.forEach(c=>{var z,Z,nt;s&&((z=c==null?void 0:c.assegnatario)==null?void 0:z.id)===r&&(n.add(c.id),rt&&o&&!gt.has(c.id)&&l.push(c));const p=G(c.stato),u=K(p.color),f=["badge"];u.className&&f.push(u.className),u.className||f.push("bg-secondary"),u.textClass?f.push(u.textClass):!u.style&&!u.accentColor&&f.push("text-white");const g=lt(u),y=((Z=c.assegnatario)==null?void 0:Z.nome)||"Non assegnata",S=c.cliente?c.cliente.ragione_sociale||`${c.cliente.nome} ${c.cliente.cognome}`.trim()||"Cliente #"+c.cliente.id:"Nessun cliente",w=Array.isArray(c.allegati)?c.allegati:[],C=w.length>0?w[0]:null,v=C?pt(C,c.id):"#",T=typeof c.tracking_code=="string"?c.tracking_code.trim():"",k=T&&$.trackingBaseUrl?`${$.trackingBaseUrl}${encodeURIComponent(T)}`:"",h=(()=>{var Et;const A=typeof(c==null?void 0:c.customer_email)=="string"?c.customer_email:null,mt=typeof((Et=c==null?void 0:c.cliente)==null?void 0:Et.email)=="string"?c.cliente.email:null;return(A&&A.trim()!==""?A:mt||"").trim()})(),I=[`
            <button class="btn btn-icon btn-soft-accent btn-sm" type="button" onclick="viewPractice(${c.id})" title="Visualizza">
                <i class="fa-solid fa-eye"></i>
            </button>
        `];if($.canManagePractices&&I.push(`
                <button class="btn btn-icon btn-outline-secondary btn-sm" type="button" onclick="editPractice(${c.id})" title="Cambia stato">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
            `),$.canCreatePractices){const A=h!=="",mt=A?"btn btn-icon btn-outline-primary btn-sm":"btn btn-icon btn-outline-warning btn-sm",xt=A?"Reinvia email al cliente":"Nessun contatto salvato: potrai inserirlo manualmente.";I.push(`
                <button class="${mt}" type="button" data-customer-email="${m(h)}" data-recipient-state="${A?"known":"missing"}" onclick="resendCustomerMail(${c.id}, this.dataset.customerEmail)" title="${m(xt)}">
                    <i class="fa-solid fa-envelope"></i>
                </button>
            `)}if($.canManagePractices){const A=encodeURIComponent(String((nt=c==null?void 0:c.titolo)!=null?nt:""));I.push(`
                <button class="btn btn-icon btn-outline-danger btn-sm" type="button" data-practice-title="${A}" onclick="deletePractice(${c.id}, this.dataset.practiceTitle)" title="Elimina pratica">
                    <i class="fa-solid fa-trash"></i>
                </button>
            `)}const _=h?`
                <div class="d-flex flex-column">
                    <span class="badge bg-light text-dark border font-monospace">${m(h)}</span>
                    <small class="text-muted">Destinatario predefinito</small>
                </div>
            `:`
                <div class="text-danger fw-semibold small">Email mancante</div>
                <small class="text-muted">Richiedi un contatto prima dell'invio.</small>
            `;i+=`
            <tr>
                <td>
                    <div>
                        <div class="fw-semibold">${m(c.titolo)}</div>
                        <small class="text-muted">#${c.id}</small>
                    </div>
                </td>
                <td>
                    ${T?`
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-light text-dark border font-monospace">${m(T)}</span>
                            ${k?`<a class="btn btn-icon btn-outline-secondary btn-sm" href="${m(k)}" target="_blank" rel="noopener noreferrer" title="Apri tracking"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>`:""}
                        </div>
                    `:'<span class="text-muted small">\u2014</span>'}
                </td>
                <td>
                    <span class="badge bg-${c.categoria==="CAF"?"info":"warning"}">${m(c.categoria)}</span>
                </td>
                <td>
                    <span class="${f.join(" ")}"${g?` style="${g}"`:""}>${m(p.label)}</span>
                </td>
                <td>
                    ${C?`
                        <a class="btn btn-icon btn-outline-success btn-sm" href="${m(v)}" target="_blank" rel="noopener noreferrer" title="Scarica pratica elaborata">
                            <i class="fa-solid fa-file-arrow-down"></i>
                        </a>
                    `:'<span class="text-muted small">\u2014</span>'}
                </td>
                <td>${m(y)}</td>
                <td>${m(S)}</td>
                <td>${_}</td>
                <td>
                    <small>${N(c.data_aggiornamento)}</small>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        ${I.join("")}
                    </div>
                </td>
            </tr>
        `}),i+="</tbody></table></div>",t.innerHTML=i,s){if(gt=n,!rt)rt=!0;else if(o&&l.length&&((d=window.CS)!=null&&d.showToast)){const c=l.map(g=>{const y=g!=null&&g.titolo?String(g.titolo).trim():"";return m(y||`Pratica #${g.id}`)}),p=c.slice(0,2).join(", "),u=c.length-Math.min(c.length,2),f=c.length===1?`Nuova pratica assegnata: ${p}.`:`Hai ${c.length} nuove pratiche assegnate: ${p}${u>0?` e altre ${u}.`:"."}`;window.CS.showToast(f,"info")}}}function Le(t){const a=document.getElementById("active-filters-display"),e=document.getElementById("active-filters-list");if(!a||!e)return;const o={search:{label:"Ricerca"},categoria:{label:"Categoria"},stato:{label:"Stato",format:n=>ve(n)},tipo_pratica:{label:"Tipologia",format:n=>ye(n)},operatore:{label:"Operatore",format:n=>he(n)},dal:{label:"Dal",format:Pt},al:{label:"Al",format:Pt},assegnata:{label:"Assegnazione",format:n=>n==="0"?"Non assegnate":"Assegnate"},order:{label:"Ordine",format:n=>{switch(n){case"recenti":return"Pi\xF9 recenti";case"scadenza":return"Per scadenza";case"stato":return"Per stato";case"assegnatario":return"Per assegnazione";default:return n}}}},i=[];Object.entries(t||{}).forEach(([n,l])=>{if(l==null||l===""||!(n in o))return;const r=o[n],d=r.format?r.format(l):l;i.push(`<span class="badge bg-light text-dark">${r.label}: ${m(String(d))}</span>`)});const s=document.getElementById("active-quick-filter-wrapper");s&&(s.style.display=W&&W!=="all"?"inline-flex":"none"),i.length>0?(e.innerHTML=i.join(" "),a.style.display="block"):(e.innerHTML="",a.style.display=W&&W!=="all"?"block":"none")}function Pe(){$t(),St();const t=document.getElementById("create-type-btn");t&&t.addEventListener("click",()=>Ue());const a=document.getElementById("create-status-btn");a&&a.addEventListener("click",()=>Ge())}async function $t(){const t=document.getElementById("types-list-container");if(t)try{F(t);const a=await X(!0);Be(t,a)}catch(a){L(t,"Errore nel caricamento delle tipologie: "+a.message)}}function Be(t,a){if(!a.length){t.innerHTML=`
            <div class="text-center text-muted py-3">
                <p>Nessuna tipologia configurata.</p>
            </div>
        `;return}let e='<div class="list-group list-group-flush">';a.forEach(o=>{var i;e+=`
            <div class="list-group-item d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="mb-1">${m(o.nome)}</h6>
                    <p class="mb-1 small text-muted">Categoria: ${o.categoria}</p>
                    <small class="text-muted">Campi personalizzati: ${((i=o.campi_personalizzati)==null?void 0:i.length)||0}</small>
                </div>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary" onclick="editType(${o.id})" title="Modifica">
                        <i class="fa-solid fa-edit"></i>
                    </button>
                    <button class="btn btn-outline-danger" onclick="deleteType(${o.id})" title="Elimina">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
        `}),e+="</div>",t.innerHTML=e}async function St(){const t=document.getElementById("statuses-list-container");if(t)try{F(t);const a=await O(!0);qe(t,a)}catch(a){L(t,"Errore nel caricamento degli stati: "+a.message)}}function qe(t,a){if(!a.length){t.innerHTML=`
            <div class="text-center text-muted py-3">
                <p>Nessuno stato configurato.</p>
            </div>
        `;return}const e={in_lavorazione:"primary",completata:"success",sospesa:"warning",archiviata:"secondary"};let o='<div class="list-group list-group-flush">';a.forEach(i=>{const s=e[i.codice]||i.colore||"secondary";o+=`
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <span class="badge bg-${s} me-3">${m(i.nome)}</span>
                    <div>
                        <div class="small">${m(i.codice)}</div>
                        <div class="small text-muted">Ordine: ${i.ordering}</div>
                    </div>
                </div>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary" onclick="editStatus(${i.id})" title="Modifica">
                        <i class="fa-solid fa-edit"></i>
                    </button>
                    <button class="btn btn-outline-danger" onclick="deleteStatus(${i.id})" title="Elimina">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
        `}),o+="</div>",t.innerHTML=o}function Me(){var i,s;at(),ut({target:"admin",showRead:(s=(i=document.getElementById("show-read-notifications"))==null?void 0:i.checked)!=null?s:!1});const t=document.getElementById("create-operator-btn");t&&t.addEventListener("click",()=>Je());const a=document.getElementById("refresh-operators");a&&a.addEventListener("click",()=>{at()});const e=document.getElementById("show-read-notifications");e&&e.addEventListener("change",()=>{ut({target:"admin",showRead:e.checked})});const o=document.getElementById("notifications-list");o&&o.addEventListener("click",we)}async function at(){const t=document.getElementById("operators-table-container");if(t)try{F(t);const a=await E("GET",{action:"list_operators",only_active:!1});x.operators=a.data.operators||[],ct=Array.isArray(x.operators)?[...x.operators]:[],Oe(t,x.operators),ee(x.operators)}catch(a){L(t,"Errore nel caricamento degli operatori: "+a.message),ee([])}}function Oe(t,a){if(!a.length){t.innerHTML=`
            <div class="text-center text-muted py-4">
                <i class="fa-solid fa-users fa-2x mb-3 opacity-50"></i>
                <p>Nessun operatore configurato.</p>
            </div>
        `;return}let e=`
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Operatore</th>
                        <th>Email</th>
                        <th>Ruolo</th>
                        <th>Utente sistema</th>
                        <th>Stato</th>
                        <th>Registrato</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
    `;a.forEach(o=>{e+=`
            <tr>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="avatar-sm bg-${o.ruolo==="CAF"?"info":"warning"} bg-opacity-10 text-${o.ruolo==="CAF"?"info":"warning"} me-3">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <div>
                            <div class="fw-semibold">${m(o.nome)} ${m(o.cognome)}</div>
                            <small class="text-muted">ID: ${o.id}</small>
                        </div>
                    </div>
                </td>
                <td>${m(o.email)}</td>
                <td>
                    <span class="badge bg-${o.ruolo==="CAF"?"info":"warning"}">${o.ruolo}</span>
                </td>
                <td>
                    ${o.user_username?`<small class="text-muted">${m(o.user_username)}</small>`:'<span class="text-muted">Non collegato</span>'}
                </td>
                <td>
                    <span class="badge bg-${o.attivo?"success":"secondary"}">
                        ${o.attivo?"Attivo":"Disattivo"}
                    </span>
                </td>
                <td>
                    <small>${N(o.created_at)}</small>
                </td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick="editOperator(${o.id})" title="Modifica">
                            <i class="fa-solid fa-edit"></i>
                        </button>
                        <button class="btn btn-outline-${o.attivo?"warning":"success"}" 
                                onclick="toggleOperator(${o.id}, ${!o.attivo})" 
                                title="${o.attivo?"Disattiva":"Attiva"}">
                            <i class="fa-solid fa-${o.attivo?"pause":"play"}"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `}),e+="</tbody></table></div>",t.innerHTML=e}function Fe(){var t,a;b.root||(b.root=document.getElementById("cafPatronatoModal"),b.root&&((t=window.bootstrap)!=null&&t.Modal)&&(b.instance=window.bootstrap.Modal.getOrCreateInstance(b.root,{backdrop:"static"}),b.title=b.root.querySelector(".modal-title"),b.body=b.root.querySelector(".modal-body"),b.footer=b.root.querySelector(".modal-footer"))),b.confirmRoot||(b.confirmRoot=document.getElementById("cafPatronatoConfirmModal"),b.confirmRoot&&((a=window.bootstrap)!=null&&a.Modal)&&(b.confirmInstance=window.bootstrap.Modal.getOrCreateInstance(b.confirmRoot,{backdrop:"static"}),b.confirmBody=b.confirmRoot.querySelector(".modal-body"),b.confirmAction=b.confirmRoot.querySelector("#cafPatronatoConfirmAction"))),b.confirmAction&&b.confirmAction.addEventListener("click",()=>{var o,i,s;if(typeof b.confirmHandler!="function"){(o=b.confirmAction)==null||o.blur(),(i=b.confirmInstance)==null||i.hide();return}const e=b.confirmHandler();if(e instanceof Promise){b.confirmAction&&(b.confirmAction.disabled=!0),e.then(n=>{var l;n!==!1&&(b.confirmHandler=null,b.confirmAction&&b.confirmAction.blur(),(l=b.confirmInstance)==null||l.hide())}).catch(n=>{console.error("CAF/Patronato confirm handler error:",n)}).finally(()=>{b.confirmAction&&(b.confirmAction.disabled=!1)});return}e!==!1&&(b.confirmHandler=null,b.confirmAction&&b.confirmAction.blur(),(s=b.confirmInstance)==null||s.hide())})}function De(t="lg"){if(!b.root)return;const a=b.root.querySelector(".modal-dialog");a&&(a.classList.remove("modal-sm","modal-lg","modal-xl"),(t==="sm"||t==="lg"||t==="xl")&&a.classList.add(`modal-${t}`))}function q({title:t="",body:a="",footer:e="",size:o="lg",onShown:i=null}){if(!b.instance||!b.title||!b.body||!b.footer){console.warn("Modal non disponibile: assicurarsi che il markup sia presente.");return}b.title.textContent=t,b.body.innerHTML=a,b.footer.innerHTML=e||'<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>',De(o),b.instance.show(),typeof i=="function"&&setTimeout(()=>i(b.body),0)}function ot(){var t;(t=b.instance)==null||t.hide()}function Y({message:t,title:a="Conferma",confirmLabel:e="Conferma",confirmVariant:o="primary",onConfirm:i,onShown:s}){if(!b.confirmInstance||!b.confirmBody||!b.confirmAction){console.warn("Modal di conferma non disponibile.");return}b.confirmHandler=typeof i=="function"?i:null,b.confirmRoot.querySelector(".modal-title").textContent=a,b.confirmBody.innerHTML=t,b.confirmAction.textContent=e,b.confirmAction.className=`btn btn-${o}`,b.confirmInstance.show(),typeof s=="function"&&setTimeout(()=>{s(b.confirmBody)},0)}function U({showClose:t=!0,submitLabel:a="Salva",submitId:e="caf-patronato-submit"}={}){let o="";return t&&(o+='<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>'),o+=`<button type="submit" form="caf-patronato-modal-form" id="${e}" class="btn btn-primary">${m(a)}</button>`,o}function R(t,a,e="caf-patronato-submit"){if(!t)return;const o=document.getElementById(e);if(o){o.disabled=a;const i=o.dataset.originalText||o.textContent;o.dataset.originalText||(o.dataset.originalText=i),o.textContent=a?"Attendere...":i}Array.from(t.elements).forEach(i=>{(i instanceof HTMLButtonElement||i instanceof HTMLInputElement||i instanceof HTMLSelectElement||i instanceof HTMLTextAreaElement)&&i.id!==e&&(i.disabled=a)})}function j(t,a="",e="danger"){if(!t)return;const o=t.querySelector('[data-role="form-alert"]');if(o){if(!a){o.classList.add("d-none"),o.textContent="";return}o.className=`alert alert-${e}`,o.textContent=a}}function Re(){var t;return((t=document.querySelector('meta[name="csrf-token"]'))==null?void 0:t.getAttribute("content"))||null}function Dt(t){if(!t)return"";const a=new Date(t);if(Number.isNaN(a.getTime()))return"";const e=a.getFullYear(),o=String(a.getMonth()+1).padStart(2,"0"),i=String(a.getDate()).padStart(2,"0");return`${e}-${o}-${i}`}async function Ct(){var t,a,e,o;if($.useLegacyCreate&&$.createUrl){window.location.href=$.createUrl;return}if(!$.canCreatePractices){(a=(t=window.CS)==null?void 0:t.showToast)==null||a.call(t,"Non hai i permessi per creare pratiche.","warning");return}try{const[i,s,n]=await Promise.all([X(!0),O(!0),et(!0)]),l=jt({mode:"create",types:i,statuses:s,operators:n});q({title:"Nuova pratica",body:l,footer:U({submitLabel:"Crea pratica"}),size:"xl",onShown:r=>Ht(r,{mode:"create",types:i,statuses:s,operators:n,practice:null})})}catch(i){console.error("Errore caricamento dati creazione pratica:",i),(o=(e=window.CS)==null?void 0:e.showToast)==null||o.call(e,`Errore nel preparare la pratica: ${i.message}`,"error")}}function Rt(t){var e,o;if(!$.canManagePractices){(o=(e=window.CS)==null?void 0:e.showToast)==null||o.call(e,"Non hai i permessi per modificare le pratiche.","warning");return}if(!t)return;const a=`status.php?id=${encodeURIComponent(t)}`;window.location.href=a}function aa(t){if(!t)return;const a=`view.php?id=${encodeURIComponent(t)}`;window.location.href=a}function oa(t,a=""){var d,c;if(!$.canCreatePractices){(c=(d=window.CS)==null?void 0:d.showToast)==null||c.call(d,"Non hai i permessi per inviare email al cliente.","warning");return}const e=parseInt(t,10);if(!e)return;const o=typeof a=="string"?a.trim():"",i=o!=="",s=!i,n=i?"Puoi personalizzare l'indirizzo oppure lasciare il suggerimento predefinito.":"Specificare un indirizzo: la pratica non ha ancora un contatto email salvato.",r=`
        <p>Vuoi reinviare la mail di conferma al cliente per questa pratica?</p>
        ${i?`
            <div class="alert alert-light border d-flex align-items-start gap-3" role="status">
                <div class="text-primary fs-4"><i class="fa-solid fa-envelope-circle-check"></i></div>
                <div>
                    <div class="fw-semibold mb-1">Destinatario suggerito</div>
                    <div class="font-monospace">${m(o)}</div>
                    <small class="text-muted">Ultimo indirizzo confermato per questa pratica.</small>
                </div>
            </div>
        `:`
            <div class="alert alert-warning d-flex align-items-start gap-3" role="status">
                <div class="fs-4"><i class="fa-solid fa-circle-exclamation"></i></div>
                <div>
                    <div class="fw-semibold mb-1">Nessun indirizzo salvato</div>
                    <small>Inserisci l'email del cliente per procedere con l'invio.</small>
                </div>
            </div>
        `}
        <div class="mt-3">
            <label class="form-label" for="caf-resend-email-input">Email destinatario</label>
            <input type="email" class="form-control" id="caf-resend-email-input" name="recipient" value="${m(o)}" placeholder="cliente@example.com" data-role="resend-email-input">
            <small class="text-muted">${n}</small>
            <div class="invalid-feedback d-none" data-role="resend-email-feedback"></div>
        </div>
    `;Y({title:"Reinvia email al cliente",message:r,confirmLabel:"Reinvia",confirmVariant:"primary",onConfirm:async()=>{var p,u,f,g,y,S,w,C,v,T,k,h;try{const I=(p=b.confirmRoot)==null?void 0:p.querySelector('[data-role="resend-email-input"]');let _=null;if(I&&typeof I.value=="string"){const A=I.value.trim();A!==""&&(_=A)}if(!o&&_===null){(f=(u=window.CS)==null?void 0:u.showToast)==null||f.call(u,"Inserisci un indirizzo email per il cliente.","warning");const A=(g=b.confirmRoot)==null?void 0:g.querySelector('[data-role="resend-email-feedback"]');return A&&(A.textContent="Indicare un indirizzo email valido del cliente.",A.classList.remove("d-none")),!1}if(_!==null&&!Tt(_)){(S=(y=window.CS)==null?void 0:y.showToast)==null||S.call(y,"Indirizzo email non valido.","warning");const A=(w=b.confirmRoot)==null?void 0:w.querySelector('[data-role="resend-email-feedback"]');return A&&(A.textContent="Formato email non valido.",A.classList.remove("d-none")),!1}const z={action:"resend_customer_mail",id:e};_!==null&&(z.recipient=_),await E("POST",z);const Z=_!=null?_:o,nt=Z?`Email inviata a ${Z}.`:"Email di conferma reinviata al cliente.";return(v=(C=window.CS)==null?void 0:C.showToast)==null||v.call(C,nt,"success"),P(tt,{silent:!0}),!0}catch(I){const _=I&&I.message?I.message:"Invio email non riuscito.";(k=(T=window.CS)==null?void 0:T.showToast)==null||k.call(T,`Errore: ${_}`,"error");const z=(h=b.confirmRoot)==null?void 0:h.querySelector('[data-role="resend-email-feedback"]');return z&&(z.textContent=_,z.classList.remove("d-none")),!1}},onShown:p=>{const u=p==null?void 0:p.querySelector('[data-role="resend-email-input"]'),f=p==null?void 0:p.querySelector('[data-role="resend-email-feedback"]'),g=b.confirmAction,y=()=>{if(!(u instanceof HTMLInputElement)){g&&(g.disabled=!1);return}const S=u.value.trim();let w="";S===""?s&&(w="Inserire l'indirizzo email del cliente."):Tt(S)||(w="Formato email non valido."),f&&(w?(f.textContent=w,f.classList.remove("d-none")):(f.textContent="",f.classList.add("d-none"))),g&&(g.disabled=!!w)};y(),u instanceof HTMLInputElement&&(u.focus(),u.dataset.cafResendBound||(u.dataset.cafResendBound="1",u.addEventListener("input",y)))}})}function ia(t,a=""){var s,n;if(!$.canManagePractices){(n=(s=window.CS)==null?void 0:s.showToast)==null||n.call(s,"Non hai i permessi per eliminare pratiche.","warning");return}const e=parseInt(t,10);if(!e)return;let o="";if(typeof a=="string"&&a.trim()!==""){const l=a.trim();try{o=decodeURIComponent(l)}catch{o=l}}const i=o!==""?o:`Pratica #${e}`;Y({title:"Elimina pratica",message:`<p>Eliminando <strong>${m(i)}</strong> verranno rimossi definitivamente documenti, note, timeline e movimenti economici associati. L'operazione non pu\xF2 essere annullata.</p>
                  <p class="mb-0 text-danger">Confermi di voler procedere?</p>`,confirmLabel:"Elimina",confirmVariant:"danger",onConfirm:async()=>{var l,r,d,c;try{return await E("POST",{action:"delete_practice",id:e}),(r=(l=window.CS)==null?void 0:l.showToast)==null||r.call(l,"Pratica eliminata correttamente.","success"),await P(tt||1,{silent:!1}),!0}catch(p){const u=p&&p.message?p.message:"Eliminazione non riuscita.";return(c=(d=window.CS)==null?void 0:d.showToast)==null||c.call(d,`Errore: ${u}`,"error"),!1}}})}async function it(t,a={}){var o;const e=a.container||(b.instance&&((o=b.root)!=null&&o.classList.contains("show"))?b.body:null);if(e)try{const[i,s]=await Promise.all([E("GET",{action:"get_practice",id:t}),O()]);B=i.data,a.mode==="page"&&Ft(B),e.innerHTML=Gt(B,s),Wt(e,B,s,{...a,container:e})}catch(i){console.error("Errore nell'aggiornamento pratica:",i),L(e,"Errore nell'aggiornamento pratica: "+i.message)}}function jt({mode:t,types:a,statuses:e,operators:o,practice:i}){var y,S,w,C,v,T,k;const s=((y=i==null?void 0:i.tipo)==null?void 0:y.id)||((S=a[0])==null?void 0:S.id)||"",n=(i==null?void 0:i.stato)||((w=e[0])==null?void 0:w.codice)||"",l=((C=i==null?void 0:i.assegnatario)==null?void 0:C.id)||"",r=(i==null?void 0:i.categoria)||((v=a.find(h=>h.id===s))==null?void 0:v.categoria)||"CAF",d=i!=null&&i.scadenza?Dt(i.scadenza):"",c=((T=i==null?void 0:i.cliente)==null?void 0:T.id)||(i==null?void 0:i.cliente_id)||"",p=(i==null?void 0:i.metadati)||{},u=a.map(h=>`
        <option value="${h.id}" data-categoria="${m(h.categoria)}" ${h.id===s?"selected":""}>
            ${m(h.nome)} (${h.categoria})
        </option>
    `).join(""),f=e.map(h=>`
        <option value="${m(h.codice)}" ${h.codice===n?"selected":""}>
            ${m(h.nome)}
        </option>
    `).join(""),g=['<option value="">Nessuna assegnazione</option>'].concat(o.filter(h=>h.ruolo===r&&h.attivo).map(h=>`
                <option value="${h.id}" ${h.id===l?"selected":""}>
                    ${m(`${h.nome} ${h.cognome}`)}
                </option>
            `)).join("");return`
        <form id="caf-patronato-modal-form" class="needs-validation" novalidate>
            <div class="alert alert-danger d-none" data-role="form-alert"></div>
            ${t==="edit"?`<input type="hidden" name="id" value="${i.id}">`:""}
            <input type="hidden" name="categoria" value="${m(r)}" data-role="categoria-input">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="mb-3">
                        <label class="form-label" for="practice-title">Titolo</label>
                        <input type="text" class="form-control" id="practice-title" name="titolo" maxlength="200" required value="${i?m(i.titolo):""}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="practice-description">Descrizione</label>
                        <textarea class="form-control" id="practice-description" name="descrizione" rows="4" placeholder="Dettagli della pratica">${i!=null&&i.descrizione?m(i.descrizione):""}</textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="practice-type">Tipologia</label>
                            <select class="form-select" id="practice-type" name="tipo_pratica" required>${u}</select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="practice-status">Stato</label>
                            <select class="form-select" id="practice-status" name="stato" required>${f}</select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="practice-due-date">Scadenza</label>
                            <input type="date" class="form-control" id="practice-due-date" name="scadenza" value="${d}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="practice-client">ID Cliente</label>
                            <input type="number" class="form-control" id="practice-client" name="cliente_id" min="1" placeholder=" opzionale" value="${c}">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="practice-note">Note interne</label>
                        <textarea class="form-control" id="practice-note" name="note" rows="3" placeholder="Annotazioni visibili agli amministratori">${i!=null&&i.note?m(i.note):""}</textarea>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded p-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-muted small">Categoria</span>
                            <span class="badge bg-${r==="PATRONATO"?"warning":"info"} text-dark" data-role="categoria-badge">${m(r)}</span>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="practice-operator">Operatore assegnato</label>
                            <select class="form-select" id="practice-operator" name="id_utente_caf_patronato">${g}</select>
                            <small class="form-text text-muted">Solo operatori attivi della stessa categoria.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="practice-category">Categoria servizio</label>
                            <input type="text" class="form-control" id="practice-category" value="${m(r)}" disabled>
                        </div>
                        ${i?`
                        <div class="small text-muted">
                            <div>Creata il: ${N(i.data_creazione)}</div>
                            <div>Aggiornata il: ${N(i.data_aggiornamento)}</div>
                        </div>`:""}
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Campi personalizzati</h6>
                    <span class="badge bg-secondary" data-role="custom-count"></span>
                </div>
                <div class="row g-3" data-role="custom-fields">
                    ${Vt(((k=a.find(h=>h.id===s))==null?void 0:k.campi_personalizzati)||[],p)}
                </div>
            </div>
        </form>
    `}function Ht(t,a){const e=t.querySelector("#caf-patronato-modal-form");if(!e)return;e.__cafConfig=a,e.addEventListener("submit",async i=>{var n,l;i.preventDefault(),j(e);const s=a.submitButtonId||"caf-patronato-submit";R(e,!0,s);try{const r=je(e),d=a.mode==="edit"?"update_practice":"create_practice",c=await E("POST",{action:d,...r});(l=(n=window.CS)==null?void 0:n.showToast)==null||l.call(n,`Pratica ${a.mode==="edit"?"aggiornata":"creata"} con successo.`,"success"),typeof a.onSuccess=="function"?a.onSuccess({payload:r,response:c,form:e}):(ot(),P(),document.getElementById("practices-summary-container")&&vt())}catch(r){console.error("Errore salvataggio pratica:",r),j(e,r.message||"Errore inatteso nel salvataggio."),typeof a.onError=="function"&&a.onError(r)}finally{R(e,!1,s)}});const o=e.querySelector("#practice-type");o&&o.addEventListener("change",()=>{Ut(e)}),Ut(e)}function Ut(t){var C,v;const a=t.__cafConfig||{},{types:e=[],operators:o=[],practice:i}=a,s=t.querySelector("#practice-type"),n=t.querySelector('[data-role="categoria-input"]'),l=t.querySelector('[data-role="categoria-badge"]'),r=t.querySelector("#practice-operator"),d=t.querySelector('[data-role="custom-fields"]'),c=t.querySelector('[data-role="custom-count"]');if(!s||!n||!l||!d)return;const p=parseInt(s.value,10),u=e.find(T=>T.id===p),f=(u==null?void 0:u.categoria)||"CAF";if(n.value=f,l.textContent=f,l.className=`badge bg-${f==="PATRONATO"?"warning":"info"} text-dark`,r){const T=r.value;r.innerHTML='<option value="">Nessuna assegnazione</option>',o.filter(k=>k.ruolo===f&&k.attivo).forEach(k=>{const h=document.createElement("option");h.value=String(k.id),h.textContent=`${k.nome} ${k.cognome}`,String(k.id)===T&&(h.selected=!0),r.appendChild(h)})}const y=((C=i==null?void 0:i.tipo)==null?void 0:C.id)===p?(i==null?void 0:i.metadati)||{}:{},S=Vt((u==null?void 0:u.campi_personalizzati)||[],y);d.innerHTML=S;const w=((v=u==null?void 0:u.campi_personalizzati)==null?void 0:v.length)||0;c&&(c.textContent=w?`${w} campi`:"Nessun campo",c.style.display=w?"inline-block":"none")}function Vt(t,a){return!Array.isArray(t)||t.length===0?'<div class="col-12"><p class="text-muted small mb-0">Questa tipologia non prevede campi personalizzati.</p></div>':t.map(e=>{if(!e||typeof e!="object")return"";const o=e.slug||"";if(!o)return"";const i=e.label||o,s=(e.type||"text").toLowerCase(),n=H(e.required),l=a&&typeof a=="object"?a[o]:void 0,r=`meta_${o}`,d=e.help?`<small class="form-text text-muted">${m(e.help)}</small>`:"";if(s==="textarea")return`
                <div class="col-12">
                    <label class="form-label" for="${r}">${m(i)}${n?" *":""}</label>
                    <textarea class="form-control" id="${r}" name="${r}" rows="3" ${n?"required":""}>${l?m(String(l)):""}</textarea>
                    ${d}
                </div>
            `;if(s==="select"){const p=(Array.isArray(e.options)?e.options:[]).map(u=>{var S,w;const f=typeof u=="object"?(S=u.value)!=null?S:u.label:u,g=typeof u=="object"?(w=u.label)!=null?w:u.value:u,y=String(f)===String(l)?"selected":"";return`<option value="${m(String(f))}" ${y}>${m(String(g))}</option>`}).join("");return`
                <div class="col-md-6">
                    <label class="form-label" for="${r}">${m(i)}${n?" *":""}</label>
                    <select class="form-select" id="${r}" name="${r}" ${n?"required":""}>
                        <option value="">Seleziona...</option>
                        ${p}
                    </select>
                    ${d}
                </div>
            `}if(s==="checkbox"){const c=H(l)?"checked":"";return`
                <div class="col-md-6">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="${r}" name="${r}" ${c}>
                        <label class="form-check-label" for="${r}">${m(i)}</label>
                        ${d}
                    </div>
                </div>
            `}if(s==="number")return`
                <div class="col-md-6">
                    <label class="form-label" for="${r}">${m(i)}${n?" *":""}</label>
                    <input type="number" class="form-control" id="${r}" name="${r}" value="${l!==void 0?m(String(l)):""}" ${n?"required":""}>
                    ${d}
                </div>
            `;if(s==="date"){const c=l?Dt(String(l)):"";return`
                <div class="col-md-6">
                    <label class="form-label" for="${r}">${m(i)}${n?" *":""}</label>
                    <input type="date" class="form-control" id="${r}" name="${r}" value="${c}" ${n?"required":""}>
                    ${d}
                </div>
            `}return`
            <div class="col-md-6">
                <label class="form-label" for="${r}">${m(i)}${n?" *":""}</label>
                <input type="text" class="form-control" id="${r}" name="${r}" value="${l!==void 0?m(String(l)):""}" ${n?"required":""}>
                ${d}
            </div>
        `}).join("")}function je(t){var s,n;const a=new FormData(t),e={};if(a.forEach((l,r)=>{e[r]=l}),e.id&&(e.id=parseInt(e.id,10),Number.isNaN(e.id)&&delete e.id),e.titolo=String(e.titolo||"").trim(),e.descrizione=String(e.descrizione||"").trim(),e.note=String(e.note||"").trim(),e.scadenza=e.scadenza?String(e.scadenza):"",e.cliente_id=e.cliente_id?parseInt(e.cliente_id,10):null,Number.isNaN(e.cliente_id)&&(e.cliente_id=null),e.tipo_pratica=parseInt(e.tipo_pratica,10),Number.isNaN(e.tipo_pratica))throw new Error("Seleziona una tipologia valida.");e.id_utente_caf_patronato=e.id_utente_caf_patronato?parseInt(e.id_utente_caf_patronato,10):null,Number.isNaN(e.id_utente_caf_patronato)&&(e.id_utente_caf_patronato=null);const o={};return Object.keys(e).forEach(l=>{if(l.startsWith("meta_")){const r=l.replace("meta_",""),d=e[l];if(typeof d=="string"){if(d===""&&d!=="0"){delete e[l];return}o[r]=d}else d instanceof File,o[r]=d;delete e[l]}}),(((n=(s=t.__cafConfig)==null?void 0:s.types.find(l=>l.id===e.tipo_pratica))==null?void 0:n.campi_personalizzati)||[]).forEach(l=>{const r=l.slug||"";if(!r)return;if((l.type||"text").toLowerCase()==="checkbox"){const c=t.querySelector(`[name="meta_${r}"]`);o[r]=c&&c.checked?"1":"0"}}),e.metadati=o,e}function Gt(t,a){var w,C;const e=$.canManagePractices,o=a.find(v=>v.codice===t.stato),i=`<span class="badge bg-primary">${m((o==null?void 0:o.nome)||t.stato)}</span>`,s=Array.isArray(t.documenti)?t.documenti:[],n=Array.isArray(t.note_storico)?t.note_storico:[],l=Array.isArray(t.eventi)?t.eventi:[],r=t.assegnatario?`${m(t.assegnatario.nome)} (${m(t.assegnatario.ruolo)})`:'<span class="text-muted">Non assegnata</span>',d=t.cliente?m(t.cliente.ragione_sociale||`${t.cliente.nome||""} ${t.cliente.cognome||""}`.trim()||`Cliente #${t.cliente.id}`):'<span class="text-muted">Nessun cliente</span>',c=s.length?s.map(v=>{const T=pt(v,t.id),k=[`
            <a class="btn btn-outline-primary" href="${m(T)}" target="_blank" rel="noopener noreferrer">
                <i class="fa-solid fa-download"></i>
            </a>
        `];return e&&k.push(`
                <button type="button" class="btn btn-outline-danger" data-action="delete-document" data-document-id="${v.id}">
                    <i class="fa-solid fa-trash"></i>
                </button>
            `),`
            <div class="list-group-item d-flex justify-content-between align-items-start" data-document-id="${v.id}">
                <div>
                    <div class="fw-semibold">${m(v.file_name)}</div>
                    <div class="text-muted small">${kt(v.file_size)} \xB7 ${m(v.mime_type)} \xB7 ${N(v.created_at)}</div>
                </div>
                <div class="btn-group btn-group-sm">
                    ${k.join("")}
                </div>
            </div>
        `}).join(""):'<div class="list-group-item text-muted">Nessun allegato presente.</div>',p=n.length?n.map(v=>{var z;const T=v.autore&&typeof v.autore=="object"?v.autore.nome:v.autore,k=H((z=v.visibile_operatore)!=null?z:v.visibile_operatori),h=[];v.visibile_admin&&h.push("Amministratori"),k&&h.push("Operatori");const I=h.length?h.join(" e "):"Autore",_=e||v.puoi_eliminare;return`
            <div class="list-group-item" data-note-id="${v.id}">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-semibold">${m(T||"Sconosciuto")}</div>
                        <div class="text-muted small">${N(v.created_at)}</div>
                    </div>
                    ${_?`
                    <button type="button" class="btn btn-sm btn-outline-danger" data-action="delete-note" data-note-id="${v.id}">
                        <i class="fa-solid fa-trash"></i>
                    </button>`:""}
                </div>
                <p class="mb-2 mt-2">${m(v.contenuto||"")}</p>
                <div class="small text-muted">Visibile a: ${m(I)}</div>
            </div>
        `}).join(""):'<div class="list-group-item text-muted">Nessuna nota disponibile.</div>',u=e?`
        <form id="practice-status-form" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="practice-status-select">Aggiorna stato</label>
                <select class="form-select" id="practice-status-select" name="status">
                    ${a.map(v=>`<option value="${m(v.codice)}" ${v.codice===t.stato?"selected":""}>${m(v.nome)}</option>`).join("")}
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" type="submit">Aggiorna</button>
            </div>
        </form>
    `:`
        <div class="alert alert-secondary" role="alert">
            Aggiornamento stato disponibile solo per gli operatori Patronato.
        </div>
    `,f=e?`
        <form id="practice-upload-form" class="row g-2 align-items-end" enctype="multipart/form-data">
            <div class="col-md-8">
                <label class="form-label" for="practice-document">Carica nuovo documento</label>
                <input type="file" class="form-control" id="practice-document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Carica</button>
            </div>
        </form>
    `:`
        <div class="alert alert-secondary" role="alert">
            Caricamento ed eliminazione documenti disponibili solo agli operatori Patronato.
        </div>
    `,g=e?`
        <form id="practice-note-form" class="border rounded p-3 mb-3">
            <div class="mb-3">
                <label class="form-label" for="practice-note-text">Aggiungi nota</label>
                <textarea class="form-control" id="practice-note-text" name="content" rows="3" required placeholder="Testo della nota"></textarea>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="note-visible-admin" name="visible_admin" checked>
                <label class="form-check-label" for="note-visible-admin">Mostra agli amministratori</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="note-visible-operators" name="visible_operator" ${t.assegnatario?"checked":""}>
                <label class="form-check-label" for="note-visible-operators">Mostra agli operatori</label>
            </div>
            <div class="mt-3">
                <button class="btn btn-primary" type="submit">Salva nota</button>
            </div>
        </form>
    `:`
        <div class="alert alert-secondary" role="alert">
            Solo gli operatori Patronato possono aggiungere note operative.
        </div>
    `,y=He(((w=t.tipo)==null?void 0:w.campi_personalizzati)||[],t.metadati||{}),S=l.length?l.map(v=>`
        <div class="list-group-item">
            <div class="d-flex justify-content-between">
                <div class="fw-semibold">${m(v.evento)}</div>
                <div class="text-muted small">${N(v.created_at)}</div>
            </div>
            <div class="small">${m(v.messaggio||"")}</div>
        </div>
    `).join(""):'<div class="list-group-item text-muted">Nessun evento registrato.</div>';return`
        <div class="practice-details">
            <section class="mb-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Informazioni principali</h6>
                                ${i}
                            </div>
                            <div class="mb-2"><span class="text-muted small">Titolo</span><div class="fw-semibold">${m(t.titolo)}</div></div>
                            <div class="mb-2"><span class="text-muted small">Tipologia</span><div>${m(((C=t.tipo)==null?void 0:C.nome)||"")}</div></div>
                            <div class="mb-2"><span class="text-muted small">Categoria</span><div>${m(t.categoria)}</div></div>
                            <div class="mb-2"><span class="text-muted small">Operatore</span><div>${r}</div></div>
                            <div class="mb-2"><span class="text-muted small">Cliente</span><div>${d}</div></div>
                            <div class="text-muted small">Creata il ${N(t.data_creazione)} \xB7 Aggiornata il ${N(t.data_aggiornamento)}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <h6>Descrizione</h6>
                            <p class="mb-0">${t.descrizione?m(t.descrizione):'<span class="text-muted">Nessuna descrizione.</span>'}</p>
                            ${t.scadenza?`<div class="mt-3"><span class="badge bg-warning text-dark">Scadenza: ${N(t.scadenza)}</span></div>`:""}
                        </div>
                    </div>
                </div>
            </section>

            <section class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Stato pratica</h5>
                </div>
                ${u}
            </section>

            <section class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Allegati</h5>
                </div>
                ${f}
                <div class="list-group mt-3" data-role="attachments-list">
                    ${c}
                </div>
            </section>

            <section class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Note</h5>
                </div>
                ${g}
                <div class="list-group" data-role="notes-list">
                    ${p}
                </div>
            </section>

            <section class="mb-4">
                <h5>Campi personalizzati</h5>
                <div class="list-group">
                    ${y}
                </div>
            </section>

            <section>
                <h5>Storico eventi</h5>
                <div class="list-group">
                    ${S}
                </div>
            </section>
        </div>
    `}function Wt(t,a,e,o={}){const i={...o,container:t};if((i.mode||"modal")==="modal"){const p=document.getElementById("practice-edit-button");p&&(p.onclick=()=>{ot(),setTimeout(()=>Rt(a.id),120)})}const n=t.querySelector("#practice-status-form");n&&n.addEventListener("submit",async p=>{var y,S,w,C;p.preventDefault();const u=n.querySelector('select[name="status"]'),f=u?u.value:null;if(!f)return;appearance.className||classes.push("bg-secondary");const g=n.querySelector('button[type="submit"]');g&&(g.disabled=!0,!appearance.textClass&&!appearance.style&&classes.push("text-white"),g.dataset.originalText=g.dataset.originalText||g.textContent,g.textContent="Aggiornamento...");try{await E("POST",{action:"update_status",id:a.id,status:f}),(S=(y=window.CS)==null?void 0:y.showToast)==null||S.call(y,"Stato pratica aggiornato.","success"),P(),it(a.id,i)}catch(v){(C=(w=window.CS)==null?void 0:w.showToast)==null||C.call(w,`Errore aggiornamento stato: ${v.message}`,"error")}finally{g&&(g.disabled=!1,g.textContent=g.dataset.originalText||"Aggiorna")}});const l=t.querySelector("#practice-upload-form");l&&l.addEventListener("submit",async p=>{var g,y,S,w,C,v;p.preventDefault();const u=l.querySelector('input[type="file"][name="document"]');if(!u||!u.files||!u.files.length){(y=(g=window.CS)==null?void 0:g.showToast)==null||y.call(g,"Seleziona un file da caricare.","warning");return}const f=l.querySelector('button[type="submit"]');f&&(f.disabled=!0,f.dataset.originalText=f.dataset.originalText||f.textContent,f.textContent="Caricamento...");try{await Jt(a.id,u.files[0]),(w=(S=window.CS)==null?void 0:S.showToast)==null||w.call(S,"Documento caricato con successo.","success"),u.value="",it(a.id,i)}catch(T){(v=(C=window.CS)==null?void 0:C.showToast)==null||v.call(C,`Errore upload documento: ${T.message}`,"error")}finally{f&&(f.disabled=!1,f.textContent=f.dataset.originalText||"Carica")}});const r=t.querySelector("#practice-note-form");r&&r.addEventListener("submit",async p=>{var g,y,S,w,C,v,T,k,h;p.preventDefault();const u=(g=r.querySelector('[name="content"]'))==null?void 0:g.value.trim();if(!u){(S=(y=window.CS)==null?void 0:y.showToast)==null||S.call(y,"Inserisci il testo della nota.","warning");return}const f=r.querySelector('button[type="submit"]');f&&(f.disabled=!0,f.dataset.originalText=f.dataset.originalText||f.textContent,f.textContent="Salvataggio...");try{await E("POST",{action:"add_note",id:a.id,content:u,visible_admin:(w=r.querySelector('[name="visible_admin"]'))!=null&&w.checked?1:0,visible_operator:(C=r.querySelector('[name="visible_operator"]'))!=null&&C.checked?1:0}),r.reset();const I=r.querySelector('[name="visible_admin"]');I&&(I.checked=!0);const _=r.querySelector('[name="visible_operator"]');_&&a.assegnatario&&(_.checked=!0),(T=(v=window.CS)==null?void 0:v.showToast)==null||T.call(v,"Nota aggiunta correttamente.","success"),it(a.id,i)}catch(I){(h=(k=window.CS)==null?void 0:k.showToast)==null||h.call(k,`Errore creazione nota: ${I.message}`,"error")}finally{f&&(f.disabled=!1,f.textContent=f.dataset.originalText||"Salva nota")}});const d=t.querySelector('[data-role="attachments-list"]');d&&d.addEventListener("click",p=>{if(!(p.target instanceof Element))return;const u=p.target.closest('[data-action="delete-document"]');if(!u)return;const f=parseInt(u.getAttribute("data-document-id"),10);f&&Y({title:"Elimina documento",message:"Confermi l'eliminazione del documento selezionato? L'operazione non pu\xF2 essere annullata.",confirmLabel:"Elimina",confirmVariant:"danger",onConfirm:async()=>{var g,y,S,w;try{await E("POST",{action:"delete_document",practice_id:a.id,document_id:f}),(y=(g=window.CS)==null?void 0:g.showToast)==null||y.call(g,"Documento eliminato.","success"),it(a.id,i)}catch(C){(w=(S=window.CS)==null?void 0:S.showToast)==null||w.call(S,`Errore eliminazione documento: ${C.message}`,"error")}}})});const c=t.querySelector('[data-role="notes-list"]');c&&c.addEventListener("click",p=>{if(!(p.target instanceof Element))return;const u=p.target.closest('[data-action="delete-note"]');if(!u)return;const f=parseInt(u.getAttribute("data-note-id"),10);f&&Y({title:"Elimina nota",message:"Vuoi realmente eliminare questa nota?",confirmLabel:"Elimina",confirmVariant:"danger",onConfirm:async()=>{var g,y,S,w;try{await E("POST",{action:"delete_note",practice_id:a.id,note_id:f}),(y=(g=window.CS)==null?void 0:g.showToast)==null||y.call(g,"Nota eliminata.","success"),it(a.id,i)}catch(C){(w=(S=window.CS)==null?void 0:S.showToast)==null||w.call(S,`Errore eliminazione nota: ${C.message}`,"error")}}})})}function He(t,a){return!Array.isArray(t)||t.length===0?'<div class="list-group-item text-muted">Nessun campo personalizzato definito.</div>':t.map(e=>{if(!e||typeof e!="object")return"";const o=e.slug||"";if(!o)return"";const i=e.label||o,s=(e.type||"text").toLowerCase(),n=a?a[o]:void 0;let l="";return s==="checkbox"?l=H(n)?"S\xEC":"No":Array.isArray(n)?l=n.map(r=>m(String(r))).join(", "):n!=null&&n!==""?l=m(String(n)):l='<span class="text-muted">Non valorizzato</span>',`
            <div class="list-group-item d-flex justify-content-between">
                <span class="fw-semibold">${m(i)}</span>
                <span>${l}</span>
            </div>
        `}).join("")}async function Jt(t,a){const e=new FormData;e.append("action","add_document"),e.append("id",t),e.append("document",a);const o=Re(),i=await fetch(st,{method:"POST",headers:{"X-Requested-With":"XMLHttpRequest",...o?{"X-CSRF-Token":o}:{}},body:e}),s=await i.json();if(!i.ok)throw new Error(s.error||`HTTP ${i.status}`);return s}async function Ue(){var t,a;try{const e=Qt();q({title:"Nuova tipologia",body:e,footer:U({submitLabel:"Crea tipologia",submitId:"type-submit"}),size:"lg",onShown:o=>Xt(o,{type:null})})}catch(e){(a=(t=window.CS)==null?void 0:t.showToast)==null||a.call(t,`Impossibile aprire la modale: ${e.message}`,"error")}}async function na(t){var a,e;try{const i=(await X(!0)).find(n=>n.id===t);if(!i)throw new Error("Tipologia non trovata");const s=Qt(i);q({title:`Modifica tipologia #${i.id}`,body:s,footer:U({submitLabel:"Salva tipologia",submitId:"type-submit"}),size:"lg",onShown:n=>Xt(n,{type:i})})}catch(o){(e=(a=window.CS)==null?void 0:a.showToast)==null||e.call(a,`Errore caricamento tipologia: ${o.message}`,"error")}}function Qt(t=null){const a=t!=null&&t.campi_personalizzati?JSON.stringify(t.campi_personalizzati,null,2):"";return`
        <form id="caf-patronato-modal-form" class="needs-validation" novalidate>
            <div class="alert alert-danger d-none" data-role="form-alert"></div>
            ${t?`<input type="hidden" name="id" value="${t.id}">`:""}
            <div class="mb-3">
                <label class="form-label" for="type-name">Nome</label>
                <input type="text" class="form-control" id="type-name" name="nome" required maxlength="160" value="${t?m(t.nome):""}">
            </div>
            <div class="mb-3">
                <label class="form-label" for="type-category">Categoria</label>
                <select class="form-select" id="type-category" name="categoria" required>
                    <option value="">Seleziona...</option>
                    <option value="CAF" ${(t==null?void 0:t.categoria)==="CAF"?"selected":""}>CAF</option>
                    <option value="PATRONATO" ${(t==null?void 0:t.categoria)==="PATRONATO"?"selected":""}>Patronato</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="type-custom-fields">Campi personalizzati (JSON)</label>
                <textarea class="form-control font-monospace" id="type-custom-fields" name="campi_personalizzati" rows="8" placeholder="[]">${a?m(a):""}</textarea>
                <small class="text-muted">Inserisci un array JSON di campi con chiavi: slug, label, type, required, options.</small>
            </div>
        </form>
    `}function Xt(t,{type:a}){const e=t.querySelector("#caf-patronato-modal-form");e&&e.addEventListener("submit",async o=>{var i,s;o.preventDefault(),j(e),R(e,!0,"type-submit");try{const n=Ve(e);await E("POST",{action:a?"update_type":"create_type",...n}),(s=(i=window.CS)==null?void 0:i.showToast)==null||s.call(i,`Tipologia ${a?"aggiornata":"creata"} con successo.`,"success"),Q("types"),ot(),$t()}catch(n){j(e,n.message||"Errore nel salvataggio della tipologia.")}finally{R(e,!1,"type-submit")}})}function Ve(t){const a={nome:t.querySelector('[name="nome"]').value.trim(),categoria:t.querySelector('[name="categoria"]').value,campi_personalizzati:[]},e=t.querySelector('[name="id"]');if(e){const i=parseInt(e.value,10);Number.isNaN(i)||(a.id=i)}const o=t.querySelector('[name="campi_personalizzati"]').value.trim();if(o)try{const i=JSON.parse(o);a.campi_personalizzati=Array.isArray(i)?i:[]}catch{throw new Error("I campi personalizzati devono essere un JSON valido.")}return a}function sa(t){Y({title:"Elimina tipologia",message:"Confermi l'eliminazione della tipologia selezionata? Le pratiche associate potrebbero risultare orfane.",confirmLabel:"Elimina",confirmVariant:"danger",onConfirm:async()=>{var a,e,o,i;try{await E("POST",{action:"delete_type",id:t}),(e=(a=window.CS)==null?void 0:a.showToast)==null||e.call(a,"Tipologia eliminata.","success"),Q("types"),$t()}catch(s){(i=(o=window.CS)==null?void 0:o.showToast)==null||i.call(o,`Errore eliminazione tipologia: ${s.message}`,"error")}}})}async function Ge(){const t=Yt();q({title:"Nuovo stato pratica",body:t,footer:U({submitLabel:"Crea stato",submitId:"status-submit"}),size:"lg",onShown:a=>Zt(a,{status:null})})}async function ra(t){var a,e;try{const i=(await O(!0)).find(n=>n.id===t);if(!i)throw new Error("Stato non trovato");const s=Yt(i);q({title:`Modifica stato #${i.id}`,body:s,footer:U({submitLabel:"Salva stato",submitId:"status-submit"}),size:"lg",onShown:n=>Zt(n,{status:i})})}catch(o){(e=(a=window.CS)==null?void 0:a.showToast)==null||e.call(a,`Errore caricamento stato: ${o.message}`,"error")}}function Yt(t=null){return`
        <form id="caf-patronato-modal-form" class="needs-validation" novalidate>
            <div class="alert alert-danger d-none" data-role="form-alert"></div>
            ${t?`<input type="hidden" name="id" value="${t.id}">`:""}
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="status-name">Nome</label>
                    <input type="text" class="form-control" id="status-name" name="nome" required value="${t?m(t.nome):""}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="status-code">Codice</label>
                    <input type="text" class="form-control" id="status-code" name="codice" required pattern="[a-z0-9_-]+" ${t?"readonly":""} value="${t?m(t.codice):""}">
                    <small class="text-muted">Minuscole, numeri, trattini e underscore.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="status-color">Colore badge</label>
                    <input type="text" class="form-control" id="status-color" name="colore" value="${t?m(t.colore):"primary"}" placeholder="Esempio: primary, success, warning">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="status-ordering">Ordine visualizzazione</label>
                    <input type="number" class="form-control" id="status-ordering" name="ordering" min="0" value="${t?parseInt(t.ordering,10):0}">
                </div>
            </div>
        </form>
    `}function Zt(t,{status:a}){const e=t.querySelector("#caf-patronato-modal-form");e&&e.addEventListener("submit",async o=>{var i,s;o.preventDefault(),j(e),R(e,!0,"status-submit");try{const n=We(e);await E("POST",{action:a?"update_status_definition":"create_status",...n}),(s=(i=window.CS)==null?void 0:i.showToast)==null||s.call(i,`Stato ${a?"aggiornato":"creato"} con successo.`,"success"),Q("statuses"),ot(),St(),document.getElementById("practices-filters-form")&&ht()}catch(n){j(e,n.message||"Errore nel salvataggio dello stato.")}finally{R(e,!1,"status-submit")}})}function We(t){const a={nome:t.querySelector('[name="nome"]').value.trim(),codice:t.querySelector('[name="codice"]').value.trim(),colore:t.querySelector('[name="colore"]').value.trim()||"primary",ordering:parseInt(t.querySelector('[name="ordering"]').value||"0",10)||0},e=t.querySelector('[name="id"]');if(e){const o=parseInt(e.value,10);Number.isNaN(o)||(a.id=o)}return a}function la(t){Y({title:"Elimina stato",message:"Eliminando lo stato le pratiche associate saranno impostate allo stato predefinito.",confirmLabel:"Elimina",confirmVariant:"danger",onConfirm:async()=>{var a,e,o,i;try{await E("POST",{action:"delete_status_definition",id:t}),(e=(a=window.CS)==null?void 0:a.showToast)==null||e.call(a,"Stato eliminato.","success"),Q("statuses"),St(),document.getElementById("practices-filters-form")&&ht()}catch(s){(i=(o=window.CS)==null?void 0:o.showToast)==null||i.call(o,`Errore eliminazione stato: ${s.message}`,"error")}}})}function Je(){const t=Kt();q({title:"Nuovo operatore",body:t,footer:U({submitLabel:"Crea operatore",submitId:"operator-submit"}),size:"lg",onShown:a=>te(a,{operator:null})})}async function ca(t){var a,e;try{(!Array.isArray(ct)||!ct.length)&&await at();const o=ct.find(s=>s.id===t);if(!o)throw new Error("Operatore non trovato");const i=Kt(o);q({title:`Modifica operatore #${o.id}`,body:i,footer:U({submitLabel:"Salva operatore",submitId:"operator-submit"}),size:"lg",onShown:s=>te(s,{operator:o})})}catch(o){(e=(a=window.CS)==null?void 0:a.showToast)==null||e.call(a,`Errore apertura operatore: ${o.message}`,"error")}}function Kt(t=null){const e=(t?H(t.attivo):!0)?"checked":"";return`
        <form id="caf-patronato-modal-form" class="needs-validation" novalidate>
            <div class="alert alert-danger d-none" data-role="form-alert"></div>
            ${t?`<input type="hidden" name="id" value="${t.id}">`:""}
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="operator-name">Nome</label>
                    <input type="text" class="form-control" id="operator-name" name="nome" required value="${t?m(t.nome):""}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="operator-surname">Cognome</label>
                    <input type="text" class="form-control" id="operator-surname" name="cognome" required value="${t?m(t.cognome):""}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="operator-email">Email</label>
                    <input type="email" class="form-control" id="operator-email" name="email" required value="${t?m(t.email):""}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="operator-role">Ruolo</label>
                    <select class="form-select" id="operator-role" name="ruolo" required>
                        <option value="">Seleziona...</option>
                        <option value="CAF" ${(t==null?void 0:t.ruolo)==="CAF"?"selected":""}>CAF</option>
                        <option value="PATRONATO" ${(t==null?void 0:t.ruolo)==="PATRONATO"?"selected":""}>Patronato</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="operator-user-id">ID Utente sistema (opzionale)</label>
                    <input type="number" class="form-control" id="operator-user-id" name="user_id" min="1" value="${(t==null?void 0:t.user_id)||""}" placeholder="Collega a un utente interno">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="operator-password">Password (facoltativa)</label>
                    <input type="password" class="form-control" id="operator-password" name="password" placeholder="Lascia vuoto per generare automaticamente">
                    ${t?'<small class="text-muted">Compila per reimpostare la password.</small>':'<small class="text-muted">Lascia vuoto per generare una password temporanea.</small>'}
                </div>
                <div class="col-md-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="operator-active" name="attivo" ${e}>
                        <label class="form-check-label" for="operator-active">Operatore attivo</label>
                    </div>
                </div>
            </div>
        </form>
    `}function te(t,{operator:a}){const e=t.querySelector("#caf-patronato-modal-form");e&&e.addEventListener("submit",async o=>{var i,s;o.preventDefault(),j(e),R(e,!0,"operator-submit");try{const n=Qe(e);await E("POST",{action:"save_operator",...n}),(s=(i=window.CS)==null?void 0:i.showToast)==null||s.call(i,`Operatore ${a?"aggiornato":"creato"} con successo.`,"success"),Q("operators"),ot(),at(),$.canConfigureModule&&et(!0)}catch(n){j(e,n.message||"Errore nel salvataggio operatore.")}finally{R(e,!1,"operator-submit")}})}function Qe(t){const a=t.querySelector('[name="user_id"]'),e=a?a.value.trim():"",o=t.querySelector('[name="id"]'),i={nome:t.querySelector('[name="nome"]').value.trim(),cognome:t.querySelector('[name="cognome"]').value.trim(),email:t.querySelector('[name="email"]').value.trim(),ruolo:t.querySelector('[name="ruolo"]').value,user_id:e?parseInt(e,10):null,password:t.querySelector('[name="password"]').value,attivo:t.querySelector('[name="attivo"]').checked?1:0};if(Number.isNaN(i.user_id)&&(i.user_id=null),o){const s=parseInt(o.value,10);Number.isNaN(s)||(i.id=s)}return i}async function da(t,a){var e,o;try{await E("POST",{action:"toggle_operator",id:t,enable:a}),(e=window.CS)!=null&&e.showToast&&window.CS.showToast(`Operatore ${a?"attivato":"disattivato"} con successo`,"success"),Q("operators"),at()}catch(i){(o=window.CS)!=null&&o.showToast&&window.CS.showToast("Errore: "+i.message,"error")}}async function E(t,a={}){var d;const e=(d=document.querySelector('meta[name="csrf-token"]'))==null?void 0:d.getAttribute("content"),o=t==="GET",i=o&&Object.keys(a).length?`${st}?${new URLSearchParams(a).toString()}`:st,s={method:t,headers:{"Content-Type":o?"application/x-www-form-urlencoded":"application/json","X-Requested-With":"XMLHttpRequest"}};e&&!o&&(s.headers["X-CSRF-Token"]=e),!o&&Object.keys(a).length&&(s.body=JSON.stringify(a));const n=await fetch(i,s),l=await n.text();let r=null;if(l&&l.trim()!=="")try{r=JSON.parse(l)}catch(c){console.error("CAF/Patronato API JSON parse error:",c,l)}if(!n.ok){const c=r&&typeof r.error=="string"?r.error:l||`HTTP ${n.status}`;throw new Error(c)}if(r===null){if(!l||l.trim()==="")return{};throw new Error("Risposta non valida dal server.")}return r}function Xe(t,a={}){!t||typeof a!="object"||a===null||Object.entries(a).forEach(([e,o])=>{const i=t.querySelectorAll(`[name="${e}"]`);i.length&&i.forEach(s=>{s instanceof HTMLInputElement?s.type==="checkbox"?s.checked=H(o):s.type==="radio"?s.checked=s.value===String(o):s.value=o!=null?o:"":(s instanceof HTMLSelectElement||s instanceof HTMLTextAreaElement)&&(s.value=o!=null?o:"")})})}function Ye(t){const a=document.getElementById(t);if(!a)return{};const e=new FormData(a),o={};for(const[i,s]of e.entries())s.trim()!==""&&(o[i]=s.trim());return o}function F(t){t.innerHTML=`
        <div class="d-flex justify-content-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Caricamento...</span>
            </div>
        </div>
    `}function L(t,a){t.innerHTML=`
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            ${m(a)}
        </div>
    `}function Ze(t,a,e){if(!t||!a||a.pages<=1){t&&(t.style.display="none");return}const{page:o,pages:i}=a;let s="";o>1&&(s+=`<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault(); (${e})(${o-1})">Precedente</a></li>`);const n=Math.max(1,o-2),l=Math.min(i,o+2);n>1&&(s+=`<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault(); (${e})(1)">1</a></li>`,n>2&&(s+='<li class="page-item disabled"><span class="page-link">...</span></li>'));for(let r=n;r<=l;r++)s+=`<li class="page-item ${r===o?"active":""}">
            <a class="page-link" href="#" onclick="event.preventDefault(); (${e})(${r})">${r}</a>
        </li>`;l<i&&(l<i-1&&(s+='<li class="page-item disabled"><span class="page-link">...</span></li>'),s+=`<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault(); (${e})(${i})">${i}</a></li>`),o<i&&(s+=`<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault(); (${e})(${o+1})">Successivo</a></li>`),t.querySelector("ul").innerHTML=s,t.style.display="block"}function ee(t){const a=Array.isArray(t)?t.length:0,e=Array.isArray(t)?t.filter(o=>H(o.attivo)).length:0;J("operators-count-total",a),J("operators-count-active",e)}function ae(t){const a=Array.isArray(t)?t.filter(e=>e.stato!=="letta").length:0;J("notifications-count-open",a)}
//# sourceMappingURL=caf-patronato.js.map
