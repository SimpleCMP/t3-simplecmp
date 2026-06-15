"use strict";var SimpleCMP=(()=>{var ye=Object.defineProperty;var ft=Object.getOwnPropertyDescriptor;var Yi=Object.getOwnPropertyNames;var en=Object.prototype.hasOwnProperty;var tn=(r,e)=>{for(var t in e)ye(r,t,{get:e[t],enumerable:!0})},nn=(r,e,t,i)=>{if(e&&typeof e=="object"||typeof e=="function")for(let n of Yi(e))!en.call(r,n)&&n!==t&&ye(r,n,{get:()=>e[n],enumerable:!(i=ft(e,n))||i.enumerable});return r};var rn=r=>nn(ye({},"__esModule",{value:!0}),r),h=(r,e,t,i)=>{for(var n=i>1?void 0:i?ft(e,t):e,o=r.length-1,s;o>=0;o--)(s=r[o])&&(n=(i?s(e,t,n):s(n))||n);return i&&n&&ye(e,t,n),n};var fo={};tn(fo,{CmsBridge:()=>L,LayeredClassifier:()=>G,ServiceDbClient:()=>W,VERSION:()=>to,addEventListener:()=>so,audit:()=>Xr,auditChecks:()=>Be,auditDom:()=>eo,auditWorstSeverity:()=>Yr,getManager:()=>ao,getRecorder:()=>oo,init:()=>io,show:()=>ro,updateConfig:()=>co});function Le(r=document){let e=an(r);return[cn(e),ln(e),pn(e),sn(r)]}function vt(r,e){let t=r.getAttribute("aria-label");if(t!==null&&t.trim()!=="")return t.trim();let i=r.getAttribute("aria-labelledby");return i!==null&&i.trim()!==""?i.split(/\s+/).map(n=>e.querySelector(`[id="${n}"]`)?.textContent?.trim()??"").filter(n=>n!=="").join(" ").trim():""}function on(r,e){let t=vt(r,e);return t!==""?t:(r.textContent??"").trim()}function sn(r){let e="dom-accessible-names",i="Banner region and actions have accessible names",n=Array.from(r.querySelectorAll("simplecmp-banner"));if(n.length===0)return{id:e,section:"2.2",severity:"info",title:i,detail:"No banner is currently mounted \u2014 DOM check skipped.",passed:!0};let o=[];for(let s of n){let a=s.shadowRoot;if(a===null)continue;let c=a.querySelector(".cn-body");c!==null&&vt(c,a)===""&&o.push("the banner region has no accessible name (add aria-label or aria-labelledby)"),Array.from(a.querySelectorAll(".cn-buttons > *")).forEach((m,d)=>{on(m,a)===""&&o.push(`banner action ${d+1} has no accessible name`)})}return o.length===0?{id:e,section:"2.2",severity:"info",title:i,detail:"The banner region and every action button expose an accessible name.",passed:!0}:{id:e,section:"2.2",severity:"critical",title:i,detail:`WCAG 4.1.2 / 2.4.6: assistive tech can't name part of the consent UI, so screen-reader users can't identify or operate it \u2014 consent that can't be perceived isn't valid. Unnamed:
  - ${o.join(`
  - `)}`,passed:!1}}function an(r){let e=r.querySelectorAll("simplecmp-banner"),t=[];for(let i of Array.from(e)){let n=i.shadowRoot;if(n===null)continue;let o=n.querySelectorAll(".cn-buttons > *");for(let s of Array.from(o))s instanceof HTMLElement&&t.push(s)}return t}function cn(r){let e="dom-buttons-are-buttons",i="Banner actions are real button elements";if(r.length===0)return{id:e,section:"2.2",severity:"info",title:i,detail:"No banner is currently mounted \u2014 DOM check skipped.",passed:!0};let n=r.filter(o=>o.tagName!=="BUTTON").map(o=>o.tagName.toLowerCase());return n.length===0?{id:e,section:"2.2",severity:"info",title:i,detail:"All banner action elements are <button> tags.",passed:!0}:{id:e,section:"2.2",severity:"critical",title:i,detail:`Found ${n.length} action element(s) rendered as <${n.join(", ")}> instead of <button>. EDPB 03/2022 \xA7 Skipping flags reject-as-link as a deceptive design pattern \u2014 actions must be real buttons so visitors (and assistive tech) treat them as equivalent affordances. Don't override the bundle's button render.`,passed:!1}}function ln(r){let e="dom-buttons-equal-styling",i="Banner buttons are styled identically",[n,...o]=r;if(n===void 0||o.length===0)return{id:e,section:"1.2",severity:"info",title:i,detail:n===void 0?"No banner is currently mounted \u2014 DOM check skipped.":"Only one button rendered \u2014 equal-styling check requires at least two.",passed:!0};let s=ht(n),a=[];return o.forEach((c,l)=>{let m=ht(c);for(let d of dn)m[d]!==s[d]&&a.push(`button ${l+2} ${d}: ${m[d]} (button 1: ${s[d]})`)}),a.length===0?{id:e,section:"1.2",severity:"info",title:i,detail:"All visible buttons share the same color, weight, and border styling.",passed:!0}:{id:e,section:"1.2",severity:"critical",title:i,detail:`Banner buttons differ in styling beyond their labels \u2014 this is a Stirring dark pattern (BGH I ZR 7/16, DSB D124.0507/24, EDPB 03/2022). Buttons must share identical color, weight and border treatment. Mismatched properties:
  - ${a.slice(0,6).join(`
  - `)}${a.length>6?`
  - (\u2026 ${a.length-6} more)`:""}`,passed:!1}}function pn(r){let e="dom-buttons-wcag-contrast",i="Banner buttons meet WCAG AA contrast (\u2265 4.5:1)";if(r.length===0)return{id:e,section:"1.2",severity:"info",title:i,detail:"No banner is currently mounted \u2014 DOM check skipped.",passed:!0};let n=[];return r.forEach((o,s)=>{let a=getComputedStyle(o),c=yt(a.color),l=un(o);if(c===null||l===null)return;let m=fn(c,l);m<4.5&&n.push(`button ${s+1}: contrast ${m.toFixed(2)}:1 (needs \u2265 4.5:1)`)}),n.length===0?{id:e,section:"1.2",severity:"info",title:i,detail:"All button text meets WCAG AA contrast against its background.",passed:!0}:{id:e,section:"1.2",severity:"critical",title:i,detail:`One or more banner buttons fall below the WCAG AA text-contrast threshold of 4.5:1. The Austrian DSB (D124.0507/24) explicitly cited insufficient contrast as a manipulative design choice. Failing buttons:
  - ${n.join(`
  - `)}`,passed:!1}}var dn=["backgroundColor","color","fontWeight","borderTopWidth","borderTopStyle","borderTopColor"];function ht(r){let e=getComputedStyle(r);return{backgroundColor:e.backgroundColor,color:e.color,fontWeight:e.fontWeight,borderTopWidth:e.borderTopWidth,borderTopStyle:e.borderTopStyle,borderTopColor:e.borderTopColor}}function un(r){let e=r;for(;e!==null;){let t=getComputedStyle(e),i=yt(t.backgroundColor);if(i!==null&&!mn(t.backgroundColor))return i;let n=e.parentNode??e.getRootNode().host??null;e=n instanceof HTMLElement?n:null}return[255,255,255]}function yt(r){let e=r.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);return e===null?null:[Number(e[1]),Number(e[2]),Number(e[3])]}function mn(r){if(r==="transparent")return!0;let e=r.match(/rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*(\d*\.?\d+)\s*\)/);return e!==null&&Number(e[1])===0}function gt([r,e,t]){let i=n=>{let o=n/255;return o<=.03928?o/12.92:((o+.055)/1.055)**2.4};return .2126*i(r)+.7152*i(e)+.0722*i(t)}function fn(r,e){let t=gt(r),i=gt(e),[n,o]=t>i?[t,i]:[i,t];return(n+.05)/(o+.05)}var hn={de:[{phrase:"vielleicht sp\xE4ter",reason:"verschiebt die Entscheidung statt sie zu treffen"},{phrase:"nicht jetzt",reason:"klingt aufschiebend, nicht ablehnend"},{phrase:"\xFCberspringen",reason:'klingt nach \u201Esp\xE4ter", nicht \u201Enein"'},{phrase:"schlie\xDFen",reason:"beschreibt eine UI-Aktion, nicht eine Ablehnung"},{phrase:"weiter ohne",reason:"unklar \u2014 was genau wird abgelehnt?"}],en:[{phrase:"maybe later",reason:"defers instead of refuses"},{phrase:"not now",reason:"sounds like postponement, not refusal"},{phrase:"skip",reason:'reads as "later", not "no"'},{phrase:"close",reason:"describes a UI action, not a rejection"},{phrase:"continue without",reason:"ambiguous \u2014 what is actually being rejected?"},{phrase:"remind me later",reason:"defers instead of refuses"}],fr:[{phrase:"plus tard",reason:"reporte au lieu de refuser"},{phrase:"pas maintenant",reason:"sonne comme un report, pas un refus"},{phrase:"fermer",reason:"d\xE9crit une action UI, pas un refus"}],it:[{phrase:"pi\xF9 tardi",reason:"rimanda invece di rifiutare"},{phrase:"non ora",reason:"suona come un rinvio, non un rifiuto"},{phrase:"chiudi",reason:"descrive un'azione UI, non un rifiuto"}],es:[{phrase:"m\xE1s tarde",reason:"aplaza en lugar de rechazar"},{phrase:"ahora no",reason:"suena como aplazamiento, no rechazo"},{phrase:"cerrar",reason:"describe una acci\xF3n UI, no un rechazo"}],nl:[{phrase:"later",reason:"verschuift de keuze in plaats van te weigeren"},{phrase:"niet nu",reason:"klinkt als uitstel, geen weigering"},{phrase:"sluiten",reason:"beschrijft een UI-actie, geen weigering"},{phrase:"overslaan",reason:'klinkt als "later", niet "nee"'}]},gn={de:[{phrase:"erlebnis verbessern",reason:'\u201EErlebnis" ist Marketing-Sprache'},{phrase:"verbessere dein erlebnis",reason:"manipulativer Nudge zur Zustimmung"},{phrase:"verbessern sie ihr erlebnis",reason:"manipulativer Nudge zur Zustimmung"},{phrase:"volle funktionalit\xE4t",reason:"suggeriert eingeschr\xE4nkten Service bei Ablehnung"},{phrase:"volles erlebnis",reason:"suggeriert eingeschr\xE4nkten Service bei Ablehnung"},{phrase:"vertrauensvolle partner",reason:"vage \u2014 benenne die Verantwortlichen"},{phrase:"optimal erleben",reason:'\u201Eoptimal" ist subjektiv und manipulativ'},{phrase:"personalisieren sie ihren besuch",reason:"Marketing-Nudge zur Zustimmung"},{phrase:"personalisiere deinen besuch",reason:"Marketing-Nudge zur Zustimmung"}],en:[{phrase:"improve your experience",reason:"marketing nudge toward acceptance"},{phrase:"enhance your experience",reason:"marketing nudge toward acceptance"},{phrase:"get the full experience",reason:"suggests degraded service on refusal"},{phrase:"full functionality",reason:"suggests degraded service on refusal"},{phrase:"trusted partners",reason:"vague \u2014 name the controllers"},{phrase:"personalize your visit",reason:"marketing nudge toward acceptance"},{phrase:"continue to enjoy",reason:"marketing nudge"},{phrase:"tailored experience",reason:"marketing nudge toward acceptance"}],fr:[{phrase:"meilleure exp\xE9rience",reason:"langage marketing, pousse vers l\u2019acceptation"},{phrase:"exp\xE9rience optimale",reason:"\xAB optimal \xBB est subjectif et manipulateur"},{phrase:"partenaires de confiance",reason:"vague \u2014 nommer les responsables"},{phrase:"personnaliser votre visite",reason:"nudge marketing vers l\u2019acceptation"}],it:[{phrase:"migliore esperienza",reason:"linguaggio marketing, spinge verso l\u2019accettazione"},{phrase:"esperienza ottimale",reason:"\xABottimale\xBB \xE8 soggettivo e manipolativo"},{phrase:"partner di fiducia",reason:"vago \u2014 nominare i titolari"},{phrase:"personalizza la tua visita",reason:"nudge marketing verso l\u2019accettazione"}],es:[{phrase:"mejor experiencia",reason:"lenguaje marketing, empuja hacia la aceptaci\xF3n"},{phrase:"experiencia \xF3ptima",reason:"\xAB\xF3ptima\xBB es subjetiva y manipuladora"},{phrase:"socios de confianza",reason:"vago \u2014 nombrar los responsables"},{phrase:"personalizar tu visita",reason:"nudge marketing hacia la aceptaci\xF3n"}],nl:[{phrase:"betere ervaring",reason:"marketingtaal, duwt richting acceptatie"},{phrase:"optimale ervaring",reason:'"optimaal" is subjectief en manipulatief'},{phrase:"vertrouwde partners",reason:"vaag \u2014 noem de verwerkingsverantwoordelijken"},{phrase:"personaliseer je bezoek",reason:"marketingnudge richting acceptatie"}]};function Ue(r,e){let t=r.translations;if(typeof t!="object"||t===null)return[];let i=[];for(let[n,o]of Object.entries(t)){if(typeof o!="object"||o===null)continue;let s=o;for(let a of e){if(typeof s!="object"||s===null){s=void 0;break}s=s[a]}typeof s=="string"&&s.trim()!==""&&i.push({lang:n,text:s})}return i}function bt(r,e,t){let i=r[e.toLowerCase()];if(i===void 0)return null;let n=t.toLowerCase();for(let o of i)if(n.includes(o.phrase))return o;return null}function kt(r){let e=Ue(r,["decline"]),t=[];for(let{lang:i,text:n}of e){let o=bt(hn,i,n);o!==null&&t.push(`[${i}] "${n}" \u2014 ${o.reason}`)}return t.length===0?{passed:!0,detail:"Reject button labels read as clear refusal across all configured languages."}:{passed:!1,detail:`Reject button label(s) read as deferral or postponement instead of refusal, which EDPB 03/2022 \xA7 Skipping treats as deceptive design. Affected language(s):
  - ${t.join(`
  - `)}`}}var Ie=80,Ne=600;function wt(r){let e=Ue(r,["consentNotice","description"]),t=[];for(let{lang:i,text:n}of e){let o=n.length;o<Ie?t.push(`[${i}] ${o} chars \u2014 below the ${Ie}-char floor; likely doesn\u2019t carry the purpose enumeration + controller info "informed" consent needs.`):o>Ne&&t.push(`[${i}] ${o} chars \u2014 above the ${Ne}-char ceiling; risks EDPB 03/2022 \xA7 Overloading. Trim to the essentials or move detail into the modal.`)}return t.length===0?{passed:!0,detail:"Banner descriptions sit in the informative-but-readable range across languages."}:{passed:!1,detail:`Banner description length is outside the recommended range (${Ie}\u2013${Ne} chars). Affected language(s):
  - ${t.join(`
  - `)}`}}function St(r){let e=Ue(r,["consentNotice","description"]),t=[];for(let{lang:i,text:n}of e){let o=bt(gn,i,n);o!==null&&t.push(`[${i}] "${o.phrase}" \u2014 ${o.reason}`)}return t.length===0?{passed:!0,detail:"Banner descriptions stay clear of marketing-nudge language."}:{passed:!1,detail:`Banner description(s) contain marketing-nudge phrases that push the visitor toward accepting \u2014 EDPB 03/2022 \xA7 Stirring + CNIL 17.09.2020 flag this as deceptive design. Affected phrase(s):
  - ${t.join(`
  - `)}`}}var Be=[{id:"privacy-policy-url",section:"1.5",title:"Privacy-policy URL configured",failSeverity:"critical",run:r=>{let e=r.privacyPolicy;return typeof e=="string"&&e!==""&&e!=="#"?{passed:!0,detail:"Privacy policy URL is set."}:typeof e=="object"&&e!==null&&Object.values(e).filter(i=>typeof i=="string"&&i!==""&&i!=="#").length>0?{passed:!0,detail:"Privacy policy URL is set (per-language map)."}:{passed:!1,detail:"GDPR Art. 13 requires the privacy policy to be linked before consent is captured. Set `simplecmp.privacyPolicy` to the live URL of the site\u2019s policy."}}},{id:"first-layer-reject",section:"1.3",title:'"Reject all" available on first layer',failSeverity:"critical",run:r=>r.hideDeclineAll===!0?{passed:!1,detail:"VG Hannover 10 A 5385/22 (19.03.2025) and the EDPB Cookie Banner Taskforce require a first-layer Reject affordance. `hideDeclineAll: true` removes it. Set `hideDeclineAll: false` or remove the property."}:{passed:!0,detail:"Reject affordance is present on the first banner layer."}},{id:"opt-in-defaults",section:"1.1",title:"Non-essential services default to OFF",failSeverity:"critical",run:r=>{let e=r.services??[],t=[];for(let i of e)i.required!==!0&&i.default===!0&&t.push(i.name);return t.length===0?{passed:!0,detail:"All non-essential services default to OFF."}:{passed:!1,detail:`${t.length} non-essential service(s) have \`default: true\` (pre-consent granted): ${t.slice(0,5).join(", ")}${t.length>5?`, \u2026 (${t.length-5} more)`:""}. Pre-ticked consent fails Planet49 / Cookie II. Either mark the service \`required: true\` (if it truly is essential) or remove the \`default: true\` flag.`}}},{id:"pre-consent-blocking",section:"1.7",title:"Pre-consent tracking blocked",failSeverity:"critical",run:r=>{let e=r.interceptRuntime;return e===void 0||e===!1?{passed:!1,detail:"Without `interceptRuntime`, third-party scripts can dispatch requests before the user has chosen \u2014 \xA7 25 TDDDG and Art. 5(3) ePrivacy require prior consent for any non-essential storage/access. Enable `interceptRuntime: true` (or the CMS-side equivalent setting that does the same)."}:{passed:!0,detail:"Pre-consent runtime blocking is enabled."}}},{id:"persistent-revocation-trigger",section:"1.6",title:"Persistent revocation trigger enabled",failSeverity:"warning",run:r=>{let e=r.floatingTrigger;return e===void 0||e===!1?{passed:!1,detail:"GDPR Art. 7(3) demands that withdrawal be as easy as granting consent. Without a persistent trigger (`floatingTrigger`), visitors must hunt for the consent banner to revoke. Set `floatingTrigger: true` or configure a custom trigger."}:{passed:!0,detail:"Persistent revocation trigger is enabled."}}},{id:"imprint-url-dach",section:"1.5",title:"Imprint URL configured (DACH compliance)",failSeverity:"warning",run:r=>{let e=r.imprint;return typeof e=="string"&&e!==""&&e!=="#"?{passed:!0,detail:"Imprint URL is set."}:typeof e=="object"&&e!==null&&Object.values(e).filter(i=>typeof i=="string"&&i!==""&&i!=="#").length>0?{passed:!0,detail:"Imprint URL is set (per-language map)."}:{passed:!1,detail:"German TMG / Austrian ECG / Swiss UWG require a separately reachable Impressum. Surface the link next to the privacy policy in the banner by setting `simplecmp.imprint` to the live URL. Skip this check only if the site is not targeted at DACH visitors."}}},{id:"heuristic-decline-label-clarity",section:"2.2",title:"Reject button labels read as clear refusal",failSeverity:"warning",run:kt},{id:"heuristic-no-marketing-nudge-in-description",section:"2.3",title:"Banner description avoids marketing nudges",failSeverity:"warning",run:St},{id:"heuristic-description-length",section:"2.1",title:"Banner description sits in a readable length range",failSeverity:"warning",run:wt},{id:"services-have-purposes",section:"1.4",title:"Each service declares processing purposes",failSeverity:"warning",run:r=>{let e=r.services??[],t=[];for(let i of e){let n=i.purposes;(!Array.isArray(n)||n.length===0)&&t.push(i.name)}return t.length===0?{passed:!0,detail:"All services declare at least one purpose."}:{passed:!1,detail:`${t.length} service(s) have no \`purposes\` declared: ${t.slice(0,5).join(", ")}${t.length>5?`, \u2026 (${t.length-5} more)`:""}. EDPB 05/2020 \xA7 42 requires consent to be specific per purpose. Either tag each service with at least one purpose category or remove unused entries.`}}}];function _t(r){return Be.map(e=>{let t=e.run(r);return{id:e.id,section:e.section,severity:t.passed?"info":e.failSeverity,title:e.title,detail:t.detail,passed:t.passed}})}function At(r){let e="info";for(let t of r){if(t.severity==="critical")return"critical";t.severity==="warning"&&(e="warning")}return e}var vn="opt-in",yn=new Set(["AT","BE","BG","HR","CY","CZ","DK","EE","FI","FR","DE","GR","HU","IE","IT","LV","LT","LU","MT","NL","PL","PT","RO","SK","SI","ES","SE","IS","LI","NO","GB","CH","EU","EEA"]),bn=new Set(["US","US-CA","US-VA","US-CO","US-CT","US-UT","US-TX","US-OR","US-MT","US-DE","US-IA","US-NE","US-NH","US-NJ","US-IN","US-TN","US-MN","US-MD","US-KY","US-RI"]);function be(r,e,t=vn){if(r){let i=r.toUpperCase();if(e){let n=e[r]??e[i];if(n)return n}if(yn.has(i))return"opt-in";if(bn.has(i))return"opt-out"}return t}function qe(){if(typeof document>"u")return[];let r=document.cookie.split(";"),e=[],t=/^\s*([^=]+)\s*=\s*(.*?)$/;for(let i of r){let n=t.exec(i);n!==null&&e.push({name:n[1]??"",value:n[2]??""})}return e}function He(r){for(let e of qe())if(e.name===r)return e;return null}function Ct(r,e,t,i,n){if(typeof document>"u")return;let o="";if(t){let s=new Date;s.setTime(s.getTime()+t*24*60*60*1e3),o=`; expires=${s.toUTCString()}`}i!==void 0&&(o+=`; domain=${i}`),o+=n!==void 0?`; path=${n}`:"; path=/",document.cookie=`${r}=${e||""}${o}; SameSite=Lax`}function Y(r,e,t){if(typeof document>"u")return!1;let i=`${r}=; Max-Age=-99999999;`;return document.cookie=i,i+=` path=${e||"/"};`,document.cookie=i,t!==void 0&&(i+=` domain=${t};`,document.cookie=i),He(r)===null}var Fe=class{constructor(){this.value=null}get(){return this.value}set(e){this.value=e}delete(){this.value=null}},Ve=class{constructor(e){this.cookieName=e.storageName,this.cookieDomain=e.cookieDomain,this.cookiePath=e.cookiePath,this.cookieExpiresAfterDays=e.cookieExpiresAfterDays}get(){let e=He(this.cookieName);return e?e.value:null}set(e){Ct(this.cookieName,e,this.cookieExpiresAfterDays,this.cookieDomain,this.cookiePath)}delete(){Y(this.cookieName)}},ke=class{constructor(e,t){this.key=e.storageName,this.handle=t}get(){return this.handle.getItem(this.key)}getWithKey(e){return this.handle.getItem(e)}set(e){this.handle.setItem(this.key,e)}setWithKey(e,t){this.handle.setItem(e,t)}delete(){this.handle.removeItem(this.key)}deleteWithKey(e){this.handle.removeItem(e)}},We=class extends ke{constructor(e){super(e,localStorage)}},ee=class extends ke{constructor(e){super(e,sessionStorage)}},kn={cookie:Ve,test:Fe,localStorage:We,sessionStorage:ee},Ke=kn;function xt(r){let e={};for(let t of Array.from(r.attributes))t.name.startsWith("data-")&&(e[t.name.slice(5)]=t.value);return e}function Et(r,e){for(let[t,i]of Object.entries(r))e[t]!==i&&e.setAttribute(`data-${t}`,i)}function wn(r){return r.replace(/[-[\]/{}()*+?.\\^$|]/g,"\\$&")}function zt(r,e){return r===void 0?void 0:typeof r=="function"?r(e):new Function("opts",r)(e)}var te=class{constructor(e,t,i){this.confirmed=!1;this.changed=!1;this.states={};this.initialized={};this.executedOnce={};this.watchers=new Set;this.config=e;let n={storageName:this.storageName,cookieDomain:this.cookieDomain,cookiePath:this.cookiePath,cookieExpiresAfterDays:this.cookieExpiresAfterDays};if(t!==void 0)this.store=t;else{let o=Ke[this.storageMethod]??Ke.cookie;this.store=new o(n)}this.auxiliaryStore=i??new ee(n),this.consents=this.defaultConsents,this.loadConsents(),this.applyConsents(),this.savedConsents={...this.consents}}get storageMethod(){return this.config.storageMethod??"cookie"}get storageName(){return this.config.storageName??this.config.cookieName??"simplecmp"}get cookieDomain(){return this.config.cookieDomain}get cookiePath(){return this.config.cookiePath}get cookieExpiresAfterDays(){return this.config.cookieExpiresAfterDays??120}get defaultConsents(){let e={};for(let t of this.config.services)e[t.name]=this.getDefaultConsent(t);return e}watch(e){this.watchers.add(e)}unwatch(e){this.watchers.delete(e)}notify(e,t){for(let i of this.watchers)i.update(this,e,t)}getService(e){return this.config.services.find(t=>t.name===e)}get regime(){return be(this.config.region,this.config.regimes,this.config.regimeDefault)}get bannerMode(){let e=this.regime;return e==="opt-in"?"wall":e==="opt-out"?"notice":"none"}getDefaultConsent(e){if(e.required??this.config.required??!1)return!0;if(this.config.respectGPC!==!1&&typeof navigator<"u"&&navigator.globalPrivacyControl===!0)return!1;let i=this.regime!=="opt-in";return e.default??this.config.default??i}changeAll(e){let t=0;for(let i of this.config.services.filter(n=>!n.contextualConsentOnly)){let o=i.required??this.config.required??!1?!0:e;this.updateConsent(i.name,o)&&t++}return t}updateConsent(e,t){let i=(this.consents[e]||!1)!==t;return this.consents[e]=t,this.notify("consents",this.consents),i}resetConsents(){this.consents=this.defaultConsents,this.states={},this.confirmed=!1,this.applyConsents(),this.savedConsents={...this.consents},this.store.delete(),this.notify("consents",this.consents)}getConsent(e){return this.consents[e]||!1}loadConsents(){let e=this.store.get();if(e===null)return this.consents;let t;try{t=JSON.parse(decodeURIComponent(e))}catch{return this.consents=this.defaultConsents,this.consents}let i,n;if(t!==null&&typeof t=="object"&&"__v"in t&&"consents"in t){let s=t;i=s.__v,n=s.consents}else n=t;if(typeof n!="object"||n===null||Array.isArray(n))return this.consents=this.defaultConsents,this.consents;this.consents=n;let o=this.config.consentVersion;return o!==void 0&&i!==void 0&&!this._versionsCompatible(i,o)?(this.versionMismatch={storedVersion:i,configVersion:o,policy:this.config.consentVersionPolicy??"any"},this.consents=this.defaultConsents,this.confirmed=!1,this.changed=!0):(this._checkConsents(),this.notify("consents",this.consents)),this.consents}_versionsCompatible(e,t){let i=String(e),n=String(t);return(this.config.consentVersionPolicy??"any")==="major"?i.split(".")[0]===n.split(".")[0]:i===n}saveAndApplyConsents(e){this.saveConsents(e),this.applyConsents()}changedConsents(){let e={};for(let[t,i]of Object.entries(this.consents))this.savedConsents[t]!==i&&(e[t]=i);return e}saveConsents(e){let t=this.config.consentVersion!==void 0?{__v:this.config.consentVersion,consents:this.consents}:this.consents,i=encodeURIComponent(JSON.stringify(t));this.store.set(i),this.confirmed=!0,this.changed=!1,this.versionMismatch=void 0;let n=this.changedConsents();this.savedConsents={...this.consents},this.notify("saveConsents",{changes:n,consents:this.consents,type:e??"script"})}applyConsents(e,t,i){let n=0;for(let o of this.config.services){if(i!==void 0&&i!==o.name)continue;let s=o.vars??{},a={service:o,config:this.config,vars:s};this.initialized[o.name]||(this.initialized[o.name]=!0,zt(o.onInit,a))}for(let o of this.config.services){if(i!==void 0&&i!==o.name)continue;let s=this.states[o.name],a=o.vars??{},c=o.optOut!==void 0?o.optOut:this.config.optOut??!1,l=o.required!==void 0?o.required:this.config.required??!1,m=this.confirmed||c||e||t||!1,d=this.getConsent(o.name)&&m||l,f={service:o,config:this.config,vars:a,consents:this.consents,confirmed:this.confirmed};s!==d&&n++,!e&&(zt(d?o.onAccept:o.onDecline,f),this.updateServiceElements(o,d),this.updateServiceStorage(o,d),o.callback!==void 0&&o.callback(d,o),this.config.callback!==void 0&&this.config.callback(d,o),this.states[o.name]=d)}if(!e&&typeof document<"u"){let o=new Set(this.config.services.map(a=>a.name)),s=new Set;for(let a of Array.from(document.querySelectorAll("[data-name]"))){let c=a.getAttribute("data-name");if(c===null||c===""||o.has(c)||s.has(c)||i!==void 0&&i!==c)continue;s.add(c);let l={name:c,purposes:[]},m=this.getConsent(c);this.updateServiceElements(l,m)}}return this.notify("applyConsents",{changedServices:n,serviceName:i}),n}updateServiceElements(e,t){if(typeof document>"u")return;if(t){if(e.onlyOnce&&this.executedOnce[e.name])return;this.executedOnce[e.name]=!0}let i=document.querySelectorAll(`[data-name='${e.name}']`);for(let n of Array.from(i)){let o=n.parentElement;if(!o)continue;let s=xt(n),{type:a,src:c,href:l}=s,m=["href","src","type"];if(a==="placeholder"){t?(n.style.display="none",s["original-display"]=n.style.display):n.style.display=s["original-display"]||"block";continue}if(n.tagName==="IFRAME"){if(t&&n.src===c){console.debug(`Skipping ${n.tagName} for service ${e.name}, as it already has the correct type...`);continue}let f=document.createElement(n.tagName);for(let g of Array.from(n.attributes))if(g.name==="style"){let[_="",Xi=""]=g.value.split(":");f.style[_.trim()]=Xi.trim()}else f.setAttribute(g.name,g.value);f.innerText=n.innerText,f.text=n.text,t?(s["original-display"]!==void 0&&(f.style.display=s["original-display"]),s.src!==void 0&&(f.src=s.src)):(f.src="about:blank",s["modified-by-klaro"]!==void 0&&s["original-display"]!==void 0?f.setAttribute("data-original-display",s["original-display"]):(n.style.display!==void 0&&f.setAttribute("data-original-display",n.style.display),f.setAttribute("data-modified-by-klaro","yes")),f.style.display="none"),o.insertBefore(f,n),o.removeChild(n),this._toggleAutoPlaceholder(f,e,t)}else if(n.tagName==="SCRIPT"||n.tagName==="LINK"){let d=n;if(t&&d.type===(a??"")&&d.src===c&&d.href===l){console.debug(`Skipping ${n.tagName} for service ${e.name}, as it already has the correct type / src / href...`);continue}let f=document.createElement(n.tagName);for(let g of Array.from(n.attributes))f.setAttribute(g.name,g.value);if(n.hasAttribute("nonce")&&f.setAttribute("nonce",n.nonce??""),f.innerText=n.innerText,f.text=n.text,t)f.type=a??"",c!==void 0&&(f.src=c),l!==void 0&&(f.href=l);else if(f.type="text/plain",n.tagName==="LINK"){let g=f.getAttribute("href");g!==null&&g!==""&&(f.hasAttribute("data-href")||f.setAttribute("data-href",g),f.removeAttribute("href"))}o.insertBefore(f,n),o.removeChild(n),this._toggleAutoPlaceholder(f,e,t)}else{let d=n;if(t){for(let f of m){let g=s[f];g!==void 0&&(s[`original-${f}`]===void 0&&(s[`original-${f}`]=d[f]??""),d[f]=g)}s.title!==void 0&&(n.title=s.title),s["original-display"]!==void 0?n.style.display=s["original-display"]:n.style.removeProperty("display")}else{s.title!==void 0&&n.removeAttribute("title"),s["original-display"]===void 0&&n.style.display!==void 0&&(s["original-display"]=n.style.display),n.style.display="none";for(let f of m)s[f]!==void 0&&(s[`original-${f}`]!==void 0?d[f]=s[`original-${f}`]:n.removeAttribute(f))}Et(s,n),this._toggleAutoPlaceholder(n,e,t)}}}_toggleAutoPlaceholder(e,t,i){if(typeof document>"u")return;let n=e.nextElementSibling,o=n?.hasAttribute("data-simplecmp-auto-placeholder")&&n.getAttribute("data-simplecmp-for")===t.name?n:null;if(i){o!==null&&o.remove();return}if(this.config.autoContextualPlaceholder===!1||t.noAutoPlaceholder===!0||e.hasAttribute("data-no-placeholder")||o!==null)return;let s=document.createElement("simplecmp-contextual-notice");s.setAttribute("service-name",t.name),s.setAttribute("data-simplecmp-auto-placeholder",""),s.setAttribute("data-simplecmp-for",t.name);let a=e.getAttribute("data-blocked-source");a!==null&&s.setAttribute("data-blocked-source",a);for(let l of["data-simplecmp-title","data-simplecmp-description"]){let m=e.getAttribute(l);m!==null&&s.setAttribute(l,m)}let c=s;c.serviceName=t.name,c.manager=this,c.config=this.config,e.insertAdjacentElement("afterend",s)}updateServiceStorage(e,t){if(t||!e.cookies||e.cookies.length===0||typeof window>"u"||typeof document>"u")return;let i=qe();for(let n of e.cookies){let o=n,s,a;if(Array.isArray(o))[o,s,a]=o;else if(o!==null&&typeof o=="object"&&!(o instanceof RegExp)){let l=o;o=l.pattern,s=l.path,a=l.domain}if(o===void 0)continue;let c;if(o instanceof RegExp)c=o;else if(typeof o=="string")c=o.startsWith("^")?new RegExp(o):new RegExp(`^${wn(o)}$`);else continue;for(let l of i){if(c.exec(l.name)===null)continue;console.debug("Deleting cookie:",l.name,"Matched pattern:",c,"Path:",s,"Domain:",a);let m=Y(l.name,s,a);!m&&a===void 0&&(m=Y(l.name,s,`.${window.location.hostname}`)),m||console.warn(`SimpleCMP: cookie "${l.name}" still present after deletion attempt for service "${e.name}". It may be set on a path/domain we cannot reach from JS, or another script re-set it.`)}}}_checkConsents(){let e=!0,t=new Set(this.config.services.map(n=>n.name)),i=new Set(Object.keys(this.consents));for(let n of Object.keys(this.consents))t.has(n)||delete this.consents[n];for(let n of this.config.services)i.has(n.name)||(this.consents[n.name]=this.getDefaultConsent(n),e=!1);this.confirmed=e,e||(this.changed=!0)}};var Mt={acceptAll:"Alles akzeptieren",acceptSelected:"Auswahl akzeptieren",consentModal:{description:"Hier kannst du die Dienste, die wir auf dieser Website nutzen m\xF6chten, bewerten und anpassen. Du hast das Sagen! Aktiviere oder deaktiviere die Dienste, wie du es f\xFCr richtig h\xE4ltst.",privacyPolicy:{text:"Um mehr zu erfahren, lies bitte unsere {privacyPolicy}."}},consentNotice:{changeDescription:"Seit deinem letzten Besuch gab es \xC4nderungen, bitte erneuere deine Zustimmung.",description:"Hi! K\xF6nnen wir bitte ein paar zus\xE4tzliche Dienste f\xFCr {purposes} aktivieren? Du kannst deine Zustimmung sp\xE4ter jederzeit \xE4ndern oder zur\xFCckziehen.",learnMore:"Lass mich w\xE4hlen"},contextualConsent:{description:"M\xF6chtest du von {title} bereitgestellte externe Inhalte laden?",descriptionEmptyStore:"Um diesem Dienst dauerhaft zustimmen zu k\xF6nnen, musst du {title} in den {link} zustimmen.",descriptionUnknownHost:"Blockierter Drittinhalt von {title}. Diese Quelle wurde vom Site-Administrator noch nicht freigegeben \u2014 bitte wende dich an die Administratorin oder den Administrator, um diese Inhalte zu aktivieren."},decline:"Nein danke",ok:"Passt f\xFCr mich",privacyPolicy:{text:"Um mehr zu erfahren, lies bitte unsere {privacyPolicy}."},purposes:{advertising:{description:"Diese Dienste verarbeiten pers\xF6nliche Informationen, um dir personalisierte oder interessenbezogene Werbung zu zeigen."},analytics:{description:"Diese Dienste erfassen, wie Besucher diese Seite nutzen, damit wir ihre Funktion messen und verbessern k\xF6nnen."},marketing:{description:"Diese Dienste verarbeiten pers\xF6nliche Daten, um dir relevante Inhalte \xFCber Produkte, Dienstleistungen oder Themen zu zeigen, die dich interessieren k\xF6nnten."},personalization:{description:"Diese Dienste passen die Inhalte dieser Seite an deine Vorlieben und dein bisheriges Verhalten an."}},service:{disableAll:{description:"Mit diesem Schalter kannst du alle Dienste aktivieren oder deaktivieren."},optOut:{description:"Diese Dienste werden standardm\xE4\xDFig geladen (du kannst dich jedoch abmelden)"}}};var $t={consentModal:{description:"Aqu\xED puedes evaluar y personalizar los servicios que nos gustar\xEDa utilizar en este sitio web. \xA1T\xFA decides! Habilita o deshabilita los servicios como mejor te parezca.",privacyPolicy:{text:"Para saber m\xE1s, por favor lee nuestra {privacyPolicy}."}},consentNotice:{changeDescription:"Ha habido cambios desde tu \xFAltima visita. Por favor renueva tu consentimiento.",description:"\xA1Hola! \xBFPodemos habilitar algunos servicios adicionales para {purposes}? Siempre puedes cambiar o retirar tu consentimiento m\xE1s tarde.",learnMore:"Quiero elegir"},contextualConsent:{description:"\xBFQuieres cargar el contenido externo suministrado por {title}?"},decline:"No, gracias",ok:"De acuerdo",privacyPolicy:{text:"Para saber m\xE1s, por favor lee nuestra {privacyPolicy}."},purposes:{advertising:{description:"Estos servicios procesan informaci\xF3n personal para mostrarte anuncios personalizados o basados en tus intereses."},functional:{description:"Estos servicios son esenciales para el correcto funcionamiento de este sitio web. No puedes desactivarlos ya que la p\xE1gina no funcionar\xEDa correctamente."},marketing:{description:"Estos servicios procesan informaci\xF3n personal para mostrarte contenido relevante sobre productos, servicios o temas que puedan interesarte."}},service:{disableAll:{description:"Usa este interruptor para activar o desactivar todos los servicios."},optOut:{description:"Este servicio est\xE1 habilitado por defecto (pero puedes optar por desactivarlo)"}}};var Pt={consentModal:{description:"Tu peux \xE9valuer et personnaliser ici les services que nous aimerions utiliser sur ce site. C'est toi qui d\xE9cides ! Active ou d\xE9sactive les services comme bon te semble.",privacyPolicy:{text:"Pour en savoir plus, lis notre {privacyPolicy}."}},consentNotice:{changeDescription:"Il y a eu des changements depuis ta derni\xE8re visite, renouvelle ton consentement s'il te pla\xEEt.",description:"Salut ! On peut activer quelques services suppl\xE9mentaires pour {purposes} ? Tu peux modifier ou retirer ton consentement \xE0 tout moment.",learnMore:"Laisse-moi choisir"},contextualConsent:{description:"Tu veux charger un contenu externe fourni par {title} ?"},decline:"Non merci",ok:"\xC7a marche",privacyPolicy:{text:"Pour en savoir plus, lis notre {privacyPolicy}."},purposes:{advertising:{description:"Ces services traitent les informations personnelles pour te pr\xE9senter des publicit\xE9s personnalis\xE9es ou bas\xE9es sur tes int\xE9r\xEAts."},marketing:{description:"Ces services traitent les informations personnelles afin de te pr\xE9senter du contenu pertinent sur les produits, les services ou les sujets qui pourraient t'int\xE9resser."}},service:{disableAll:{description:"Utilise ce bouton pour activer ou d\xE9sactiver tous les services."},optOut:{description:"Ce service est charg\xE9 par d\xE9faut (mais tu peux le d\xE9sactiver)"}}};var Dt={consentModal:{description:"Qui puoi valutare e personalizzare i servizi che vorremmo utilizzare su questo sito web. Sei tu a decidere! Abilita o disabilita i servizi come preferisci.",privacyPolicy:{text:"Per saperne di pi\xF9, leggi la nostra {privacyPolicy}."}},consentNotice:{changeDescription:"Ci sono stati dei cambiamenti dalla tua ultima visita, ti chiediamo di rinnovare il tuo consenso.",description:"Ciao! Possiamo attivare alcuni servizi aggiuntivi per {purposes}? Puoi sempre modificare o ritirare il tuo consenso in un secondo momento.",learnMore:"Fammi scegliere"},contextualConsent:{description:"Vuoi caricare contenuti esterni forniti da {title}?"},decline:"No, grazie",ok:"Va bene",privacyPolicy:{text:"Per saperne di pi\xF9, leggi la nostra {privacyPolicy}."},purposes:{advertising:{description:"Questi servizi elaborano le informazioni personali per mostrarti annunci pubblicitari personalizzati o basati sui tuoi interessi."},marketing:{description:"Questi servizi elaborano le informazioni personali per mostrarti contenuti rilevanti su prodotti, servizi o argomenti che potrebbero interessarti."}},service:{disableAll:{description:"Usa questo interruttore per attivare o disattivare tutti i servizi."},optOut:{description:"Questo servizio \xE8 caricato di default (ma puoi scegliere di non usarlo)"}}};var Tt={consentModal:{description:"Hier kun je de diensten beoordelen en aanpassen die wij op deze website willen gebruiken. Jij hebt de leiding! Schakel de diensten naar eigen inzicht in of uit.",privacyPolicy:{text:"Voor meer informatie kun je ons {privacyPolicy} lezen."}},consentNotice:{changeDescription:"Er waren veranderingen sinds je laatste bezoek, vernieuw je toestemming alsjeblieft.",description:"Hoi! Kunnen we een aantal extra diensten voor {purposes} inschakelen? Je kunt je toestemming later altijd nog wijzigen of intrekken.",learnMore:"Laat me kiezen"},contextualConsent:{description:"Wil je externe content laden die door {title} wordt aangeleverd?"},decline:"Nee, bedankt",ok:"Klinkt goed",privacyPolicy:{text:"Voor meer informatie kun je ons {privacyPolicy} lezen."},purposes:{advertising:{description:"Deze diensten verwerken persoonlijke informatie om je gepersonaliseerde of op interesse gebaseerde advertenties te tonen."},functional:{description:"Deze diensten zijn essentieel voor het correct functioneren van deze website. Je kunt ze hier niet uitschakelen omdat de dienst anders niet correct zou werken."},marketing:{description:"Deze diensten verwerken persoonlijke informatie om je relevante inhoud te tonen over producten, diensten of onderwerpen waarin je ge\xEFnteresseerd zou kunnen zijn."}},service:{disableAll:{description:"Gebruik deze schakelaar om alle diensten in of uit te schakelen."},optOut:{description:"Deze diensten worden standaard geladen (maar je kunt je afmelden)"}}};var En={de:Mt,es:$t,fr:Pt,it:Dt,nl:Tt},Rt=En;function Ot(r){let e=new Set;for(let t of r.services){let i=t.purposes??[];for(let n of i)e.add(n)}return Array.from(e)}function ie(r,e,t=!0){let i=r;for(let n of Object.keys(e)){if(n==="__proto__"||n==="constructor"||n==="prototype")continue;let o=e[n],s=i[n];typeof o=="string"?(t||s===void 0)&&(i[n]=o):typeof o=="object"&&o!==null&&(typeof s=="object"&&s!==null?ie(s,o,t):(t||s===void 0)&&(i[n]=o))}return r}function zn(r,...e){let t=e[0],i;e.length===0?i={}:typeof t=="string"||typeof t=="number"?i=Array.prototype.slice.call(e):i=t??{};let n=[],o=String(r);for(;o.length>0;){let s=o.match(/\{(?!\{)([\w\d]+)\}(?!\})/);if(s===null||s.index===void 0||s[1]===void 0){n.push(o),o="";break}let a=o.substring(0,s.index);o=o.substring(s.index+s[0].length),n.push(a);let c=Number.parseInt(s[1],10);Number.isNaN(c)?n.push(i[s[1]]):n.push(i[c])}return n}function Ge(r){if(r?.lang!==void 0&&r.lang!=="zz")return r.lang;let e=typeof window<"u"?window:void 0,t=typeof document<"u"?document.documentElement.lang:void 0,i=r?.languages?.[0],n=((typeof e?.language=="string"?e.language:null)||t||i||"en").toLowerCase(),s=/^([\w]+)-([\w]+)$/.exec(n);return s===null||s[1]===void 0?n:s[1]}function jt(r,e,t){let i=Array.isArray(e)?e:[e],n=r;for(let o of i){if(n===void 0)return t;if(typeof o=="string"&&o.endsWith("?")){let s=o.slice(0,-1),a=n instanceof Map?n.get(s):n[s];typeof a=="string"&&(n=a)}else if(n instanceof Map)n=n.get(o);else if(n!==null&&typeof n=="object")n=n[o];else return t}if(typeof n!="string")return t;if(n!=="")return n}function Lt(r,e,t,i,...n){let o=i,s=!1;o[0]==="!"&&(o=o.slice(1),s=!0),Array.isArray(o)||(o=[o]);let a=jt(r,[e,...o]);return a===void 0&&t!==void 0&&(a=jt(r,[t,...o])),a===void 0?s?void 0:[`[missing translation: ${e}/${o.join("/")}]`]:n.length>0?zn(a,...n):a}function V(r){let e=new Map;for(let t of Object.keys(r)){let i=r[t];typeof i=="string"||i===null?e.set(t,i):typeof i=="object"&&i!==null&&e.set(t,V(i))}return e}function z(r,e,t=!0,i=!1){if(!(e instanceof Map)||!(r instanceof Map))throw new Error("Parameters are not maps!");let n=i?new Map(r):r,o=(s,a,c)=>{if(c instanceof Map){let l=new Map;z(l,c,!0,!1),s.set(a,l)}else s.set(a,c)};for(let s of e.keys()){let a=e.get(s),c=n.get(s);n.has(s)?a instanceof Map&&c instanceof Map?n.set(s,z(c,a,t,i)):t&&o(n,s,a):o(n,s,a)}return n}var Mn={analytics:["analytics_storage"],marketing:["ad_storage","ad_user_data","ad_personalization"]},$n=["marketing"],Pn="simplecmp_consent_update",Dn=500,Tn=["google"];function Rn(r){let e=typeof r=="object"&&r!==null?r:{},t=e.dataLayerEvent;return{vendors:e.vendors??Tn,purposeSignals:e.purposeSignals??Mn,adPurposes:e.adPurposes??$n,waitForUpdate:e.waitForUpdate??Dn,dataLayerEvent:t===!1?null:typeof t=="string"?t:Pn,redactAdsData:e.redactAdsData??!1}}function On(r){return Array.isArray(r.dataLayer)||(r.dataLayer=[]),typeof r.gtag!="function"&&(r.gtag=function(){r.dataLayer.push(arguments)}),r.gtag}function jn(r){let e=new Set;for(let t of Object.values(r))for(let i of t)e.add(i);return[...e]}function Ln(r,e){return Object.keys(e).filter(t=>e[t]?.includes(r))}function In(r,e){let t=On(e),i=jn(r.purposeSignals),n=o=>{let s={};for(let a of i){let c=Ln(a,r.purposeSignals).some(l=>o.granted(l));s[a]=c?"granted":"denied"}return s};return{id:"google",emit(o,s){let a=n(s);r.redactAdsData&&i.includes("ad_storage")&&t("set","ads_data_redaction",a.ad_storage!=="granted"),o==="default"?t("consent","default",{...a,security_storage:"granted",wait_for_update:r.waitForUpdate}):(t("consent","update",{...a}),r.dataLayerEvent!==null&&Array.isArray(e.dataLayer)&&e.dataLayer.push({event:r.dataLayerEvent}))}}}function Nn(r,e){return{id:"meta",emit(t,i){if(typeof e.fbq!="function")return;let n=r.adPurposes.some(o=>i.granted(o));e.fbq("consent",n?"grant":"revoke")}}}function Un(r,e){return{id:"microsoftUet",emit(t,i){Array.isArray(e.uetq)||(e.uetq=[]);let n=r.adPurposes.some(o=>i.granted(o))?"granted":"denied";e.uetq.push("consent",t,{ad_storage:n})}}}function Bn(r,e){let t=[];for(let i of r.vendors)i==="google"?t.push(In(r,e)):i==="meta"?t.push(Nn(r,e)):i==="microsoftUet"&&t.push(Un(r,e));return t}function Qe(r,e,t){if(typeof window>"u")return()=>{};let i=Rn(r),n=Bn(i,window);if(n.length===0)return()=>{};let o=c=>({granted:l=>t.some(m=>(m.purposes??[]).includes(l)&&c(m))}),s=(c,l)=>{let m=o(l);for(let d of n)d.emit(c,m)};s("default",c=>e.getDefaultConsent(c)),e.confirmed&&s("update",c=>e.getConsent(c.name));let a={update:(c,l)=>{l!=="consents"&&l!=="saveConsents"||s("update",m=>c.getConsent(m.name))}};return e.watch(a),()=>e.unwatch(a)}var qn,Ze=new Map,ne={},It={},we={},Se={};function Nt(r,e){we[r]===void 0?we[r]=[e]:we[r].push(e);let t=Se[r];if(t!==void 0){for(let i of t)if(e(...i)===!1)break}}function Je(r,...e){let t=we[r];if(Se[r]===void 0?Se[r]=[e]:Se[r].push(e),t!==void 0){for(let i of t)if(i(...e)===!0)return!0}}function Hn(r){let e={...r};if(e.version===2)return e;if(e.apps!==void 0&&e.services===void 0&&(e.services=e.apps,console.warn("Warning, your configuration file is outdated. Please change `apps` to `services`"),delete e.apps),e.translations!==void 0&&typeof e.translations=="object"&&e.translations!==null){let t=e.translations;t.apps!==void 0&&t.services===void 0&&(t.services=t.apps,console.warn("Warning, your configuration file is outdated. Please change `apps` to `services` in the `translations` key"),delete t.apps)}return e}function Ut(r){let e=new Map;z(e,Ze),Fn(e,r);let t=r.translations??{};return z(e,V(t)),e}function Fn(r,e){let t=e.tones;if(t!=null)for(let[i,n]of Object.entries(t)){if(n!=="informal")continue;let o=Rt[i];if(o===void 0)continue;let s=new Map;s.set(i,V(o)),z(r,s)}}function re(r){let e=r??qn;if(!e)throw new Error("SimpleCMP getManager called without config and no default config set");let t=e.storageName??e.cookieName??"default",i=Vn(e);return(ne[t]===void 0||It[t]!==i)&&(ne[t]=new te(Hn(e)),It[t]=i,ne[t].versionMismatch!==void 0&&Je("consentVersionMismatch",ne[t].versionMismatch)),ne[t]}function Vn(r){try{return JSON.stringify(r,(e,t)=>typeof t=="function"?void 0:t)}catch{return`__unserialisable__:${Wn++}`}}var Wn=0;var Bt={acceptAll:"Accept all",acceptSelected:"Accept selected",close:"Close",consentModal:{description:"Here you can assess and customize the services that we'd like to use on this website. You're in charge! Enable or disable services as you see fit.",title:"Services we would like to use"},consentNotice:{changeDescription:"There were changes since your last visit, please renew your consent.",title:"Cookie Consent",description:"Hi! Could we please enable some additional services for {purposes}? You can always change or withdraw your consent later.",optOutDescription:"We use cookies and similar technologies, including for {purposes}. They are on by default \u2014 you can opt out at any time.",imprint:{name:"Imprint"},learnMore:"Let me choose",testing:"Testing mode!"},doNotSell:"Do Not Sell or Share My Personal Information",contextualConsent:{acceptAlways:"Always",acceptOnce:"Yes",description:"Do you want to load external content supplied by {title}?",descriptionEmptyStore:"To agree to this service permanently, you must accept {title} in the {link}.",descriptionUnknownHost:"Blocked third-party content from {title}. The site administrator has not reviewed this source \u2014 please contact them to enable it.",modalLinkText:"Consent Manager",providerInfoLink:"More information \u203A"},providerInfo:{title:"Provider information",close:"Close",noData:"No provider information available.",field:{vendor:"Provider",description:"Description",address:"Address",country:"Country",privacyPolicy:"Privacy policy",optOut:"Opt-out",partner:"Partners / joint controllers"}},decline:"I decline",ok:"That's ok",poweredBy:"Realized with Klaro!",privacyPolicy:{name:"privacy policy",text:"To learn more, please read our {privacyPolicy}."},purposeItem:{service:"service",services:"services"},purposes:{advertising:{description:"These services process personal information to show you personalized or interest-based advertisements.",title:"Advertising"},analytics:{description:"These services collect data about how visitors use this site so we can measure and improve its performance.",title:"Analytics"},functional:{description:`These services are essential for the correct functioning of this website. You cannot disable them here as the service would not work correctly otherwise.
`,title:"Service Provision"},marketing:{description:"These services process personal information to show you relevant content about products, services or topics that you might be interested in.",title:"Marketing"},performance:{description:`These services process personal information to optimize the service that this website offers.
`,title:"Performance Optimization"},personalization:{description:"These services tailor what you see on this site to your preferences and prior interactions.",title:"Personalization"},security:{description:"These services protect this site against abuse \u2014 for example, by detecting suspicious traffic or blocking automated attacks.",title:"Security"}},save:"Save",service:{disableAll:{description:"Use this switch to enable or disable all services.",title:"Enable or disable all services"},optOut:{description:"This services is loaded by default (but you can opt out)",title:"(opt-out)"},purpose:"purpose",purposes:"purposes",required:{description:"This services is always required",title:"(always required)"}}};var qt={privacyPolicy:{name:"\u043F\u043E\u043B\u0438\u0442\u0438\u043A\u0430 \u043D\u0430 \u043F\u043E\u0432\u0435\u0440\u0438\u0442\u0435\u043B\u043D\u043E\u0441\u0442",text:'\u0417\u0430 \u0434\u0430 \u0440\u0430\u0437\u0431\u0435\u0440\u0435\u0442\u0435 \u043F\u043E\u0432\u0435\u0447\u0435, \u043C\u043E\u043B\u044F \u043F\u0440\u043E\u0447\u0435\u0442\u0435\u0442\u0435 \u043D\u0430\u0448\u0430\u0442\u0430 <tr-hint v="privacy policy">{privacyPolicy}</tr-hint>.'},consentModal:{title:"\u0423\u0441\u043B\u0443\u0433\u0438, \u043A\u043E\u0438\u0442\u043E \u0431\u0438\u0445\u0435\u043C \u0438\u0441\u043A\u0430\u043B\u0438 \u0434\u0430 \u0438\u0437\u043F\u043E\u043B\u0437\u0432\u0430\u043C\u0435",description:"\u0422\u0443\u043A \u043C\u043E\u0436\u0435\u0442\u0435 \u0434\u0430 \u043E\u0446\u0435\u043D\u0438\u0442\u0435 \u0438 \u043F\u0435\u0440\u0441\u043E\u043D\u0430\u043B\u0438\u0437\u0438\u0440\u0430\u0442\u0435 \u0443\u0441\u043B\u0443\u0433\u0438\u0442\u0435, \u043A\u043E\u0438\u0442\u043E \u0431\u0438\u0445\u043C\u0435 \u0438\u0441\u043A\u0430\u043B\u0438 \u0434\u0430 \u0438\u0437\u043F\u043E\u043B\u0437\u0432\u0430\u0442\u0435 \u043D\u0430 \u0442\u043E\u0437\u0438 \u0443\u0435\u0431\u0441\u0430\u0439\u0442. \u0412\u0438\u0435 \u043E\u0442\u0433\u043E\u0432\u0430\u0440\u044F\u0442\u0435 \u0437\u0430 \u0442\u043E\u0432\u0430! \u0420\u0430\u0437\u0440\u0435\u0448\u0430\u0432\u0430\u0439\u0442\u0435 \u0438\u043B\u0438 \u0437\u0430\u0431\u0440\u0430\u043D\u044F\u0432\u0430\u0439\u0442\u0435 \u0443\u0441\u043B\u0443\u0433\u0438\u0442\u0435, \u043A\u0430\u043A\u0442\u043E \u043D\u0430\u043C\u0435\u0440\u0438\u0442\u0435 \u0437\u0430 \u0434\u043E\u0431\u0440\u0435."},consentNotice:{testing:"\u0422\u0435\u0441\u0442\u043E\u0432 \u043C\u043E\u0434!",title:"\u0421\u044A\u0433\u043B\u0430\u0441\u0438\u0435 \u0437\u0430 \u0438\u0437\u043F\u043E\u043B\u0437\u0432\u0430\u043D\u0435 \u043D\u0430 \u0431\u0438\u0441\u043A\u0432\u0438\u0442\u043A\u0438",changeDescription:"\u0418\u043C\u0430 \u043F\u0440\u043E\u043C\u0435\u043D\u0438 \u0441\u043B\u0435\u0434 \u043F\u043E\u0441\u043B\u0435\u0434\u043D\u043E\u0442\u043E \u0412\u0438 \u043F\u043E\u0441\u0435\u0449\u0435\u043D\u0438\u0435, \u043C\u043E\u043B\u044F, \u043F\u043E\u0434\u043D\u043E\u0432\u0435\u0442\u0435 \u0441\u044A\u0433\u043B\u0430\u0441\u0438\u0435\u0442\u043E \u0441\u0438.",description:"\u0417\u0434\u0440\u0430\u0432\u0435\u0439\u0442\u0435! \u041C\u043E\u0436\u0435\u043C \u043B\u0438 \u0434\u0430 \u0440\u0430\u0437\u0440\u0435\u0448\u0438\u043C \u043D\u044F\u043A\u043E\u0438 \u0434\u043E\u043F\u044A\u043B\u043D\u0438\u0442\u0435\u043B\u043D\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u0437\u0430 {purposes}? \u0412\u0438\u043D\u0430\u0433\u0438 \u043C\u043E\u0436\u0435\u0442\u0435 \u0434\u0430 \u043F\u0440\u043E\u043C\u0435\u043D\u0438\u0442\u0435 \u0438\u043B\u0438 \u043E\u0442\u0442\u0435\u0433\u043B\u0438\u0442\u0435 \u0441\u044A\u0433\u043B\u0430\u0441\u0438\u0435\u0442\u043E \u0441\u0438 \u043F\u043E-\u043A\u044A\u0441\u043D\u043E.","learnMore|capitalize":"\u041D\u0435\u043A\u0430 \u0434\u0430 \u0438\u0437\u0431\u0435\u0440\u0430"},purposes:{functional:{"title|capitalize":"\u041F\u0440\u0435\u0434\u043E\u0441\u0442\u0430\u0432\u044F\u043D\u0435 \u043D\u0430 \u0443\u0441\u043B\u0443\u0433\u0438",description:`\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u0441\u0430 \u043E\u0442 \u0441\u044A\u0449\u0435\u0441\u0442\u0432\u0435\u043D\u043E \u0437\u043D\u0430\u0447\u0435\u043D\u0438\u0435 \u0437\u0430 \u043F\u0440\u0430\u0432\u0438\u043B\u043D\u043E\u0442\u043E \u0444\u0443\u043D\u043A\u0446\u0438\u043E\u043D\u0438\u0440\u0430\u043D\u0435 \u043D\u0430 \u0442\u043E\u0437\u0438 \u0443\u0435\u0431\u0441\u0430\u0439\u0442. \u041D\u0435 \u043C\u043E\u0436\u0435\u0442\u0435 \u0434\u0430 \u0433\u0438 \u0434\u0435\u0430\u043A\u0442\u0438\u0432\u0438\u0440\u0430\u0442\u0435 \u0442\u0443\u043A, \u0442\u044A\u0439 \u043A\u0430\u0442\u043E \u0432 \u043F\u0440\u043E\u0442\u0438\u0432\u0435\u043D \u0441\u043B\u0443\u0447\u0430\u0439 \u0443\u0441\u043B\u0443\u0433\u0430\u0442\u0430 \u043D\u044F\u043C\u0430 \u0434\u0430 \u0440\u0430\u0431\u043E\u0442\u0438 \u043F\u0440\u0430\u0432\u0438\u043B\u043D\u043E.
`},performance:{"title|capitalize":"\u041E\u043F\u0442\u0438\u043C\u0438\u0437\u0438\u0440\u0430\u043D\u0435 \u043D\u0430 \u043F\u0440\u043E\u0438\u0437\u0432\u043E\u0434\u0438\u0442\u0435\u043B\u043D\u043E\u0441\u0442\u0442\u0430",description:`\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u043E\u0431\u0440\u0430\u0431\u043E\u0442\u0432\u0430\u0442 \u043B\u0438\u0447\u043D\u0430 \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044F, \u0437\u0430 \u0434\u0430 \u043E\u043F\u0442\u0438\u043C\u0438\u0437\u0438\u0440\u0430\u0442 \u0443\u0441\u043B\u0443\u0433\u0438\u0442\u0435, \u043A\u043E\u0438\u0442\u043E \u043F\u0440\u0435\u0434\u043B\u0430\u0433\u0430 \u0442\u043E\u0437\u0438 \u0443\u0435\u0431\u0441\u0430\u0439\u0442.
`},marketing:{"title|capitalize":"\u041C\u0430\u0440\u043A\u0435\u0442\u0438\u043D\u0433",description:"\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u043E\u0431\u0440\u0430\u0431\u043E\u0442\u0432\u0430\u0442 \u043B\u0438\u0447\u043D\u0430 \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044F, \u0437\u0430 \u0434\u0430 \u0432\u0438 \u043F\u043E\u043A\u0430\u0437\u0432\u0430\u0442 \u043F\u043E\u0434\u0445\u043E\u0434\u044F\u0449\u043E \u0441\u044A\u0434\u044A\u0440\u0436\u0430\u043D\u0438\u0435 \u0437\u0430 \u043F\u0440\u043E\u0434\u0443\u043A\u0442\u0438, \u0443\u0441\u043B\u0443\u0433\u0438 \u0438\u043B\u0438 \u0442\u0435\u043C\u0438, \u043A\u043E\u0438\u0442\u043E \u043C\u043E\u0436\u0435 \u0434\u0430 \u0432\u0438 \u0438\u043D\u0442\u0435\u0440\u0435\u0441\u0443\u0432\u0430\u0442."},advertising:{"title|capitalize":"\u0420\u0435\u043A\u043B\u0430\u043C\u0438\u0440\u0430\u043D\u0435",description:"\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u043E\u0431\u0440\u0430\u0431\u043E\u0442\u0432\u0430\u0442 \u043B\u0438\u0447\u043D\u0430 \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044F, \u0437\u0430 \u0434\u0430 \u0432\u0438 \u043F\u043E\u043A\u0430\u0437\u0432\u0430\u0442 \u043F\u0435\u0440\u0441\u043E\u043D\u0430\u043B\u0438\u0437\u0438\u0440\u0430\u043D\u0438 \u0438\u043B\u0438 \u0431\u0430\u0437\u0438\u0440\u0430\u043D\u0438 \u043D\u0430 \u0438\u043D\u0442\u0435\u0440\u0435\u0441\u0438 \u0440\u0435\u043A\u043B\u0430\u043C\u0438."}},purposeItem:{service:"\u041F\u0440\u043E\u0441\u0442\u0430 <tr-snip>\u0443\u0441\u043B\u0443\u0433\u0430</tr-snip>, \u043A\u043E\u044F\u0442\u043E \u0438\u043D\u0441\u0442\u0430\u043B\u0438\u0440\u0430\u043C \u043D\u0430 \u043A\u043E\u043C\u043F\u044E\u0442\u044A\u0440\u0430 \u0441\u0438.",services:"\u041D\u044F\u043A\u043E\u043B\u043A\u043E \u043F\u0440\u043E\u0441\u0442\u0438 <tr-snip>\u0443\u0441\u043B\u0443\u0433\u0438</tr-snip>, \u043A\u043E\u0438\u0442\u043E \u0438\u043D\u0441\u0442\u0430\u043B\u0438\u0440\u0430\u043C \u043D\u0430 \u043A\u043E\u043C\u043F\u044E\u0442\u044A\u0440\u0430 \u0441\u0438."},"ok|capitalize":"\u0421\u044A\u0433\u043B\u0430\u0441\u0435\u043D \u0441\u044A\u043C","save|capitalize":"\u0437\u0430\u043F\u0430\u0437\u0438","decline|capitalize":"\u041E\u0442\u043A\u0430\u0437\u0432\u0430\u043C","close|capitalize":"\u0417\u0430\u0442\u0432\u0430\u0440\u044F\u043D\u0435","acceptAll|capitalize":"\u041F\u043E\u0437\u0432\u043E\u043B\u044F\u0432\u0430\u043D\u0435 \u043D\u0430 \u0432\u0441\u0438\u0447\u043A\u0438","acceptSelected|capitalize":"\u041F\u043E\u0437\u0432\u043E\u043B\u0438 \u0437\u0430 \u0438\u0437\u0431\u0440\u0430\u043D\u0438\u0442\u0435",service:{disableAll:{"title|capitalize":"\u0440\u0430\u0437\u0440\u0435\u0448\u0430\u0432\u0430\u043D\u0435 \u0438\u043B\u0438 \u0437\u0430\u0431\u0440\u0430\u043D\u044F\u0432\u0430\u043D\u0435 \u043D\u0430 \u0432\u0441\u0438\u0447\u043A\u0438 \u0443\u0441\u043B\u0443\u0433\u0438",description:"\u0418\u0437\u043F\u043E\u043B\u0437\u0432\u0430\u0439\u0442\u0435 \u0442\u043E\u0437\u0438 \u0431\u0443\u0442\u043E\u043D\u0438, \u0437\u0430 \u0434\u0430 \u0440\u0430\u0437\u0440\u0435\u0448\u0438\u0442\u0435 \u0438\u043B\u0438 \u0437\u0430\u0431\u0440\u0430\u043D\u0438\u0442\u0435 \u0432\u0441\u0438\u0447\u043A\u0438 \u0443\u0441\u043B\u0443\u0433\u0438."},optOut:{title:"(\u0432\u043A\u043B-\u0438\u0437\u043A\u043B)",description:"\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u0441\u0430 \u0432\u043A\u043B\u044E\u0447\u0435\u043D\u0438 \u043F\u043E \u043F\u043E\u0434\u0440\u0430\u0437\u0431\u0438\u0440\u0430\u043D\u0435 (\u043C\u043E\u0436\u0435 \u0434\u0430 \u0433\u0438 \u0432\u043A\u043B-\u0438\u0437\u043A\u043B)"},required:{title:"(\u0438\u0437\u0438\u0441\u043A\u0432\u0430 \u0441\u0435 \u0432\u0438\u043D\u0430\u0433\u0438)",description:"\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u0441\u0430 \u0432\u0438\u043D\u0430\u0433\u0438 \u043D\u0435\u043E\u0431\u0445\u043E\u0434\u0438\u043C\u0438"},purposes:"Processing <tr-snip>purposes</tr-snip>",purpose:"Processing <tr-snip>purpose</tr-snip>"},poweredBy:"Realized with Klaro!",contextualConsent:{description:"\u0418\u0441\u043A\u0430\u0442\u0435 \u043B\u0438 \u0434\u0430 \u0437\u0430\u0440\u0435\u0434\u0438\u0442\u0435 \u0432\u044A\u043D\u0448\u043D\u043E \u0441\u044A\u0434\u044A\u0440\u0436\u0430\u043D\u0438\u0435, \u043F\u0440\u0435\u0434\u043E\u0441\u0442\u0430\u0432\u0435\u043D\u043E \u043E\u0442 {title}?",acceptOnce:"\u0414\u0430",acceptAlways:"\u0412\u0438\u043D\u0430\u0433\u0438"}};var Ht={acceptAll:"Accepta-les totes",acceptSelected:"Accepta les escollides",service:{disableAll:{description:"Useu aquest bot\xF3 per a habilitar o deshabilitar totes les aplicacions.",title:"Habilita/deshabilita totes les aplicacions"},optOut:{description:"Aquesta aplicaci\xF3 es carrega per defecte, per\xF2 podeu desactivar-la",title:"(opt-out)"},purpose:"Finalitat",purposes:"Finalitats",required:{description:"Aquesta aplicaci\xF3 es necessita sempre",title:"(necess\xE0ria)"}},close:"Tanca",consentModal:{description:"Aqu\xED podeu veure i personalitzar la informaci\xF3 que recopilem sobre v\xF3s.",privacyPolicy:{name:"pol\xEDtica de privadesa",text:"Per a m\xE9s informaci\xF3, consulteu la nostra {privacyPolicy}."},title:"Informaci\xF3 que recopilem"},consentNotice:{changeDescription:"Hi ha hagut canvis des de la vostra darrera visita. Actualitzeu el vostre consentiment.",description:"Recopilem i processem la vostra informaci\xF3 personal amb les seg\xFCents finalitats: {purposes}.",imprint:{name:"Empremta"},learnMore:"Saber-ne m\xE9s",privacyPolicy:{name:"pol\xEDtica de privadesa"}},decline:"Rebutja",ok:"Accepta",poweredBy:"Funciona amb Klaro!",purposeItem:{service:"aplicaci\xF3",services:"aplicacions"},save:"Desa"};var Ft={privacyPolicy:{name:"z\xE1sady ochrany soukrom\xED",text:'Pro dal\u0161\xED informace si p\u0159e\u010Dtete na\u0161e <tr-hint v="privacy policy">{privacyPolicy}</tr-hint>.'},consentModal:{title:"Slu\u017Eby, kter\xE9 bychom r\xE1di vyu\u017Eili",description:"Zde m\u016F\u017Eete posoudit a p\u0159izp\u016Fsobit slu\u017Eby, kter\xE9 bychom r\xE1di na tomto webu pou\u017E\xEDvali. M\xE1te to pod kontrolou! Povolte nebo zaka\u017Ete slu\u017Eby, jak uzn\xE1te za vhodn\xE9."},consentNotice:{testing:"Testing mode!",changeDescription:"Od va\u0161\xED posledn\xED n\xE1v\u0161t\u011Bvy do\u0161lo ke zm\u011Bn\xE1m, obnovte pros\xEDm sv\u016Fj souhlas.",description:"\u201EDobr\xFD den! M\u016F\u017Eeme povolit n\u011Bkter\xE9 dal\u0161\xED slu\u017Eby pro {purposes}? Sv\u016Fj souhlas m\u016F\u017Eete kdykoliv zm\u011Bnit nebo odvolat.\u201C","learnMore|capitalize":"Vyberu si"},purposes:{functional:{"title|capitalize":"Poskytov\xE1n\xED slu\u017Eeb",description:`Tyto slu\u017Eby jsou nezbytn\xE9 pro spr\xE1vn\xE9 fungov\xE1n\xED tohoto webu. Nelze je zde deaktivovat, proto\u017Ee slu\u017Eba by jinak nefungovala spr\xE1vn\u011B.
`},performance:{"title|capitalize":"Optimalizace v\xFDkonu",description:`V r\xE1mci t\u011Bchto slu\u017Eeb jsou zpracov\xE1v\xE1ny osobn\xED \xFAdaje za \xFA\u010Delem optimalizace slu\u017Eeb, kter\xE9 jsou na tomto webu poskytov\xE1ny.
`},marketing:{"title|capitalize":"Marketing",description:"V r\xE1mci t\u011Bchto slu\u017Eeb jsou zpracov\xE1v\xE1ny osobn\xED \xFAdaje, aby se v\xE1m zobrazoval relevantn\xED obsah o produktech, slu\u017Eb\xE1ch nebo t\xE9matech, kter\xE9 by v\xE1s mohly zaj\xEDmat."},advertising:{"title|capitalize":"Reklama",description:"V r\xE1mci t\u011Bchto slu\u017Eeb jsou zpracov\xE1v\xE1ny osobn\xED \xFAdaje, aby v\xE1m zobrazovaly personalizovan\xE9 nebo z\xE1jmov\u011B orientovan\xE9 reklamy."}},purposeItem:{service:"Jednoduch\xE1 slu\u017Eba <tr-snip></tr-snip> , kterou nainstaluji do sv\xE9ho po\u010D\xEDta\u010De.",services:"N\u011Bkolik jednoduch\xFDch slu\u017Eeb <tr-snip></tr-snip> , kter\xE9 nainstaluji do sv\xE9ho po\u010D\xEDta\u010De."},"ok|capitalize":"To je v po\u0159\xE1dku",save:"ulo\u017Eit","decline|capitalize":"Nep\u0159ij\xEDm\xE1m",close:"zav\u0159\xEDt",acceptAll:"p\u0159ijmout v\u0161e",acceptSelected:"p\u0159ijmout vybran\xE9",service:{disableAll:{title:"povolit nebo zak\xE1zat v\u0161echny slu\u017Eby",description:"Pomoc\xED tohoto p\u0159ep\xEDna\u010De m\u016F\u017Eete povolit nebo zak\xE1zat v\u0161echny slu\u017Eby."},optOut:{title:"(opt-out)",description:"Tato slu\u017Eba se na\u010D\xEDt\xE1 ve v\xFDchoz\xEDm nastaven\xED (ale m\u016F\u017Eete ji zru\u0161it)"},required:{title:"(v\u017Edy vy\u017Eadov\xE1no)",description:"Tato slu\u017Eba je v\u017Edy vy\u017Eadov\xE1na"},purposes:"Zpracov\xE1n\xED  pro \xFA\u010Dely <tr-snip></tr-snip>",purpose:"Zpracov\xE1n\xED pro \xFA\u010Dely <tr-snip></tr-snip>"},poweredBy:"Realizov\xE1no pomoc\xED Klaro!",contextualConsent:{description:"Chcete na\u010D\xEDst extern\xED obsah dod\xE1van\xFD prost\u0159ednictv\xEDm {title}?",acceptOnce:"Ano",acceptAlways:"V\u017Edy"}};var Vt={acceptAll:"Tillad alle",acceptSelected:"Tillad udvalgte",service:{disableAll:{description:"Brug denne kontakt til at aktivere/deaktivere alle apps.",title:"Aktiver/deaktiver alle applikatione"},optOut:{description:"Denne applikation indl\xE6ses som standard (men du kan deaktivere den)",title:"Opt-Out"},purpose:"Form\xE5l",purposes:"Form\xE5l",required:{description:"Denne applikation er altid n\xF8dvendig",title:"(Altid n\xF8dvendig)"}},close:"Luk",consentModal:{description:"Her kan du se og \xE6ndre, hvilke informationer vi gemmer om dig.",privacyPolicy:{name:"Flere informationer finde du under {privacyPolicy}",text:"databeskyttelseserkl\xE6ring."},title:"Informationer, som vi gemmer"},consentNotice:{changeDescription:"Der har v\xE6ret \xE6ndringer siden dit sidste bes\xF8g. Opdater dit valg.",description:"Vi gemmer og behandler dine personlige oplysninger til f\xF8lgende form\xE5l: {purposes}.",imprint:{name:""},learnMore:"L\xE6s mere",privacyPolicy:{name:"Datenschutzerkl\xE4rung"}},decline:"Afvis",ok:"Ok",poweredBy:"Realiseret med Klaro!",purposeItem:{service:"",services:""},save:"Gem"};var Wt={acceptAll:"Alle akzeptieren",acceptSelected:"Ausgew\xE4hlte akzeptieren",doNotSell:"Meine Daten nicht verkaufen oder weitergeben",close:"Schlie\xDFen",consentModal:{description:"Hier k\xF6nnen Sie die Dienste, die wir auf dieser Website nutzen m\xF6chten, bewerten und anpassen. Sie haben das Sagen! Aktivieren oder deaktivieren Sie die Dienste, wie Sie es f\xFCr richtig halten.",privacyPolicy:{name:"Datenschutzerkl\xE4rung",text:"Um mehr zu erfahren, lesen Sie bitte unsere {privacyPolicy}."},title:"Dienste, die wir nutzen m\xF6chten"},consentNotice:{changeDescription:"Seit Ihrem letzten Besuch gab es \xC4nderungen, bitte erneuern Sie Ihre Zustimmung.",title:"Cookie-Einstellungen",description:"Hallo! K\xF6nnten wir bitte einige zus\xE4tzliche Dienste f\xFCr {purposes} aktivieren? Sie k\xF6nnen Ihre Zustimmung sp\xE4ter jederzeit \xE4ndern oder zur\xFCckziehen.",optOutDescription:"Wir verwenden Cookies und \xE4hnliche Technologien, unter anderem f\xFCr {purposes}. Sie sind standardm\xE4\xDFig aktiv \u2014 Sie k\xF6nnen jederzeit widersprechen.",imprint:{name:"Impressum"},learnMore:"Lassen Sie mich w\xE4hlen",privacyPolicy:{name:"Datenschutzerkl\xE4rung"},testing:"Testmodus!"},contextualConsent:{acceptAlways:"Immer",acceptOnce:"Ja",description:"M\xF6chten Sie von {title} bereitgestellte externe Inhalte laden?",descriptionEmptyStore:"Um diesem Dienst dauerhaft zustimmen zu k\xF6nnen, m\xFCssen Sie {title} in den {link} zustimmen.",descriptionUnknownHost:"Blockierter Drittinhalt von {title}. Diese Quelle wurde vom Site-Administrator noch nicht freigegeben \u2014 bitte wenden Sie sich an die Administratorin oder den Administrator, um diese Inhalte zu aktivieren.",modalLinkText:"Cookie-Einstellungen",providerInfoLink:"Weitere Informationen \u203A"},providerInfo:{title:"Provider-Informationen",close:"Schlie\xDFen",noData:"Keine Anbieter-Informationen verf\xFCgbar.",field:{vendor:"Anbieter",description:"Beschreibung",address:"Adresse",country:"Land",privacyPolicy:"Datenschutzerkl\xE4rung",optOut:"Opt-Out",partner:"Partner / gemeinsam Verantwortliche"}},decline:"Ich lehne ab",ok:"Das ist ok",poweredBy:"Realisiert mit Klaro!",privacyPolicy:{name:"Datenschutzerkl\xE4rung",text:"Um mehr zu erfahren, lesen Sie bitte unsere {privacyPolicy}."},purposeItem:{service:"Dienst",services:"Dienste"},purposes:{advertising:{description:"Diese Dienste verarbeiten pers\xF6nliche Informationen, um Ihnen personalisierte oder interessenbezogene Werbung zu zeigen.",title:"Werbung"},analytics:{description:"Diese Dienste erfassen, wie Besucher diese Seite nutzen, damit wir ihre Funktion messen und verbessern k\xF6nnen.",title:"Statistik"},functional:{description:`Diese Dienste sind f\xFCr die korrekte Funktion dieser Website unerl\xE4sslich. Sie k\xF6nnen sie hier nicht deaktivieren, da der Dienst sonst nicht richtig funktionieren w\xFCrde.
`,title:"Dienstbereitstellung"},marketing:{description:"Diese Dienste verarbeiten pers\xF6nliche Daten, um Ihnen relevante Inhalte \xFCber Produkte, Dienstleistungen oder Themen zu zeigen, die Sie interessieren k\xF6nnten.",title:"Marketing"},performance:{description:`Diese Dienste verarbeiten personenbezogene Daten, um den von dieser Website angebotenen Service zu optimieren.
`,title:"Optimierung der Leistung"},personalization:{description:"Diese Dienste passen die Inhalte dieser Seite an Ihre Vorlieben und Ihr bisheriges Verhalten an.",title:"Personalisierung"},security:{description:"Diese Dienste sch\xFCtzen diese Website vor Missbrauch \u2014 zum Beispiel durch Erkennung verd\xE4chtigen Datenverkehrs oder Abwehr automatisierter Angriffe.",title:"Sicherheit"}},save:"Speichern",service:{disableAll:{description:"Mit diesem Schalter k\xF6nnen Sie alle Dienste aktivieren oder deaktivieren.",title:"Alle Dienste aktivieren oder deaktivieren"},optOut:{description:"Diese Dienste werden standardm\xE4\xDFig geladen (Sie k\xF6nnen sich jedoch abmelden)",title:"(Opt-out)"},purpose:"Zweck",purposes:"Zwecke",required:{description:"Dieser Service ist immer erforderlich",title:"(immer erforderlich)"}}};var Kt={acceptAll:"",acceptAll_en:"Accept all",acceptSelected:"",acceptSelected_en:"Accept selected",service:{disableAll:{description:"\u03A7\u03C1\u03B7\u03C3\u03B9\u03BC\u03BF\u03C0\u03BF\u03AF\u03B7\u03C3\u03B5 \u03B1\u03C5\u03C4\u03CC \u03C4\u03BF\u03BD \u03B4\u03B9\u03B1\u03BA\u03CC\u03C0\u03C4\u03B7 \u03B3\u03B9\u03B1 \u03BD\u03B1 \u03B5\u03BD\u03B5\u03C1\u03B3\u03BF\u03C0\u03BF\u03B9\u03AE\u03C3\u03B5\u03B9\u03C2/\u03B1\u03C0\u03B5\u03BD\u03B5\u03C1\u03B3\u03BF\u03C0\u03BF\u03B9\u03AE\u03C3\u03B5\u03B9\u03C2 \u03CC\u03BB\u03B5\u03C2 \u03C4\u03B9\u03C2 \u03B5\u03C6\u03B1\u03C1\u03BC\u03BF\u03B3\u03AD\u03C2.",title:"\u0393\u03B9\u03B1 \u03CC\u03BB\u03B5\u03C2 \u03C4\u03B9\u03C2 \u03B5\u03C6\u03B1\u03C1\u03BC\u03BF\u03B3\u03AD\u03C2"},optOut:{description:"\u0395\u03AF\u03BD\u03B1\u03B9 \u03C0\u03C1\u03BF\u03BA\u03B1\u03B8\u03BF\u03C1\u03B9\u03C3\u03BC\u03AD\u03BD\u03BF \u03BD\u03B1 \u03C6\u03BF\u03C1\u03C4\u03CE\u03BD\u03B5\u03C4\u03B1\u03B9, \u03AC\u03BB\u03BB\u03B1 \u03BC\u03C0\u03BF\u03C1\u03B5\u03AF \u03BD\u03B1 \u03C0\u03B1\u03C1\u03B1\u03BB\u03B7\u03C6\u03B8\u03B5\u03AF",title:"(\u03BC\u03B7 \u03B1\u03C0\u03B1\u03B9\u03C4\u03BF\u03CD\u03BC\u03B5\u03BD\u03BF)"},purpose:"\u03A3\u03BA\u03BF\u03C0\u03CC\u03C2",purposes:"\u03A3\u03BA\u03BF\u03C0\u03BF\u03AF",required:{description:"\u0394\u03B5\u03BD \u03B3\u03AF\u03BD\u03B5\u03C4\u03B1\u03B9 \u03BD\u03B1 \u03BB\u03B5\u03B9\u03C4\u03BF\u03C5\u03C1\u03B3\u03AE\u03C3\u03B5\u03B9 \u03C3\u03C9\u03C3\u03C4\u03AC \u03B7 \u03B5\u03C6\u03B1\u03C1\u03BC\u03BF\u03B3\u03AE \u03C7\u03C9\u03C1\u03AF\u03C2 \u03B1\u03C5\u03C4\u03CC",title:"(\u03B1\u03C0\u03B1\u03B9\u03C4\u03BF\u03CD\u03BC\u03B5\u03BD\u03BF)"}},close:"\u039A\u03BB\u03B5\u03AF\u03C3\u03B9\u03BC\u03BF",consentModal:{description:"\u0395\u03B4\u03CE \u03BC\u03C0\u03BF\u03C1\u03B5\u03AF\u03C2 \u03BD\u03B1 \u03B4\u03B5\u03B9\u03C2 \u03BA\u03B1\u03B9 \u03BD\u03B1 \u03C1\u03C5\u03B8\u03BC\u03AF\u03C3\u03B5\u03B9\u03C2 \u03C4\u03B9\u03C2 \u03C0\u03BB\u03B7\u03C1\u03BF\u03C6\u03BF\u03C1\u03AF\u03B5\u03C2 \u03C0\u03BF\u03C5 \u03C3\u03C5\u03BB\u03BB\u03AD\u03B3\u03BF\u03C5\u03BC\u03B5 \u03C3\u03C7\u03B5\u03C4\u03B9\u03BA\u03AC \u03BC\u03B5 \u03B5\u03C3\u03AD\u03BD\u03B1.",privacyPolicy:{name:"\u03A0\u03BF\u03BB\u03B9\u03C4\u03B9\u03BA\u03AE \u0391\u03C0\u03BF\u03C1\u03C1\u03AE\u03C4\u03BF\u03C5",text:"\u0393\u03B9\u03B1 \u03C0\u03B5\u03C1\u03B9\u03C3\u03C3\u03CC\u03C4\u03B5\u03C1\u03B5\u03C2 \u03C0\u03BB\u03B7\u03C1\u03BF\u03C6\u03BF\u03C1\u03AF\u03B5\u03C2, \u03C0\u03B1\u03C1\u03B1\u03BA\u03B1\u03BB\u03CE \u03B4\u03B9\u03B1\u03B2\u03AC\u03C3\u03C4\u03B5 \u03C4\u03B7\u03BD {privacyPolicy}."},title:"\u03A0\u03BB\u03B7\u03C1\u03BF\u03C6\u03BF\u03C1\u03AF\u03B5\u03C2 \u03C0\u03BF\u03C5 \u03C3\u03C5\u03BB\u03BB\u03AD\u03B3\u03BF\u03C5\u03BC\u03B5"},consentNotice:{changeDescription:"\u03A0\u03C1\u03B1\u03B3\u03BC\u03B1\u03C4\u03BF\u03C0\u03BF\u03B9\u03AE\u03B8\u03B7\u03BA\u03B1\u03BD \u03B1\u03BB\u03BB\u03B1\u03B3\u03AD\u03C2 \u03BC\u03B5\u03C4\u03AC \u03C4\u03B7\u03BD \u03C4\u03B5\u03BB\u03B5\u03C5\u03C4\u03B1\u03AF\u03B1 \u03C3\u03B1\u03C2 \u03B5\u03C0\u03AF\u03C3\u03BA\u03B5\u03C8\u03B7 \u03C0\u03B1\u03C1\u03B1\u03BA\u03B1\u03BB\u03BF\u03CD\u03BC\u03B5 \u03B1\u03BD\u03B1\u03BD\u03B5\u03CE\u03C3\u03C4\u03B5 \u03C4\u03B7\u03BD \u03C3\u03C5\u03B3\u03BA\u03B1\u03C4\u03AC\u03B8\u03B5\u03C3\u03B7 \u03C3\u03B1\u03C2.",description:"\u03A3\u03C5\u03B3\u03BA\u03B5\u03BD\u03C4\u03C1\u03CE\u03BD\u03BF\u03C5\u03BC\u03B5 \u03BA\u03B1\u03B9 \u03B5\u03C0\u03B5\u03BE\u03B5\u03C1\u03B3\u03B1\u03B6\u03CC\u03BC\u03B1\u03C3\u03C4\u03B5 \u03C4\u03B1 \u03C0\u03C1\u03BF\u03C3\u03C9\u03C0\u03B9\u03BA\u03AC \u03B4\u03B5\u03B4\u03BF\u03BC\u03AD\u03BD\u03B1 \u03C3\u03B1\u03C2 \u03B3\u03B9\u03B1 \u03C4\u03BF\u03C5\u03C2 \u03C0\u03B1\u03C1\u03B1\u03BA\u03AC\u03C4\u03C9 \u03BB\u03CC\u03B3\u03BF\u03C5\u03C2: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"\u03A0\u03B5\u03C1\u03B9\u03C3\u03C3\u03CC\u03C4\u03B5\u03C1\u03B1",privacyPolicy:{name:"\u03A0\u03BF\u03BB\u03B9\u03C4\u03B9\u03BA\u03AE \u0391\u03C0\u03BF\u03C1\u03C1\u03AE\u03C4\u03BF\u03C5"}},decline:"\u0391\u03C0\u03CC\u03C1\u03C1\u03B9\u03C0\u03C4\u03C9",ok:"OK",poweredBy:"\u03A5\u03C0\u03BF\u03C3\u03C4\u03B7\u03C1\u03AF\u03B6\u03B5\u03C4\u03B1\u03B9 \u03B1\u03C0\u03CC \u03C4\u03BF Klaro!",purposeItem:{service:"\u03A5\u03C0\u03B7\u03C1\u03B5\u03C3\u03AF\u03B1",services:"\u03A5\u03C0\u03B7\u03C1\u03B5\u03C3\u03AF\u03B5\u03C2"},save:"\u0391\u03C0\u03BF\u03B8\u03AE\u03BA\u03B5\u03C5\u03C3\u03B7"};var Gt={acceptAll:"Aceptar todas",acceptSelected:"Aceptar seleccionadas",close:"Cerrar",consentModal:{description:"Aqu\xED puede evaluar y personalizar los servicios que nos gustar\xEDa utilizar en este sitio web. \xA1Usted decide! Habilite o deshabilite los servicios como considere oportuno.",privacyPolicy:{name:"pol\xEDtica de privacidad",text:"Para saber m\xE1s, por favor lea nuestra {privacyPolicy}."},title:"Servicios que nos gustar\xEDa utilizar"},consentNotice:{changeDescription:"Ha habido cambios en las cookies desde su \xFAltima visita. Debe renovar su consentimiento.",description:"\xA1Hola! \xBFPodr\xEDamos habilitar algunos servicios adicionales para {purposes}? Siempre podr\xE1 cambiar o retirar su consentimiento m\xE1s tarde.",imprint:{name:"Imprimir"},learnMore:"Quiero elegir",privacyPolicy:{name:"pol\xEDtica de privacidad"},testing:"\xA1Modo de prueba!"},contextualConsent:{acceptAlways:"Siempre",acceptOnce:"S\xED",description:"\xBFQuieres cargar el contenido externo suministrado por {title}?"},decline:"Descartar todas",ok:"De acuerdo",poweredBy:"\xA1Realizado con Klaro!",privacyPolicy:{name:"pol\xEDtica de privacidad",text:"Para saber m\xE1s, por favor lea nuestra {privacyPolicy}."},purposeItem:{service:"servicio",services:"servicios"},purposes:{advertising:{description:"Estos servicios procesan informaci\xF3n personal para mostrarle anuncios personalizados o basados en intereses.",title:"Publicidad"},functional:{description:"Estos servicios son esenciales para el correcto funcionamiento de este sitio web. No puede desactivarlos ya que la p\xE1gina no funcionar\xEDa correctamente.",title:"Prestaci\xF3n de servicios"},marketing:{description:"Estos servicios procesan informaci\xF3n personal para mostrarle contenido relevante sobre productos, servicios o temas que puedan interesarle.",title:"Marketing"},performance:{description:"Estos servicios procesan informaci\xF3n personal para optimizar el servicio que ofrece este sitio.",title:"Optimizaci\xF3n del rendimiento"}},save:"Guardar",service:{disableAll:{description:"Utilice este interruptor para activar o desactivar todos los servicios.",title:"Activar o desactivar todos los servicios"},optOut:{description:"Este servicio est\xE1 habilitado por defecto (pero puede optar por lo contrario)",title:"(desactivar)"},purpose:"Finalidad",purposes:"Finalidades",required:{description:"Este servicio es necesario siempre",title:"(siempre requerido)"}}};var Qt={acceptAll:"",acceptAll_en:"Accept all",acceptSelected:"",acceptSelected_en:"Accept selected",service:{disableAll:{description:"Aktivoi kaikki p\xE4\xE4lle/pois.",title:"Valitse kaikki"},optOut:{description:"Ladataan oletuksena (mutta voit ottaa sen pois p\xE4\xE4lt\xE4)",title:"(ladataan oletuksena)"},purpose:"K\xE4ytt\xF6tarkoitus",purposes:"K\xE4ytt\xF6tarkoitukset",required:{description:"Sivusto vaatii t\xE4m\xE4n aina",title:"(vaaditaan)"}},close:"Sulje",consentModal:{description:"Voit tarkastella ja muokata sinusta ker\xE4\xE4mi\xE4mme tietoja.",privacyPolicy:{name:"tietosuojasivultamme",text:"Voit lukea lis\xE4tietoja {privacyPolicy}."},title:"Ker\xE4\xE4m\xE4mme tiedot"},consentNotice:{changeDescription:"Olemme tehneet muutoksia ehtoihin viime vierailusi j\xE4lkeen, tarkista ehdot.",description:"Ker\xE4\xE4mme ja k\xE4sittelemme henkil\xF6tietoja seuraaviin tarkoituksiin: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"Lue lis\xE4\xE4",privacyPolicy:{name:"tietosuojasivultamme"}},decline:"Hylk\xE4\xE4",ok:"Hyv\xE4ksy",poweredBy:"Palvelun tarjoaa Klaro!",purposeItem:{service:"",services:""},save:"Tallenna"};var Zt={acceptAll:"Accepter tout",acceptSelected:"Accepter s\xE9lectionn\xE9",close:"Fermer",consentModal:{description:"Vous pouvez ici \xE9valuer et personnaliser les services que nous aimerions utiliser sur ce site. C'est vous qui d\xE9cidez ! Activez ou d\xE9sactivez les services comme bon vous semble.",privacyPolicy:{name:"politique de confidentialit\xE9",text:"Pour en savoir plus, veuillez lire notre {privacyPolicy}."},title:"Services que nous souhaitons utiliser"},consentNotice:{changeDescription:"Il y a eu des changements depuis votre derni\xE8re visite, veuillez renouveler votre consentement.",description:"Bonjour ! Pourrions-nous activer des services suppl\xE9mentaires pour {purposes}? Vous pouvez toujours modifier ou retirer votre consentement plus tard.",imprint:{name:"mentions l\xE9gales"},learnMore:"Laissez-moi choisir",privacyPolicy:{name:"politique de confidentialit\xE9"},testing:"Mode test !"},contextualConsent:{acceptAlways:"Toujours",acceptOnce:"Oui",description:"Vous souhaitez charger un contenu externe fourni par {title}?"},decline:"Je refuse",ok:"C'est bon.",poweredBy:"R\xE9alis\xE9 avec Klaro !",privacyPolicy:{name:"politique de confidentialit\xE9",text:"Pour en savoir plus, veuillez lire notre {privacyPolicy}."},purposeItem:{service:"service",services:"services"},purposes:{advertising:{description:"Ces services traitent les informations personnelles pour vous pr\xE9senter des publicit\xE9s personnalis\xE9es ou bas\xE9es sur des int\xE9r\xEAts.",title:"Publicit\xE9"},functional:{description:`Ces services sont essentiels au bon fonctionnement de ce site. Vous ne pouvez pas les d\xE9sactiver ici car le service ne fonctionnerait pas correctement autrement.
`,title:"Prestation de services"},marketing:{description:"Ces services traitent les informations personnelles afin de vous pr\xE9senter un contenu pertinent sur les produits, les services ou les sujets qui pourraient vous int\xE9resser.",title:"Marketing"},performance:{description:`Ces services traitent les informations personnelles afin d'optimiser le service que ce site Web offre.
`,title:"Optimisation de la performance"}},save:"Enregistrer",service:{disableAll:{description:"Utilisez ce commutateur pour activer ou d\xE9sactiver tous les services.",title:"Activer ou d\xE9sactiver tous les services"},optOut:{description:"Ce service est charg\xE9 par d\xE9faut (mais vous pouvez le d\xE9sactiver)",title:"(opt-out)"},purpose:"Objet",purposes:"Fins",required:{description:"Ce service est toujours n\xE9cessaire",title:"(toujours requis)"}}};var Jt={acceptAll:"Aceptar todas",acceptSelected:"Aceptar seleccionadas",close:"Pechar",consentModal:{description:"Aqu\xED pode avaliar e personalizar os servizos que nos gustar\xEDa utilizar neste sitio web. \xA1Vostede decide! Habilite ou deshabilite os servicios como lle conve\xF1a.",privacyPolicy:{name:"pol\xEDtica de privacidade",text:"Para saber m\xE1is, por favor lea a nosa {privacyPolicy}."},title:"Servizos que nos gustar\xEDa utilizar"},consentNotice:{changeDescription:"Houbo cambios nas cookies dende a s\xFAa \xFAltima visita. Debe renovar o seu consentimento.",description:"\xA1Ola! \xBFPoder\xEDamos habilitar alg\xFAns servizos adicionais para {purposes}? Sempre poder\xE1 cambiar ou retirar o s\xE9u consentimento m\xE1is tarde.",imprint:{name:"Imprimir"},learnMore:"Quero elixir",privacyPolicy:{name:"pol\xEDtica de privacidade"},testing:"\xA1Modo de proba!"},decline:"Descartar todas",ok:"De acordo",poweredBy:"\xA1Realizado con Klaro!",privacyPolicy:{name:"pol\xEDtica de privacidade",text:"Para saber m\xE1is, por favor lea a nosa {privacyPolicy}."},purposeItem:{service:"servizo",services:"servizos"},purposes:{advertising:{description:"Estes servizos procesan informaci\xF3n persoal para mostrarlle anuncios personalizados ou basados en intereses.",title:"Publicidade"},functional:{description:"Estes servizos son esenciais para o correcto funcionamiento deste sitio web. Non pode desactivalos xa que a p\xE1xina non funcionar\xEDa correctamente.",title:"Prestaci\xF3n de servizos"},marketing:{description:"Estes servizos procesan informaci\xF3n persoal para mostrarlle contido relevante sobre produtos, servizos ou temas que poidan interesarlle.",title:"Marketing"},performance:{description:"Estes servizos procesan informaci\xF3n persoal para optimizar o servizo que ofrece este sitio.",title:"Optimizaci\xF3n do rendimento"}},save:"Gardar",service:{disableAll:{description:"Utilice este interruptor para activar ou desactivar todos os servizos.",title:"Activar ou desactivar todos os servizos"},optOut:{description:"Este servizo est\xE1 habilitado por defecto (pero pode optar polo contrario)",title:"(desactivar)"},purpose:"Finalidade",purposes:"Finalidades",required:{description:"Este servizo \xE9 necesario sempre",title:"(sempre requirido)"}}};var Xt={acceptAll:"",acceptAll_en:"Prihvati sve",acceptSelected:"",acceptSelected_en:"Prihvati odabrane",service:{disableAll:{description:"Koristite ovaj prekida\u010D da omogu\u0107ite/onemogu\u0107ite sve aplikacije odjednom.",title:"Izmeijeni sve"},optOut:{description:"Ova aplikacija je u\u010Ditana automatski (ali je mo\u017Eete onemogu\u0107iti)",title:"(onemogu\u0107ite)"},purpose:"Svrha",purposes:"Svrhe",required:{description:"Ova aplikacija je uvijek obavezna",title:"(obavezna)"}},close:"Zatvori",consentModal:{description:"Ovdje mo\u017Eete vidjeti i podesiti informacije koje prikupljamo o Vama.",privacyPolicy:{name:"pravila privatnosti",text:"Za vi\u0161e informacije pro\u010Ditajte na\u0161a {privacyPolicy}."},title:"Informacije koje prikupljamo"},consentNotice:{changeDescription:"Do\u0161lo je do promjena od Va\u0161e posljednjeg posje\u0107ivanja web stranice, molimo Vas da a\u017Eurirate svoja odobrenja.",description:"Mi prikupljamo i procesiramo Va\u0161e osobne podatke radi slijede\u0107eg: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"Saznajte vi\u0161e",privacyPolicy:{name:"pravila privatnosti"}},decline:"Odbij",ok:"U redu",poweredBy:"Pokre\u0107e Klaro!",purposeItem:{service:"",services:""},save:"Spremi"};var Yt={};var ei={acceptAll:"Accettare tutti",acceptSelected:"Accettare selezionato",close:"Chiudi",consentModal:{description:"Qui pu\xF2 valutare e personalizzare i servizi che vorremmo utilizzare su questo sito web. \xC8 lei il responsabile! Abilitare o disabilitare i servizi come meglio crede.",privacyPolicy:{name:"informativa sulla privacy",text:"Per saperne di pi\xF9, legga la nostra {privacyPolicy}."},title:"Servizi che desideriamo utilizzare"},consentNotice:{changeDescription:"Ci sono stati dei cambiamenti rispetto alla sua ultima visita, la preghiamo di rinnovare il suo consenso.",description:"Salve, possiamo attivare alcuni servizi aggiuntivi per {purposes}? Pu\xF2 sempre modificare o ritirare il suo consenso in un secondo momento.",imprint:{name:"impronta"},learnMore:"Lasciatemi scegliere",privacyPolicy:{name:"informativa sulla privacy"},testing:"Modalit\xE0 di test!"},contextualConsent:{acceptAlways:"Sempre",acceptOnce:"S\xEC",description:"Vuole caricare contenuti esterni forniti da {title}?"},decline:"Rifiuto",ok:"Va bene cos\xEC",poweredBy:"Realizzato con Klaro!",privacyPolicy:{name:"informativa sulla privacy",text:"Per saperne di pi\xF9, legga la nostra {privacyPolicy}."},purposeItem:{service:"servizio",services:"servizi"},purposes:{advertising:{description:"Questi servizi elaborano le informazioni personali per mostrarle annunci pubblicitari personalizzati o basati su interessi.",title:"Pubblicit\xE0"},functional:{description:`Questi servizi sono essenziali per il corretto funzionamento di questo sito web. Non pu\xF2 disattivarli qui perch\xE9 altrimenti il servizio non funzionerebbe correttamente.
`,title:"Fornitura di servizi"},marketing:{description:"Questi servizi elaborano le informazioni personali per mostrarle contenuti rilevanti su prodotti, servizi o argomenti che potrebbero interessarla.",title:"Marketing"},performance:{description:`Questi servizi elaborano le informazioni personali per ottimizzare il servizio offerto da questo sito web.
`,title:"Ottimizzazione delle prestazioni"}},save:"Salva",service:{disableAll:{description:"Utilizzi questo interruttore per attivare o disattivare tutti i servizi.",title:"Attivare o disattivare tutti i servizi"},optOut:{description:"Questo servizio \xE8 caricato di default (ma \xE8 possibile scegliere di non usufruirne)",title:"(opt-out)"},purpose:"Scopo dell",purposes:"Finalit\xE0",required:{description:"Questo servizio \xE8 sempre richiesto",title:"(sempre richiesto)"}}};var ti={acceptAll:"Accepteer alle",acceptSelected:"Geselecteerde",close:"Sluit",consentModal:{description:"Hier kunt u de diensten die wij op deze website willen gebruiken beoordelen en aanpassen. U heeft de leiding! Schakel de diensten naar eigen inzicht in of uit.",privacyPolicy:{name:"privacybeleid",text:"Voor meer informatie kunt u ons {privacyPolicy} lezen."},title:"Diensten die we graag willen gebruiken"},consentNotice:{changeDescription:"Er waren veranderingen sinds uw laatste bezoek, gelieve uw toestemming te hernieuwen.",description:"Hallo, kunnen wij u een aantal extra diensten aanbieden voor {purposes}? U kunt uw toestemming later altijd nog wijzigen of intrekken.",imprint:{name:"impressum"},learnMore:"Laat me kiezen",privacyPolicy:{name:"privacybeleid"},testing:"Testmodus!"},contextualConsent:{acceptAlways:"Altijd",acceptOnce:"Ja",description:"Wilt u externe content laden die door {title} wordt aangeleverd ?"},decline:"Ik weiger",ok:"Dat is ok\xE9",poweredBy:"Gerealiseerd met Klaro!",privacyPolicy:{name:"privacybeleid",text:"Voor meer informatie kunt u ons {privacyPolicy} lezen."},purposeItem:{service:"service",services:"diensten"},purposes:{advertising:{description:"Deze diensten verwerken persoonlijke informatie om u gepersonaliseerde of op interesse gebaseerde advertenties te tonen.",title:"Reclame"},functional:{description:`Deze diensten zijn essentieel voor het correct functioneren van deze website. U kunt ze hier niet uitschakelen omdat de dienst anders niet correct zou werken.
`,title:"Dienstverlening"},marketing:{description:"Deze diensten verwerken persoonlijke informatie om u relevante inhoud te tonen over producten, diensten of onderwerpen waarin u ge\xEFnteresseerd zou kunnen zijn.",title:"Marketing"},performance:{description:`Deze diensten verwerken persoonlijke informatie om de service die deze website biedt te optimaliseren.
`,title:"Optimalisatie van de prestaties"}},save:"Opslaan",service:{disableAll:{description:"Gebruik deze schakelaar om alle diensten in of uit te schakelen.",title:"Alle diensten in- of uitschakelen"},optOut:{description:"Deze diensten worden standaard geladen (maar u kunt zich afmelden)",title:"(opt-out)"},purpose:"Verwerkingsdoel",purposes:"Verwerkingsdoeleinden",required:{description:"Deze diensten zijn altijd nodig",title:"(altijd nodig)"}}};var ii={acceptAll:"Godtar alle",acceptSelected:"Godtar valgt",service:{disableAll:{description:"Bruk denne for \xE5 skru av/p\xE5 alle apper.",title:"Bytt alle apper"},optOut:{description:"Denne appen er lastet som standard (men du kan skru det av)",title:"(opt-out)"},purpose:"\xC5rsak",purposes:"\xC5rsaker",required:{description:"Denne applikasjonen er alltid p\xE5krevd",title:"(alltid p\xE5krevd)"}},close:"",close_en:"Close",consentModal:{description:"Her kan du se og velge hvilken informasjon vi samler inn om deg.",privacyPolicy:{name:"personvernerkl\xE6ring",text:"For \xE5 l\xE6re mer, vennligst les v\xE5r {privacyPolicy}."},title:"Informasjon vi samler inn"},consentNotice:{changeDescription:"Det har skjedd endringer siden ditt siste bes\xF8k, vennligst oppdater ditt samtykke.",description:"Vi samler inn og prosesserer din personlige informasjon av f\xF8lgende \xE5rsaker: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"L\xE6r mer",privacyPolicy:{name:"personvernerkl\xE6ring"}},decline:"Avsl\xE5",ok:"OK",poweredBy:"Laget med Klaro!",purposeItem:{service:"",services:""},save:"Opslaan"};var ni={acceptAll:"Tot acceptar",acceptSelected:"Acceptar \xE7\xF2 seleccionat",close:"Tampar",consentModal:{description:"Aqu\xED pod\xE8tz mesurar e personalizar los servicis que volriam utilizar sus aqueste site web. Av\xE8tz lo darri\xE8r mot ! Activatz o desactivatz segon v\xF2stra causida.",title:"Servicis que volriam utilizar"},consentNotice:{changeDescription:"I agu\xE8t de modificacions dempu\xE8i v\xF2stra darri\xE8ra visita, merc\xE9s de repassar v\xF2stre consentiment.",description:"Adieu\u202F! Poiriam activar mai de servici per {purposes}\u202F? Pod\xE8tz totjorn modificar o tirar v\xF2stre consentiment mai tard.",learnMore:"Me daissar causir",testing:"M\xF2de t\xE8st !"},contextualConsent:{acceptAlways:"Totjorn",acceptOnce:"\xD2c",description:"Vol\xE8tz cargar de contenguts ext\xE8rn provesits per {title}\u202F?"},decline:"Refusi",ok:"Es bon",poweredBy:"Realizat amb Klaro !",privacyPolicy:{name:"politica de confidencialitat",text:"Per ne saber mai, vejatz n\xF2stra {privacyPolicy}."},purposeItem:{service:"servici",services:"servicis"},purposes:{advertising:{description:"Aquestes servicis tractan d\u2019informacions personalas per vos mostrar de reclamas personalizadas o basadas suls inter\xE8sses.",title:"Reclama"},functional:{description:`Aquestes servicis son essencials pel foncionament corr\xE8ct d\u2019aqueste site web. Los pod\xE8tz pas desactivar aqu\xED pr\u2019amor que lo servici foncionari\xE1 pas coma cal autrament.
`,title:"Servici de provision"},marketing:{description:"Aquestes servicis tractan d\u2019informacions personalas per vos mostrar de contenguts a prepaus de produits, de servicis o t\xE8mas que poiri\xE1n vos interessar.",title:"Marketing"},performance:{description:`Aquestes servicis tractan d\u2019informacions per optimizar lo servici qu\u2019aqueste site web prepausa.
`,title:"Optimizacion de las performan\xE7as"}},save:"Salvar",service:{disableAll:{description:"Utilizatz aqueste alternator per activar o desactivar totes los servicis.",title:"Activar o desactivar totes los servicis"},optOut:{description:"Aqueste servici es cargar per defaut (mas lo pod\xE8tz desactivar)",title:"(opt-out)"},purpose:"finalitat",purposes:"finalitat",required:{description:"Aqueste servici es totjorn requesit",title:"(totjorn requesit)"}}};var ri={acceptAll:"Zaakceptuj wszystkie",acceptSelected:"Zaakceptuj wybrane",close:"Zamknij",consentModal:{description:"Tutaj mog\u0105 Pa\u0144stwo oceni\u0107 i dostosowa\u0107 us\u0142ugi, kt\xF3re chcieliby\u015Bmy wykorzysta\u0107 na tej stronie. W\u0142\u0105czaj lub wy\u0142\u0105czaj us\u0142ugi wed\u0142ug w\u0142asnego uznania.",privacyPolicy:{name:"polityk\u0105 prywatno\u015Bci",text:"Aby dowiedzie\u0107 si\u0119 wi\u0119cej, prosimy o zapoznanie si\u0119 z nasz\u0105 {privacyPolicy}."},title:"Us\u0142ugi, z kt\xF3rych chcieliby\u015Bmy skorzysta\u0107"},consentNotice:{changeDescription:"Od Twojej ostatniej wizyty nast\u0105pi\u0142y zmiany, prosimy o odnowienie zgody.",description:"Czy mo\u017Cemy w\u0142\u0105czy\u0107 dodatkowe us\u0142ugi dla {purposes}? W ka\u017Cdej chwili mog\u0105 Pa\u0144stwo p\xF3\u017Aniej zmieni\u0107 lub wycofa\u0107 swoj\u0105 zgod\u0119.",imprint:{name:"Imprint"},learnMore:"Pozw\xF3l mi wybra\u0107",privacyPolicy:{name:"polityka prywatno\u015Bci"},testing:"Tryb testowy!"},contextualConsent:{acceptAlways:"Zawsze",acceptOnce:"Tak",description:"Czy chc\u0105 Pa\u0144stwo za\u0142adowa\u0107 tre\u015Bci zewn\u0119trzne dostarczane przez {title}?"},decline:"Odmawiam",ok:"Ok",poweredBy:"Technologia dostarczona przez Klaro",privacyPolicy:{name:"polityka prywatno\u015Bci",text:"Aby dowiedzie\u0107 si\u0119 wi\u0119cej, prosimy o zapoznanie si\u0119 z nasz\u0105 {privacyPolicy}."},purposeItem:{service:"us\u0142uga",services:"us\u0142ugi"},purposes:{advertising:{description:"Us\u0142ugi te przetwarzaj\u0105 dane osobowe w celu pokazania Pa\u0144stwu spersonalizowanych lub opartych na zainteresowaniach reklam.",title:"Reklama"},functional:{description:`Us\u0142ugi te s\u0105 niezb\u0119dne do prawid\u0142owego funkcjonowania niniejszej strony internetowej. Nie mog\u0105 Pa\u0144stwo ich tutaj wy\u0142\u0105czy\u0107, poniewa\u017C w przeciwnym razie strona nie dzia\u0142a\u0142aby prawid\u0142owo.
`,title:"\u015Awiadczenie us\u0142ug"},marketing:{description:"Us\u0142ugi te przetwarzaj\u0105 dane osobowe w celu pokazania Pa\u0144stwu istotnych tre\u015Bci dotycz\u0105cych produkt\xF3w, us\u0142ug lub temat\xF3w, kt\xF3rymi mog\u0105 by\u0107 Pa\u0144stwo zainteresowani.",title:"Marketing"},performance:{description:`Us\u0142ugi te przetwarzaj\u0105 dane osobowe w celu optymalizacji us\u0142ug oferowanych przez t\u0119 stron\u0119.
`,title:"Optymalizacja wydajno\u015Bci"}},save:"Zapisz",service:{disableAll:{description:"Za pomoc\u0105 tego prze\u0142\u0105cznika mo\u017Cna w\u0142\u0105cza\u0107 lub wy\u0142\u0105cza\u0107 wszystkie us\u0142ugi.",title:"W\u0142\u0105cz lub wy\u0142\u0105cz wszystkie us\u0142ugi"},optOut:{description:"Ta us\u0142uga jest domy\u015Blnie za\u0142adowana (ale mog\u0105 Pa\u0144stwo z niej zrezygnowa\u0107)",title:"(opt-out)"},purpose:"Cel",purposes:"Cele",required:{description:"Us\u0142ugi te s\u0105 zawsze wymagane",title:"(zawsze wymagane)"}}};var oi={acceptAll:"Aceitar todos",acceptSelected:"Aceitar selecionados",close:"Fechar",consentModal:{description:"Aqui voc\xEA pode avaliar e personalizar os servi\xE7os que gostar\xEDamos de usar neste website. Voc\xEA est\xE1 no comando! Habilite ou desabilite os servi\xE7os como julgar conveniente.",privacyPolicy:{name:"pol\xEDtica de privacidade",text:"Para saber mais, por favor, leia nossa {privacyPolicy}."},title:"Servi\xE7os que gostar\xEDamos de utilizar"},consentNotice:{changeDescription:"Houve mudan\xE7as desde sua \xFAltima visita, queira renovar seu consentimento.",description:"Ol\xE1! Poder\xEDamos, por favor, habilitar alguns servi\xE7os adicionais para {purposes}? Voc\xEA pode sempre mudar ou retirar seu consentimento mais tarde.",imprint:{name:"imprimir"},learnMore:"Deixe-me escolher",privacyPolicy:{name:"pol\xEDtica de privacidade"},testing:"Modo de teste!"},contextualConsent:{acceptAlways:"Sempre",acceptOnce:"Sim",description:"Voc\xEA deseja carregar conte\xFAdo externo fornecido por {title}?"},decline:"Recusar",ok:"Aceito.",poweredBy:"Realizado com Klaro!",privacyPolicy:{name:"pol\xEDtica de privacidade",text:"Para saber mais, por favor, leia nossa {privacyPolicy}."},purposeItem:{service:"servi\xE7o",services:"servi\xE7os"},purposes:{advertising:{description:"Esses servi\xE7os processam informa\xE7\xF5es pessoais para mostrar a voc\xEA an\xFAncios personalizados ou baseados em interesses.",title:"Publicidade"},functional:{description:`Esses servi\xE7os s\xE3o essenciais para o correto funcionamento deste website. Voc\xEA n\xE3o pode desativ\xE1-los aqui, pois de outra forma o servi\xE7o n\xE3o funcionaria corretamente.
`,title:"Presta\xE7\xE3o de servi\xE7os"},marketing:{description:"Esses servi\xE7os processam informa\xE7\xF5es pessoais para mostrar a voc\xEA conte\xFAdo relevante sobre produtos, servi\xE7os ou t\xF3picos que possam ser do seu interesse.",title:"Marketing"},performance:{description:`Esses servi\xE7os processam informa\xE7\xF5es pessoais para otimizar o servi\xE7o que este website oferece.
`,title:"Otimiza\xE7\xE3o do desempenho"}},save:"Salvar",service:{disableAll:{description:"Use essa chave para habilitar ou desabilitar todos os servi\xE7os.",title:"Habilitar ou desabilitar todos os servi\xE7os"},optOut:{description:"Estes servi\xE7os s\xE3o carregados por padr\xE3o (mas o voc\xEA pode optar por n\xE3o participar).",title:"(opt-out)"},purpose:"Objetivo",purposes:"Objetivos",required:{description:"Esses servi\xE7os s\xE3o sempre necess\xE1rios",title:"(sempre necess\xE1rio)"}}};var si={acceptAll:"",acceptAll_en:"Accept all",acceptSelected:"",acceptSelected_en:"Accept selected",service:{disableAll:{description:"Utiliza\u021Bi acest switch pentru a activa/dezactiva toate aplica\u021Biile.",title:"Comuta\u021Bi \xEEntre toate aplica\u021Biile"},optOut:{description:"Aceast\u0103 aplica\u021Bie este \xEEnc\u0103rcat\u0103 \xEEn mod implicit (dar pute\u021Bi renun\u021Ba)",title:"(opt-out)"},purpose:"Scop",purposes:"Scopuri",required:{description:"Aceast\u0103 aplica\u021Bie este \xEEntotdeauna necesar\u0103",title:"(\xEEntotdeauna necesar)"}},close:"",close_en:"Close",consentModal:{description:"Aici pute\u021Bi vedea \u0219i personaliza informa\u021Biile pe care le colect\u0103m despre dvs.",privacyPolicy:{name:"politica privacy",text:"Pentru a afla mai multe, v\u0103 rug\u0103m s\u0103 citi\u021Bi {privacyPolicy}."},title:"Informa\u021Biile pe care le colect\u0103m"},consentNotice:{changeDescription:"Au existat modific\u0103ri de la ultima vizit\u0103, v\u0103 rug\u0103m s\u0103 actualiza\u021Bi consim\u021B\u0103m\xE2ntul.",description:"Colect\u0103m \u0219i proces\u0103m informa\u021Biile dvs. personale \xEEn urm\u0103toarele scopuri: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"Afl\u0103 mai multe",privacyPolicy:{name:"politica privacy"}},decline:"Renun\u021B\u0103",ok:"OK",poweredBy:"Realizat de Klaro!",purposeItem:{service:"",services:""},save:"Salveaz\u0103"};var ai={acceptAll:"\u041F\u0440\u0438\u043D\u044F\u0442\u044C \u0432\u0441\u0451",acceptSelected:"\u041F\u0440\u0438\u043D\u044F\u0442\u044C \u0432\u044B\u0431\u0440\u0430\u043D\u043D\u044B\u0435",service:{disableAll:{description:"\u0418\u0441\u043F\u043E\u043B\u044C\u0437\u0443\u0439\u0442\u0435 \u044D\u0442\u043E\u0442 \u043F\u0435\u0440\u0435\u043A\u043B\u044E\u0447\u0430\u0442\u0435\u043B\u044C, \u0447\u0442\u043E\u0431\u044B \u0432\u043A\u043B\u044E\u0447\u0438\u0442\u044C/\u043E\u0442\u043A\u043B\u044E\u0447\u0438\u0442\u044C \u0432\u0441\u0435 \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u044F.",title:"\u041F\u0435\u0440\u0435\u043A\u043B\u044E\u0447\u0438\u0442\u044C \u0432\u0441\u0435 \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u044F"},optOut:{description:"\u042D\u0442\u043E \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u0435 \u0432\u043A\u043B\u044E\u0447\u0435\u043D\u043E \u043F\u043E \u0443\u043C\u043E\u043B\u0447\u0430\u043D\u0438\u044E (\u043D\u043E \u0432\u044B \u043C\u043E\u0436\u0435\u0442\u0435 \u043E\u0442\u043A\u0430\u0437\u0430\u0442\u044C\u0441\u044F)",title:"(\u043E\u0442\u043A\u0430\u0437\u0430\u0442\u044C\u0441\u044F)"},purpose:"\u041D\u0430\u043C\u0435\u0440\u0435\u043D\u0438\u0435",purposes:"\u041D\u0430\u043C\u0435\u0440\u0435\u043D\u0438\u044F",required:{description:"\u042D\u0442\u043E \u043E\u0431\u044F\u0437\u0430\u0442\u0435\u043B\u044C\u043D\u043E\u0435 \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u0435",title:"(\u0432\u0441\u0435\u0433\u0434\u0430 \u043E\u0431\u044F\u0437\u0430\u0442\u0435\u043B\u044C\u043D\u044B\u0439)"}},close:"\u0417\u0430\u043A\u0440\u044B\u0442\u044C",consentModal:{description:"\u0417\u0434\u0435\u0441\u044C \u0432\u044B \u043C\u043E\u0436\u0435\u0442\u0435 \u043F\u0440\u043E\u0441\u043C\u043E\u0442\u0440\u0435\u0442\u044C \u0438 \u043D\u0430\u0441\u0442\u0440\u043E\u0438\u0442\u044C, \u043A\u0430\u043A\u0443\u044E \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044E \u043E \u0432\u0430\u0441 \u043C\u044B \u0445\u0440\u0430\u043D\u0438\u043C.",privacyPolicy:{name:"\u0421\u043E\u0433\u043B\u0430\u0448\u0435\u043D\u0438\u0435",text:"\u0427\u0442\u043E\u0431\u044B \u0443\u0437\u043D\u0430\u0442\u044C \u0431\u043E\u043B\u044C\u0448\u0435, \u043F\u043E\u0436\u0430\u043B\u0443\u0439\u0441\u0442\u0430, \u043F\u0440\u043E\u0447\u0438\u0442\u0430\u0439\u0442\u0435 \u043D\u0430\u0448\u0435 {privacyPolicy}."},title:"\u0418\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044F, \u043A\u043E\u0442\u043E\u0440\u0443\u044E \u043C\u044B \u0441\u043E\u0445\u0440\u0430\u043D\u044F\u0435\u043C"},consentNotice:{changeDescription:"\u0421\u043E \u0432\u0440\u0435\u043C\u0435\u043D\u0438 \u0432\u0430\u0448\u0435\u0433\u043E \u043F\u043E\u0441\u043B\u0435\u0434\u043D\u0435\u0433\u043E \u0432\u0438\u0437\u0438\u0442\u0430 \u043F\u0440\u043E\u0438\u0437\u043E\u0448\u043B\u0438 \u0438\u0437\u043C\u0435\u043D\u0435\u043D\u0438\u044F, \u043E\u0431\u043D\u043E\u0432\u0438\u0442\u0435 \u0441\u0432\u043E\u0451 \u0441\u043E\u0433\u043B\u0430\u0441\u0438\u0435.",description:"\u041C\u044B \u0441\u043E\u0431\u0438\u0440\u0430\u0435\u043C \u0438 \u043E\u0431\u0440\u0430\u0431\u0430\u0442\u044B\u0432\u0430\u0435\u043C \u0432\u0430\u0448\u0443 \u043B\u0438\u0447\u043D\u0443\u044E \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044E \u0434\u043B\u044F \u0441\u043B\u0435\u0434\u0443\u044E\u0449\u0438\u0445 \u0446\u0435\u043B\u0435\u0439: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"\u041D\u0430\u0441\u0442\u0440\u043E\u0438\u0442\u044C",privacyPolicy:{name:"\u043F\u043E\u043B\u0438\u0442\u0438\u043A\u0430 \u043A\u043E\u043D\u0444\u0438\u0434\u0435\u043D\u0446\u0438\u0430\u043B\u044C\u043D\u043E\u0441\u0442\u0438"}},decline:"\u041E\u0442\u043A\u043B\u043E\u043D\u0438\u0442\u044C",ok:"\u041F\u0440\u0438\u043D\u044F\u0442\u044C",poweredBy:"\u0420\u0430\u0431\u043E\u0442\u0430\u0435\u0442 \u043D\u0430 \u041A\u043B\u0430\u0440\u043E!",purposeItem:{service:"",services:""},save:"\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C"};var ci={acceptAll:"",acceptAll_en:"Accept all",acceptSelected:"",acceptSelected_en:"Accept selected",service:{disableAll:{description:"Koristite ovaj prekida\u010D da omogu\u0107ite/onesposobite sve aplikacije odjednom.",title:"Izmeni sve"},optOut:{description:"Ova aplikacija je u\u010Ditana automatski (ali je mo\u017Eete onesposobiti)",title:"(onesposobite)"},purpose:"Svrha",purposes:"Svrhe",required:{description:"Ova aplikacija je uvek neophodna",title:"(neophodna)"}},close:"Zatvori",consentModal:{description:"Ovde mo\u017Eete videti i podesiti informacije koje prikupljamo o Vama.",privacyPolicy:{name:"politiku privatnosti",text:"Za vi\u0161e informacije pro\u010Ditajte na\u0161u {privacyPolicy}."},title:"Informacije koje prikupljamo"},consentNotice:{changeDescription:"Do\u0161lo je do promena od Va\u0161e poslednje posete, molimo Vas da a\u017Eurirate svoja odobrenja.",description:"Mi prikupljamo i procesiramo Va\u0161e li\u010Dne podatke radi slede\u0107eg: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"Saznajte vi\u0161e",privacyPolicy:{name:"politiku privatnosti"}},decline:"Odbij",ok:"U redu",poweredBy:"Pokre\u0107e Klaro!",purposeItem:{service:"",services:""},save:"Sa\u010Duvaj"};var li={consentModal:{title:"\u0418\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u0458\u0435 \u043A\u043E\u0458\u0435 \u043F\u0440\u0438\u043A\u0443\u043F\u0459\u0430\u043C\u043E",description:`\u041E\u0432\u0434\u0435 \u043C\u043E\u0436\u0435\u0442\u0435 \u0432\u0438\u0434\u0435\u0442 \u0438 \u043F\u043E\u0434\u0435\u0441\u0438\u0442\u0438 \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u0458\u0435 \u043A\u043E\u0458\u0435 \u043F\u0440\u0438\u043A\u0443\u043F\u0459\u0430\u043C\u043E \u043E \u0412\u0430\u043C\u0430.
`,privacyPolicy:{name:"\u043F\u043E\u043B\u0438\u0442\u0438\u043A\u0443 \u043F\u0440\u0438\u0432\u0430\u0442\u043D\u043E\u0441\u0442\u0438",text:`\u0417\u0430 \u0432\u0438\u0448\u0435 \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u0458\u0430 \u043F\u0440\u043E\u0447\u0438\u0442\u0430\u0458\u0442\u0435 \u043D\u0430\u0448\u0443 {privacyPolicy}.
`}},consentNotice:{changeDescription:"\u0414\u043E\u0448\u043B\u043E \u0458\u0435 \u0434\u043E \u043F\u0440\u043E\u043C\u0435\u043D\u0430 \u043E\u0434 \u0412\u0430\u0448\u0435 \u043F\u043E\u0441\u043B\u0435\u0434\u043D\u0458\u0435 \u043F\u043E\u0441\u0435\u0442\u0435, \u043C\u043E\u043B\u0438\u043C\u043E \u0412\u0430\u0441 \u0434\u0430 \u0430\u0436\u0443\u0440\u0438\u0440\u0430\u0442\u0435 \u0441\u0432\u043E\u0458\u0430 \u043E\u0434\u043E\u0431\u0440\u0435\u045A\u0430.",description:`\u041C\u0438 \u043F\u0440\u0438\u043A\u0443\u043F\u0459\u0430\u043C\u043E \u0438 \u043F\u0440\u043E\u0446\u0435\u0441\u0438\u0440\u0430\u043C\u043E \u0412\u0430\u0448\u0435 \u043B\u0438\u0447\u043D\u0435 \u043F\u043E\u0434\u0430\u0442\u043A\u0435 \u0440\u0430\u0434\u0438 \u0441\u043B\u0435\u0434\u0435\u045B\u0435\u0433: {purposes}.
`,learnMore:"\u0421\u0430\u0437\u043D\u0430\u0458\u0442\u0435 \u0432\u0438\u0448\u0435",privacyPolicy:{name:"\u043F\u043E\u043B\u0438\u0442\u0438\u043A\u0443 \u043F\u0440\u0438\u0432\u0430\u0442\u043D\u043E\u0441\u0442\u0438"}},ok:"\u0423 \u0440\u0435\u0434\u0443",save:"\u0421\u0430\u0447\u0443\u0432\u0430\u0458",decline:"\u041E\u0434\u0431\u0438\u0458",close:"\u0417\u0430\u0442\u0432\u043E\u0440\u0438",service:{disableAll:{title:"\u0418\u0437\u043C\u0435\u043D\u0438 \u0441\u0432\u0435",description:"\u041A\u043E\u0440\u0438\u0441\u0442\u0438\u0442\u0435 \u043E\u0432\u0430\u0458 \u043F\u0440\u0435\u043A\u0438\u0434\u0430\u0447 \u0434\u0430 \u043E\u043C\u043E\u0433\u0443\u045B\u0438\u0442\u0435/\u043E\u043D\u0435\u0441\u043F\u043E\u0441\u043E\u0431\u0438\u0442\u0435 \u0441\u0432\u0435 \u0430\u043F\u043B\u0438\u043A\u0430\u0446\u0438\u0458\u0435 \u043E\u0434\u0458\u0435\u0434\u043D\u043E\u043C."},optOut:{title:"(\u043E\u043D\u0435\u0441\u043F\u043E\u0441\u043E\u0431\u0438\u0442\u0435)",description:"\u041E\u0432\u0430 \u0430\u043F\u043B\u0438\u043A\u0430\u0446\u0438\u0458\u0430 \u0458\u0435 \u0443\u0447\u0438\u0442\u0430\u043D\u0430 \u0430\u0443\u0442\u043E\u043C\u0430\u0442\u0441\u043A\u0438 (\u0430\u043B\u0438 \u0458\u0435 \u043C\u043E\u0436\u0435\u0442\u0435 \u043E\u043D\u0435\u0441\u043F\u043E\u0441\u043E\u0431\u0438\u0442\u0438)"},required:{title:"(\u043D\u0435\u043E\u043F\u0445\u043E\u0434\u043D\u0430)",description:"\u041E\u0432\u0430 \u0430\u043F\u043B\u0438\u043A\u0430\u0446\u0438\u0458\u0430 \u0458\u0435 \u0443\u0432\u0435\u043A \u043D\u0435\u043E\u043F\u0445\u043E\u0434\u043D\u0430."},purposes:"\u0421\u0432\u0440\u0445\u0435",purpose:"\u0421\u0432\u0440\u0445\u0430"},poweredBy:"\u041F\u043E\u043A\u0440\u0435\u045B\u0435 \u041A\u043B\u0430\u0440\u043E!"};var pi={acceptAll:"Acceptera alla",acceptSelected:"Acceptera markerat",service:{disableAll:{description:"Anv\xE4nd detta reglage f\xF6r att aktivera/avaktivera samtliga appar.",title:"\xC4ndra f\xF6r alla appar"},optOut:{description:"Den h\xE4r appen laddas som standardinst\xE4llning (men du kan avaktivera den)",title:"(Avaktivera)"},purpose:"Syfte",purposes:"Syften",required:{description:"Den h\xE4r applikationen kr\xE4vs alltid",title:"(Kr\xE4vs alltid)"}},close:"St\xE4ng",consentModal:{description:"H\xE4r kan du se och anpassa vilken information vi samlar om dig.",privacyPolicy:{name:"Integritetspolicy",text:"F\xF6r att veta mer, l\xE4s v\xE5r {privacyPolicy}."},title:"Information som vi samlar"},consentNotice:{changeDescription:"Det har skett f\xF6r\xE4ndringar sedan ditt senaste bes\xF6k, var god uppdatera ditt medgivande.",description:"Vi samlar och bearbetar din personliga data i f\xF6ljande syften: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"L\xE4s mer",privacyPolicy:{name:"Integritetspolicy"}},decline:"Avb\xF6j",ok:"OK",poweredBy:"K\xF6rs p\xE5 Klaro!",purposeItem:{service:"",services:""},save:"Spara"};var di={acceptAll:"",acceptAll_en:"Accept all",acceptSelected:"",acceptSelected_en:"Accept selected",service:{disableAll:{description:"Toplu a\xE7ma/kapama i\xE7in bu d\xFC\u011Fmeyi kullanabilirsin.",title:"T\xFCm uygulamalar\u0131 a\xE7/kapat"},optOut:{description:"Bu uygulama varsay\u0131landa y\xFCklendi (ancak iptal edebilirsin)",title:"(iste\u011Fe ba\u011Fl\u0131)"},purpose:"Ama\xE7",purposes:"Ama\xE7lar",required:{description:"Bu uygulama her zaman gerekli",title:"(her zaman gerekli)"}},close:"Kapat",consentModal:{description:"Hakk\u0131n\u0131zda toplad\u0131\u011F\u0131m\u0131z bilgileri burada g\xF6rebilir ve \xF6zelle\u015Ftirebilirsiniz.",privacyPolicy:{name:"Gizlilik Politikas\u0131",text:"Daha fazlas\u0131 i\xE7in l\xFCtfen {privacyPolicy} sayfam\u0131z\u0131 okuyun."},title:"Saklad\u0131\u011F\u0131m\u0131z bilgiler"},consentNotice:{changeDescription:"Son ziyaretinizden bu yana de\u011Fi\u015Fiklikler oldu, l\xFCtfen se\xE7iminizi g\xFCncelleyin.",description:"Ki\u015Fisel bilgilerinizi a\u015Fa\u011F\u0131daki ama\xE7larla sakl\u0131yor ve i\u015Fliyoruz: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"Daha fazla bilgi",privacyPolicy:{name:"Gizlilik Politikas\u0131"}},decline:"Reddet",ok:"Tamam",poweredBy:"Klaro taraf\u0131ndan geli\u015Ftirildi!",purposeItem:{service:"",services:""},save:"Kaydet"};var ui={acceptAll:"\u7167\u5355\u5168\u6536",acceptSelected:"\u63A5\u53D7\u9009\u62E9",close:"\u5BC6\u5207",consentModal:{description:"\u5728\u8FD9\u91CC\uFF0C\u60A8\u53EF\u4EE5\u8BC4\u4F30\u548C\u5B9A\u5236\u6211\u4EEC\u5E0C\u671B\u5728\u672C\u7F51\u7AD9\u4E0A\u4F7F\u7528\u7684\u670D\u52A1\u3002\u60A8\u662F\u8D1F\u8D23\u4EBA\uFF01\u60A8\u53EF\u4EE5\u6839\u636E\u81EA\u5DF1\u7684\u9700\u8981\u542F\u7528\u6216\u7981\u7528\u670D\u52A1\u3002\u542F\u7528\u6216\u7981\u7528\u60A8\u8BA4\u4E3A\u5408\u9002\u7684\u670D\u52A1\u3002",privacyPolicy:{name:"\u9690\u79C1\u653F\u7B56",text:"\u8981\u4E86\u89E3\u66F4\u591A\uFF0C\u8BF7\u9605\u8BFB\u6211\u4EEC\u7684{privacyPolicy} \u3002"},title:"\u6211\u4EEC\u60F3\u4F7F\u7528\u7684\u670D\u52A1"},consentNotice:{changeDescription:"\u81EA\u4E0A\u6B21\u8BBF\u95EE\u540E\u6709\u53D8\u5316\uFF0C\u8BF7\u66F4\u65B0\u60A8\u7684\u540C\u610F\u3002",description:"\u4F60\u597D\uFF01\u6211\u4EEC\u53EF\u4EE5\u4E3A{purposes} \u542F\u7528\u4E00\u4E9B\u989D\u5916\u7684\u670D\u52A1\u5417\uFF1F\u60A8\u53EF\u4EE5\u968F\u65F6\u66F4\u6539\u6216\u64A4\u56DE\u60A8\u7684\u540C\u610F\u3002",imprint:{name:"\u5370\u8BB0"},learnMore:"\u8BA9\u6211\u6765\u9009",privacyPolicy:{name:"\u9690\u79C1\u653F\u7B56"},testing:"\u6D4B\u8BD5\u6A21\u5F0F\uFF01"},contextualConsent:{acceptAlways:"\u603B\u662F",acceptOnce:"\u662F\u7684\uFF0C\u662F\u7684",description:"\u4F60\u60F3\u52A0\u8F7D\u7531{title} \u63D0\u4F9B\u7684\u5916\u90E8\u5185\u5BB9\u5417\uFF1F"},decline:"\u6211\u62D2\u7EDD",ok:"\u6CA1\u4E8B\u7684",poweredBy:"\u4E0EKlaro\u4E00\u8D77\u5B9E\u73B0!",privacyPolicy:{name:"\u9690\u79C1\u653F\u7B56",text:"\u8981\u4E86\u89E3\u66F4\u591A\uFF0C\u8BF7\u9605\u8BFB\u6211\u4EEC\u7684{privacyPolicy} \u3002"},purposeItem:{service:"\u670D\u52A1",services:"\u670D\u52A1"},purposes:{advertising:{description:"\u8FD9\u4E9B\u670D\u52A1\u5904\u7406\u4E2A\u4EBA\u4FE1\u606F\uFF0C\u5411\u60A8\u5C55\u793A\u4E2A\u6027\u5316\u6216\u57FA\u4E8E\u5174\u8DA3\u7684\u5E7F\u544A\u3002",title:"\u5E7F\u544A\u5BA3\u4F20"},functional:{description:`\u8FD9\u4E9B\u670D\u52A1\u5BF9\u4E8E\u672C\u7F51\u7AD9\u7684\u6B63\u5E38\u8FD0\u884C\u662F\u5FC5\u4E0D\u53EF\u5C11\u7684\u3002\u60A8\u4E0D\u80FD\u5728\u8FD9\u91CC\u7981\u7528\u5B83\u4EEC\uFF0C\u5426\u5219\u670D\u52A1\u5C06\u65E0\u6CD5\u6B63\u5E38\u8FD0\u884C\u3002
`,title:"\u670D\u52A1\u63D0\u4F9B"},marketing:{description:"\u8FD9\u4E9B\u670D\u52A1\u4F1A\u5904\u7406\u4E2A\u4EBA\u4FE1\u606F\uFF0C\u5411\u60A8\u5C55\u793A\u60A8\u53EF\u80FD\u611F\u5174\u8DA3\u7684\u4EA7\u54C1\u3001\u670D\u52A1\u6216\u4E3B\u9898\u7684\u76F8\u5173\u5185\u5BB9\u3002",title:"\u5E02\u573A\u8425\u9500"},performance:{description:`\u8FD9\u4E9B\u670D\u52A1\u5904\u7406\u4E2A\u4EBA\u4FE1\u606F\u662F\u4E3A\u4E86\u4F18\u5316\u672C\u7F51\u7AD9\u63D0\u4F9B\u7684\u670D\u52A1\u3002
`,title:"\u6027\u80FD\u4F18\u5316"}},save:"\u633D\u6551",service:{disableAll:{description:"\u4F7F\u7528\u6B64\u5F00\u5173\u53EF\u542F\u7528\u6216\u7981\u7528\u6240\u6709\u670D\u52A1\u3002",title:"\u542F\u7528\u6216\u505C\u7528\u6240\u6709\u670D\u52A1"},optOut:{description:"\u8FD9\u4E2A\u670D\u52A1\u662F\u9ED8\u8BA4\u52A0\u8F7D\u7684(\u4F46\u4F60\u53EF\u4EE5\u9009\u62E9\u9000\u51FA)",title:"(\u9009\u62E9\u9000\u51FA)"},purpose:"\u76EE\u7684",purposes:"\u76EE\u7684",required:{description:"\u8FD9\u79CD\u670D\u52A1\u662F\u5FC5\u987B\u7684",title:"(\u603B\u662F\u9700\u8981)"}}};var br={bg:qt,ca:Ht,cs:Ft,da:Vt,de:Wt,el:Kt,en:Bt,es:Gt,fi:Qt,fr:Zt,gl:Jt,hr:Xt,hu:Yt,it:ei,nl:ti,no:ii,oc:ni,pl:ri,pt:oi,ro:si,ru:ai,sr:ci,sr_cyrl:li,sv:pi,tr:di,zh:ui},mi=br;var fi="simplecmp-reported:";function hi(r){let e=r.indexOf("?"),t=r.indexOf("#"),i=[e,t].filter(n=>n>=0);return i.length===0?r:r.slice(0,Math.min(...i))}var L=class{constructor(e){this.lastSent=new Map;this.warned=new Set;this.pending=[];this.pendingKeys=[];this.flushTimer=null;this.lifecycleHooked=!1;this.url=e.url,this.host=(()=>{try{return new URL(e.url).hostname}catch{return""}})(),this.auth=e.auth,this.source=e.source??"default",this.dedupTtlMs=e.dedupTtlMs??36e5,this.crossSessionDedupMs=e.crossSessionDedupMs??6048e5,this.reportGeneration=Math.max(0,Math.floor(e.reportGeneration??0)),this.flushDebounceMs=e.flushDebounceMs??1500,this.maxBatchSize=Math.max(1,e.maxBatchSize??25),this.timeoutMs=e.timeoutMs??5e3,this.respectDoNotTrack=e.respectDoNotTrack??!0,this.fetchFn=e.fetch??(typeof fetch<"u"?fetch.bind(globalThis):void 0),this.now=e.now??(()=>Date.now()),e.storage!==void 0?this.storage=e.storage:typeof localStorage<"u"?this.storage=localStorage:this.storage=null,this.nav=e.navigator??(typeof navigator<"u"?navigator:void 0);let t=e.sampleRate??1;this.sessionInScope=t>=1||Math.random()<t}onDetection(e){if(!this.sessionInScope||this.respectDoNotTrack&&this.nav?.doNotTrack==="1"||this.host&&e.origin===this.host)return;let t=`${e.kind}:${e.identifier}`;if(this._dedupHit(t))return;let i=this.now();if(this.lastSent.set(t,i),this.pendingKeys.push(t),this.pending.push(this._toBridgeDetection(e)),this.pending.length>=this.maxBatchSize){this._flush();return}this._scheduleFlush(),this._hookLifecycle()}flushNow(){return this._flush()}_dedupHit(e){let t=this.now(),i=this.lastSent.get(e);return i!==void 0&&t-i<this.dedupTtlMs?!0:this._crossSessionHit(e,t)}_crossSessionHit(e,t){if(this.storage===null||this.crossSessionDedupMs<=0)return!1;let i=`${fi}${this.source}:${e}`,n=null;try{n=this.storage.getItem(i)}catch{return!1}if(n===null)return!1;let o=this._parseMarker(n);if(o===null)return!1;if(o.gen<this.reportGeneration){try{this.storage.removeItem(i)}catch{}return!1}if(t-o.ts<this.crossSessionDedupMs)return!0;try{this.storage.removeItem(i)}catch{}return!1}_markCrossSession(e,t){if(this.storage===null||this.crossSessionDedupMs<=0)return;let i=`${fi}${this.source}:${e}`;try{this.storage.setItem(i,`${this.reportGeneration}.${t}`)}catch{}}_parseMarker(e){let t=e.indexOf(".");if(t===-1){let o=Number(e);return Number.isFinite(o)?{gen:0,ts:o}:null}let i=Number(e.slice(0,t)),n=Number(e.slice(t+1));return!Number.isFinite(i)||!Number.isFinite(n)?null:{gen:i,ts:n}}_scheduleFlush(){this.flushTimer!==null||typeof setTimeout>"u"||(this.flushTimer=setTimeout(()=>{this.flushTimer=null,this._flush()},this.flushDebounceMs))}_hookLifecycle(){if(this.lifecycleHooked||typeof addEventListener>"u")return;this.lifecycleHooked=!0;let e=()=>{this._flushBeacon()};addEventListener("pagehide",e,{capture:!0}),typeof document<"u"&&document.addEventListener("visibilitychange",()=>{document.visibilityState==="hidden"&&e()},{capture:!0})}_flushBeacon(){if(this.pending.length===0)return;if(typeof this.nav?.sendBeacon!="function"){this._flush({keepalive:!0});return}let e=this._buildPayload(this.pending),t=new Blob([JSON.stringify(e)],{type:"application/json"}),i=!1;try{i=this.nav.sendBeacon(this.url,t)}catch{i=!1}i?(this._markBatchSent(),this.pending=[],this.pendingKeys=[]):this._flush({keepalive:!0})}async _flush(e={}){if(this.flushTimer!==null&&(clearTimeout(this.flushTimer),this.flushTimer=null),this.pending.length===0)return;let t=this.pending,i=this.pendingKeys;this.pending=[],this.pendingKeys=[];try{await this._post(this._buildPayload(t),e);let n=this.now();for(let o of i)this._markCrossSession(o,n)}catch(n){if(this._shouldClearOnError(n))for(let o of i)this.lastSent.delete(o);this._warnOnce("post",n)}}_markBatchSent(){let e=this.now();for(let t of this.pendingKeys)this._markCrossSession(t,e)}_toBridgeDetection(e){let t={kind:e.kind,identifier:e.identifier,firstSeen:e.firstSeen,lastSeen:e.lastSeen,count:e.count,status:e.status==="known"?"known":"unknown"};return e.origin!==void 0&&(t.origin=e.origin),e.firstSeenOn!==void 0&&(t.firstSeenOn=hi(e.firstSeenOn)),e.matchedService!==void 0&&(t.matchedService=e.matchedService),t}_buildPayload(e){let t=typeof location<"u"?location:void 0,i=typeof document<"u"?document:void 0,n=typeof navigator<"u"?navigator:void 0,o={url:t?hi(t.href):""},s=i?.referrer;s&&(o.referrer=s);let a=n?.userAgent;return a&&(o.userAgent=a),{schemaVersion:2,source:this.source,sentAt:new Date(this.now()).toISOString(),page:o,library:{name:"simplecmp",version:"0.0.1"},detections:e}}async _post(e,t){if(!this.fetchFn)throw new Error("fetch is unavailable");let i=new Headers({"Content-Type":"application/json"});if(this.auth){let s=this.auth.header??"Authorization",a=this.auth.scheme??"Bearer";i.set(s,`${a} ${this.auth.token}`.trim())}let n=typeof AbortController<"u"?new AbortController:null,o=n&&typeof setTimeout<"u"?setTimeout(()=>n.abort(),this.timeoutMs):void 0;try{let s={method:"POST",headers:i,body:JSON.stringify(e),signal:n?.signal};t.keepalive===!0&&(s.keepalive=!0);let a=await this.fetchFn(this.url,s);if(!a.ok)throw new Error(`CMS bridge POST responded ${a.status}`)}finally{o!==void 0&&clearTimeout(o)}}_shouldClearOnError(e){let t=e instanceof Error?e.message:String(e);return/responded 4\d\d/.exec(t)===null}_warnOnce(e,t){if(this.warned.has(e))return;this.warned.add(e);let i=t instanceof Error?t.message:String(t);console.warn(`SimpleCMP cms-bridge: ${e} failed (${i}). The bridge will keep trying on subsequent detection events; this warning fires once per error category per session.`)}};var gi="simplecmp.servicedb.";function Xe(r,e){let t=e.cookie?`c:${e.cookie}`:`o:${e.origin??""}`;return`${gi}${r}.${t}`}function kr(r,e){let t=r.get("Cache-Control");if(!t)return e;let i=/max-age=(\d+)/.exec(t);if(!i||!i[1])return e;let n=Number.parseInt(i[1],10);return Number.isNaN(n)||n<0?e:n*1e3}var W=class{constructor(e){this.inflight=new Map;this.warned=new Set;this.url=e.url.replace(/\/+$/,""),this.host=(()=>{try{return new URL(this.url).host}catch{return this.url}})(),this.auth=e.auth,this.cacheTtlMs=e.cacheTtlMs??864e5,this.timeoutMs=e.timeoutMs??3e3,this.apiVersion=e.apiVersion??"v1",this.fetchFn=e.fetch??(typeof fetch<"u"?fetch.bind(globalThis):void 0),this.storage=e.storage??(typeof localStorage<"u"?localStorage:void 0),this.now=e.now??(()=>Date.now())}async lookup(e){let t=Xe(this.host,e),i=this._readCache(t);return i!==void 0?(this.now()-i.storedAt<i.maxAgeMs||this._revalidate(t,e),i.match):this._fetchAndCache(t,e)}async lookupBatch(e){if(e.length===0)return[];let t=new Array(e.length).fill(void 0),i=[];for(let n=0;n<e.length;n++){let o=e[n];if(!o)continue;let s=Xe(this.host,o),a=this._readCache(s);a!==void 0&&this.now()-a.storedAt<a.maxAgeMs?t[n]=a.match:i.push({index:n,query:o})}if(i.length===0)return t.map(n=>n??null);try{let n=JSON.stringify({items:i.map(a=>a.query)}),s=(await this._request(`/${this.apiVersion}/lookup`,{method:"POST",headers:{"Content-Type":"application/json"},body:n}))?.items??[];for(let a=0;a<i.length;a++){let c=i[a],l=s[a];if(!c)continue;let m=l?.matches?.[0]??null,d=Xe(this.host,c.query);this._writeCache(d,m,this.cacheTtlMs),t[c.index]=m}}catch(n){this._warnOnce("batch-lookup",n);for(let o of i)t[o.index]=null}return t.map(n=>n??null)}clearCache(){if(!this.storage)return;let e=this.storage;if(typeof e.length!="number"||typeof e.key!="function")return;let t=`${gi}${this.host}.`,i=[];for(let n=0;n<e.length;n++){let o=e.key(n);o?.startsWith(t)&&i.push(o)}for(let n of i)this.storage.removeItem(n)}async health(){try{return await this._request(`/${this.apiVersion}/health`,{method:"GET"})}catch{return null}}async _fetchAndCache(e,t){let i=this.inflight.get(e);if(i)return i;let n=(async()=>{try{let s=(await this._request(`/${this.apiVersion}/lookup`,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({items:[t]})}))?.items?.[0]?.matches?.[0]??null;return this._writeCache(e,s,this.cacheTtlMs),s}catch(o){return this._warnOnce("lookup",o),null}finally{this.inflight.delete(e)}})();return this.inflight.set(e,n),n}async _revalidate(e,t){this.inflight.has(e)||await this._fetchAndCache(e,t).catch(()=>{})}async _request(e,t){if(!this.fetchFn)throw new Error("fetch is unavailable");let i=new Headers(t.headers);if(this.auth){let s=this.auth.header??"Authorization",a=this.auth.scheme??"Bearer";i.set(s,`${a} ${this.auth.token}`.trim())}let n=typeof AbortController<"u"?new AbortController:null,o=n&&typeof setTimeout<"u"?setTimeout(()=>n.abort(),this.timeoutMs):void 0;try{let s=await this.fetchFn(`${this.url}${e}`,{...t,headers:i,signal:n?.signal});if(!s.ok)throw new Error(`Service DB ${e} responded ${s.status}`);return await s.json()}finally{o!==void 0&&clearTimeout(o)}}_readCache(e){if(this.storage)try{let t=this.storage.getItem(e);if(!t)return;let i=JSON.parse(t);return typeof i.storedAt!="number"||typeof i.maxAgeMs!="number"?void 0:i}catch{return}}_writeCache(e,t,i){if(this.storage)try{let n={match:t,storedAt:this.now(),maxAgeMs:i};this.storage.setItem(e,JSON.stringify(n))}catch{}}_absorbCacheControl(e){return kr(e,this.cacheTtlMs)}_warnOnce(e,t){if(this.warned.has(e))return;this.warned.add(e);let i=t instanceof Error?t.message:String(t);console.warn(`SimpleCMP service-db: ${e} failed (${i}). Falling back to local classification for this session category.`)}};function _e(r,e,t){if(typeof e=="string"){if(e.length>=2&&e.startsWith("/")&&e.endsWith("/"))try{return new RegExp(e.slice(1,-1)).test(r)}catch{return!1}return e===r}if(e instanceof RegExp)return e.test(r);if(Array.isArray(e)&&e.length>0){let i=e[0];if(typeof i!="string")return!1;try{return new RegExp(i).test(r)}catch{return i===r}}return typeof e=="object"&&e!==null&&"name"in e&&"requireOrigin"in e&&wr(e.requireOrigin,t)?_e(r,e.name,t):!1}function Ae(r,e){if(e instanceof RegExp)return e.test(r);if(typeof e!="string")return!1;if(e.length>=2&&e.startsWith("/")&&e.endsWith("/"))try{return new RegExp(`^(?:${e.slice(1,-1)})$`).test(r)}catch{return!1}if(e.startsWith("*.")){let t=e.slice(2);return r===t||r.endsWith(`.${t}`)}return e===r}function wr(r,e){for(let t of e)if(Ae(t,r))return!0;return!1}var K=class{constructor(e){this.services=e;this.observedOrigins=new Set}classify(e){e.kind!=="cookie"&&e.origin&&this.observedOrigins.add(e.origin);let t=this._findService(e);return t?{matchedService:t,status:"known"}:{status:"unknown"}}hasObservedOrigin(e){return this.observedOrigins.has(e)}get observedOriginsView(){return this.observedOrigins}_findService(e){if(e.kind==="cookie"){for(let t of this.services)if(t.cookies){for(let i of t.cookies)if(_e(e.identifier,i,this.observedOrigins))return t.name}return}if(e.origin){for(let t of this.services)if(t.origins){for(let i of t.origins)if(Ae(e.origin,i))return t.name}}}};var G=class{constructor(e,t){this.dbClient=e;this.listeners=new Set;this.local=new K(t)}classify(e){let t=this.local.classify(e);if(t.status==="known")return t;let i=e.kind==="cookie"?{cookie:e.identifier}:e.origin?{origin:e.origin}:null;if(!i)return t;let n=this.dbClient.lookup(i).then(o=>{o&&(e.kind==="cookie"&&!this._hostQualifierPasses(o,e.identifier)||this._dispatch(e,this._toEnrichment(o)))}).catch(()=>{});return{...t,pending:n}}_hostQualifierPasses(e,t){let i=e.matches?.cookies??[];if(i.length===0)return!0;let n=this.local.observedOriginsView;for(let o of i)if(_e(t,o,n))return!0;return!1}onEnrichment(e){this.listeners.add(e)}offEnrichment(e){this.listeners.delete(e)}_dispatch(e,t){for(let i of this.listeners)try{i(e,t)}catch(n){console.warn("SimpleCMP service-db: enrichment listener threw:",n)}}_toEnrichment(e){return{matchedService:e.id,matchedVendor:e.vendor,status:"known"}}};var Sr="simplecmp.recorder.";function vi(r){return!!(!r||r==="localhost"||r.endsWith(".localhost")||r.endsWith(".local")||r.endsWith(".test")||/^127\.\d+\.\d+\.\d+$/.test(r)||/^192\.168\.\d+\.\d+$/.test(r)||/^10\.\d+\.\d+\.\d+$/.test(r)||r==="::1"||r==="0.0.0.0")}var Ce=class{constructor(e){this.listeners=new Set;this.settledListeners=new Set;this.detections=new Map;this.active=!1;this.options=e.options,this.classifier=e.classifier,this.services=e.services,this.onDetectionForLibEvent=e.onDetectionForLibEvent;let t=i=>this._ingest(i);this.watchers=e.watcherFactories.map(i=>i(t))}start(){if(this.active)return;this.active=!0;let e=typeof location<"u"?location.hostname:"",t=vi(e);!t&&!this.options.silenceProductionWarning&&console.warn(`SimpleCMP: recorder is active on a hostname that looks like production (${e||"unknown"}). Set \`record: { silenceProductionWarning: true }\` to suppress this warning if intentional.`),this.options.persistInDev&&t&&this._loadFromStorage();for(let n of this.watchers)n.start();let i=this.options.summaryIntervalMs??3e4;i>0&&typeof setInterval<"u"&&(this.summaryTimer=setInterval(()=>this._logSummary(),i))}stop(){if(this.active){this.active=!1;for(let e of this.watchers)e.stop();this.summaryTimer!==void 0&&(clearInterval(this.summaryTimer),this.summaryTimer=void 0)}}getSnapshot(){return Array.from(this.detections.values())}clear(){this.detections.clear(),this._writeToStorage()}on(e,t){e==="detection"?this.listeners.add(t):e==="detectionSettled"&&this.settledListeners.add(t)}off(e,t){e==="detection"?this.listeners.delete(t):e==="detectionSettled"&&this.settledListeners.delete(t)}recordSyntheticDetection(e){this.active&&this._ingest(e)}enrichDetection(e,t){let i=`${e.kind}:${e.identifier}`,n=this.detections.get(i);if(!n)return;let o={...n,...t,lastSeen:Date.now()};this.detections.set(i,o),this._announce(o),this._writeToStorage()}exportConfig(){let e=new Map;for(let i of this.services){let n={name:i.name};i.cookies&&(n.cookies=i.cookies.slice()),i.origins&&(n.origins=i.origins.slice()),e.set(i.name,n)}let t=1;for(let i of this.detections.values()){if(i.status!=="unknown")continue;let n=i.kind==="cookie"?`unknown-cookie-${t++}`:`unknown-${i.origin?.replace(/[^a-z0-9]+/gi,"-")??"origin"}-${t++}`,o={name:n,purposes:[]};i.kind==="cookie"?o.cookies=[i.identifier]:i.origin&&(o.origins=[i.origin]),e.set(n,o)}return{services:Array.from(e.values())}}assertNoUnknown(){let e=this.getSnapshot().filter(i=>i.status==="unknown");if(e.length===0)return;let t=e.map(i=>`  - [${i.kind}] ${i.identifier}${i.origin?` (${i.origin})`:""}`).join(`
`);throw new Error(`SimpleCMP recorder: ${e.length} unknown detection(s):
${t}
Add a service for each, or pass \`record: { silenceProductionWarning: true }\` if intentional.`)}_ingest(e){if(e.kind==="cookie"&&this.options.ignoreCookies?.includes(e.identifier))return;let t=`${e.kind}:${e.identifier}`,i=Date.now(),n=this.detections.get(t);if(n){n.lastSeen=i,n.count+=1;return}let o=this.classifier.classify(e),{pending:s,...a}=o,c={kind:e.kind,identifier:e.identifier,origin:e.origin,firstSeen:i,lastSeen:i,firstSeenOn:e.firstSeenOn,count:1,...a};this.detections.set(t,c),this._announce(c),this._writeToStorage(),e.kind!=="cookie"&&e.origin&&this._reclassifyUnknownCookiesOnNewOrigin(),s?s.finally(()=>this._announceSettled(t)):this._announceSettled(t)}_announce(e){typeof console<"u"&&typeof console.info=="function"&&console.info(`[SimpleCMP recorder] ${e.kind} ${e.status==="unknown"?"\u{1F7E1} unknown":`\u2192 ${e.matchedService}`}: ${e.identifier}`);for(let t of[...this.listeners])try{t(e)}catch(i){console.warn("SimpleCMP recorder: listener threw:",i)}if(this.onDetectionForLibEvent)try{this.onDetectionForLibEvent(e)}catch(t){console.warn("SimpleCMP recorder: lib-event handler threw:",t)}}_reclassifyUnknownCookiesOnNewOrigin(){for(let e of this.detections.values()){if(e.kind!=="cookie"||e.status!=="unknown")continue;let t=this.classifier.classify({kind:e.kind,identifier:e.identifier,origin:e.origin,firstSeenOn:e.firstSeenOn});if(t.status==="known"&&t.matchedService){let i={status:"known",matchedService:t.matchedService};t.matchedVendor!==void 0&&(i.matchedVendor=t.matchedVendor),this.enrichDetection({kind:e.kind,identifier:e.identifier},i)}}}_announceSettled(e){let t=this.detections.get(e);if(t)for(let i of[...this.settledListeners])try{i(t)}catch(n){console.warn("SimpleCMP recorder: settled listener threw:",n)}}_logSummary(){if(this.detections.size===0)return;let e=Array.from(this.detections.values()).map(t=>({kind:t.kind,identifier:t.identifier,origin:t.origin??"",status:t.status,service:t.matchedService??"",count:t.count,firstSeenOn:t.firstSeenOn??""}));typeof console.table=="function"&&(console.groupCollapsed("[SimpleCMP recorder] catalog"),console.table(e),console.groupEnd())}_storageKey(){return Sr+(this.options.storageName??"default")}_loadFromStorage(){if(!(typeof sessionStorage>"u"))try{let e=sessionStorage.getItem(this._storageKey());if(!e)return;let t=JSON.parse(e);if(t.schema!==1||!Array.isArray(t.detections))return;for(let i of t.detections)this.detections.set(`${i.kind}:${i.identifier}`,i)}catch{}}_writeToStorage(){if(this.options.persistInDev&&!(typeof location>"u"||!vi(location.hostname))&&!(typeof sessionStorage>"u"))try{let e=JSON.stringify({schema:1,detections:Array.from(this.detections.values())});sessionStorage.setItem(this._storageKey(),e)}catch{}}};function _r(r){let e=new Set;if(!r)return e;for(let t of r.split(";")){let i=t.indexOf("="),n=(i>=0?t.slice(0,i):t).trim();n&&e.add(n)}return e}var xe=class{constructor(e,t={}){this.seen=new Set;this.sink=e,this.intervalMs=t.intervalMs??1e3,this.readCookies=t.readCookies??(()=>typeof document<"u"?document.cookie:""),this.getPathname=()=>typeof location<"u"?location.pathname+location.search:void 0}start(){this.timerId===void 0&&(this._scan(),this.timerId=setInterval(()=>this._scan(),this.intervalMs))}stop(){this.timerId!==void 0&&(clearInterval(this.timerId),this.timerId=void 0)}scanOnce(){this._scan()}_scan(){let e=_r(this.readCookies()),t=this.getPathname();for(let i of e)this.seen.has(i)||(this.seen.add(i),this.sink({kind:"cookie",identifier:i,firstSeenOn:t}))}};var Ye={SCRIPT:"script",IFRAME:"iframe",IMG:"image",LINK:"link",AUDIO:"request",VIDEO:"request",SOURCE:"request",TRACK:"request",EMBED:"request",OBJECT:"request"};function Ar(r){let e=r.tagName;if(e==="LINK")return r.href||void 0;if(e==="OBJECT")return r.data||void 0;let t=r.getAttribute("src");if(t)try{return new URL(t,location.href).href}catch{return}}function Cr(r){try{return new URL(r).hostname}catch{return}}var Ee=class{constructor(e,t={}){this.seen=new Set;this.sink=e,this.root=t.root??(typeof document<"u"?document.documentElement:null)}start(){!this.root||this.observer||(this._initialScan(),!(typeof MutationObserver>"u")&&(this.observer=new MutationObserver(e=>this._onMutations(e)),this.observer.observe(this.root,{childList:!0,subtree:!0})))}stop(){this.observer&&(this.observer.disconnect(),this.observer=void 0)}_initialScan(){if(!this.root)return;let e=Object.keys(Ye).join(","),t=this.root.querySelectorAll(e);for(let i of Array.from(t))this._handleElement(i)}_onMutations(e){for(let t of e)for(let i of Array.from(t.addedNodes)){if(i.nodeType!==1)continue;let n=i;if(this._handleElement(n),n.querySelectorAll){let o=Object.keys(Ye).join(",");for(let s of Array.from(n.querySelectorAll(o)))this._handleElement(s)}}}_handleElement(e){let t=Ye[e.tagName];if(!t)return;let i=Ar(e);if(!i)return;let n=Cr(i);if(!n||typeof location<"u"&&n===location.hostname)return;let o=`${t}:${i}`;if(this.seen.has(o))return;this.seen.add(o);let s={kind:t,identifier:i,origin:n,firstSeenOn:typeof location<"u"?location.pathname+location.search:void 0};this.sink(s)}};function xr(r){try{return new URL(r).hostname}catch{return}}var ze=class{constructor(e,t={}){this.seen=new Set;this.sink=e,this.perf=t.performance??(typeof performance<"u"?performance:void 0),this.Observer=t.PerformanceObserver??(typeof PerformanceObserver<"u"?PerformanceObserver:void 0)}start(){if(!this.observer&&(this._drainExisting(),!!this.Observer))try{this.observer=new this.Observer(e=>this._handleList(e)),this.observer.observe({type:"resource",buffered:!1})}catch{try{this.observer=new this.Observer(e=>this._handleList(e)),this.observer.observe({entryTypes:["resource"]})}catch{this.observer=void 0}}}stop(){this.observer&&(this.observer.disconnect(),this.observer=void 0)}_drainExisting(){if(this.perf)try{let e=this.perf.getEntriesByType("resource");for(let t of e)this._handleEntry(t)}catch{}}_handleList(e){for(let t of e.getEntries())this._handleEntry(t)}_handleEntry(e){let t=e.name;if(!t)return;let i=xr(t);if(!i||typeof location<"u"&&i===location.hostname)return;let n=`request:${t}`;if(this.seen.has(n))return;this.seen.add(n);let o={kind:"request",identifier:t,origin:i,firstSeenOn:typeof location<"u"?location.pathname+location.search:void 0};this.sink(o)}};function Er(){if(typeof window>"u"||typeof URLSearchParams>"u")return!1;try{return new URLSearchParams(window.location.search).get("simplecmp_discover")==="1"}catch{return!1}}function yi(r){let e=typeof r.record=="object"&&r.record!==null?{...r.record}:{};if(!e.storageName&&typeof r.storageName=="string"&&(e.storageName=r.storageName),e.storageName){let s=e.ignoreCookies??[];e.ignoreCookies=s.includes(e.storageName)?s:[e.storageName,...s]}let t=r.services??[],i=r.serviceDbUrl?new G(new W({url:r.serviceDbUrl,auth:r.serviceDbAuth}),t):null,n=i??new K(t),o=new Ce({options:e,classifier:n,services:t,watcherFactories:[s=>new xe(s,{intervalMs:e.cookieIntervalMs}),s=>new Ee(s),s=>new ze(s)],onDetectionForLibEvent:s=>{Je("recorderDetection",s)}});if(i&&i.onEnrichment((s,a)=>{o.enrichDetection(s,a)}),r.cmsBridgeUrl){let s=Er(),a=new L({url:r.cmsBridgeUrl,auth:r.cmsBridgeAuth,source:r.cmsBridge?.source??e.storageName??"default",dedupTtlMs:r.cmsBridge?.dedupTtlMs,crossSessionDedupMs:s?0:r.cmsBridge?.crossSessionDedupMs,flushDebounceMs:r.cmsBridge?.flushDebounceMs,maxBatchSize:r.cmsBridge?.maxBatchSize,sampleRate:s?1:r.cmsBridge?.sampleRate,respectDoNotTrack:s?!1:r.cmsBridge?.respectDoNotTrack,timeoutMs:r.cmsBridge?.timeoutMs,reportGeneration:r.cmsBridge?.reportGeneration});o.on("detectionSettled",c=>a.onDetection(c))}return o}function bi(r){switch(r){case"script-src":return"script";case"iframe-src":return"iframe";case"img-src":return"image";case"fetch":case"xhr":case"sendBeacon":return"request"}}function ki(r){try{return new URL(r,window.location.href).hostname||void 0}catch{return}}function wi(r){let e={matcher:r.matcher,consentChecker:r.consentChecker,sameOriginHosts:[window.location.host,...r.sameOriginHosts??[]],onBlock:r.onBlock??(()=>{})},t=[et(HTMLScriptElement.prototype,"script-src",e),et(HTMLIFrameElement.prototype,"iframe-src",e),et(HTMLImageElement.prototype,"img-src",e),zr(e),Mr(e),$r(e)];return()=>{for(let i of t)i()}}function Me(r,e){if(!r||r==="about:blank")return null;let t;try{t=new URL(r,window.location.href)}catch{return null}let{host:i,hostname:n}=t;if(i===""||e.sameOriginHosts.includes(i))return null;let o=e.matcher(n);return o===null||e.consentChecker(o)?null:o}function et(r,e,t){let i=Object.getOwnPropertyDescriptor(r,"src");if(!i?.get||!i?.set)return()=>{};let n=i.set;return Object.defineProperty(r,"src",{configurable:!0,enumerable:i.enumerable,get:i.get,set(o){let s=this.getAttribute?.("data-name");if(s!=null&&t.consentChecker(s)===!0){n.call(this,o);return}let a=Me(o,t);if(a!==null){t.onBlock({mechanism:e,url:o,service:a});return}n.call(this,o)}}),()=>Object.defineProperty(r,"src",i)}function zr(r){if(typeof window.fetch!="function")return()=>{};let e=window.fetch.bind(window);return window.fetch=function(i,n){let o;typeof i=="string"?o=i:i instanceof URL?o=i.href:o=i.url;let s=Me(o,r);return s!==null?(r.onBlock({mechanism:"fetch",url:o,service:s}),Promise.reject(new TypeError(`SimpleCMP: consent for ${s} not granted`))):e(i,n)},()=>{window.fetch=e}}function Mr(r){let e=XMLHttpRequest.prototype.open,t=XMLHttpRequest.prototype.send,i="__simplecmpBlockedService";return XMLHttpRequest.prototype.open=function(o,s,...a){let c=this;c[i]!==void 0&&delete c[i];let l=typeof s=="string"?s:s.href,m=Me(l,r);m!==null&&(r.onBlock({mechanism:"xhr",url:l,service:m}),c[i]=m),e.call(this,o,s,...a)},XMLHttpRequest.prototype.send=function(o){this[i]===void 0&&t.call(this,o)},()=>{XMLHttpRequest.prototype.open=e,XMLHttpRequest.prototype.send=t}}function $r(r){if(typeof navigator.sendBeacon!="function")return()=>{};let e=navigator.sendBeacon.bind(navigator);return navigator.sendBeacon=function(i,n){let o=typeof i=="string"?i:i.href,s=Me(o,r);return s!==null?(r.onBlock({mechanism:"sendBeacon",url:o,service:s}),!1):e(i,n)},()=>{navigator.sendBeacon=e}}function Si(r,e={}){let t=r.filter(n=>Array.isArray(n.origins)&&n.origins.length>0).map(n=>({name:n.name,origins:n.origins})),i=e.blockAllUnknown===!0;return n=>{if(n==="")return null;for(let o of t)for(let s of o.origins)if(Ae(n,s))return o.name;return i?n:null}}var $e=globalThis,Pe=$e.ShadowRoot&&($e.ShadyCSS===void 0||$e.ShadyCSS.nativeShadow)&&"adoptedStyleSheets"in Document.prototype&&"replace"in CSSStyleSheet.prototype,tt=Symbol(),_i=new WeakMap,oe=class{constructor(e,t,i){if(this._$cssResult$=!0,i!==tt)throw Error("CSSResult is not constructable. Use `unsafeCSS` or `css` instead.");this.cssText=e,this.t=t}get styleSheet(){let e=this.o,t=this.t;if(Pe&&e===void 0){let i=t!==void 0&&t.length===1;i&&(e=_i.get(t)),e===void 0&&((this.o=e=new CSSStyleSheet).replaceSync(this.cssText),i&&_i.set(t,e))}return e}toString(){return this.cssText}},Ai=r=>new oe(typeof r=="string"?r:r+"",void 0,tt),y=(r,...e)=>{let t=r.length===1?r[0]:e.reduce((i,n,o)=>i+(s=>{if(s._$cssResult$===!0)return s.cssText;if(typeof s=="number")return s;throw Error("Value passed to 'css' function must be a 'css' function result: "+s+". Use 'unsafeCSS' to pass non-literal values, but take care to ensure page security.")})(n)+r[o+1],r[0]);return new oe(t,r,tt)},Ci=(r,e)=>{if(Pe)r.adoptedStyleSheets=e.map(t=>t instanceof CSSStyleSheet?t:t.styleSheet);else for(let t of e){let i=document.createElement("style"),n=$e.litNonce;n!==void 0&&i.setAttribute("nonce",n),i.textContent=t.cssText,r.appendChild(i)}},it=Pe?r=>r:r=>r instanceof CSSStyleSheet?(e=>{let t="";for(let i of e.cssRules)t+=i.cssText;return Ai(t)})(r):r;var{is:Pr,defineProperty:Dr,getOwnPropertyDescriptor:Tr,getOwnPropertyNames:Rr,getOwnPropertySymbols:Or,getPrototypeOf:jr}=Object,M=globalThis,xi=M.trustedTypes,Lr=xi?xi.emptyScript:"",Ir=M.reactiveElementPolyfillSupport,se=(r,e)=>r,ae={toAttribute(r,e){switch(e){case Boolean:r=r?Lr:null;break;case Object:case Array:r=r==null?r:JSON.stringify(r)}return r},fromAttribute(r,e){let t=r;switch(e){case Boolean:t=r!==null;break;case Number:t=r===null?null:Number(r);break;case Object:case Array:try{t=JSON.parse(r)}catch{t=null}}return t}},De=(r,e)=>!Pr(r,e),Ei={attribute:!0,type:String,converter:ae,reflect:!1,useDefault:!1,hasChanged:De};Symbol.metadata??(Symbol.metadata=Symbol("metadata")),M.litPropertyMetadata??(M.litPropertyMetadata=new WeakMap);var A=class extends HTMLElement{static addInitializer(e){this._$Ei(),(this.l??(this.l=[])).push(e)}static get observedAttributes(){return this.finalize(),this._$Eh&&[...this._$Eh.keys()]}static createProperty(e,t=Ei){if(t.state&&(t.attribute=!1),this._$Ei(),this.prototype.hasOwnProperty(e)&&((t=Object.create(t)).wrapped=!0),this.elementProperties.set(e,t),!t.noAccessor){let i=Symbol(),n=this.getPropertyDescriptor(e,i,t);n!==void 0&&Dr(this.prototype,e,n)}}static getPropertyDescriptor(e,t,i){let{get:n,set:o}=Tr(this.prototype,e)??{get(){return this[t]},set(s){this[t]=s}};return{get:n,set(s){let a=n?.call(this);o?.call(this,s),this.requestUpdate(e,a,i)},configurable:!0,enumerable:!0}}static getPropertyOptions(e){return this.elementProperties.get(e)??Ei}static _$Ei(){if(this.hasOwnProperty(se("elementProperties")))return;let e=jr(this);e.finalize(),e.l!==void 0&&(this.l=[...e.l]),this.elementProperties=new Map(e.elementProperties)}static finalize(){if(this.hasOwnProperty(se("finalized")))return;if(this.finalized=!0,this._$Ei(),this.hasOwnProperty(se("properties"))){let t=this.properties,i=[...Rr(t),...Or(t)];for(let n of i)this.createProperty(n,t[n])}let e=this[Symbol.metadata];if(e!==null){let t=litPropertyMetadata.get(e);if(t!==void 0)for(let[i,n]of t)this.elementProperties.set(i,n)}this._$Eh=new Map;for(let[t,i]of this.elementProperties){let n=this._$Eu(t,i);n!==void 0&&this._$Eh.set(n,t)}this.elementStyles=this.finalizeStyles(this.styles)}static finalizeStyles(e){let t=[];if(Array.isArray(e)){let i=new Set(e.flat(1/0).reverse());for(let n of i)t.unshift(it(n))}else e!==void 0&&t.push(it(e));return t}static _$Eu(e,t){let i=t.attribute;return i===!1?void 0:typeof i=="string"?i:typeof e=="string"?e.toLowerCase():void 0}constructor(){super(),this._$Ep=void 0,this.isUpdatePending=!1,this.hasUpdated=!1,this._$Em=null,this._$Ev()}_$Ev(){this._$ES=new Promise(e=>this.enableUpdating=e),this._$AL=new Map,this._$E_(),this.requestUpdate(),this.constructor.l?.forEach(e=>e(this))}addController(e){(this._$EO??(this._$EO=new Set)).add(e),this.renderRoot!==void 0&&this.isConnected&&e.hostConnected?.()}removeController(e){this._$EO?.delete(e)}_$E_(){let e=new Map,t=this.constructor.elementProperties;for(let i of t.keys())this.hasOwnProperty(i)&&(e.set(i,this[i]),delete this[i]);e.size>0&&(this._$Ep=e)}createRenderRoot(){let e=this.shadowRoot??this.attachShadow(this.constructor.shadowRootOptions);return Ci(e,this.constructor.elementStyles),e}connectedCallback(){this.renderRoot??(this.renderRoot=this.createRenderRoot()),this.enableUpdating(!0),this._$EO?.forEach(e=>e.hostConnected?.())}enableUpdating(e){}disconnectedCallback(){this._$EO?.forEach(e=>e.hostDisconnected?.())}attributeChangedCallback(e,t,i){this._$AK(e,i)}_$ET(e,t){let i=this.constructor.elementProperties.get(e),n=this.constructor._$Eu(e,i);if(n!==void 0&&i.reflect===!0){let o=(i.converter?.toAttribute!==void 0?i.converter:ae).toAttribute(t,i.type);this._$Em=e,o==null?this.removeAttribute(n):this.setAttribute(n,o),this._$Em=null}}_$AK(e,t){let i=this.constructor,n=i._$Eh.get(e);if(n!==void 0&&this._$Em!==n){let o=i.getPropertyOptions(n),s=typeof o.converter=="function"?{fromAttribute:o.converter}:o.converter?.fromAttribute!==void 0?o.converter:ae;this._$Em=n;let a=s.fromAttribute(t,o.type);this[n]=a??this._$Ej?.get(n)??a,this._$Em=null}}requestUpdate(e,t,i,n=!1,o){if(e!==void 0){let s=this.constructor;if(n===!1&&(o=this[e]),i??(i=s.getPropertyOptions(e)),!((i.hasChanged??De)(o,t)||i.useDefault&&i.reflect&&o===this._$Ej?.get(e)&&!this.hasAttribute(s._$Eu(e,i))))return;this.C(e,t,i)}this.isUpdatePending===!1&&(this._$ES=this._$EP())}C(e,t,{useDefault:i,reflect:n,wrapped:o},s){i&&!(this._$Ej??(this._$Ej=new Map)).has(e)&&(this._$Ej.set(e,s??t??this[e]),o!==!0||s!==void 0)||(this._$AL.has(e)||(this.hasUpdated||i||(t=void 0),this._$AL.set(e,t)),n===!0&&this._$Em!==e&&(this._$Eq??(this._$Eq=new Set)).add(e))}async _$EP(){this.isUpdatePending=!0;try{await this._$ES}catch(t){Promise.reject(t)}let e=this.scheduleUpdate();return e!=null&&await e,!this.isUpdatePending}scheduleUpdate(){return this.performUpdate()}performUpdate(){if(!this.isUpdatePending)return;if(!this.hasUpdated){if(this.renderRoot??(this.renderRoot=this.createRenderRoot()),this._$Ep){for(let[n,o]of this._$Ep)this[n]=o;this._$Ep=void 0}let i=this.constructor.elementProperties;if(i.size>0)for(let[n,o]of i){let{wrapped:s}=o,a=this[n];s!==!0||this._$AL.has(n)||a===void 0||this.C(n,void 0,o,a)}}let e=!1,t=this._$AL;try{e=this.shouldUpdate(t),e?(this.willUpdate(t),this._$EO?.forEach(i=>i.hostUpdate?.()),this.update(t)):this._$EM()}catch(i){throw e=!1,this._$EM(),i}e&&this._$AE(t)}willUpdate(e){}_$AE(e){this._$EO?.forEach(t=>t.hostUpdated?.()),this.hasUpdated||(this.hasUpdated=!0,this.firstUpdated(e)),this.updated(e)}_$EM(){this._$AL=new Map,this.isUpdatePending=!1}get updateComplete(){return this.getUpdateComplete()}getUpdateComplete(){return this._$ES}shouldUpdate(e){return!0}update(e){this._$Eq&&(this._$Eq=this._$Eq.forEach(t=>this._$ET(t,this[t]))),this._$EM()}updated(e){}firstUpdated(e){}};A.elementStyles=[],A.shadowRootOptions={mode:"open"},A[se("elementProperties")]=new Map,A[se("finalized")]=new Map,Ir?.({ReactiveElement:A}),(M.reactiveElementVersions??(M.reactiveElementVersions=[])).push("2.1.2");var le=globalThis,zi=r=>r,Te=le.trustedTypes,Mi=Te?Te.createPolicy("lit-html",{createHTML:r=>r}):void 0,Oi="$lit$",$=`lit$${Math.random().toFixed(9).slice(2)}$`,ji="?"+$,Nr=`<${ji}>`,U=document,pe=()=>U.createComment(""),de=r=>r===null||typeof r!="object"&&typeof r!="function",lt=Array.isArray,Ur=r=>lt(r)||typeof r?.[Symbol.iterator]=="function",nt=`[ 	
\f\r]`,ce=/<(?:(!--|\/[^a-zA-Z])|(\/?[a-zA-Z][^>\s]*)|(\/?$))/g,$i=/-->/g,Pi=/>/g,I=RegExp(`>|${nt}(?:([^\\s"'>=/]+)(${nt}*=${nt}*(?:[^ 	
\f\r"'\`<>=]|("|')|))|$)`,"g"),Di=/'/g,Ti=/"/g,Li=/^(?:script|style|textarea|title)$/i,pt=r=>(e,...t)=>({_$litType$:r,strings:e,values:t}),u=pt(1),za=pt(2),Ma=pt(3),C=Symbol.for("lit-noChange"),p=Symbol.for("lit-nothing"),Ri=new WeakMap,N=U.createTreeWalker(U,129);function Ii(r,e){if(!lt(r)||!r.hasOwnProperty("raw"))throw Error("invalid template strings array");return Mi!==void 0?Mi.createHTML(e):e}var Br=(r,e)=>{let t=r.length-1,i=[],n,o=e===2?"<svg>":e===3?"<math>":"",s=ce;for(let a=0;a<t;a++){let c=r[a],l,m,d=-1,f=0;for(;f<c.length&&(s.lastIndex=f,m=s.exec(c),m!==null);)f=s.lastIndex,s===ce?m[1]==="!--"?s=$i:m[1]!==void 0?s=Pi:m[2]!==void 0?(Li.test(m[2])&&(n=RegExp("</"+m[2],"g")),s=I):m[3]!==void 0&&(s=I):s===I?m[0]===">"?(s=n??ce,d=-1):m[1]===void 0?d=-2:(d=s.lastIndex-m[2].length,l=m[1],s=m[3]===void 0?I:m[3]==='"'?Ti:Di):s===Ti||s===Di?s=I:s===$i||s===Pi?s=ce:(s=I,n=void 0);let g=s===I&&r[a+1].startsWith("/>")?" ":"";o+=s===ce?c+Nr:d>=0?(i.push(l),c.slice(0,d)+Oi+c.slice(d)+$+g):c+$+(d===-2?a:g)}return[Ii(r,o+(r[t]||"<?>")+(e===2?"</svg>":e===3?"</math>":"")),i]},ue=class r{constructor({strings:e,_$litType$:t},i){let n;this.parts=[];let o=0,s=0,a=e.length-1,c=this.parts,[l,m]=Br(e,t);if(this.el=r.createElement(l,i),N.currentNode=this.el.content,t===2||t===3){let d=this.el.content.firstChild;d.replaceWith(...d.childNodes)}for(;(n=N.nextNode())!==null&&c.length<a;){if(n.nodeType===1){if(n.hasAttributes())for(let d of n.getAttributeNames())if(d.endsWith(Oi)){let f=m[s++],g=n.getAttribute(d).split($),_=/([.?@])?(.*)/.exec(f);c.push({type:1,index:o,name:_[2],strings:g,ctor:_[1]==="."?ot:_[1]==="?"?st:_[1]==="@"?at:Z}),n.removeAttribute(d)}else d.startsWith($)&&(c.push({type:6,index:o}),n.removeAttribute(d));if(Li.test(n.tagName)){let d=n.textContent.split($),f=d.length-1;if(f>0){n.textContent=Te?Te.emptyScript:"";for(let g=0;g<f;g++)n.append(d[g],pe()),N.nextNode(),c.push({type:2,index:++o});n.append(d[f],pe())}}}else if(n.nodeType===8)if(n.data===ji)c.push({type:2,index:o});else{let d=-1;for(;(d=n.data.indexOf($,d+1))!==-1;)c.push({type:7,index:o}),d+=$.length-1}o++}}static createElement(e,t){let i=U.createElement("template");return i.innerHTML=e,i}};function Q(r,e,t=r,i){if(e===C)return e;let n=i!==void 0?t._$Co?.[i]:t._$Cl,o=de(e)?void 0:e._$litDirective$;return n?.constructor!==o&&(n?._$AO?.(!1),o===void 0?n=void 0:(n=new o(r),n._$AT(r,t,i)),i!==void 0?(t._$Co??(t._$Co=[]))[i]=n:t._$Cl=n),n!==void 0&&(e=Q(r,n._$AS(r,e.values),n,i)),e}var rt=class{constructor(e,t){this._$AV=[],this._$AN=void 0,this._$AD=e,this._$AM=t}get parentNode(){return this._$AM.parentNode}get _$AU(){return this._$AM._$AU}u(e){let{el:{content:t},parts:i}=this._$AD,n=(e?.creationScope??U).importNode(t,!0);N.currentNode=n;let o=N.nextNode(),s=0,a=0,c=i[0];for(;c!==void 0;){if(s===c.index){let l;c.type===2?l=new me(o,o.nextSibling,this,e):c.type===1?l=new c.ctor(o,c.name,c.strings,this,e):c.type===6&&(l=new ct(o,this,e)),this._$AV.push(l),c=i[++a]}s!==c?.index&&(o=N.nextNode(),s++)}return N.currentNode=U,n}p(e){let t=0;for(let i of this._$AV)i!==void 0&&(i.strings!==void 0?(i._$AI(e,i,t),t+=i.strings.length-2):i._$AI(e[t])),t++}},me=class r{get _$AU(){return this._$AM?._$AU??this._$Cv}constructor(e,t,i,n){this.type=2,this._$AH=p,this._$AN=void 0,this._$AA=e,this._$AB=t,this._$AM=i,this.options=n,this._$Cv=n?.isConnected??!0}get parentNode(){let e=this._$AA.parentNode,t=this._$AM;return t!==void 0&&e?.nodeType===11&&(e=t.parentNode),e}get startNode(){return this._$AA}get endNode(){return this._$AB}_$AI(e,t=this){e=Q(this,e,t),de(e)?e===p||e==null||e===""?(this._$AH!==p&&this._$AR(),this._$AH=p):e!==this._$AH&&e!==C&&this._(e):e._$litType$!==void 0?this.$(e):e.nodeType!==void 0?this.T(e):Ur(e)?this.k(e):this._(e)}O(e){return this._$AA.parentNode.insertBefore(e,this._$AB)}T(e){this._$AH!==e&&(this._$AR(),this._$AH=this.O(e))}_(e){this._$AH!==p&&de(this._$AH)?this._$AA.nextSibling.data=e:this.T(U.createTextNode(e)),this._$AH=e}$(e){let{values:t,_$litType$:i}=e,n=typeof i=="number"?this._$AC(e):(i.el===void 0&&(i.el=ue.createElement(Ii(i.h,i.h[0]),this.options)),i);if(this._$AH?._$AD===n)this._$AH.p(t);else{let o=new rt(n,this),s=o.u(this.options);o.p(t),this.T(s),this._$AH=o}}_$AC(e){let t=Ri.get(e.strings);return t===void 0&&Ri.set(e.strings,t=new ue(e)),t}k(e){lt(this._$AH)||(this._$AH=[],this._$AR());let t=this._$AH,i,n=0;for(let o of e)n===t.length?t.push(i=new r(this.O(pe()),this.O(pe()),this,this.options)):i=t[n],i._$AI(o),n++;n<t.length&&(this._$AR(i&&i._$AB.nextSibling,n),t.length=n)}_$AR(e=this._$AA.nextSibling,t){for(this._$AP?.(!1,!0,t);e!==this._$AB;){let i=zi(e).nextSibling;zi(e).remove(),e=i}}setConnected(e){this._$AM===void 0&&(this._$Cv=e,this._$AP?.(e))}},Z=class{get tagName(){return this.element.tagName}get _$AU(){return this._$AM._$AU}constructor(e,t,i,n,o){this.type=1,this._$AH=p,this._$AN=void 0,this.element=e,this.name=t,this._$AM=n,this.options=o,i.length>2||i[0]!==""||i[1]!==""?(this._$AH=Array(i.length-1).fill(new String),this.strings=i):this._$AH=p}_$AI(e,t=this,i,n){let o=this.strings,s=!1;if(o===void 0)e=Q(this,e,t,0),s=!de(e)||e!==this._$AH&&e!==C,s&&(this._$AH=e);else{let a=e,c,l;for(e=o[0],c=0;c<o.length-1;c++)l=Q(this,a[i+c],t,c),l===C&&(l=this._$AH[c]),s||(s=!de(l)||l!==this._$AH[c]),l===p?e=p:e!==p&&(e+=(l??"")+o[c+1]),this._$AH[c]=l}s&&!n&&this.j(e)}j(e){e===p?this.element.removeAttribute(this.name):this.element.setAttribute(this.name,e??"")}},ot=class extends Z{constructor(){super(...arguments),this.type=3}j(e){this.element[this.name]=e===p?void 0:e}},st=class extends Z{constructor(){super(...arguments),this.type=4}j(e){this.element.toggleAttribute(this.name,!!e&&e!==p)}},at=class extends Z{constructor(e,t,i,n,o){super(e,t,i,n,o),this.type=5}_$AI(e,t=this){if((e=Q(this,e,t,0)??p)===C)return;let i=this._$AH,n=e===p&&i!==p||e.capture!==i.capture||e.once!==i.once||e.passive!==i.passive,o=e!==p&&(i===p||n);n&&this.element.removeEventListener(this.name,this,i),o&&this.element.addEventListener(this.name,this,e),this._$AH=e}handleEvent(e){typeof this._$AH=="function"?this._$AH.call(this.options?.host??this.element,e):this._$AH.handleEvent(e)}},ct=class{constructor(e,t,i){this.element=e,this.type=6,this._$AN=void 0,this._$AM=t,this.options=i}get _$AU(){return this._$AM._$AU}_$AI(e){Q(this,e)}};var qr=le.litHtmlPolyfillSupport;qr?.(ue,me),(le.litHtmlVersions??(le.litHtmlVersions=[])).push("3.3.2");var Ni=(r,e,t)=>{let i=t?.renderBefore??e,n=i._$litPart$;if(n===void 0){let o=t?.renderBefore??null;i._$litPart$=n=new me(e.insertBefore(pe(),o),o,void 0,t??{})}return n._$AI(r),n};var fe=globalThis,P=class extends A{constructor(){super(...arguments),this.renderOptions={host:this},this._$Do=void 0}createRenderRoot(){var t;let e=super.createRenderRoot();return(t=this.renderOptions).renderBefore??(t.renderBefore=e.firstChild),e}update(e){let t=this.render();this.hasUpdated||(this.renderOptions.isConnected=this.isConnected),super.update(e),this._$Do=Ni(t,this.renderRoot,this.renderOptions)}connectedCallback(){super.connectedCallback(),this._$Do?.setConnected(!0)}disconnectedCallback(){super.disconnectedCallback(),this._$Do?.setConnected(!1)}render(){return C}};P._$litElement$=!0,P.finalized=!0,fe.litElementHydrateSupport?.({LitElement:P});var Hr=fe.litElementPolyfillSupport;Hr?.({LitElement:P});(fe.litElementVersions??(fe.litElementVersions=[])).push("4.2.2");var k=r=>(e,t)=>{t!==void 0?t.addInitializer(()=>{customElements.define(r,e)}):customElements.define(r,e)};var Fr={attribute:!0,type:String,converter:ae,reflect:!1,hasChanged:De},Vr=(r=Fr,e,t)=>{let{kind:i,metadata:n}=t,o=globalThis.litPropertyMetadata.get(n);if(o===void 0&&globalThis.litPropertyMetadata.set(n,o=new Map),i==="setter"&&((r=Object.create(r)).wrapped=!0),o.set(t.name,r),i==="accessor"){let{name:s}=t;return{set(a){let c=e.get.call(this);e.set.call(this,a),this.requestUpdate(s,c,r,!0,a)},init(a){return a!==void 0&&this.C(s,void 0,r,a),a}}}if(i==="setter"){let{name:s}=t;return function(a){let c=this[s];e.call(this,a),this.requestUpdate(s,c,r,!0,a)}}throw Error("Unsupported decorator location: "+i)};function v(r){return(e,t)=>typeof t=="object"?Vr(r,e,t):((i,n,o)=>{let s=n.hasOwnProperty(o);return n.constructor.createProperty(o,i),s?Object.getOwnPropertyDescriptor(n,o):void 0})(r,e,t)}function Re(r){return v({...r,state:!0,attribute:!1})}var B=(r,e,t)=>(t.configurable=!0,t.enumerable=!0,Reflect.decorate&&typeof e!="object"&&Object.defineProperty(r,e,t),t);function Oe(r,e){return(t,i,n)=>{let o=s=>s.renderRoot?.querySelector(r)??null;if(e){let{get:s,set:a}=typeof i=="object"?t:n??(()=>{let c=Symbol();return{get(){return this[c]},set(l){this[c]=l}}})();return B(t,i,{get(){let c=s.call(this);return c===void 0&&(c=o(this),(c!==null||this.hasUpdated)&&a.call(this,c)),c}})}return B(t,i,{get(){return o(this)}})}}var dt=r=>r??p;var Ui={ATTRIBUTE:1,CHILD:2,PROPERTY:3,BOOLEAN_ATTRIBUTE:4,EVENT:5,ELEMENT:6},Bi=r=>(...e)=>({_$litDirective$:r,values:e}),je=class{constructor(e){}get _$AU(){return this._$AM._$AU}_$AT(e,t,i){this._$Ct=e,this._$AM=t,this._$Ci=i}_$AS(e,t){return this.update(e,t)}update(e,t){return this.render(...t)}};var he=class extends je{constructor(e){if(super(e),this.it=p,e.type!==Ui.CHILD)throw Error(this.constructor.directiveName+"() can only be used in child bindings")}render(e){if(e===p||e==null)return this._t=void 0,this.it=e;if(e===C)return e;if(typeof e!="string")throw Error(this.constructor.directiveName+"() called with a non-string value");if(e===this.it)return this._t;this.it=e;let t=[e];return t.raw=t,this._t={_$litType$:this.constructor.resultType,strings:t,values:[]}}};he.directiveName="unsafeHTML",he.resultType=1;var J=Bi(he);function x(r){return r.split("-").map(e=>e.slice(0,1).toUpperCase()+e.slice(1)).join(" ")}function qi(r){let e=Ut(r),t=Ge(r),i=r.fallbackLang??"zz";return(n,...o)=>Lt(e,t,i,n,...o)}function Wr(r){return{update:()=>{r.requestUpdate()}}}var b=class extends P{_t(e,...t){if(this._translator===void 0){if(this.config===void 0)return e;this._translator=qi(this.config)}return this._translator(e,...t)}createRenderRoot(){return this.getAttribute("mode")==="light"?this:super.createRenderRoot()}_detachWatcher(){this._watcher!==void 0&&this._watcherManager!==void 0&&this._watcherManager.unwatch(this._watcher),this._watcher=void 0,this._watcherManager=void 0}_syncWatcher(){this._watcherManager!==this.manager&&(this._detachWatcher(),this.manager!==void 0&&(this._watcher=Wr(this),this._watcherManager=this.manager,this.manager.watch(this._watcher)))}connectedCallback(){super.connectedCallback(),this._syncWatcher()}disconnectedCallback(){this._detachWatcher(),super.disconnectedCallback()}willUpdate(e){super.willUpdate(e),e.has("config")&&(this._translator=void 0),e.has("manager")&&this._syncWatcher()}_emit(e,t){this.dispatchEvent(new CustomEvent(`simplecmp:${e}`,{detail:t,bubbles:!0,composed:!0}))}};h([v({attribute:!1})],b.prototype,"config",2),h([v({attribute:!1})],b.prototype,"manager",2);var Kr=y`
  :host {
    --simplecmp-color-primary: #15775a;
    --simplecmp-color-primary-hover: #0f5d44;
    --simplecmp-color-secondary: #6c757d;
    --simplecmp-color-danger: #da2c43;
    --simplecmp-color-bg: #ffffff;
    --simplecmp-color-bg-alt: #f5f7f9;
    --simplecmp-color-border: #dde2e7;
    --simplecmp-color-text: #1a232c;
    --simplecmp-color-text-muted: #5f6b78;

    --simplecmp-radius: 6px;
    --simplecmp-spacing: 0.75rem;
    --simplecmp-spacing-sm: 0.5rem;
    --simplecmp-spacing-lg: 1.25rem;

    --simplecmp-font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    --simplecmp-font-family-heading: var(--simplecmp-font-family);
    --simplecmp-font-size: 0.95rem;
    --simplecmp-font-size-heading: 20px;
    --simplecmp-font-size-sm: 0.85rem;
    --simplecmp-line-height: 1.5;

    --simplecmp-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    --simplecmp-z-index: 2147483000;
  }

  @media (prefers-reduced-motion: reduce) {
    :host {
      --simplecmp-transition: none;
    }
  }
`,ge=y`
  :host {
    color: var(--simplecmp-color-text, #1a232c);
    font-family: var(--simplecmp-font-family, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif);
    font-size: var(--simplecmp-font-size, 0.95rem);
    line-height: var(--simplecmp-line-height, 1.5);
  }
`,w=y`
  ${Kr}
  ${ge}
`;function Gr(r,e){if(r!==void 0){if(typeof r=="string")return r;if(typeof r=="object")return r[e]??r.default}}var D=class extends b{constructor(){super(...arguments);this.testing=!1;this._previouslyFocused=null;this._handleAccept=()=>{this.manager!==void 0&&(this.manager.changeAll(!0),this.manager.saveAndApplyConsents("accept"),this._emit("accept"))};this._handleDecline=()=>{this.manager!==void 0&&(this.manager.changeAll(!1),this.manager.saveAndApplyConsents("decline"),this._emit("decline"))};this._handleConfigure=t=>{t.preventDefault(),this._emit("configure")}}connectedCallback(){super.connectedCallback(),this.config?.autoFocus===!0&&(this._previouslyFocused=typeof document<"u"?document.activeElement:null,queueMicrotask(()=>{this.renderRoot?.querySelector(".cn-body")?.focus()}))}disconnectedCallback(){super.disconnectedCallback();let t=this._previouslyFocused;this._previouslyFocused=null,t&&typeof t.focus=="function"&&t.isConnected&&t.focus()}render(){let t=this.config,i=this.manager;if(t===void 0||i===void 0)return p;if(!this.testing&&i.confirmed)return p;if(t.noNotice===!0)return p;let n=i.bannerMode==="notice",o=this._activeLang(),s=this._resolvePolicyUrl(t.privacyPolicy,["privacyPolicyUrl"],o),a=this._resolvePolicyUrl(t.imprint,["imprintUrl"],o),c=this._t(["!","consentNotice","title"]),l=t.showNoticeTitle===!0&&c!==void 0,m=t.htmlTexts===!0,d=s?u`<a href=${s}>${this._t(["privacyPolicy","name"])}</a>`:"",f=a?u`<a href=${a}>${this._imprintLinkText()}</a>`:"",g=u`<a
      href="#"
      @click=${this._handleConfigure}
      >${this._t(["consentNotice","learnMore"])}</a
    >`,_=this._t(n?["consentNotice","optOutDescription"]:["consentNotice","description"],{purposes:u`<strong>${this._purposesText(t)}</strong>`,privacyPolicy:d,imprint:f,learnMoreLink:g});return u`
      <div
        class="cn-body"
        role="region"
        aria-live="polite"
        aria-labelledby=${dt(l?"cn-title":void 0)}
        aria-label=${dt(l?void 0:c)}
        aria-describedby="cn-description"
        tabindex="-1"
      >
        ${l?u`<h2 id="cn-title">${c}</h2>`:p}
        <p id="cn-description">${m?Qr(_):_}</p>
        ${this._renderPolicyLinks(s,a)}
        ${i.changed?u`<p class="cn-changes">${this._t(["consentNotice","changeDescription"])}</p>`:p}
        ${this.testing?u`<p>${this._t(["consentNotice","testing"])}</p>`:p}
        ${n?this._renderNoticeButtons(t):this._renderButtonRow(t)}
      </div>
    `}_renderNoticeButtons(t){let i=this._resolveLayout(t),n=t.hideLearnMore!==!0;return u`<div class="cn-buttons cn-layout-${i}">
      ${n?u`<button
            type="button"
            class="cn-configure"
            @click=${this._handleConfigure}
          >
            ${this._t(["consentNotice","learnMore"])}
          </button>`:p}
      <button type="button" class="cn-decline" @click=${this._handleDecline}>
        ${this._t(["doNotSell"])}
      </button>
      <button type="button" class="cn-accept" @click=${this._handleAccept}>
        ${this._t(["ok"])}
      </button>
    </div>`}_renderButtonRow(t){let i=this._resolveLayout(t),n=i!=="compact"&&t.hideLearnMore!==!0,o=t.hideDeclineAll!==!0;return u`<div class="cn-buttons cn-layout-${i}">
      ${n?u`<button
            type="button"
            class="cn-configure"
            @click=${this._handleConfigure}
          >
            ${this._t(["consentNotice","learnMore"])}
          </button>`:p}
      ${o?u`<button
            type="button"
            class="cn-decline"
            @click=${this._handleDecline}
          >
            ${this._t(["decline"])}
          </button>`:p}
      <button type="button" class="cn-accept" @click=${this._handleAccept}>
        ${this._t(["ok"])}
      </button>
    </div>`}_resolveLayout(t){let i=(t.layout??"standard").toString().toLowerCase();return i==="compact"||i==="stacked"?i:"standard"}_activeLang(){return this.config?.lang??document.documentElement.lang??"en"}_purposesText(t){let i=t.purposeOrder??[],o=Ot(t).filter(c=>c!=="functional").sort((c,l)=>i.indexOf(c)-i.indexOf(l)).map(c=>this._tString(["!","purposes",c,"title?"])||x(c));if(o.length<=1)return o[0]??"";let s=o.slice(0,-2),a=o.slice(-2).join(" & ");return[...s,a].join(", ")}_resolvePolicyUrl(t,i,n){let o=Gr(t,n);if(o!==void 0)return o;let s=this._tString(["!",...i]);return s===""?void 0:s}_imprintLinkText(){return this._tString(["!","consentNotice","imprint","name"])||this._tString(["!","imprint","name"])||"Imprint"}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}_renderPolicyLinks(t,i){return t===void 0&&i===void 0?p:u`
      <p class="cn-policy-links">
        ${t?u`<a href=${t}>${this._t(["privacyPolicy","name"])}</a>`:p}
        ${t&&i?" \xB7 ":p}
        ${i?u`<a href=${i}>${this._imprintLinkText()}</a>`:p}
      </p>
    `}};D.styles=[w,y`
      :host {
        display: block;
        position: fixed;
        /* Banner placement — overridable via three tokens, all set
           together by the integrator (t3-simplecmp ships a 3x3
           picker). Default mirrors the original hard-coded
           bottom-right corner. */
        inset: var(
          --simplecmp-banner-inset,
          auto var(--simplecmp-spacing) var(--simplecmp-spacing) auto
        );
        transform: var(--simplecmp-banner-transform, none);
        max-width: var(--simplecmp-banner-max-width, 30rem);
        z-index: var(--simplecmp-z-index);
      }

      :host([hidden]) {
        display: none;
      }

      .cn-body {
        background: var(--simplecmp-color-bg);
        color: var(--simplecmp-color-text);
        border: 1px solid var(--simplecmp-color-border);
        border-radius: var(--simplecmp-radius);
        box-shadow: var(--simplecmp-shadow);
        padding: var(--simplecmp-spacing-lg);
      }

      h2 {
        margin: 0 0 var(--simplecmp-spacing) 0;
        font-family: var(--simplecmp-font-family-heading);
        font-size: var(--simplecmp-font-size-heading);
      }

      p {
        margin: 0 0 var(--simplecmp-spacing) 0;
      }

      .cn-policy-links {
        font-size: var(--simplecmp-font-size-sm);
        color: var(--simplecmp-color-text-muted);
      }

      .cn-policy-links a {
        color: var(--simplecmp-color-text-muted);
      }

      /* Layout-independent button row defaults. Per-layout overrides
         live in the .cn-layout-* selectors below.

         Compliance baseline (legal-compliance.md §1.2 + §2.3): all
         three buttons share identical styling. Visual hierarchy is
         carried by label + position, never by color/size/weight.
         The previous Accept-filled-primary vs. Decline-ghost-outline
         treatment was a Stirring dark pattern; this rewrite levels
         the visual playing field. */
      .cn-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: var(--simplecmp-spacing-sm);
        margin-top: var(--simplecmp-spacing);
      }

      button {
        font: inherit;
        font-weight: 500;
        border: 1px solid var(--simplecmp-color-border);
        border-radius: var(--simplecmp-radius);
        padding: var(--simplecmp-spacing-sm) var(--simplecmp-spacing);
        cursor: pointer;
        background: var(--simplecmp-color-bg-alt);
        color: var(--simplecmp-color-text);
        line-height: var(--simplecmp-line-height);
        /* WCAG 2.5.8 (AA) — minimum 24×24 target. */
        min-block-size: 24px;
        min-inline-size: 24px;
      }

      button:hover {
        background: var(--simplecmp-color-border);
      }

      button:focus-visible {
        outline: 2px solid var(--simplecmp-color-primary);
        outline-offset: 2px;
      }

      /* Standard layout — horizontal flex row with Configure-Decline-
         Accept in source order. Wraps on narrow viewports. */
      .cn-layout-standard {
        /* Inherits .cn-buttons defaults — explicit name retained for
           future per-layout tweaks without bloating the base rule. */
      }

      /* Compact layout — Decline | Accept only, no Configure. The
         component skips the Configure button in render() when
         layout === 'compact'; this rule just exists so a
         downstream theme can target compact-mode if needed. */
      .cn-layout-compact {
        /* same as standard for now */
      }

      /* Stacked layout — vertical column, each button full-width.
         Optimised for narrow viewports and assistive tech where
         buttons-of-equal-styling on a row are hard to scan. */
      .cn-layout-stacked {
        flex-direction: column;
        align-items: stretch;
      }

      .cn-layout-stacked button {
        width: 100%;
      }

      a {
        color: var(--simplecmp-color-primary);
      }

      .cn-changes {
        font-size: var(--simplecmp-font-size-sm);
        font-style: italic;
        color: var(--simplecmp-color-text-muted);
      }
    `],h([v({type:Boolean})],D.prototype,"testing",2),D=h([k("simplecmp-banner")],D);function Qr(r){return typeof r=="string"?J(r):Array.isArray(r)?r.map(e=>typeof e=="string"?J(e):e):r}function ve(r){let e;try{e=new URL(r,document.baseURI)}catch{return!1}return e.protocol==="http:"||e.protocol==="https:"}var T=class extends b{constructor(){super(...arguments);this.open=!1;this._onCancel=()=>{};this._onClose=()=>{this.open=!1,this._emit("provider-info-close")};this._onCloseClick=()=>{this.open=!1,this._emit("provider-info-close")};this._onBackdropClick=t=>{t.target===this._dialog&&(this.open=!1,this._emit("provider-info-close"))}}updated(t){if(super.updated(t),t.has("open")){let i=this._dialog;if(i===void 0)return;this.open&&!i.open?i.showModal():!this.open&&i.open&&i.close()}}render(){let t=this.service;if(t===void 0)return p;let i=this._tString(["providerInfo","close"])||"Close";return u`
      <dialog
        aria-labelledby="simplecmp-provider-info-title"
        @cancel=${this._onCancel}
        @close=${this._onClose}
        @click=${this._onBackdropClick}
      >
        <header>
          <h2 id="simplecmp-provider-info-title">
            ${this._tString(["providerInfo","title"])||"Provider information"}
          </h2>
          <button type="button" class="close" @click=${this._onCloseClick} aria-label=${i}>
            ×
          </button>
        </header>
        <div class="body">${this._renderBody(t)}</div>
        <footer>
          <button type="button" @click=${this._onCloseClick}>${i}</button>
        </footer>
      </dialog>
    `}_renderBody(t){let i=[],n=(o,s,a=!1)=>{if(s===void 0||s==="")return;let c=this._tString(["providerInfo","field",o])||o,l=a&&ve(s)?u`<a href=${s} target="_blank" rel="noopener noreferrer">${s}</a>`:u`${s}`;i.push(u`<dt>${c}</dt><dd>${l}</dd>`)};return n("vendor",t.vendor),n("description",t.vendorDescription),n("address",t.vendorAddress),n("country",t.vendorCountry),n("privacyPolicy",t.privacyPolicyUrl,!0),n("optOut",t.vendorOptOutUrl,!0),n("partner",t.vendorPartner),i.length===0?u`<p class="empty">
        ${this._tString(["providerInfo","noData"])||"No provider information available."}
      </p>`:u`<dl>${i}</dl>`}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}};T.styles=[w,y`
      :host {
        display: contents;
      }

      dialog {
        max-width: 36rem;
        width: 90%;
        border: 1px solid var(--simplecmp-color-border);
        border-radius: var(--simplecmp-radius);
        padding: 0;
        background: var(--simplecmp-color-bg);
        color: var(--simplecmp-color-text);
        font-family: var(--simplecmp-font-family);
        font-size: var(--simplecmp-font-size);
      }

      dialog::backdrop {
        background: rgba(0, 0, 0, 0.4);
      }

      header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: var(--simplecmp-spacing-lg);
        border-bottom: 1px solid var(--simplecmp-color-border);
      }

      header h2 {
        font-size: var(--simplecmp-font-size-heading, 1.25rem);
        font-family: var(--simplecmp-font-family-heading, var(--simplecmp-font-family));
        margin: 0;
      }

      button.close {
        font: inherit;
        background: transparent;
        border: none;
        color: var(--simplecmp-color-text-muted);
        cursor: pointer;
        font-size: 1.5rem;
        line-height: 1;
        padding: 0 var(--simplecmp-spacing-sm);
      }

      button.close:hover {
        color: var(--simplecmp-color-text);
      }

      .body {
        padding: var(--simplecmp-spacing-lg);
      }

      dl {
        margin: 0;
        display: grid;
        grid-template-columns: max-content 1fr;
        gap: var(--simplecmp-spacing-sm) var(--simplecmp-spacing);
      }

      dt {
        font-weight: 600;
        color: var(--simplecmp-color-text-muted);
        white-space: nowrap;
      }

      dd {
        margin: 0;
        word-break: break-word;
      }

      dd a {
        color: var(--simplecmp-color-primary);
        text-decoration: underline;
      }

      dd a:hover {
        color: var(--simplecmp-color-primary-hover);
      }

      .empty {
        font-style: italic;
        color: var(--simplecmp-color-text-muted);
      }

      footer {
        display: flex;
        justify-content: flex-end;
        padding: var(--simplecmp-spacing) var(--simplecmp-spacing-lg);
        border-top: 1px solid var(--simplecmp-color-border);
      }

      footer button {
        font: inherit;
        border: 1px solid transparent;
        border-radius: var(--simplecmp-radius);
        padding: var(--simplecmp-spacing-sm) var(--simplecmp-spacing);
        cursor: pointer;
        background: var(--simplecmp-color-primary);
        color: white;
      }

      footer button:hover {
        background: var(--simplecmp-color-primary-hover);
      }
    `],h([v({attribute:!1})],T.prototype,"service",2),h([v({type:Boolean,reflect:!0})],T.prototype,"open",2),h([Oe("dialog")],T.prototype,"_dialog",2),T=h([k("simplecmp-provider-info-modal")],T);var R=class extends b{constructor(){super(...arguments);this._autoPlaceholder=!1;this._providerInfoOpen=!1;this._onAcceptOnce=()=>{let t=this._resolveService();t===void 0||this.manager===void 0||(this.manager.updateConsent(t.name,!0),this.manager.applyConsents(!1,!0,t.name),this.manager.updateConsent(t.name,!1),this._emit("contextual-accept-once",{name:t.name}))};this._onAccept=()=>{let t=this._resolveService();t===void 0||this.manager===void 0||(this.manager.updateConsent(t.name,!0),this.manager.confirmed?(this.manager.saveConsents("contextual-accept"),this.manager.applyConsents(!1,!0,t.name)):this.manager.applyConsents(!1,!0,t.name),this._emit("contextual-accept",{name:t.name}))};this._onConfigure=t=>{t.preventDefault(),this._emit("configure")};this._onProviderInfoOpen=t=>{t.preventDefault(),this._providerInfoOpen=!0};this._onProviderInfoClose=()=>{this._providerInfoOpen=!1}}connectedCallback(){super.connectedCallback(),this._autoPlaceholder=this.hasAttribute("data-simplecmp-auto-placeholder"),this.hasAttribute("role")||this.setAttribute("role","region")}_resolveService(){if(this.service!==void 0)return this.service;if(this.serviceName!==void 0){if(this.config!==void 0){let t=this.config.services.find(i=>i.name===this.serviceName);if(t!==void 0)return t}return{name:this.serviceName,purposes:[]}}}_renderMode(){let t=this.service?.name??this.serviceName;return t===void 0||this.config?.services.some(n=>n.name===t)===!0?"configured":this.getAttribute("data-blocked-source")==="host"?"host":"library"}render(){let t=this._resolveService();if(t===void 0||this.manager===void 0)return p;let i=this._renderMode(),n=i==="host"?t.name:this._resolveTitle(t),o=i==="host"?this._t(["contextualConsent","descriptionUnknownHost"],{title:n}):this._resolveDescription(t,n);if(i==="host")return u`<p>${o}</p>`;let s=this.manager.store.get()!==null,a=i==="configured"&&s,c=i==="configured";return u`
      <p>${o}</p>
      ${this._renderPurposes(t)}
      ${this._renderProviderInfoLink(t)}
      <div class="buttons">
        <button type="button" class="accept-once" @click=${this._onAcceptOnce}>
          ${this._t(["contextualConsent","acceptOnce"])}
        </button>
        ${a?u`<button type="button" class="accept" @click=${this._onAccept}>
              ${this._t(["contextualConsent","acceptAlways"])}
            </button>`:p}
        ${c?u`<button type="button" class="configure" @click=${this._onConfigure}>
              ${this._t(["contextualConsent","modalLinkText"])}
            </button>`:p}
      </div>
      ${this._renderProviderInfoModal(t)}
    `}_renderProviderInfoLink(t){return this._hasProviderData(t)?u`
      <p class="provider-info-link">
        <a href="#" @click=${this._onProviderInfoOpen}>
          ${this._tString(["contextualConsent","providerInfoLink"])||"More information \u203A"}
        </a>
      </p>
    `:p}_renderProviderInfoModal(t){return this._providerInfoOpen?u`
      <simplecmp-provider-info-modal
        .service=${this._resolveProviderService(t)}
        .config=${this.config}
        .manager=${this.manager}
        ?open=${this._providerInfoOpen}
        @simplecmp:provider-info-close=${this._onProviderInfoClose}
      ></simplecmp-provider-info-modal>
    `:p}_resolveProviderService(t){let i=this.config?.libraryFallback?.[t.name];return i===void 0?t:{...t,vendor:t.vendor??i.vendor,vendorCountry:t.vendorCountry??i.vendorCountry,vendorAddress:t.vendorAddress??i.vendorAddress,vendorOptOutUrl:t.vendorOptOutUrl??i.vendorOptOutUrl,vendorPartner:t.vendorPartner??i.vendorPartner,vendorDescription:t.vendorDescription??i.vendorDescription,privacyPolicyUrl:t.privacyPolicyUrl??i.privacyPolicyUrl}}_hasProviderData(t){let i=this._resolveProviderService(t);return!!(i.vendor||i.vendorCountry||i.vendorAddress||i.vendorOptOutUrl||i.vendorPartner||i.vendorDescription||i.privacyPolicyUrl)}_renderPurposes(t){let i=t.purposes??[];if(i.length===0&&this.config!==void 0){let a=this.config.libraryFallback?.[t.name];a?.purposes!==void 0&&(i=a.purposes)}if(i.length===0)return p;let n=i.map(s=>this._tString(["purposes",s,"title"])).filter(s=>s.length>0);if(n.length===0)return p;let o=this._tString(["service","purposes"])||"Purposes";return u`<p class="purposes">${o}: ${n.join(", ")}</p>`}_resolveTitle(t){let i=this.getAttribute("data-simplecmp-title");return i!==null&&i.length>0?i:typeof t.placeholderTitle=="string"&&t.placeholderTitle.length>0?t.placeholderTitle:this._tString(["!",t.name,"placeholderTitle?"])||this._tString(["!",t.name,"title?"])||x(t.name)}_resolveDescription(t,i){let n=this.getAttribute("data-simplecmp-description");if(n!==null&&n.length>0)return n;if(typeof t.placeholderDescription=="string"&&t.placeholderDescription.length>0)return t.placeholderDescription;let o=this._tString(["!",t.name,"placeholderDescription?"]);return o!==""?o:this._t(["contextualConsent","description"],{title:i})}firstUpdated(t){super.firstUpdated?.(t),this._updateAriaLabel(),this._maybeFocusFirstAction()}updated(t){super.updated?.(t),this._updateAriaLabel()}_updateAriaLabel(){let t=this._resolveService();if(t===void 0)return;let i=this._resolveTitle(t),n=this._tString(["!","contextualConsent","ariaLabel?"])||i,o=n.includes("{title}")?n.replace("{title}",i):n;this.getAttribute("aria-label")!==o&&this.setAttribute("aria-label",o)}_maybeFocusFirstAction(){if(!this._autoPlaceholder)return;this.renderRoot.querySelector("button:not([disabled])")?.focus()}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}};R.styles=[w,y`
      :host {
        /*
         * Flex column with content centered along the cross axis fills
         * the host when a parent constrains its dimensions (e.g.
         * Bootstrap's \`.ratio ratio-16x9\` wrapper that absolute-
         * positions children to 640×360), and shrinks to natural
         * content size when nothing constrains it. Prevents the
         * "compact notice bar at top, ~300px white below" layout the
         * universal-blocking rewriter would otherwise produce inside
         * aspect-ratio wrappers.
         */
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: var(--simplecmp-spacing-lg);
        background: var(--simplecmp-color-bg-alt);
        border: 1px solid var(--simplecmp-color-border);
        border-radius: var(--simplecmp-radius);
        color: var(--simplecmp-color-text);
        box-sizing: border-box;
      }

      p {
        margin: 0 0 var(--simplecmp-spacing) 0;
      }

      p.purposes {
        font-size: 0.875em;
        color: var(--simplecmp-color-text-muted);
      }

      .buttons {
        display: flex;
        flex-wrap: wrap;
        gap: var(--simplecmp-spacing-sm);
      }

      button {
        font: inherit;
        border: 1px solid transparent;
        border-radius: var(--simplecmp-radius);
        padding: var(--simplecmp-spacing-sm) var(--simplecmp-spacing);
        cursor: pointer;
      }

      button.accept {
        background: var(--simplecmp-color-primary);
        color: white;
      }

      button.accept:hover {
        background: var(--simplecmp-color-primary-hover);
      }

      button.accept-once {
        background: transparent;
        color: var(--simplecmp-color-primary);
        border-color: var(--simplecmp-color-primary);
      }

      button.configure {
        background: transparent;
        color: var(--simplecmp-color-text);
        border-color: var(--simplecmp-color-border);
      }

      .provider-info-link {
        font-size: 0.875em;
        margin: 0 0 var(--simplecmp-spacing) 0;
      }

      .provider-info-link a {
        color: var(--simplecmp-color-primary);
        text-decoration: underline;
        cursor: pointer;
      }

      .provider-info-link a:hover {
        color: var(--simplecmp-color-primary-hover);
      }
    `],h([v({attribute:!1})],R.prototype,"service",2),h([v({type:String,attribute:"service-name"})],R.prototype,"serviceName",2),h([Re()],R.prototype,"_providerInfoOpen",2),R=h([k("simplecmp-contextual-notice")],R);var q=class extends b{constructor(){super(...arguments);this.visible=!0;this._onChange=t=>{let i=t.target.checked,n=this.service;n===void 0||n.required||(this.manager?.updateConsent(n.name,i),this._emit("service-toggle",{name:n.name,value:i}))}}render(){let t=this.service;if(t===void 0)return p;let i=`simplecmp-service-${t.name}`,n=t.required===!0||this.manager?.consents[t.name]===!0,o=this._tString(["!",t.name,"title?"])||x(t.name),s=this._tString(["!",t.name,"description?"])||void 0;return u`
      <div class="row">
        <input
          type="checkbox"
          id=${i}
          .checked=${n}
          ?disabled=${t.required===!0}
          tabindex=${this.visible?"0":"-1"}
          @change=${this._onChange}
        />
        <div class="meta">
          <label for=${i}>
            <span class="title">${o}</span>
            ${t.required?u`<span class="badge">${this._t(["service","required","title"])}</span>`:p}
            ${t.optOut?u`<span class="badge">${this._t(["service","optOut","title"])}</span>`:p}
          </label>
          ${s?u`<p class="description">${s}</p>`:p}
          ${this._renderPurposes(t)}
        </div>
      </div>
    `}_renderPurposes(t){let i=t.purposes??[];if(i.length===0)return p;let n=i.map(s=>this._tString(["!","purposes",s,"title?"])||x(s)).join(", "),o=this._t(["service",i.length>1?"purposes":"purpose"]);return u`<p class="purposes">${o}: ${n}</p>`}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}};q.styles=[ge,y`
      :host {
        display: block;
        margin: var(--simplecmp-spacing-sm) 0;
      }

      .row {
        display: flex;
        align-items: flex-start;
        gap: var(--simplecmp-spacing-sm);
      }

      input[type='checkbox'] {
        margin-top: 0.25rem;
        flex-shrink: 0;
        accent-color: var(--simplecmp-color-primary);
      }

      .meta {
        flex: 1;
      }

      .title {
        font-weight: 500;
      }

      .badge {
        display: inline-block;
        margin-left: var(--simplecmp-spacing-sm);
        padding: 0 0.4rem;
        font-size: var(--simplecmp-font-size-sm);
        background: var(--simplecmp-color-bg-alt);
        border-radius: var(--simplecmp-radius);
        color: var(--simplecmp-color-text-muted);
      }

      .description {
        margin: 0.25rem 0 0 0;
        font-size: var(--simplecmp-font-size-sm);
        color: var(--simplecmp-color-text-muted);
      }

      .purposes {
        margin: 0.25rem 0 0 0;
        font-size: var(--simplecmp-font-size-sm);
        color: var(--simplecmp-color-text-muted);
      }
    `],h([v({attribute:!1})],q.prototype,"service",2),h([v({type:Boolean})],q.prototype,"visible",2),q=h([k("simplecmp-service-toggle")],q);var O=class extends b{constructor(){super(...arguments);this.purpose="";this.services=[];this._expanded=!1;this._onMasterChange=t=>{let i=t.target.checked;if(this.manager!==void 0){for(let n of this.services)n.required!==!0&&this.manager.updateConsent(n.name,i);this._emit("purpose-toggle",{purpose:this.purpose,value:i})}};this._toggleExpanded=t=>{t.preventDefault(),this._expanded=!this._expanded}}render(){if(this.manager===void 0)return p;let t=this._computeStatus(),i=this._tString(["!","purposes",this.purpose,"title?"])||x(this.purpose),n=this._tString(["!","purposes",this.purpose,"description"]),o=`simplecmp-purpose-${this.purpose}`;return u`
      <div class="header">
        <input
          type="checkbox"
          id=${o}
          .checked=${t.allEnabled||!t.allDisabled&&!t.onlyRequiredEnabled}
          .indeterminate=${!t.allEnabled&&!t.allDisabled}
          ?disabled=${t.allRequired}
          @change=${this._onMasterChange}
        />
        <div class="meta">
          <label for=${o}>
            <span class="title">${i}</span>
          </label>
          ${n?u`<p class="description">${n}</p>`:p}
        </div>
      </div>

      ${this.services.length>0?u`
            <button
              type="button"
              class="toggle-services"
              aria-expanded=${this._expanded?"true":"false"}
              @click=${this._toggleExpanded}
            >
              ${this._expanded?"\u25B4":"\u25BE"} ${this.services.length}
              ${this._t(["purposeItem",this.services.length>1?"services":"service"])}
            </button>
            <ul class="services" ?hidden=${!this._expanded}>
              ${this.services.map(s=>u`
                  <li>
                    <simplecmp-service-toggle
                      .config=${this.config}
                      .manager=${this.manager}
                      .service=${s}
                      .visible=${this._expanded}
                    ></simplecmp-service-toggle>
                  </li>
                `)}
            </ul>
          `:p}
    `}_computeStatus(){let t=this.manager?.consents??{},i={allEnabled:!0,allDisabled:!0,onlyRequiredEnabled:!0,allRequired:!0};for(let n of this.services){let o=n.required===!0;o||(i.allRequired=!1),t[n.name]?(o||(i.onlyRequiredEnabled=!1),i.allDisabled=!1):o||(i.allEnabled=!1)}return i.allDisabled&&(i.onlyRequiredEnabled=!1),i}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}};O.styles=[ge,y`
      :host {
        display: block;
        border: 1px solid var(--simplecmp-color-border);
        border-radius: var(--simplecmp-radius);
        padding: var(--simplecmp-spacing);
        margin-bottom: var(--simplecmp-spacing-sm);
      }

      .header {
        display: flex;
        align-items: flex-start;
        gap: var(--simplecmp-spacing-sm);
      }

      input[type='checkbox'] {
        margin-top: 0.25rem;
        accent-color: var(--simplecmp-color-primary);
      }

      .title {
        font-weight: 500;
      }

      .description {
        margin: 0.25rem 0 0 0;
        font-size: var(--simplecmp-font-size-sm);
        color: var(--simplecmp-color-text-muted);
      }

      .toggle-services {
        margin-top: var(--simplecmp-spacing-sm);
        background: none;
        border: none;
        padding: 0;
        font: inherit;
        font-size: var(--simplecmp-font-size-sm);
        color: var(--simplecmp-color-primary);
        cursor: pointer;
      }

      .services {
        margin-top: var(--simplecmp-spacing-sm);
        padding-left: var(--simplecmp-spacing-lg);
        border-left: 2px solid var(--simplecmp-color-border);
      }

      .services[hidden] {
        display: none;
      }
    `],h([v({type:String})],O.prototype,"purpose",2),h([v({attribute:!1})],O.prototype,"services",2),h([Re()],O.prototype,"_expanded",2),O=h([k("simplecmp-purpose-group")],O);function Zr(r,e){if(r!==void 0){if(typeof r=="string")return r;if(typeof r=="object")return r[e]??r.default}}var S=class extends b{constructor(){super(...arguments);this.open=!1;this.testing=!1;this._onKeydown=t=>{if(t.key!=="Tab")return;let i=this._collectFocusable();if(i.length===0)return;let n=i[0],o=i[i.length-1];if(n===void 0||o===void 0)return;let s=this._deepActiveElement();!t.shiftKey&&s===o?(t.preventDefault(),n.focus()):t.shiftKey&&s===n&&(t.preventDefault(),o.focus())};this._onCancel=t=>{this.config?.mustConsent===!0&&t.preventDefault()};this._onClose=()=>{this.open=!1,this._emit("modal-close")};this._onCloseClick=()=>{this.config?.mustConsent!==!0&&(this.open=!1)};this._onBackdropClick=t=>{t.target===this._dialog&&this.config?.mustConsent!==!0&&(this.open=!1)};this._onAcceptAll=()=>{this.manager!==void 0&&(this.manager.changeAll(!0),this.manager.saveAndApplyConsents("accept"),this._emit("accept"),this.open=!1)};this._onDecline=()=>{this.manager!==void 0&&(this.manager.changeAll(!1),this.manager.saveAndApplyConsents("decline"),this._emit("decline"),this.open=!1)};this._onSave=()=>{this.manager!==void 0&&(this.manager.saveAndApplyConsents("save"),this._emit("save"),this.open=!1)}}updated(t){if(super.updated?.(t),t.has("open")){let i=this._dialog;if(i===void 0)return;this.open&&!i.open?i.showModal():!this.open&&i.open&&i.close()}}render(){let t=this.config,i=this.manager;return t===void 0||i===void 0?p:u`
      <dialog
        aria-labelledby="simplecmp-modal-title"
        @cancel=${this._onCancel}
        @close=${this._onClose}
        @click=${this._onBackdropClick}
        @keydown=${this._onKeydown}
      >
        ${this._renderHeader(t)}
        <div class="body">${this._renderBody(t)}</div>
        ${this._renderFooter(t,i)}
      </dialog>
    `}_renderHeader(t){let i=this._activeLang(),n=this._resolvePolicyUrl(t.privacyPolicy,["privacyPolicyUrl"],i),o=this._resolvePolicyUrl(t.imprint,["imprintUrl"],i),s=t.htmlTexts===!0,a=this._t(["consentModal","description"]),c=t.mustConsent!==!0;return u`
      <div class="header">
        ${c?u`<button
              type="button"
              class="close"
              aria-label=${this._t(["close"])}
              @click=${this._onCloseClick}
            >
              ×
            </button>`:p}
        <h1 id="simplecmp-modal-title">${this._t(["consentModal","title"])}</h1>
        <p class="description">
          ${s?Jr(a):a}
        </p>
        ${this._renderPolicyLinks(n,o)}
      </div>
    `}_renderBody(t){if(t.groupByPurpose!==!1){let n=this._collectPurposes(),o=t.purposeOrder??[],s=Array.from(n.keys()).sort((a,c)=>o.indexOf(a)-o.indexOf(c));return u`
        <div class="purposes">
          ${s.map(a=>u`
              <simplecmp-purpose-group
                .config=${this.config}
                .manager=${this.manager}
                .purpose=${a}
                .services=${n.get(a)??[]}
              ></simplecmp-purpose-group>
            `)}
        </div>
      `}return u`
      <ul class="services">
        ${t.services.map(n=>u`
            <li>
              <simplecmp-service-toggle
                .config=${this.config}
                .manager=${this.manager}
                .service=${n}
              ></simplecmp-service-toggle>
            </li>
          `)}
      </ul>
    `}_renderFooter(t,i){let n=t.hideDeclineAll!==!0,o=t.acceptAll===!0,s=i.confirmed?this._t(["save"]):this._t(["acceptSelected"]);return u`
      <div class="footer">
        ${n?u`<button type="button" class="action decline" @click=${this._onDecline}>
              ${this._t(["decline"])}
            </button>`:p}
        <button type="button" class="action save" @click=${this._onSave}>
          ${s}
        </button>
        ${o?u`<button type="button" class="action accept-all" @click=${this._onAcceptAll}>
              ${this._t(["acceptAll"])}
            </button>`:p}
      </div>
    `}_renderPolicyLinks(t,i){let n=t!==void 0&&ve(t)?t:void 0,o=i!==void 0&&ve(i)?i:void 0;return n===void 0&&o===void 0?p:u`
      <p class="policy-links">
        ${n?u`<a href=${n} target="_blank" rel="noopener"
              >${this._t(["privacyPolicy","name"])}</a
            >`:p}
        ${n&&o?" \xB7 ":p}
        ${o?u`<a href=${o} target="_blank" rel="noopener"
              >${this._imprintLinkText()}</a
            >`:p}
      </p>
    `}_activeLang(){return this.config?.lang??document.documentElement.lang??"en"}_collectFocusable(){let t=this._dialog;if(t===void 0)return[];let i=[],n='button:not([disabled]), a[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',o=s=>{if(s.matches(n)&&this._isVisible(s)&&i.push(s),s.shadowRoot!==null)for(let a of s.shadowRoot.children)o(a);for(let a of s.children)o(a)};for(let s of t.children)o(s);return i}_deepActiveElement(){let t=document.activeElement;for(;t?.shadowRoot?.activeElement;)t=t.shadowRoot.activeElement;return t}_isVisible(t){let i=t;return typeof i.checkVisibility=="function"?i.checkVisibility():t.offsetParent!==null}_collectPurposes(){let t=new Map;for(let i of this.config?.services??[])for(let n of i.purposes??[]){let o=t.get(n)??[];o.push(i),t.set(n,o)}return t}_resolvePolicyUrl(t,i,n){let o=Zr(t,n);if(o!==void 0)return o;let s=this._tString(["!",...i]);return s===""?void 0:s}_imprintLinkText(){return this._tString(["!","consentNotice","imprint","name"])||this._tString(["!","imprint","name"])||"Imprint"}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}};S.styles=[w,y`
      :host {
        display: contents;
      }

      dialog {
        max-width: 40rem;
        width: 90%;
        border: 1px solid var(--simplecmp-color-border);
        border-radius: var(--simplecmp-radius);
        padding: 0;
        background: var(--simplecmp-color-bg);
        color: var(--simplecmp-color-text);
        font-family: var(--simplecmp-font-family);
        font-size: var(--simplecmp-font-size);
      }

      dialog::backdrop {
        background: rgba(0, 0, 0, 0.4);
      }

      .header,
      .body,
      .footer {
        padding: var(--simplecmp-spacing-lg);
      }

      .header {
        border-bottom: 1px solid var(--simplecmp-color-border);
        position: relative;
      }

      h1 {
        margin: 0 0 var(--simplecmp-spacing) 0;
        font-family: var(--simplecmp-font-family-heading);
        font-size: var(--simplecmp-font-size-heading);
      }

      .description {
        margin: 0 0 var(--simplecmp-spacing) 0;
      }

      .policy-links {
        margin: 0;
        font-size: var(--simplecmp-font-size-sm);
        color: var(--simplecmp-color-text-muted);
      }

      .policy-links a {
        color: var(--simplecmp-color-text-muted);
      }

      .close {
        position: absolute;
        top: var(--simplecmp-spacing);
        right: var(--simplecmp-spacing);
        background: none;
        border: none;
        font-size: 1.25rem;
        line-height: 1;
        cursor: pointer;
        color: var(--simplecmp-color-text-muted);
      }

      .footer {
        border-top: 1px solid var(--simplecmp-color-border);
        display: flex;
        gap: var(--simplecmp-spacing-sm);
        flex-wrap: wrap;
        justify-content: flex-end;
      }

      button.action {
        font: inherit;
        border: 1px solid transparent;
        border-radius: var(--simplecmp-radius);
        padding: var(--simplecmp-spacing-sm) var(--simplecmp-spacing);
        cursor: pointer;
      }

      button.accept-all,
      button.save {
        background: var(--simplecmp-color-primary);
        color: white;
      }

      button.accept-all:hover,
      button.save:hover {
        background: var(--simplecmp-color-primary-hover);
      }

      button.decline {
        background: transparent;
        color: var(--simplecmp-color-danger);
        border-color: var(--simplecmp-color-danger);
      }

      ul.services {
        list-style: none;
        padding: 0;
        margin: 0;
      }
    `],h([v({type:Boolean,reflect:!0})],S.prototype,"open",2),h([v({type:Boolean})],S.prototype,"testing",2),h([Oe("dialog")],S.prototype,"_dialog",2),S=h([k("simplecmp-modal")],S);function Jr(r){return typeof r=="string"?J(r):Array.isArray(r)?r.map(e=>typeof e=="string"?J(e):e):r}var E=class extends b{constructor(){super(...arguments);this.position="bottom-right";this._onClick=t=>{t.preventDefault(),this._emit("trigger-click")}}connectedCallback(){super.connectedCallback(),this.setAttribute("position",this.position)}render(){let t=this._resolveLabel();return u`
      <button type="button" aria-label=${t} title=${t} @click=${this._onClick}>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path
            fill="currentColor"
            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10c0-.34-.02-.68-.05-1.01-.71.93-1.83 1.51-3.07 1.51-2.21 0-4-1.79-4-4 0-.34.04-.68.13-1.01-1.65.32-3.13-1.04-3.13-2.49 0-.79.36-1.5.93-1.96A9.95 9.95 0 0 0 12 2zm-1 5h2v2h-2zm-3 4h2v2H8zm6 0h2v2h-2zm-2 4h2v2h-2z"
          />
        </svg>
      </button>
    `}_resolveLabel(){if(this.label!==void 0&&this.label!=="")return this.label;if(this.config!==void 0){let t=this._tString(["!","floatingTrigger","label"]);if(t!=="")return t;if(be(this.config.region,this.config.regimes,this.config.regimeDefault)==="opt-out"){let n=this._tString(["!","doNotSell"]);if(n!=="")return n}}return"Cookie settings"}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}};E.styles=[w,y`
      :host {
        display: contents;
      }

      button {
        position: fixed;
        z-index: var(--simplecmp-z-index);
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 50%;
        background: var(--simplecmp-color-primary);
        color: white;
        border: none;
        box-shadow: var(--simplecmp-shadow);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }

      button:hover {
        background: var(--simplecmp-color-primary-hover);
      }

      :host([position='bottom-right']) button {
        right: var(--simplecmp-spacing);
        bottom: var(--simplecmp-spacing);
      }

      :host([position='bottom-left']) button {
        left: var(--simplecmp-spacing);
        bottom: var(--simplecmp-spacing);
      }

      :host([position='top-right']) button {
        right: var(--simplecmp-spacing);
        top: var(--simplecmp-spacing);
      }

      :host([position='top-left']) button {
        left: var(--simplecmp-spacing);
        top: var(--simplecmp-spacing);
      }

      svg {
        width: 1.25rem;
        height: 1.25rem;
      }
    `],h([v({type:String})],E.prototype,"position",2),h([v({type:String})],E.prototype,"label",2),E=h([k("simplecmp-trigger")],E);function Hi(r){let e=re(r),t=r.domMode==="light",i;r.noNotice!==!0&&e.bannerMode!=="none"&&(!e.confirmed||r.testing===!0)&&(i=new D,i.config=r,i.manager=e,r.testing===!0&&(i.testing=!0),t&&i.setAttribute("mode","light"),document.body.appendChild(i));let o=new S;o.config=r,o.manager=e,r.testing===!0&&(o.testing=!0),t&&o.setAttribute("mode","light"),document.body.appendChild(o),r.mustConsent===!0&&!e.confirmed&&(o.open=!0);let s=()=>{o.open=!0};document.addEventListener("simplecmp:configure",s);let a={update(m,d){d==="saveConsents"&&i!==void 0&&(i.remove(),i=void 0)}};e.watch(a);let c,l;return r.floatingTrigger&&(c=new E,c.config=r,typeof r.floatingTrigger=="object"&&(r.floatingTrigger.position!==void 0&&(c.position=r.floatingTrigger.position),r.floatingTrigger.label!==void 0&&(c.label=r.floatingTrigger.label)),t&&c.setAttribute("mode","light"),document.body.appendChild(c),l=()=>{o.open=!0},c.addEventListener("simplecmp:trigger-click",l)),e.applyConsents(),{show(){o.open=!0},hide(){o.open=!1},destroy(){e.unwatch(a),document.removeEventListener("simplecmp:configure",s),i?.remove(),o.remove(),c!==void 0&&l!==void 0&&(c.removeEventListener("simplecmp:trigger-click",l),c.remove())},manager:e}}var Fi=`:where(
    simplecmp-banner,
    simplecmp-modal,
    simplecmp-purpose-group,
    simplecmp-service-toggle,
    simplecmp-trigger,
    simplecmp-policy-links,
    simplecmp-contextual-notice
  ) {
  /* Colors */
  --simplecmp-color-primary: var(--bs-primary, #0d6efd);
  --simplecmp-color-primary-hover: var(--bs-primary-bg-subtle, #084298);
  --simplecmp-color-secondary: var(--bs-secondary, #6c757d);
  --simplecmp-color-danger: var(--bs-danger, #dc3545);
  --simplecmp-color-bg: var(--bs-body-bg, #ffffff);
  --simplecmp-color-bg-alt: var(--bs-tertiary-bg, #f8f9fa);
  --simplecmp-color-border: var(--bs-border-color, #dee2e6);
  --simplecmp-color-text: var(--bs-body-color, #212529);
  --simplecmp-color-text-muted: var(--bs-secondary-color, #6c757d);

  /* Geometry */
  --simplecmp-radius: var(--bs-border-radius, 0.375rem);
  --simplecmp-spacing: var(--bs-spacer, 1rem);
  --simplecmp-spacing-sm: 0.5rem;
  --simplecmp-spacing-lg: 1.5rem;

  /* Typography */
  --simplecmp-font-family: var(--bs-body-font-family, system-ui);
  --simplecmp-font-size: var(--bs-body-font-size, 1rem);
  --simplecmp-font-size-sm: 0.875rem;
  --simplecmp-line-height: var(--bs-body-line-height, 1.5);

  /* Effects */
  --simplecmp-shadow: var(--bs-box-shadow, 0 0.5rem 1rem rgba(0, 0, 0, 0.15));
}`;var Vi=`:where(
    simplecmp-banner,
    simplecmp-modal,
    simplecmp-purpose-group,
    simplecmp-service-toggle,
    simplecmp-trigger,
    simplecmp-policy-links,
    simplecmp-contextual-notice
  ) {
  /* Colors \u2014 bind to Bulma's semantic palette. Hover falls back to
     a literal (Bulma offers a generated --bulma-primary-base for
     hover/dark adjustments but it's not always present on every
     theme; the literal #1e6cbf is Bulma's default primary darkened
     by ~12% for a sensible hover treatment). */
  --simplecmp-color-primary: var(--bulma-primary, #485fc7);
  --simplecmp-color-primary-hover: var(--bulma-primary-30-invert, var(--bulma-primary-dark, #1e6cbf));
  --simplecmp-color-secondary: var(--bulma-text-weak, #6c757d);
  --simplecmp-color-danger: var(--bulma-danger, #f14668);
  --simplecmp-color-bg: var(--bulma-scheme-main, #ffffff);
  --simplecmp-color-bg-alt: var(--bulma-scheme-main-bis, #fafafa);
  --simplecmp-color-border: var(--bulma-border, #ededed);
  --simplecmp-color-text: var(--bulma-text-strong, #2c2c2c);
  --simplecmp-color-text-muted: var(--bulma-text-weak, #6e6e6e);

  /* Geometry. Bulma exposes --bulma-radius for normal corners,
     and -small / -large for the rest. We pick the medium for
     buttons/cards. Spacing uses --bulma-block-spacing which Bulma
     1.0 sets to 1.5rem (closer to card-internal feel than the
     smaller 0.5rem element padding). */
  --simplecmp-radius: var(--bulma-radius, 4px);
  --simplecmp-spacing: var(--bulma-block-spacing, 1.5rem);
  --simplecmp-spacing-sm: 0.5rem;
  --simplecmp-spacing-lg: 2rem;

  /* Typography */
  --simplecmp-font-family: var(--bulma-family-primary, system-ui);
  --simplecmp-font-size: var(--bulma-size-normal, 1rem);
  --simplecmp-font-size-sm: var(--bulma-size-small, 0.875rem);
  --simplecmp-line-height: var(--bulma-body-line-height, 1.5);

  /* Effects */
  --simplecmp-shadow: var(--bulma-shadow, 0 0.5em 1em -0.125em rgba(10, 10, 10, 0.1));
}
`;var Wi=`:where(
    simplecmp-banner,
    simplecmp-modal,
    simplecmp-purpose-group,
    simplecmp-service-toggle,
    simplecmp-trigger,
    simplecmp-policy-links,
    simplecmp-contextual-notice
  ) {
  /* Colors. Pico exposes a primary palette via --pico-primary and
     --pico-primary-hover for hover states; both are stable in v2.
     Background pair: --pico-background-color is the body / card,
     --pico-card-background-color is what cards specifically use
     (slightly elevated). Text follows the same pattern with
     --pico-color and --pico-muted-color. */
  --simplecmp-color-primary: var(--pico-primary, #0172ad);
  --simplecmp-color-primary-hover: var(--pico-primary-hover, #015887);
  --simplecmp-color-secondary: var(--pico-secondary, #5d6b89);
  --simplecmp-color-danger: var(--pico-del-color, #c62828);
  --simplecmp-color-bg: var(--pico-card-background-color, var(--pico-background-color, #ffffff));
  --simplecmp-color-bg-alt: var(--pico-card-sectioning-background-color, #f9f9f9);
  --simplecmp-color-border: var(--pico-muted-border-color, #e1e6eb);
  --simplecmp-color-text: var(--pico-color, #373c44);
  --simplecmp-color-text-muted: var(--pico-muted-color, #646b79);

  /* Geometry */
  --simplecmp-radius: var(--pico-border-radius, 0.25rem);
  --simplecmp-spacing: var(--pico-spacing, 1rem);
  --simplecmp-spacing-sm: calc(var(--pico-spacing, 1rem) * 0.5);
  --simplecmp-spacing-lg: calc(var(--pico-spacing, 1rem) * 1.5);

  /* Typography */
  --simplecmp-font-family: var(--pico-font-family, system-ui);
  --simplecmp-font-size: var(--pico-font-size, 1rem);
  --simplecmp-font-size-sm: calc(var(--pico-font-size, 1rem) * 0.875);
  --simplecmp-line-height: var(--pico-line-height, 1.5);

  /* Effects */
  --simplecmp-shadow: var(--pico-card-box-shadow, 0 0.125rem 1rem rgba(0, 0, 0, 0.04));
}
`;var Ki=`:where(
    simplecmp-banner,
    simplecmp-modal,
    simplecmp-purpose-group,
    simplecmp-service-toggle,
    simplecmp-trigger,
    simplecmp-policy-links,
    simplecmp-contextual-notice
  ) {
  /* Colors \u2014 semantic first, Tailwind palette fallback chain. The
     semantic names (--color-primary, --color-background, \u2026) follow
     the shadcn/ui convention which is the de-facto standard for
     Tailwind 4 semantic tokens. Sites that don't define them get
     the palette default (Tailwind's blue/slate). */
  --simplecmp-color-primary: var(--color-primary, var(--color-blue-600, #2563eb));
  --simplecmp-color-primary-hover: var(--color-primary-hover, var(--color-blue-700, #1d4ed8));
  --simplecmp-color-secondary: var(--color-secondary, var(--color-slate-500, #64748b));
  --simplecmp-color-danger: var(--color-destructive, var(--color-red-600, #dc2626));
  --simplecmp-color-bg: var(--color-background, var(--color-white, #ffffff));
  --simplecmp-color-bg-alt: var(--color-muted, var(--color-slate-50, #f8fafc));
  --simplecmp-color-border: var(--color-border, var(--color-slate-200, #e2e8f0));
  --simplecmp-color-text: var(--color-foreground, var(--color-slate-900, #0f172a));
  --simplecmp-color-text-muted: var(--color-muted-foreground, var(--color-slate-500, #64748b));

  /* Geometry. Tailwind 4 spacing is computed: gap-4 \u2192 calc(--spacing * 4).
     We pin --simplecmp-spacing to ~1rem (the visual rhythm the banner
     was designed for) by multiplying the base unit by 4 \u2014 which lands
     at 1rem under Tailwind's default 0.25rem base. Sites that scale
     --spacing up/down keep the banner consistent with their rhythm. */
  --simplecmp-radius: var(--radius-md, 0.375rem);
  --simplecmp-spacing: calc(var(--spacing, 0.25rem) * 4);
  --simplecmp-spacing-sm: calc(var(--spacing, 0.25rem) * 2);
  --simplecmp-spacing-lg: calc(var(--spacing, 0.25rem) * 6);

  /* Typography */
  --simplecmp-font-family: var(--font-sans, system-ui, -apple-system, sans-serif);
  --simplecmp-font-size: var(--text-base, 1rem);
  --simplecmp-font-size-sm: var(--text-sm, 0.875rem);
  --simplecmp-line-height: var(--leading-normal, 1.5);

  /* Effects */
  --simplecmp-shadow: var(--shadow-lg, 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1));
}
`;var ut="data-simplecmp-theme",Gi={bootstrap5:Fi,tailwind4:Ki,bulma:Vi,pico:Wi};function Qi(r){if(typeof document>"u")return;let e=document.querySelector(`style[${ut}]`);if(r===void 0||r==="default"){e?.remove();return}let t=Gi[r];if(t===void 0){console.warn(`SimpleCMP: theme "${r}" has no adapter; ignoring. Known themes: ${Object.keys(Gi).join(", ")}, 'default'.`);return}if(e?.getAttribute(ut)===r)return;let i=e instanceof HTMLStyleElement?e:document.createElement("style");i.setAttribute(ut,r),i.textContent=t,i.isConnected||document.head.appendChild(i)}function Xr(r){return _t(r)}function Yr(r){return At(r)}function eo(r){return Le(r)}z(Ze,V(mi));var to="0.0.1",X=null,mt=0;function Zi(r){typeof requestIdleCallback=="function"?requestIdleCallback(r,{timeout:2e3}):setTimeout(r,200)}var H=null,F=null;function io(r){uo(r),mo(r);let e=++mt,t=lo(),i=t?{...r,testing:!0}:r;t&&po(),X!==null&&(X.destroy(),X=null),H!==null&&(H(),H=null),F!==null&&(F(),F=null),Qi(r.theme);let n=re(i);i.record&&(i.deferRecorder?Zi(()=>{e===mt&&Ji(i)}):Ji(i)),i.interceptRuntime&&(H=no(i,n)),i.consentMode&&(F=Qe(i.consentMode,n,i.services));let o=null,s=!1,a=[],c=()=>{if(!(s||e!==mt)){o=Hi(i);for(let f of a)f==="show"?o.show():o.hide();a.length=0}},m=i.deferRender===!0&&!t?()=>Zi(c):c,d=null;return typeof document<"u"&&document.body!==null?m():typeof document<"u"&&(d=m,document.addEventListener("DOMContentLoaded",d,{once:!0})),X={show:()=>{o!==null?o.show():a.push("show")},hide:()=>{o!==null?o.hide():a.push("hide")},manager:n,destroy:()=>{s=!0,H!==null&&(H(),H=null),F!==null&&(F(),F=null),d!==null&&typeof document<"u"&&(document.removeEventListener("DOMContentLoaded",d),d=null),o?.destroy(),o=null,a.length=0}},X}function no(r,e){let t=typeof r.interceptRuntime=="object"&&r.interceptRuntime!==null?r.interceptRuntime:{},i=Si(r.services,{blockAllUnknown:t.universalBlock===!0}),n=t.onBlock;return wi({matcher:i,consentChecker:o=>e.getConsent(o),sameOriginHosts:t.sameOriginHosts,onBlock:o=>{j!==null&&j.recordSyntheticDetection({kind:bi(o.mechanism),identifier:o.url,origin:ki(o.url)}),n?.(o)}})}function ro(){X?.show()}var j=null;function Ji(r){j&&(j.stop(),j=null),j=yi(r),j.start()}function oo(){return j??void 0}var so=Nt,ao=re,co=ie;function lo(){if(typeof window>"u"||typeof URLSearchParams>"u")return!1;try{if(new URLSearchParams(window.location.search).get("simplecmp_audit")==="1")return!0;let e=window.location.hash;return e.length>1&&new URLSearchParams(e.slice(1)).get("simplecmp_audit")==="1"}catch{return!1}}function po(){typeof window>"u"||requestAnimationFrame(()=>{requestAnimationFrame(()=>{requestAnimationFrame(()=>{let r=Le();try{window.parent.postMessage({type:"simplecmp-audit-from-fe",results:r,location:{href:window.location.href,host:window.location.host}},"*")}catch{}})})})}function uo(r){r.cmsBridgeUrl&&!r.record&&console.warn("SimpleCMP: `cmsBridgeUrl` is set but `record` is not enabled. The CMS bridge listens to recorder detections \u2014 without the recorder running, no webhooks will ever fire. Set `record: true` or remove `cmsBridgeUrl`.")}function mo(r){r.hideDeclineAll&&console.warn('SimpleCMP: `hideDeclineAll: true` hides the "Decline all" button on the first banner level. This is incompatible with German consent requirements (BGH "Cookie II", BGH I ZR 7/16; DSK 2022). Keep the decline option equally prominent or expect compliance issues.')}return rn(fo);})();
/*! Bundled license information:

@lit/reactive-element/css-tag.js:
  (**
   * @license
   * Copyright 2019 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)

@lit/reactive-element/reactive-element.js:
lit-html/lit-html.js:
lit-element/lit-element.js:
@lit/reactive-element/decorators/custom-element.js:
@lit/reactive-element/decorators/property.js:
@lit/reactive-element/decorators/state.js:
@lit/reactive-element/decorators/event-options.js:
@lit/reactive-element/decorators/base.js:
@lit/reactive-element/decorators/query.js:
@lit/reactive-element/decorators/query-all.js:
@lit/reactive-element/decorators/query-async.js:
@lit/reactive-element/decorators/query-assigned-nodes.js:
lit-html/directive.js:
lit-html/directives/unsafe-html.js:
  (**
   * @license
   * Copyright 2017 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)

lit-html/is-server.js:
  (**
   * @license
   * Copyright 2022 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)

@lit/reactive-element/decorators/query-assigned-elements.js:
  (**
   * @license
   * Copyright 2021 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)

lit-html/directives/if-defined.js:
  (**
   * @license
   * Copyright 2018 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)
*/
//# sourceMappingURL=simplecmp.global.js.map