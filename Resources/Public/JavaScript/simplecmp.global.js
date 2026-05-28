"use strict";var SimpleCMP=(()=>{var he=Object.defineProperty;var it=Object.getOwnPropertyDescriptor;var mi=Object.getOwnPropertyNames;var fi=Object.prototype.hasOwnProperty;var hi=(r,e)=>{for(var t in e)he(r,t,{get:e[t],enumerable:!0})},gi=(r,e,t,i)=>{if(e&&typeof e=="object"||typeof e=="function")for(let n of mi(e))!fi.call(r,n)&&n!==t&&he(r,n,{get:()=>e[n],enumerable:!(i=it(e,n))||i.enumerable});return r};var vi=r=>gi(he({},"__esModule",{value:!0}),r),h=(r,e,t,i)=>{for(var n=i>1?void 0:i?it(e,t):e,o=r.length-1,s;o>=0;o--)(s=r[o])&&(n=(i?s(e,t,n):s(n))||n);return i&&n&&he(e,t,n),n};var Un={};hi(Un,{CmsBridge:()=>R,LayeredClassifier:()=>K,ServiceDbClient:()=>F,VERSION:()=>$n,addEventListener:()=>Rn,getManager:()=>Ln,getRecorder:()=>Tn,init:()=>zn,show:()=>On,updateConfig:()=>jn});var nt="simplecmp-reported:";function rt(r){let e=r.indexOf("?"),t=r.indexOf("#"),i=[e,t].filter(n=>n>=0);return i.length===0?r:r.slice(0,Math.min(...i))}var R=class{constructor(e){this.lastSent=new Map;this.warned=new Set;this.pending=[];this.pendingKeys=[];this.flushTimer=null;this.lifecycleHooked=!1;this.url=e.url,this.host=(()=>{try{return new URL(e.url).host}catch{return""}})(),this.auth=e.auth,this.source=e.source??"default",this.dedupTtlMs=e.dedupTtlMs??36e5,this.crossSessionDedupMs=e.crossSessionDedupMs??6048e5,this.flushDebounceMs=e.flushDebounceMs??1500,this.maxBatchSize=Math.max(1,e.maxBatchSize??25),this.timeoutMs=e.timeoutMs??5e3,this.respectDoNotTrack=e.respectDoNotTrack??!0,this.fetchFn=e.fetch??(typeof fetch<"u"?fetch.bind(globalThis):void 0),this.now=e.now??(()=>Date.now()),e.storage!==void 0?this.storage=e.storage:typeof localStorage<"u"?this.storage=localStorage:this.storage=null,this.nav=e.navigator??(typeof navigator<"u"?navigator:void 0);let t=e.sampleRate??1;this.sessionInScope=t>=1||Math.random()<t}onDetection(e){if(!this.sessionInScope||this.respectDoNotTrack&&this.nav?.doNotTrack==="1"||this.host&&e.origin===this.host)return;let t=`${e.kind}:${e.identifier}`;if(this._dedupHit(t))return;let i=this.now();if(this.lastSent.set(t,i),this.pendingKeys.push(t),this.pending.push(this._toBridgeDetection(e)),this.pending.length>=this.maxBatchSize){this._flush();return}this._scheduleFlush(),this._hookLifecycle()}flushNow(){return this._flush()}_dedupHit(e){let t=this.now(),i=this.lastSent.get(e);return i!==void 0&&t-i<this.dedupTtlMs?!0:this._crossSessionHit(e,t)}_crossSessionHit(e,t){if(this.storage===null||this.crossSessionDedupMs<=0)return!1;let i=`${nt}${this.source}:${e}`,n=null;try{n=this.storage.getItem(i)}catch{return!1}if(n===null)return!1;let o=Number(n);if(!Number.isFinite(o))return!1;if(t-o<this.crossSessionDedupMs)return!0;try{this.storage.removeItem(i)}catch{}return!1}_markCrossSession(e,t){if(this.storage===null||this.crossSessionDedupMs<=0)return;let i=`${nt}${this.source}:${e}`;try{this.storage.setItem(i,String(t))}catch{}}_scheduleFlush(){this.flushTimer!==null||typeof setTimeout>"u"||(this.flushTimer=setTimeout(()=>{this.flushTimer=null,this._flush()},this.flushDebounceMs))}_hookLifecycle(){if(this.lifecycleHooked||typeof addEventListener>"u")return;this.lifecycleHooked=!0;let e=()=>{this._flushBeacon()};addEventListener("pagehide",e,{capture:!0}),typeof document<"u"&&document.addEventListener("visibilitychange",()=>{document.visibilityState==="hidden"&&e()},{capture:!0})}_flushBeacon(){if(this.pending.length===0)return;if(typeof this.nav?.sendBeacon!="function"){this._flush({keepalive:!0});return}let e=this._buildPayload(this.pending),t=new Blob([JSON.stringify(e)],{type:"application/json"}),i=!1;try{i=this.nav.sendBeacon(this.url,t)}catch{i=!1}i?(this._markBatchSent(),this.pending=[],this.pendingKeys=[]):this._flush({keepalive:!0})}async _flush(e={}){if(this.flushTimer!==null&&(clearTimeout(this.flushTimer),this.flushTimer=null),this.pending.length===0)return;let t=this.pending,i=this.pendingKeys;this.pending=[],this.pendingKeys=[];try{await this._post(this._buildPayload(t),e);let n=this.now();for(let o of i)this._markCrossSession(o,n)}catch(n){if(this._shouldClearOnError(n))for(let o of i)this.lastSent.delete(o);this._warnOnce("post",n)}}_markBatchSent(){let e=this.now();for(let t of this.pendingKeys)this._markCrossSession(t,e)}_toBridgeDetection(e){let t={kind:e.kind,identifier:e.identifier,firstSeen:e.firstSeen,lastSeen:e.lastSeen,count:e.count,status:e.status==="known"?"known":"unknown"};return e.origin!==void 0&&(t.origin=e.origin),e.firstSeenOn!==void 0&&(t.firstSeenOn=rt(e.firstSeenOn)),e.matchedService!==void 0&&(t.matchedService=e.matchedService),t}_buildPayload(e){let t=typeof location<"u"?location:void 0,i=typeof document<"u"?document:void 0,n=typeof navigator<"u"?navigator:void 0,o={url:t?rt(t.href):""},s=i?.referrer;s&&(o.referrer=s);let a=n?.userAgent;return a&&(o.userAgent=a),{schemaVersion:2,source:this.source,sentAt:new Date(this.now()).toISOString(),page:o,library:{name:"simplecmp",version:"0.0.1"},detections:e}}async _post(e,t){if(!this.fetchFn)throw new Error("fetch is unavailable");let i=new Headers({"Content-Type":"application/json"});if(this.auth){let s=this.auth.header??"Authorization",a=this.auth.scheme??"Bearer";i.set(s,`${a} ${this.auth.token}`.trim())}let n=typeof AbortController<"u"?new AbortController:null,o=n&&typeof setTimeout<"u"?setTimeout(()=>n.abort(),this.timeoutMs):void 0;try{let s={method:"POST",headers:i,body:JSON.stringify(e),signal:n?.signal};t.keepalive===!0&&(s.keepalive=!0);let a=await this.fetchFn(this.url,s);if(!a.ok)throw new Error(`CMS bridge POST responded ${a.status}`)}finally{o!==void 0&&clearTimeout(o)}}_shouldClearOnError(e){let t=e instanceof Error?e.message:String(e);return/responded 4\d\d/.exec(t)===null}_warnOnce(e,t){if(this.warned.has(e))return;this.warned.add(e);let i=t instanceof Error?t.message:String(t);console.warn(`SimpleCMP cms-bridge: ${e} failed (${i}). The bridge will keep trying on subsequent detection events; this warning fires once per error category per session.`)}};function De(){if(typeof document>"u")return[];let r=document.cookie.split(";"),e=[],t=/^\s*([^=]+)\s*=\s*(.*?)$/;for(let i of r){let n=t.exec(i);n!==null&&e.push({name:n[1]??"",value:n[2]??""})}return e}function Te(r){for(let e of De())if(e.name===r)return e;return null}function ot(r,e,t,i,n){if(typeof document>"u")return;let o="";if(t){let s=new Date;s.setTime(s.getTime()+t*24*60*60*1e3),o=`; expires=${s.toUTCString()}`}i!==void 0&&(o+=`; domain=${i}`),o+=n!==void 0?`; path=${n}`:"; path=/",document.cookie=`${r}=${e||""}${o}; SameSite=Lax`}function J(r,e,t){if(typeof document>"u")return!1;let i=`${r}=; Max-Age=-99999999;`;return document.cookie=i,i+=` path=${e||"/"};`,document.cookie=i,t!==void 0&&(i+=` domain=${t};`,document.cookie=i),Te(r)===null}var Re=class{constructor(){this.value=null}get(){return this.value}set(e){this.value=e}delete(){this.value=null}},Le=class{constructor(e){this.cookieName=e.storageName,this.cookieDomain=e.cookieDomain,this.cookiePath=e.cookiePath,this.cookieExpiresAfterDays=e.cookieExpiresAfterDays}get(){let e=Te(this.cookieName);return e?e.value:null}set(e){ot(this.cookieName,e,this.cookieExpiresAfterDays,this.cookieDomain,this.cookiePath)}delete(){J(this.cookieName)}},ge=class{constructor(e,t){this.key=e.storageName,this.handle=t}get(){return this.handle.getItem(this.key)}getWithKey(e){return this.handle.getItem(e)}set(e){this.handle.setItem(this.key,e)}setWithKey(e,t){this.handle.setItem(e,t)}delete(){this.handle.removeItem(this.key)}deleteWithKey(e){this.handle.removeItem(e)}},je=class extends ge{constructor(e){super(e,localStorage)}},X=class extends ge{constructor(e){super(e,sessionStorage)}},yi={cookie:Le,test:Re,localStorage:je,sessionStorage:X},Ie=yi;function st(r){let e={};for(let t of Array.from(r.attributes))t.name.startsWith("data-")&&(e[t.name.slice(5)]=t.value);return e}function at(r,e){for(let[t,i]of Object.entries(r))e[t]!==i&&e.setAttribute(`data-${t}`,i)}function bi(r){return r.replace(/[-[\]/{}()*+?.\\^$|]/g,"\\$&")}function ct(r,e){return r===void 0?void 0:typeof r=="function"?r(e):new Function("opts",r)(e)}var Y=class{constructor(e,t,i){this.confirmed=!1;this.changed=!1;this.states={};this.initialized={};this.executedOnce={};this.watchers=new Set;this.config=e;let n={storageName:this.storageName,cookieDomain:this.cookieDomain,cookiePath:this.cookiePath,cookieExpiresAfterDays:this.cookieExpiresAfterDays};if(t!==void 0)this.store=t;else{let o=Ie[this.storageMethod]??Ie.cookie;this.store=new o(n)}this.auxiliaryStore=i??new X(n),this.consents=this.defaultConsents,this.loadConsents(),this.applyConsents(),this.savedConsents={...this.consents}}get storageMethod(){return this.config.storageMethod??"cookie"}get storageName(){return this.config.storageName??this.config.cookieName??"klaro"}get cookieDomain(){return this.config.cookieDomain}get cookiePath(){return this.config.cookiePath}get cookieExpiresAfterDays(){return this.config.cookieExpiresAfterDays??120}get defaultConsents(){let e={};for(let t of this.config.services)e[t.name]=this.getDefaultConsent(t);return e}watch(e){this.watchers.add(e)}unwatch(e){this.watchers.delete(e)}notify(e,t){for(let i of this.watchers)i.update(this,e,t)}getService(e){return this.config.services.find(t=>t.name===e)}getDefaultConsent(e){if(this.config.respectGPC!==!1&&!e.required&&typeof navigator<"u"&&navigator.globalPrivacyControl===!0)return!1;let t=e.default||e.required;return t===void 0&&(t=this.config.default),t===void 0&&(t=!1),t}changeAll(e){let t=0;for(let i of this.config.services.filter(n=>!n.contextualConsentOnly)){let o=i.required??this.config.required??!1?!0:e;this.updateConsent(i.name,o)&&t++}return t}updateConsent(e,t){let i=(this.consents[e]||!1)!==t;return this.consents[e]=t,this.notify("consents",this.consents),i}resetConsents(){this.consents=this.defaultConsents,this.states={},this.confirmed=!1,this.applyConsents(),this.savedConsents={...this.consents},this.store.delete(),this.notify("consents",this.consents)}getConsent(e){return this.consents[e]||!1}loadConsents(){let e=this.store.get();if(e===null)return this.consents;let t=JSON.parse(decodeURIComponent(e)),i;if(t!==null&&typeof t=="object"&&"__v"in t&&"consents"in t){let o=t;i=o.__v,this.consents=o.consents}else this.consents=t;let n=this.config.consentVersion;return n!==void 0&&i!==void 0&&!this._versionsCompatible(i,n)?(this.versionMismatch={storedVersion:i,configVersion:n,policy:this.config.consentVersionPolicy??"any"},this.consents=this.defaultConsents,this.confirmed=!1,this.changed=!0):(this._checkConsents(),this.notify("consents",this.consents)),this.consents}_versionsCompatible(e,t){let i=String(e),n=String(t);return(this.config.consentVersionPolicy??"any")==="major"?i.split(".")[0]===n.split(".")[0]:i===n}saveAndApplyConsents(e){this.saveConsents(e),this.applyConsents()}changedConsents(){let e={};for(let[t,i]of Object.entries(this.consents))this.savedConsents[t]!==i&&(e[t]=i);return e}saveConsents(e){let t=this.config.consentVersion!==void 0?{__v:this.config.consentVersion,consents:this.consents}:this.consents,i=encodeURIComponent(JSON.stringify(t));this.store.set(i),this.confirmed=!0,this.changed=!1,this.versionMismatch=void 0;let n=this.changedConsents();this.savedConsents={...this.consents},this.notify("saveConsents",{changes:n,consents:this.consents,type:e??"script"})}applyConsents(e,t,i){let n=0;for(let o of this.config.services){if(i!==void 0&&i!==o.name)continue;let s=o.vars??{},a={service:o,config:this.config,vars:s};this.initialized[o.name]||(this.initialized[o.name]=!0,ct(o.onInit,a))}for(let o of this.config.services){if(i!==void 0&&i!==o.name)continue;let s=this.states[o.name],a=o.vars??{},c=o.optOut!==void 0?o.optOut:this.config.optOut??!1,p=o.required!==void 0?o.required:this.config.required??!1,f=this.confirmed||c||e||t||!1,m=this.getConsent(o.name)&&f||p,u={service:o,config:this.config,vars:a,consents:this.consents,confirmed:this.confirmed};s!==m&&n++,!e&&(ct(m?o.onAccept:o.onDecline,u),this.updateServiceElements(o,m),this.updateServiceStorage(o,m),o.callback!==void 0&&o.callback(m,o),this.config.callback!==void 0&&this.config.callback(m,o),this.states[o.name]=m)}if(!e&&typeof document<"u"){let o=new Set(this.config.services.map(a=>a.name)),s=new Set;for(let a of Array.from(document.querySelectorAll("[data-name]"))){let c=a.getAttribute("data-name");if(c===null||c===""||o.has(c)||s.has(c)||i!==void 0&&i!==c)continue;s.add(c);let p={name:c,purposes:[]},f=this.getConsent(c);this.updateServiceElements(p,f)}}return this.notify("applyConsents",{changedServices:n,serviceName:i}),n}updateServiceElements(e,t){if(typeof document>"u")return;if(t){if(e.onlyOnce&&this.executedOnce[e.name])return;this.executedOnce[e.name]=!0}let i=document.querySelectorAll(`[data-name='${e.name}']`);for(let n of Array.from(i)){let o=n.parentElement;if(!o)continue;let s=st(n),{type:a,src:c,href:p}=s,f=["href","src","type"];if(a==="placeholder"){t?(n.style.display="none",s["original-display"]=n.style.display):n.style.display=s["original-display"]||"block";continue}if(n.tagName==="IFRAME"){if(t&&n.src===c){console.debug(`Skipping ${n.tagName} for service ${e.name}, as it already has the correct type...`);continue}let u=document.createElement(n.tagName);for(let v of Array.from(n.attributes))if(v.name==="style"){let[H="",ui=""]=v.value.split(":");u.style[H.trim()]=ui.trim()}else u.setAttribute(v.name,v.value);u.innerText=n.innerText,u.text=n.text,t?(s["original-display"]!==void 0&&(u.style.display=s["original-display"]),s.src!==void 0&&(u.src=s.src)):(u.src="about:blank",s["modified-by-klaro"]!==void 0&&s["original-display"]!==void 0?u.setAttribute("data-original-display",s["original-display"]):(n.style.display!==void 0&&u.setAttribute("data-original-display",n.style.display),u.setAttribute("data-modified-by-klaro","yes")),u.style.display="none"),o.insertBefore(u,n),o.removeChild(n),this._toggleAutoPlaceholder(u,e,t)}else if(n.tagName==="SCRIPT"||n.tagName==="LINK"){let m=n;if(t&&m.type===(a??"")&&m.src===c){console.debug(`Skipping ${n.tagName} for service ${e.name}, as it already has the correct type or src...`);continue}let u=document.createElement(n.tagName);for(let v of Array.from(n.attributes))u.setAttribute(v.name,v.value);n.hasAttribute("nonce")&&u.setAttribute("nonce",n.nonce??""),u.innerText=n.innerText,u.text=n.text,t?(u.type=a??"",c!==void 0&&(u.src=c),p!==void 0&&(u.href=p)):u.type="text/plain",o.insertBefore(u,n),o.removeChild(n),this._toggleAutoPlaceholder(u,e,t)}else{let m=n;if(t){for(let u of f){let v=s[u];v!==void 0&&(s[`original-${u}`]===void 0&&(s[`original-${u}`]=m[u]??""),m[u]=v)}s.title!==void 0&&(n.title=s.title),s["original-display"]!==void 0?n.style.display=s["original-display"]:n.style.removeProperty("display")}else{s.title!==void 0&&n.removeAttribute("title"),s["original-display"]===void 0&&n.style.display!==void 0&&(s["original-display"]=n.style.display),n.style.display="none";for(let u of f)s[u]!==void 0&&(s[`original-${u}`]!==void 0?m[u]=s[`original-${u}`]:n.removeAttribute(u))}at(s,n),this._toggleAutoPlaceholder(n,e,t)}}}_toggleAutoPlaceholder(e,t,i){if(typeof document>"u")return;let n=e.nextElementSibling,o=n?.hasAttribute("data-simplecmp-auto-placeholder")&&n.getAttribute("data-simplecmp-for")===t.name?n:null;if(i){o!==null&&o.remove();return}if(this.config.autoContextualPlaceholder===!1||t.noAutoPlaceholder===!0||e.hasAttribute("data-no-placeholder")||o!==null)return;let s=document.createElement("simplecmp-contextual-notice");s.setAttribute("service-name",t.name),s.setAttribute("data-simplecmp-auto-placeholder",""),s.setAttribute("data-simplecmp-for",t.name);let a=e.getAttribute("data-blocked-source");a!==null&&s.setAttribute("data-blocked-source",a);for(let p of["data-simplecmp-title","data-simplecmp-description"]){let f=e.getAttribute(p);f!==null&&s.setAttribute(p,f)}let c=s;c.serviceName=t.name,c.manager=this,c.config=this.config,e.insertAdjacentElement("afterend",s)}updateServiceStorage(e,t){if(t||!e.cookies||e.cookies.length===0||typeof window>"u"||typeof document>"u")return;let i=De();for(let n of e.cookies){let o=n,s,a;if(Array.isArray(o))[o,s,a]=o;else if(o!==null&&typeof o=="object"&&!(o instanceof RegExp)){let p=o;o=p.pattern,s=p.path,a=p.domain}if(o===void 0)continue;let c;if(o instanceof RegExp)c=o;else if(typeof o=="string")c=o.startsWith("^")?new RegExp(o):new RegExp(`^${bi(o)}$`);else continue;for(let p of i){if(c.exec(p.name)===null)continue;console.debug("Deleting cookie:",p.name,"Matched pattern:",c,"Path:",s,"Domain:",a);let f=J(p.name,s,a);!f&&a===void 0&&(f=J(p.name,s,`.${window.location.hostname}`)),f||console.warn(`SimpleCMP: cookie "${p.name}" still present after deletion attempt for service "${e.name}". It may be set on a path/domain we cannot reach from JS, or another script re-set it.`)}}}_checkConsents(){let e=!0,t=new Set(this.config.services.map(n=>n.name)),i=new Set(Object.keys(this.consents));for(let n of Object.keys(this.consents))t.has(n)||delete this.consents[n];for(let n of this.config.services)i.has(n.name)||(this.consents[n.name]=this.getDefaultConsent(n),e=!1);this.confirmed=e,e||(this.changed=!0)}};function lt(r){let e=new Set;for(let t of r.services){let i=t.purposes??[];for(let n of i)e.add(n)}return Array.from(e)}function ee(r,e,t=!0){let i=r;for(let n of Object.keys(e)){let o=e[n],s=i[n];typeof o=="string"?(t||s===void 0)&&(i[n]=o):typeof o=="object"&&o!==null&&(typeof s=="object"&&s!==null?ee(s,o,t):(t||s===void 0)&&(i[n]=o))}return r}function ki(r,...e){let t=e[0],i;e.length===0?i={}:typeof t=="string"||typeof t=="number"?i=Array.prototype.slice.call(e):i=t??{};let n=[],o=String(r);for(;o.length>0;){let s=o.match(/\{(?!\{)([\w\d]+)\}(?!\})/);if(s===null||s.index===void 0||s[1]===void 0){n.push(o),o="";break}let a=o.substring(0,s.index);o=o.substring(s.index+s[0].length),n.push(a);let c=Number.parseInt(s[1],10);Number.isNaN(c)?n.push(i[s[1]]):n.push(i[c])}return n}function Ne(r){if(r?.lang!==void 0&&r.lang!=="zz")return r.lang;let e=typeof window<"u"?window:void 0,t=typeof document<"u"?document.documentElement.lang:void 0,i=r?.languages?.[0],n=((typeof e?.language=="string"?e.language:null)||t||i||"en").toLowerCase(),s=/^([\w]+)-([\w]+)$/.exec(n);return s===null||s[1]===void 0?n:s[1]}function pt(r,e,t){let i=Array.isArray(e)?e:[e],n=r;for(let o of i){if(n===void 0)return t;if(typeof o=="string"&&o.endsWith("?")){let s=o.slice(0,-1),a=n instanceof Map?n.get(s):n[s];typeof a=="string"&&(n=a)}else if(n instanceof Map)n=n.get(o);else if(n!==null&&typeof n=="object")n=n[o];else return t}if(typeof n!="string")return t;if(n!=="")return n}function dt(r,e,t,i,...n){let o=i,s=!1;o[0]==="!"&&(o=o.slice(1),s=!0),Array.isArray(o)||(o=[o]);let a=pt(r,[e,...o]);return a===void 0&&t!==void 0&&(a=pt(r,[t,...o])),a===void 0?s?void 0:[`[missing translation: ${e}/${o.join("/")}]`]:n.length>0?ki(a,...n):a}function te(r){let e=new Map;for(let t of Object.keys(r)){let i=r[t];typeof i=="string"||i===null?e.set(t,i):typeof i=="object"&&i!==null&&e.set(t,te(i))}return e}function L(r,e,t=!0,i=!1){if(!(e instanceof Map)||!(r instanceof Map))throw new Error("Parameters are not maps!");let n=i?new Map(r):r,o=(s,a,c)=>{if(c instanceof Map){let p=new Map;L(p,c,!0,!1),s.set(a,p)}else s.set(a,c)};for(let s of e.keys()){let a=e.get(s),c=n.get(s);n.has(s)?a instanceof Map&&c instanceof Map?n.set(s,L(c,a,t,i)):t&&o(n,s,a):o(n,s,a)}return n}var wi,Be=new Map,ie={},ve={},ye={};function ut(r,e){ve[r]===void 0?ve[r]=[e]:ve[r].push(e);let t=ye[r];if(t!==void 0){for(let i of t)if(e(...i)===!1)break}}function Ue(r,...e){let t=ve[r];if(ye[r]===void 0?ye[r]=[e]:ye[r].push(e),t!==void 0){for(let i of t)if(i(...e)===!0)return!0}}function Si(r){let e={...r};if(e.version===2)return e;if(e.apps!==void 0&&e.services===void 0&&(e.services=e.apps,console.warn("Warning, your configuration file is outdated. Please change `apps` to `services`"),delete e.apps),e.translations!==void 0&&typeof e.translations=="object"&&e.translations!==null){let t=e.translations;t.apps!==void 0&&t.services===void 0&&(t.services=t.apps,console.warn("Warning, your configuration file is outdated. Please change `apps` to `services` in the `translations` key"),delete t.apps)}return e}function mt(r){let e=new Map;L(e,Be);let t=r.translations??{};return L(e,te(t)),e}function ne(r){let e=r??wi;if(!e)throw new Error("SimpleCMP getManager called without config and no default config set");let t=e.storageName??e.cookieName??"default";return ie[t]===void 0&&(ie[t]=new Y(Si(e)),ie[t].versionMismatch!==void 0&&Ue("consentVersionMismatch",ie[t].versionMismatch)),ie[t]}var ft={privacyPolicy:{name:"\u043F\u043E\u043B\u0438\u0442\u0438\u043A\u0430 \u043D\u0430 \u043F\u043E\u0432\u0435\u0440\u0438\u0442\u0435\u043B\u043D\u043E\u0441\u0442",text:'\u0417\u0430 \u0434\u0430 \u0440\u0430\u0437\u0431\u0435\u0440\u0435\u0442\u0435 \u043F\u043E\u0432\u0435\u0447\u0435, \u043C\u043E\u043B\u044F \u043F\u0440\u043E\u0447\u0435\u0442\u0435\u0442\u0435 \u043D\u0430\u0448\u0430\u0442\u0430 <tr-hint v="privacy policy">{privacyPolicy}</tr-hint>.'},consentModal:{title:"\u0423\u0441\u043B\u0443\u0433\u0438, \u043A\u043E\u0438\u0442\u043E \u0431\u0438\u0445\u0435\u043C \u0438\u0441\u043A\u0430\u043B\u0438 \u0434\u0430 \u0438\u0437\u043F\u043E\u043B\u0437\u0432\u0430\u043C\u0435",description:"\u0422\u0443\u043A \u043C\u043E\u0436\u0435\u0442\u0435 \u0434\u0430 \u043E\u0446\u0435\u043D\u0438\u0442\u0435 \u0438 \u043F\u0435\u0440\u0441\u043E\u043D\u0430\u043B\u0438\u0437\u0438\u0440\u0430\u0442\u0435 \u0443\u0441\u043B\u0443\u0433\u0438\u0442\u0435, \u043A\u043E\u0438\u0442\u043E \u0431\u0438\u0445\u043C\u0435 \u0438\u0441\u043A\u0430\u043B\u0438 \u0434\u0430 \u0438\u0437\u043F\u043E\u043B\u0437\u0432\u0430\u0442\u0435 \u043D\u0430 \u0442\u043E\u0437\u0438 \u0443\u0435\u0431\u0441\u0430\u0439\u0442. \u0412\u0438\u0435 \u043E\u0442\u0433\u043E\u0432\u0430\u0440\u044F\u0442\u0435 \u0437\u0430 \u0442\u043E\u0432\u0430! \u0420\u0430\u0437\u0440\u0435\u0448\u0430\u0432\u0430\u0439\u0442\u0435 \u0438\u043B\u0438 \u0437\u0430\u0431\u0440\u0430\u043D\u044F\u0432\u0430\u0439\u0442\u0435 \u0443\u0441\u043B\u0443\u0433\u0438\u0442\u0435, \u043A\u0430\u043A\u0442\u043E \u043D\u0430\u043C\u0435\u0440\u0438\u0442\u0435 \u0437\u0430 \u0434\u043E\u0431\u0440\u0435."},consentNotice:{testing:"\u0422\u0435\u0441\u0442\u043E\u0432 \u043C\u043E\u0434!",title:"\u0421\u044A\u0433\u043B\u0430\u0441\u0438\u0435 \u0437\u0430 \u0438\u0437\u043F\u043E\u043B\u0437\u0432\u0430\u043D\u0435 \u043D\u0430 \u0431\u0438\u0441\u043A\u0432\u0438\u0442\u043A\u0438",changeDescription:"\u0418\u043C\u0430 \u043F\u0440\u043E\u043C\u0435\u043D\u0438 \u0441\u043B\u0435\u0434 \u043F\u043E\u0441\u043B\u0435\u0434\u043D\u043E\u0442\u043E \u0412\u0438 \u043F\u043E\u0441\u0435\u0449\u0435\u043D\u0438\u0435, \u043C\u043E\u043B\u044F, \u043F\u043E\u0434\u043D\u043E\u0432\u0435\u0442\u0435 \u0441\u044A\u0433\u043B\u0430\u0441\u0438\u0435\u0442\u043E \u0441\u0438.",description:"\u0417\u0434\u0440\u0430\u0432\u0435\u0439\u0442\u0435! \u041C\u043E\u0436\u0435\u043C \u043B\u0438 \u0434\u0430 \u0440\u0430\u0437\u0440\u0435\u0448\u0438\u043C \u043D\u044F\u043A\u043E\u0438 \u0434\u043E\u043F\u044A\u043B\u043D\u0438\u0442\u0435\u043B\u043D\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u0437\u0430 {purposes}? \u0412\u0438\u043D\u0430\u0433\u0438 \u043C\u043E\u0436\u0435\u0442\u0435 \u0434\u0430 \u043F\u0440\u043E\u043C\u0435\u043D\u0438\u0442\u0435 \u0438\u043B\u0438 \u043E\u0442\u0442\u0435\u0433\u043B\u0438\u0442\u0435 \u0441\u044A\u0433\u043B\u0430\u0441\u0438\u0435\u0442\u043E \u0441\u0438 \u043F\u043E-\u043A\u044A\u0441\u043D\u043E.","learnMore|capitalize":"\u041D\u0435\u043A\u0430 \u0434\u0430 \u0438\u0437\u0431\u0435\u0440\u0430"},purposes:{functional:{"title|capitalize":"\u041F\u0440\u0435\u0434\u043E\u0441\u0442\u0430\u0432\u044F\u043D\u0435 \u043D\u0430 \u0443\u0441\u043B\u0443\u0433\u0438",description:`\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u0441\u0430 \u043E\u0442 \u0441\u044A\u0449\u0435\u0441\u0442\u0432\u0435\u043D\u043E \u0437\u043D\u0430\u0447\u0435\u043D\u0438\u0435 \u0437\u0430 \u043F\u0440\u0430\u0432\u0438\u043B\u043D\u043E\u0442\u043E \u0444\u0443\u043D\u043A\u0446\u0438\u043E\u043D\u0438\u0440\u0430\u043D\u0435 \u043D\u0430 \u0442\u043E\u0437\u0438 \u0443\u0435\u0431\u0441\u0430\u0439\u0442. \u041D\u0435 \u043C\u043E\u0436\u0435\u0442\u0435 \u0434\u0430 \u0433\u0438 \u0434\u0435\u0430\u043A\u0442\u0438\u0432\u0438\u0440\u0430\u0442\u0435 \u0442\u0443\u043A, \u0442\u044A\u0439 \u043A\u0430\u0442\u043E \u0432 \u043F\u0440\u043E\u0442\u0438\u0432\u0435\u043D \u0441\u043B\u0443\u0447\u0430\u0439 \u0443\u0441\u043B\u0443\u0433\u0430\u0442\u0430 \u043D\u044F\u043C\u0430 \u0434\u0430 \u0440\u0430\u0431\u043E\u0442\u0438 \u043F\u0440\u0430\u0432\u0438\u043B\u043D\u043E.
`},performance:{"title|capitalize":"\u041E\u043F\u0442\u0438\u043C\u0438\u0437\u0438\u0440\u0430\u043D\u0435 \u043D\u0430 \u043F\u0440\u043E\u0438\u0437\u0432\u043E\u0434\u0438\u0442\u0435\u043B\u043D\u043E\u0441\u0442\u0442\u0430",description:`\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u043E\u0431\u0440\u0430\u0431\u043E\u0442\u0432\u0430\u0442 \u043B\u0438\u0447\u043D\u0430 \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044F, \u0437\u0430 \u0434\u0430 \u043E\u043F\u0442\u0438\u043C\u0438\u0437\u0438\u0440\u0430\u0442 \u0443\u0441\u043B\u0443\u0433\u0438\u0442\u0435, \u043A\u043E\u0438\u0442\u043E \u043F\u0440\u0435\u0434\u043B\u0430\u0433\u0430 \u0442\u043E\u0437\u0438 \u0443\u0435\u0431\u0441\u0430\u0439\u0442.
`},marketing:{"title|capitalize":"\u041C\u0430\u0440\u043A\u0435\u0442\u0438\u043D\u0433",description:"\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u043E\u0431\u0440\u0430\u0431\u043E\u0442\u0432\u0430\u0442 \u043B\u0438\u0447\u043D\u0430 \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044F, \u0437\u0430 \u0434\u0430 \u0432\u0438 \u043F\u043E\u043A\u0430\u0437\u0432\u0430\u0442 \u043F\u043E\u0434\u0445\u043E\u0434\u044F\u0449\u043E \u0441\u044A\u0434\u044A\u0440\u0436\u0430\u043D\u0438\u0435 \u0437\u0430 \u043F\u0440\u043E\u0434\u0443\u043A\u0442\u0438, \u0443\u0441\u043B\u0443\u0433\u0438 \u0438\u043B\u0438 \u0442\u0435\u043C\u0438, \u043A\u043E\u0438\u0442\u043E \u043C\u043E\u0436\u0435 \u0434\u0430 \u0432\u0438 \u0438\u043D\u0442\u0435\u0440\u0435\u0441\u0443\u0432\u0430\u0442."},advertising:{"title|capitalize":"\u0420\u0435\u043A\u043B\u0430\u043C\u0438\u0440\u0430\u043D\u0435",description:"\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u043E\u0431\u0440\u0430\u0431\u043E\u0442\u0432\u0430\u0442 \u043B\u0438\u0447\u043D\u0430 \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044F, \u0437\u0430 \u0434\u0430 \u0432\u0438 \u043F\u043E\u043A\u0430\u0437\u0432\u0430\u0442 \u043F\u0435\u0440\u0441\u043E\u043D\u0430\u043B\u0438\u0437\u0438\u0440\u0430\u043D\u0438 \u0438\u043B\u0438 \u0431\u0430\u0437\u0438\u0440\u0430\u043D\u0438 \u043D\u0430 \u0438\u043D\u0442\u0435\u0440\u0435\u0441\u0438 \u0440\u0435\u043A\u043B\u0430\u043C\u0438."}},purposeItem:{service:"\u041F\u0440\u043E\u0441\u0442\u0430 <tr-snip>\u0443\u0441\u043B\u0443\u0433\u0430</tr-snip>, \u043A\u043E\u044F\u0442\u043E \u0438\u043D\u0441\u0442\u0430\u043B\u0438\u0440\u0430\u043C \u043D\u0430 \u043A\u043E\u043C\u043F\u044E\u0442\u044A\u0440\u0430 \u0441\u0438.",services:"\u041D\u044F\u043A\u043E\u043B\u043A\u043E \u043F\u0440\u043E\u0441\u0442\u0438 <tr-snip>\u0443\u0441\u043B\u0443\u0433\u0438</tr-snip>, \u043A\u043E\u0438\u0442\u043E \u0438\u043D\u0441\u0442\u0430\u043B\u0438\u0440\u0430\u043C \u043D\u0430 \u043A\u043E\u043C\u043F\u044E\u0442\u044A\u0440\u0430 \u0441\u0438."},"ok|capitalize":"\u0421\u044A\u0433\u043B\u0430\u0441\u0435\u043D \u0441\u044A\u043C","save|capitalize":"\u0437\u0430\u043F\u0430\u0437\u0438","decline|capitalize":"\u041E\u0442\u043A\u0430\u0437\u0432\u0430\u043C","close|capitalize":"\u0417\u0430\u0442\u0432\u0430\u0440\u044F\u043D\u0435","acceptAll|capitalize":"\u041F\u043E\u0437\u0432\u043E\u043B\u044F\u0432\u0430\u043D\u0435 \u043D\u0430 \u0432\u0441\u0438\u0447\u043A\u0438","acceptSelected|capitalize":"\u041F\u043E\u0437\u0432\u043E\u043B\u0438 \u0437\u0430 \u0438\u0437\u0431\u0440\u0430\u043D\u0438\u0442\u0435",service:{disableAll:{"title|capitalize":"\u0440\u0430\u0437\u0440\u0435\u0448\u0430\u0432\u0430\u043D\u0435 \u0438\u043B\u0438 \u0437\u0430\u0431\u0440\u0430\u043D\u044F\u0432\u0430\u043D\u0435 \u043D\u0430 \u0432\u0441\u0438\u0447\u043A\u0438 \u0443\u0441\u043B\u0443\u0433\u0438",description:"\u0418\u0437\u043F\u043E\u043B\u0437\u0432\u0430\u0439\u0442\u0435 \u0442\u043E\u0437\u0438 \u0431\u0443\u0442\u043E\u043D\u0438, \u0437\u0430 \u0434\u0430 \u0440\u0430\u0437\u0440\u0435\u0448\u0438\u0442\u0435 \u0438\u043B\u0438 \u0437\u0430\u0431\u0440\u0430\u043D\u0438\u0442\u0435 \u0432\u0441\u0438\u0447\u043A\u0438 \u0443\u0441\u043B\u0443\u0433\u0438."},optOut:{title:"(\u0432\u043A\u043B-\u0438\u0437\u043A\u043B)",description:"\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u0441\u0430 \u0432\u043A\u043B\u044E\u0447\u0435\u043D\u0438 \u043F\u043E \u043F\u043E\u0434\u0440\u0430\u0437\u0431\u0438\u0440\u0430\u043D\u0435 (\u043C\u043E\u0436\u0435 \u0434\u0430 \u0433\u0438 \u0432\u043A\u043B-\u0438\u0437\u043A\u043B)"},required:{title:"(\u0438\u0437\u0438\u0441\u043A\u0432\u0430 \u0441\u0435 \u0432\u0438\u043D\u0430\u0433\u0438)",description:"\u0422\u0435\u0437\u0438 \u0443\u0441\u043B\u0443\u0433\u0438 \u0441\u0430 \u0432\u0438\u043D\u0430\u0433\u0438 \u043D\u0435\u043E\u0431\u0445\u043E\u0434\u0438\u043C\u0438"},purposes:"Processing <tr-snip>purposes</tr-snip>",purpose:"Processing <tr-snip>purpose</tr-snip>"},poweredBy:"Realized with Klaro!",contextualConsent:{description:"\u0418\u0441\u043A\u0430\u0442\u0435 \u043B\u0438 \u0434\u0430 \u0437\u0430\u0440\u0435\u0434\u0438\u0442\u0435 \u0432\u044A\u043D\u0448\u043D\u043E \u0441\u044A\u0434\u044A\u0440\u0436\u0430\u043D\u0438\u0435, \u043F\u0440\u0435\u0434\u043E\u0441\u0442\u0430\u0432\u0435\u043D\u043E \u043E\u0442 {title}?",acceptOnce:"\u0414\u0430",acceptAlways:"\u0412\u0438\u043D\u0430\u0433\u0438"}};var ht={acceptAll:"Accepta-les totes",acceptSelected:"Accepta les escollides",service:{disableAll:{description:"Useu aquest bot\xF3 per a habilitar o deshabilitar totes les aplicacions.",title:"Habilita/deshabilita totes les aplicacions"},optOut:{description:"Aquesta aplicaci\xF3 es carrega per defecte, per\xF2 podeu desactivar-la",title:"(opt-out)"},purpose:"Finalitat",purposes:"Finalitats",required:{description:"Aquesta aplicaci\xF3 es necessita sempre",title:"(necess\xE0ria)"}},close:"Tanca",consentModal:{description:"Aqu\xED podeu veure i personalitzar la informaci\xF3 que recopilem sobre v\xF3s.",privacyPolicy:{name:"pol\xEDtica de privadesa",text:"Per a m\xE9s informaci\xF3, consulteu la nostra {privacyPolicy}."},title:"Informaci\xF3 que recopilem"},consentNotice:{changeDescription:"Hi ha hagut canvis des de la vostra darrera visita. Actualitzeu el vostre consentiment.",description:"Recopilem i processem la vostra informaci\xF3 personal amb les seg\xFCents finalitats: {purposes}.",imprint:{name:"Empremta"},learnMore:"Saber-ne m\xE9s",privacyPolicy:{name:"pol\xEDtica de privadesa"}},decline:"Rebutja",ok:"Accepta",poweredBy:"Funciona amb Klaro!",purposeItem:{service:"aplicaci\xF3",services:"aplicacions"},save:"Desa"};var gt={privacyPolicy:{name:"z\xE1sady ochrany soukrom\xED",text:'Pro dal\u0161\xED informace si p\u0159e\u010Dtete na\u0161e <tr-hint v="privacy policy">{privacyPolicy}</tr-hint>.'},consentModal:{title:"Slu\u017Eby, kter\xE9 bychom r\xE1di vyu\u017Eili",description:"Zde m\u016F\u017Eete posoudit a p\u0159izp\u016Fsobit slu\u017Eby, kter\xE9 bychom r\xE1di na tomto webu pou\u017E\xEDvali. M\xE1te to pod kontrolou! Povolte nebo zaka\u017Ete slu\u017Eby, jak uzn\xE1te za vhodn\xE9."},consentNotice:{testing:"Testing mode!",changeDescription:"Od va\u0161\xED posledn\xED n\xE1v\u0161t\u011Bvy do\u0161lo ke zm\u011Bn\xE1m, obnovte pros\xEDm sv\u016Fj souhlas.",description:"\u201EDobr\xFD den! M\u016F\u017Eeme povolit n\u011Bkter\xE9 dal\u0161\xED slu\u017Eby pro {purposes}? Sv\u016Fj souhlas m\u016F\u017Eete kdykoliv zm\u011Bnit nebo odvolat.\u201C","learnMore|capitalize":"Vyberu si"},purposes:{functional:{"title|capitalize":"Poskytov\xE1n\xED slu\u017Eeb",description:`Tyto slu\u017Eby jsou nezbytn\xE9 pro spr\xE1vn\xE9 fungov\xE1n\xED tohoto webu. Nelze je zde deaktivovat, proto\u017Ee slu\u017Eba by jinak nefungovala spr\xE1vn\u011B.
`},performance:{"title|capitalize":"Optimalizace v\xFDkonu",description:`V r\xE1mci t\u011Bchto slu\u017Eeb jsou zpracov\xE1v\xE1ny osobn\xED \xFAdaje za \xFA\u010Delem optimalizace slu\u017Eeb, kter\xE9 jsou na tomto webu poskytov\xE1ny.
`},marketing:{"title|capitalize":"Marketing",description:"V r\xE1mci t\u011Bchto slu\u017Eeb jsou zpracov\xE1v\xE1ny osobn\xED \xFAdaje, aby se v\xE1m zobrazoval relevantn\xED obsah o produktech, slu\u017Eb\xE1ch nebo t\xE9matech, kter\xE9 by v\xE1s mohly zaj\xEDmat."},advertising:{"title|capitalize":"Reklama",description:"V r\xE1mci t\u011Bchto slu\u017Eeb jsou zpracov\xE1v\xE1ny osobn\xED \xFAdaje, aby v\xE1m zobrazovaly personalizovan\xE9 nebo z\xE1jmov\u011B orientovan\xE9 reklamy."}},purposeItem:{service:"Jednoduch\xE1 slu\u017Eba <tr-snip></tr-snip> , kterou nainstaluji do sv\xE9ho po\u010D\xEDta\u010De.",services:"N\u011Bkolik jednoduch\xFDch slu\u017Eeb <tr-snip></tr-snip> , kter\xE9 nainstaluji do sv\xE9ho po\u010D\xEDta\u010De."},"ok|capitalize":"To je v po\u0159\xE1dku",save:"ulo\u017Eit","decline|capitalize":"Nep\u0159ij\xEDm\xE1m",close:"zav\u0159\xEDt",acceptAll:"p\u0159ijmout v\u0161e",acceptSelected:"p\u0159ijmout vybran\xE9",service:{disableAll:{title:"povolit nebo zak\xE1zat v\u0161echny slu\u017Eby",description:"Pomoc\xED tohoto p\u0159ep\xEDna\u010De m\u016F\u017Eete povolit nebo zak\xE1zat v\u0161echny slu\u017Eby."},optOut:{title:"(opt-out)",description:"Tato slu\u017Eba se na\u010D\xEDt\xE1 ve v\xFDchoz\xEDm nastaven\xED (ale m\u016F\u017Eete ji zru\u0161it)"},required:{title:"(v\u017Edy vy\u017Eadov\xE1no)",description:"Tato slu\u017Eba je v\u017Edy vy\u017Eadov\xE1na"},purposes:"Zpracov\xE1n\xED  pro \xFA\u010Dely <tr-snip></tr-snip>",purpose:"Zpracov\xE1n\xED pro \xFA\u010Dely <tr-snip></tr-snip>"},poweredBy:"Realizov\xE1no pomoc\xED Klaro!",contextualConsent:{description:"Chcete na\u010D\xEDst extern\xED obsah dod\xE1van\xFD prost\u0159ednictv\xEDm {title}?",acceptOnce:"Ano",acceptAlways:"V\u017Edy"}};var vt={acceptAll:"Tillad alle",acceptSelected:"Tillad udvalgte",service:{disableAll:{description:"Brug denne kontakt til at aktivere/deaktivere alle apps.",title:"Aktiver/deaktiver alle applikatione"},optOut:{description:"Denne applikation indl\xE6ses som standard (men du kan deaktivere den)",title:"Opt-Out"},purpose:"Form\xE5l",purposes:"Form\xE5l",required:{description:"Denne applikation er altid n\xF8dvendig",title:"(Altid n\xF8dvendig)"}},close:"Luk",consentModal:{description:"Her kan du se og \xE6ndre, hvilke informationer vi gemmer om dig.",privacyPolicy:{name:"Flere informationer finde du under {privacyPolicy}",text:"databeskyttelseserkl\xE6ring."},title:"Informationer, som vi gemmer"},consentNotice:{changeDescription:"Der har v\xE6ret \xE6ndringer siden dit sidste bes\xF8g. Opdater dit valg.",description:"Vi gemmer og behandler dine personlige oplysninger til f\xF8lgende form\xE5l: {purposes}.",imprint:{name:""},learnMore:"L\xE6s mere",privacyPolicy:{name:"Datenschutzerkl\xE4rung"}},decline:"Afvis",ok:"Ok",poweredBy:"Realiseret med Klaro!",purposeItem:{service:"",services:""},save:"Gem"};var yt={acceptAll:"Alle akzeptieren",acceptSelected:"Ausgew\xE4hlte akzeptieren",close:"Schlie\xDFen",consentModal:{description:"Hier k\xF6nnen Sie die Dienste, die wir auf dieser Website nutzen m\xF6chten, bewerten und anpassen. Sie haben das Sagen! Aktivieren oder deaktivieren Sie die Dienste, wie Sie es f\xFCr richtig halten.",privacyPolicy:{name:"Datenschutzerkl\xE4rung",text:"Um mehr zu erfahren, lesen Sie bitte unsere {privacyPolicy}."},title:"Dienste, die wir nutzen m\xF6chten"},consentNotice:{changeDescription:"Seit Ihrem letzten Besuch gab es \xC4nderungen, bitte erneuern Sie Ihre Zustimmung.",title:"Cookie-Einstellungen",description:"Hallo! K\xF6nnten wir bitte einige zus\xE4tzliche Dienste f\xFCr {purposes} aktivieren? Sie k\xF6nnen Ihre Zustimmung sp\xE4ter jederzeit \xE4ndern oder zur\xFCckziehen.",imprint:{name:"Impressum"},learnMore:"Lassen Sie mich w\xE4hlen",privacyPolicy:{name:"Datenschutzerkl\xE4rung"},testing:"Testmodus!"},contextualConsent:{acceptAlways:"Immer",acceptOnce:"Ja",description:"M\xF6chten Sie von {title} bereitgestellte externe Inhalte laden?",descriptionEmptyStore:"Um diesem Dienst dauerhaft zustimmen zu k\xF6nnen, m\xFCssen Sie {title} in den {link} zustimmen.",descriptionUnknownHost:"Blockierter Drittinhalt von {title}. Diese Quelle wurde vom Site-Administrator noch nicht freigegeben \u2014 bitte wenden Sie sich an die Administratorin oder den Administrator, um diese Inhalte zu aktivieren.",modalLinkText:"Cookie-Einstellungen",providerInfoLink:"Weitere Informationen \u203A"},providerInfo:{title:"Provider-Informationen",close:"Schlie\xDFen",noData:"Keine Anbieter-Informationen verf\xFCgbar.",field:{vendor:"Anbieter",description:"Beschreibung",address:"Adresse",country:"Land",privacyPolicy:"Datenschutzerkl\xE4rung",optOut:"Opt-Out",partner:"Partner / gemeinsam Verantwortliche"}},decline:"Ich lehne ab",ok:"Das ist ok",poweredBy:"Realisiert mit Klaro!",privacyPolicy:{name:"Datenschutzerkl\xE4rung",text:"Um mehr zu erfahren, lesen Sie bitte unsere {privacyPolicy}."},purposeItem:{service:"Dienst",services:"Dienste"},purposes:{advertising:{description:"Diese Dienste verarbeiten pers\xF6nliche Informationen, um Ihnen personalisierte oder interessenbezogene Werbung zu zeigen.",title:"Werbung"},analytics:{description:"Diese Dienste erfassen, wie Besucher diese Seite nutzen, damit wir ihre Funktion messen und verbessern k\xF6nnen.",title:"Statistik"},functional:{description:`Diese Dienste sind f\xFCr die korrekte Funktion dieser Website unerl\xE4sslich. Sie k\xF6nnen sie hier nicht deaktivieren, da der Dienst sonst nicht richtig funktionieren w\xFCrde.
`,title:"Dienstbereitstellung"},marketing:{description:"Diese Dienste verarbeiten pers\xF6nliche Daten, um Ihnen relevante Inhalte \xFCber Produkte, Dienstleistungen oder Themen zu zeigen, die Sie interessieren k\xF6nnten.",title:"Marketing"},performance:{description:`Diese Dienste verarbeiten personenbezogene Daten, um den von dieser Website angebotenen Service zu optimieren.
`,title:"Optimierung der Leistung"},personalization:{description:"Diese Dienste passen die Inhalte dieser Seite an Ihre Vorlieben und Ihr bisheriges Verhalten an.",title:"Personalisierung"},security:{description:"Diese Dienste sch\xFCtzen diese Website vor Missbrauch \u2014 zum Beispiel durch Erkennung verd\xE4chtigen Datenverkehrs oder Abwehr automatisierter Angriffe.",title:"Sicherheit"}},save:"Speichern",service:{disableAll:{description:"Mit diesem Schalter k\xF6nnen Sie alle Dienste aktivieren oder deaktivieren.",title:"Alle Dienste aktivieren oder deaktivieren"},optOut:{description:"Diese Dienste werden standardm\xE4\xDFig geladen (Sie k\xF6nnen sich jedoch abmelden)",title:"(Opt-out)"},purpose:"Zweck",purposes:"Zwecke",required:{description:"Dieser Service ist immer erforderlich",title:"(immer erforderlich)"}}};var bt={acceptAll:"",acceptAll_en:"Accept all",acceptSelected:"",acceptSelected_en:"Accept selected",service:{disableAll:{description:"\u03A7\u03C1\u03B7\u03C3\u03B9\u03BC\u03BF\u03C0\u03BF\u03AF\u03B7\u03C3\u03B5 \u03B1\u03C5\u03C4\u03CC \u03C4\u03BF\u03BD \u03B4\u03B9\u03B1\u03BA\u03CC\u03C0\u03C4\u03B7 \u03B3\u03B9\u03B1 \u03BD\u03B1 \u03B5\u03BD\u03B5\u03C1\u03B3\u03BF\u03C0\u03BF\u03B9\u03AE\u03C3\u03B5\u03B9\u03C2/\u03B1\u03C0\u03B5\u03BD\u03B5\u03C1\u03B3\u03BF\u03C0\u03BF\u03B9\u03AE\u03C3\u03B5\u03B9\u03C2 \u03CC\u03BB\u03B5\u03C2 \u03C4\u03B9\u03C2 \u03B5\u03C6\u03B1\u03C1\u03BC\u03BF\u03B3\u03AD\u03C2.",title:"\u0393\u03B9\u03B1 \u03CC\u03BB\u03B5\u03C2 \u03C4\u03B9\u03C2 \u03B5\u03C6\u03B1\u03C1\u03BC\u03BF\u03B3\u03AD\u03C2"},optOut:{description:"\u0395\u03AF\u03BD\u03B1\u03B9 \u03C0\u03C1\u03BF\u03BA\u03B1\u03B8\u03BF\u03C1\u03B9\u03C3\u03BC\u03AD\u03BD\u03BF \u03BD\u03B1 \u03C6\u03BF\u03C1\u03C4\u03CE\u03BD\u03B5\u03C4\u03B1\u03B9, \u03AC\u03BB\u03BB\u03B1 \u03BC\u03C0\u03BF\u03C1\u03B5\u03AF \u03BD\u03B1 \u03C0\u03B1\u03C1\u03B1\u03BB\u03B7\u03C6\u03B8\u03B5\u03AF",title:"(\u03BC\u03B7 \u03B1\u03C0\u03B1\u03B9\u03C4\u03BF\u03CD\u03BC\u03B5\u03BD\u03BF)"},purpose:"\u03A3\u03BA\u03BF\u03C0\u03CC\u03C2",purposes:"\u03A3\u03BA\u03BF\u03C0\u03BF\u03AF",required:{description:"\u0394\u03B5\u03BD \u03B3\u03AF\u03BD\u03B5\u03C4\u03B1\u03B9 \u03BD\u03B1 \u03BB\u03B5\u03B9\u03C4\u03BF\u03C5\u03C1\u03B3\u03AE\u03C3\u03B5\u03B9 \u03C3\u03C9\u03C3\u03C4\u03AC \u03B7 \u03B5\u03C6\u03B1\u03C1\u03BC\u03BF\u03B3\u03AE \u03C7\u03C9\u03C1\u03AF\u03C2 \u03B1\u03C5\u03C4\u03CC",title:"(\u03B1\u03C0\u03B1\u03B9\u03C4\u03BF\u03CD\u03BC\u03B5\u03BD\u03BF)"}},close:"\u039A\u03BB\u03B5\u03AF\u03C3\u03B9\u03BC\u03BF",consentModal:{description:"\u0395\u03B4\u03CE \u03BC\u03C0\u03BF\u03C1\u03B5\u03AF\u03C2 \u03BD\u03B1 \u03B4\u03B5\u03B9\u03C2 \u03BA\u03B1\u03B9 \u03BD\u03B1 \u03C1\u03C5\u03B8\u03BC\u03AF\u03C3\u03B5\u03B9\u03C2 \u03C4\u03B9\u03C2 \u03C0\u03BB\u03B7\u03C1\u03BF\u03C6\u03BF\u03C1\u03AF\u03B5\u03C2 \u03C0\u03BF\u03C5 \u03C3\u03C5\u03BB\u03BB\u03AD\u03B3\u03BF\u03C5\u03BC\u03B5 \u03C3\u03C7\u03B5\u03C4\u03B9\u03BA\u03AC \u03BC\u03B5 \u03B5\u03C3\u03AD\u03BD\u03B1.",privacyPolicy:{name:"\u03A0\u03BF\u03BB\u03B9\u03C4\u03B9\u03BA\u03AE \u0391\u03C0\u03BF\u03C1\u03C1\u03AE\u03C4\u03BF\u03C5",text:"\u0393\u03B9\u03B1 \u03C0\u03B5\u03C1\u03B9\u03C3\u03C3\u03CC\u03C4\u03B5\u03C1\u03B5\u03C2 \u03C0\u03BB\u03B7\u03C1\u03BF\u03C6\u03BF\u03C1\u03AF\u03B5\u03C2, \u03C0\u03B1\u03C1\u03B1\u03BA\u03B1\u03BB\u03CE \u03B4\u03B9\u03B1\u03B2\u03AC\u03C3\u03C4\u03B5 \u03C4\u03B7\u03BD {privacyPolicy}."},title:"\u03A0\u03BB\u03B7\u03C1\u03BF\u03C6\u03BF\u03C1\u03AF\u03B5\u03C2 \u03C0\u03BF\u03C5 \u03C3\u03C5\u03BB\u03BB\u03AD\u03B3\u03BF\u03C5\u03BC\u03B5"},consentNotice:{changeDescription:"\u03A0\u03C1\u03B1\u03B3\u03BC\u03B1\u03C4\u03BF\u03C0\u03BF\u03B9\u03AE\u03B8\u03B7\u03BA\u03B1\u03BD \u03B1\u03BB\u03BB\u03B1\u03B3\u03AD\u03C2 \u03BC\u03B5\u03C4\u03AC \u03C4\u03B7\u03BD \u03C4\u03B5\u03BB\u03B5\u03C5\u03C4\u03B1\u03AF\u03B1 \u03C3\u03B1\u03C2 \u03B5\u03C0\u03AF\u03C3\u03BA\u03B5\u03C8\u03B7 \u03C0\u03B1\u03C1\u03B1\u03BA\u03B1\u03BB\u03BF\u03CD\u03BC\u03B5 \u03B1\u03BD\u03B1\u03BD\u03B5\u03CE\u03C3\u03C4\u03B5 \u03C4\u03B7\u03BD \u03C3\u03C5\u03B3\u03BA\u03B1\u03C4\u03AC\u03B8\u03B5\u03C3\u03B7 \u03C3\u03B1\u03C2.",description:"\u03A3\u03C5\u03B3\u03BA\u03B5\u03BD\u03C4\u03C1\u03CE\u03BD\u03BF\u03C5\u03BC\u03B5 \u03BA\u03B1\u03B9 \u03B5\u03C0\u03B5\u03BE\u03B5\u03C1\u03B3\u03B1\u03B6\u03CC\u03BC\u03B1\u03C3\u03C4\u03B5 \u03C4\u03B1 \u03C0\u03C1\u03BF\u03C3\u03C9\u03C0\u03B9\u03BA\u03AC \u03B4\u03B5\u03B4\u03BF\u03BC\u03AD\u03BD\u03B1 \u03C3\u03B1\u03C2 \u03B3\u03B9\u03B1 \u03C4\u03BF\u03C5\u03C2 \u03C0\u03B1\u03C1\u03B1\u03BA\u03AC\u03C4\u03C9 \u03BB\u03CC\u03B3\u03BF\u03C5\u03C2: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"\u03A0\u03B5\u03C1\u03B9\u03C3\u03C3\u03CC\u03C4\u03B5\u03C1\u03B1",privacyPolicy:{name:"\u03A0\u03BF\u03BB\u03B9\u03C4\u03B9\u03BA\u03AE \u0391\u03C0\u03BF\u03C1\u03C1\u03AE\u03C4\u03BF\u03C5"}},decline:"\u0391\u03C0\u03CC\u03C1\u03C1\u03B9\u03C0\u03C4\u03C9",ok:"OK",poweredBy:"\u03A5\u03C0\u03BF\u03C3\u03C4\u03B7\u03C1\u03AF\u03B6\u03B5\u03C4\u03B1\u03B9 \u03B1\u03C0\u03CC \u03C4\u03BF Klaro!",purposeItem:{service:"\u03A5\u03C0\u03B7\u03C1\u03B5\u03C3\u03AF\u03B1",services:"\u03A5\u03C0\u03B7\u03C1\u03B5\u03C3\u03AF\u03B5\u03C2"},save:"\u0391\u03C0\u03BF\u03B8\u03AE\u03BA\u03B5\u03C5\u03C3\u03B7"};var kt={acceptAll:"Accept all",acceptSelected:"Accept selected",close:"Close",consentModal:{description:"Here you can assess and customize the services that we'd like to use on this website. You're in charge! Enable or disable services as you see fit.",title:"Services we would like to use"},consentNotice:{changeDescription:"There were changes since your last visit, please renew your consent.",title:"Cookie Consent",description:"Hi! Could we please enable some additional services for {purposes}? You can always change or withdraw your consent later.",imprint:{name:"Imprint"},learnMore:"Let me choose",testing:"Testing mode!"},contextualConsent:{acceptAlways:"Always",acceptOnce:"Yes",description:"Do you want to load external content supplied by {title}?",descriptionEmptyStore:"To agree to this service permanently, you must accept {title} in the {link}.",descriptionUnknownHost:"Blocked third-party content from {title}. The site administrator has not reviewed this source \u2014 please contact them to enable it.",modalLinkText:"Consent Manager",providerInfoLink:"More information \u203A"},providerInfo:{title:"Provider information",close:"Close",noData:"No provider information available.",field:{vendor:"Provider",description:"Description",address:"Address",country:"Country",privacyPolicy:"Privacy policy",optOut:"Opt-out",partner:"Partners / joint controllers"}},decline:"I decline",ok:"That's ok",poweredBy:"Realized with Klaro!",privacyPolicy:{name:"privacy policy",text:"To learn more, please read our {privacyPolicy}."},purposeItem:{service:"service",services:"services"},purposes:{advertising:{description:"These services process personal information to show you personalized or interest-based advertisements.",title:"Advertising"},analytics:{description:"These services collect data about how visitors use this site so we can measure and improve its performance.",title:"Analytics"},functional:{description:`These services are essential for the correct functioning of this website. You cannot disable them here as the service would not work correctly otherwise.
`,title:"Service Provision"},marketing:{description:"These services process personal information to show you relevant content about products, services or topics that you might be interested in.",title:"Marketing"},performance:{description:`These services process personal information to optimize the service that this website offers.
`,title:"Performance Optimization"},personalization:{description:"These services tailor what you see on this site to your preferences and prior interactions.",title:"Personalization"},security:{description:"These services protect this site against abuse \u2014 for example, by detecting suspicious traffic or blocking automated attacks.",title:"Security"}},save:"Save",service:{disableAll:{description:"Use this switch to enable or disable all services.",title:"Enable or disable all services"},optOut:{description:"This services is loaded by default (but you can opt out)",title:"(opt-out)"},purpose:"purpose",purposes:"purposes",required:{description:"This services is always required",title:"(always required)"}}};var wt={acceptAll:"Aceptar todas",acceptSelected:"Aceptar seleccionadas",close:"Cerrar",consentModal:{description:"Aqu\xED puede evaluar y personalizar los servicios que nos gustar\xEDa utilizar en este sitio web. \xA1Usted decide! Habilite o deshabilite los servicios como considere oportuno.",privacyPolicy:{name:"pol\xEDtica de privacidad",text:"Para saber m\xE1s, por favor lea nuestra {privacyPolicy}."},title:"Servicios que nos gustar\xEDa utilizar"},consentNotice:{changeDescription:"Ha habido cambios en las cookies desde su \xFAltima visita. Debe renovar su consentimiento.",description:"\xA1Hola! \xBFPodr\xEDamos habilitar algunos servicios adicionales para {purposes}? Siempre podr\xE1 cambiar o retirar su consentimiento m\xE1s tarde.",imprint:{name:"Imprimir"},learnMore:"Quiero elegir",privacyPolicy:{name:"pol\xEDtica de privacidad"},testing:"\xA1Modo de prueba!"},contextualConsent:{acceptAlways:"Siempre",acceptOnce:"S\xED",description:"\xBFQuieres cargar el contenido externo suministrado por {title}?"},decline:"Descartar todas",ok:"De acuerdo",poweredBy:"\xA1Realizado con Klaro!",privacyPolicy:{name:"pol\xEDtica de privacidad",text:"Para saber m\xE1s, por favor lea nuestra {privacyPolicy}."},purposeItem:{service:"servicio",services:"servicios"},purposes:{advertising:{description:"Estos servicios procesan informaci\xF3n personal para mostrarle anuncios personalizados o basados en intereses.",title:"Publicidad"},functional:{description:"Estos servicios son esenciales para el correcto funcionamiento de este sitio web. No puede desactivarlos ya que la p\xE1gina no funcionar\xEDa correctamente.",title:"Prestaci\xF3n de servicios"},marketing:{description:"Estos servicios procesan informaci\xF3n personal para mostrarle contenido relevante sobre productos, servicios o temas que puedan interesarle.",title:"Marketing"},performance:{description:"Estos servicios procesan informaci\xF3n personal para optimizar el servicio que ofrece este sitio.",title:"Optimizaci\xF3n del rendimiento"}},save:"Guardar",service:{disableAll:{description:"Utilice este interruptor para activar o desactivar todos los servicios.",title:"Activar o desactivar todos los servicios"},optOut:{description:"Este servicio est\xE1 habilitado por defecto (pero puede optar por lo contrario)",title:"(desactivar)"},purpose:"Finalidad",purposes:"Finalidades",required:{description:"Este servicio es necesario siempre",title:"(siempre requerido)"}}};var St={acceptAll:"",acceptAll_en:"Accept all",acceptSelected:"",acceptSelected_en:"Accept selected",service:{disableAll:{description:"Aktivoi kaikki p\xE4\xE4lle/pois.",title:"Valitse kaikki"},optOut:{description:"Ladataan oletuksena (mutta voit ottaa sen pois p\xE4\xE4lt\xE4)",title:"(ladataan oletuksena)"},purpose:"K\xE4ytt\xF6tarkoitus",purposes:"K\xE4ytt\xF6tarkoitukset",required:{description:"Sivusto vaatii t\xE4m\xE4n aina",title:"(vaaditaan)"}},close:"Sulje",consentModal:{description:"Voit tarkastella ja muokata sinusta ker\xE4\xE4mi\xE4mme tietoja.",privacyPolicy:{name:"tietosuojasivultamme",text:"Voit lukea lis\xE4tietoja {privacyPolicy}."},title:"Ker\xE4\xE4m\xE4mme tiedot"},consentNotice:{changeDescription:"Olemme tehneet muutoksia ehtoihin viime vierailusi j\xE4lkeen, tarkista ehdot.",description:"Ker\xE4\xE4mme ja k\xE4sittelemme henkil\xF6tietoja seuraaviin tarkoituksiin: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"Lue lis\xE4\xE4",privacyPolicy:{name:"tietosuojasivultamme"}},decline:"Hylk\xE4\xE4",ok:"Hyv\xE4ksy",poweredBy:"Palvelun tarjoaa Klaro!",purposeItem:{service:"",services:""},save:"Tallenna"};var _t={acceptAll:"Accepter tout",acceptSelected:"Accepter s\xE9lectionn\xE9",close:"Fermer",consentModal:{description:"Vous pouvez ici \xE9valuer et personnaliser les services que nous aimerions utiliser sur ce site. C'est vous qui d\xE9cidez ! Activez ou d\xE9sactivez les services comme bon vous semble.",privacyPolicy:{name:"politique de confidentialit\xE9",text:"Pour en savoir plus, veuillez lire notre {privacyPolicy}."},title:"Services que nous souhaitons utiliser"},consentNotice:{changeDescription:"Il y a eu des changements depuis votre derni\xE8re visite, veuillez renouveler votre consentement.",description:"Bonjour ! Pourrions-nous activer des services suppl\xE9mentaires pour {purposes}? Vous pouvez toujours modifier ou retirer votre consentement plus tard.",imprint:{name:"mentions l\xE9gales"},learnMore:"Laissez-moi choisir",privacyPolicy:{name:"politique de confidentialit\xE9"},testing:"Mode test !"},contextualConsent:{acceptAlways:"Toujours",acceptOnce:"Oui",description:"Vous souhaitez charger un contenu externe fourni par {title}?"},decline:"Je refuse",ok:"C'est bon.",poweredBy:"R\xE9alis\xE9 avec Klaro !",privacyPolicy:{name:"politique de confidentialit\xE9",text:"Pour en savoir plus, veuillez lire notre {privacyPolicy}."},purposeItem:{service:"service",services:"services"},purposes:{advertising:{description:"Ces services traitent les informations personnelles pour vous pr\xE9senter des publicit\xE9s personnalis\xE9es ou bas\xE9es sur des int\xE9r\xEAts.",title:"Publicit\xE9"},functional:{description:`Ces services sont essentiels au bon fonctionnement de ce site. Vous ne pouvez pas les d\xE9sactiver ici car le service ne fonctionnerait pas correctement autrement.
`,title:"Prestation de services"},marketing:{description:"Ces services traitent les informations personnelles afin de vous pr\xE9senter un contenu pertinent sur les produits, les services ou les sujets qui pourraient vous int\xE9resser.",title:"Marketing"},performance:{description:`Ces services traitent les informations personnelles afin d'optimiser le service que ce site Web offre.
`,title:"Optimisation de la performance"}},save:"Enregistrer",service:{disableAll:{description:"Utilisez ce commutateur pour activer ou d\xE9sactiver tous les services.",title:"Activer ou d\xE9sactiver tous les services"},optOut:{description:"Ce service est charg\xE9 par d\xE9faut (mais vous pouvez le d\xE9sactiver)",title:"(opt-out)"},purpose:"Objet",purposes:"Fins",required:{description:"Ce service est toujours n\xE9cessaire",title:"(toujours requis)"}}};var At={acceptAll:"Aceptar todas",acceptSelected:"Aceptar seleccionadas",close:"Pechar",consentModal:{description:"Aqu\xED pode avaliar e personalizar os servizos que nos gustar\xEDa utilizar neste sitio web. \xA1Vostede decide! Habilite ou deshabilite os servicios como lle conve\xF1a.",privacyPolicy:{name:"pol\xEDtica de privacidade",text:"Para saber m\xE1is, por favor lea a nosa {privacyPolicy}."},title:"Servizos que nos gustar\xEDa utilizar"},consentNotice:{changeDescription:"Houbo cambios nas cookies dende a s\xFAa \xFAltima visita. Debe renovar o seu consentimento.",description:"\xA1Ola! \xBFPoder\xEDamos habilitar alg\xFAns servizos adicionais para {purposes}? Sempre poder\xE1 cambiar ou retirar o s\xE9u consentimento m\xE1is tarde.",imprint:{name:"Imprimir"},learnMore:"Quero elixir",privacyPolicy:{name:"pol\xEDtica de privacidade"},testing:"\xA1Modo de proba!"},decline:"Descartar todas",ok:"De acordo",poweredBy:"\xA1Realizado con Klaro!",privacyPolicy:{name:"pol\xEDtica de privacidade",text:"Para saber m\xE1is, por favor lea a nosa {privacyPolicy}."},purposeItem:{service:"servizo",services:"servizos"},purposes:{advertising:{description:"Estes servizos procesan informaci\xF3n persoal para mostrarlle anuncios personalizados ou basados en intereses.",title:"Publicidade"},functional:{description:"Estes servizos son esenciais para o correcto funcionamiento deste sitio web. Non pode desactivalos xa que a p\xE1xina non funcionar\xEDa correctamente.",title:"Prestaci\xF3n de servizos"},marketing:{description:"Estes servizos procesan informaci\xF3n persoal para mostrarlle contido relevante sobre produtos, servizos ou temas que poidan interesarlle.",title:"Marketing"},performance:{description:"Estes servizos procesan informaci\xF3n persoal para optimizar o servizo que ofrece este sitio.",title:"Optimizaci\xF3n do rendimento"}},save:"Gardar",service:{disableAll:{description:"Utilice este interruptor para activar ou desactivar todos os servizos.",title:"Activar ou desactivar todos os servizos"},optOut:{description:"Este servizo est\xE1 habilitado por defecto (pero pode optar polo contrario)",title:"(desactivar)"},purpose:"Finalidade",purposes:"Finalidades",required:{description:"Este servizo \xE9 necesario sempre",title:"(sempre requirido)"}}};var xt={acceptAll:"",acceptAll_en:"Prihvati sve",acceptSelected:"",acceptSelected_en:"Prihvati odabrane",service:{disableAll:{description:"Koristite ovaj prekida\u010D da omogu\u0107ite/onemogu\u0107ite sve aplikacije odjednom.",title:"Izmeijeni sve"},optOut:{description:"Ova aplikacija je u\u010Ditana automatski (ali je mo\u017Eete onemogu\u0107iti)",title:"(onemogu\u0107ite)"},purpose:"Svrha",purposes:"Svrhe",required:{description:"Ova aplikacija je uvijek obavezna",title:"(obavezna)"}},close:"Zatvori",consentModal:{description:"Ovdje mo\u017Eete vidjeti i podesiti informacije koje prikupljamo o Vama.",privacyPolicy:{name:"pravila privatnosti",text:"Za vi\u0161e informacije pro\u010Ditajte na\u0161a {privacyPolicy}."},title:"Informacije koje prikupljamo"},consentNotice:{changeDescription:"Do\u0161lo je do promjena od Va\u0161e posljednjeg posje\u0107ivanja web stranice, molimo Vas da a\u017Eurirate svoja odobrenja.",description:"Mi prikupljamo i procesiramo Va\u0161e osobne podatke radi slijede\u0107eg: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"Saznajte vi\u0161e",privacyPolicy:{name:"pravila privatnosti"}},decline:"Odbij",ok:"U redu",poweredBy:"Pokre\u0107e Klaro!",purposeItem:{service:"",services:""},save:"Spremi"};var Ct={};var $t={acceptAll:"Accettare tutti",acceptSelected:"Accettare selezionato",close:"Chiudi",consentModal:{description:"Qui pu\xF2 valutare e personalizzare i servizi che vorremmo utilizzare su questo sito web. \xC8 lei il responsabile! Abilitare o disabilitare i servizi come meglio crede.",privacyPolicy:{name:"informativa sulla privacy",text:"Per saperne di pi\xF9, legga la nostra {privacyPolicy}."},title:"Servizi che desideriamo utilizzare"},consentNotice:{changeDescription:"Ci sono stati dei cambiamenti rispetto alla sua ultima visita, la preghiamo di rinnovare il suo consenso.",description:"Salve, possiamo attivare alcuni servizi aggiuntivi per {purposes}? Pu\xF2 sempre modificare o ritirare il suo consenso in un secondo momento.",imprint:{name:"impronta"},learnMore:"Lasciatemi scegliere",privacyPolicy:{name:"informativa sulla privacy"},testing:"Modalit\xE0 di test!"},contextualConsent:{acceptAlways:"Sempre",acceptOnce:"S\xEC",description:"Vuole caricare contenuti esterni forniti da {title}?"},decline:"Rifiuto",ok:"Va bene cos\xEC",poweredBy:"Realizzato con Klaro!",privacyPolicy:{name:"informativa sulla privacy",text:"Per saperne di pi\xF9, legga la nostra {privacyPolicy}."},purposeItem:{service:"servizio",services:"servizi"},purposes:{advertising:{description:"Questi servizi elaborano le informazioni personali per mostrarle annunci pubblicitari personalizzati o basati su interessi.",title:"Pubblicit\xE0"},functional:{description:`Questi servizi sono essenziali per il corretto funzionamento di questo sito web. Non pu\xF2 disattivarli qui perch\xE9 altrimenti il servizio non funzionerebbe correttamente.
`,title:"Fornitura di servizi"},marketing:{description:"Questi servizi elaborano le informazioni personali per mostrarle contenuti rilevanti su prodotti, servizi o argomenti che potrebbero interessarla.",title:"Marketing"},performance:{description:`Questi servizi elaborano le informazioni personali per ottimizzare il servizio offerto da questo sito web.
`,title:"Ottimizzazione delle prestazioni"}},save:"Salva",service:{disableAll:{description:"Utilizzi questo interruttore per attivare o disattivare tutti i servizi.",title:"Attivare o disattivare tutti i servizi"},optOut:{description:"Questo servizio \xE8 caricato di default (ma \xE8 possibile scegliere di non usufruirne)",title:"(opt-out)"},purpose:"Scopo dell",purposes:"Finalit\xE0",required:{description:"Questo servizio \xE8 sempre richiesto",title:"(sempre richiesto)"}}};var zt={acceptAll:"Accepteer alle",acceptSelected:"Geselecteerde",close:"Sluit",consentModal:{description:"Hier kunt u de diensten die wij op deze website willen gebruiken beoordelen en aanpassen. U heeft de leiding! Schakel de diensten naar eigen inzicht in of uit.",privacyPolicy:{name:"privacybeleid",text:"Voor meer informatie kunt u ons {privacyPolicy} lezen."},title:"Diensten die we graag willen gebruiken"},consentNotice:{changeDescription:"Er waren veranderingen sinds uw laatste bezoek, gelieve uw toestemming te hernieuwen.",description:"Hallo, kunnen wij u een aantal extra diensten aanbieden voor {purposes}? U kunt uw toestemming later altijd nog wijzigen of intrekken.",imprint:{name:"impressum"},learnMore:"Laat me kiezen",privacyPolicy:{name:"privacybeleid"},testing:"Testmodus!"},contextualConsent:{acceptAlways:"Altijd",acceptOnce:"Ja",description:"Wilt u externe content laden die door {title} wordt aangeleverd ?"},decline:"Ik weiger",ok:"Dat is ok\xE9",poweredBy:"Gerealiseerd met Klaro!",privacyPolicy:{name:"privacybeleid",text:"Voor meer informatie kunt u ons {privacyPolicy} lezen."},purposeItem:{service:"service",services:"diensten"},purposes:{advertising:{description:"Deze diensten verwerken persoonlijke informatie om u gepersonaliseerde of op interesse gebaseerde advertenties te tonen.",title:"Reclame"},functional:{description:`Deze diensten zijn essentieel voor het correct functioneren van deze website. U kunt ze hier niet uitschakelen omdat de dienst anders niet correct zou werken.
`,title:"Dienstverlening"},marketing:{description:"Deze diensten verwerken persoonlijke informatie om u relevante inhoud te tonen over producten, diensten of onderwerpen waarin u ge\xEFnteresseerd zou kunnen zijn.",title:"Marketing"},performance:{description:`Deze diensten verwerken persoonlijke informatie om de service die deze website biedt te optimaliseren.
`,title:"Optimalisatie van de prestaties"}},save:"Opslaan",service:{disableAll:{description:"Gebruik deze schakelaar om alle diensten in of uit te schakelen.",title:"Alle diensten in- of uitschakelen"},optOut:{description:"Deze diensten worden standaard geladen (maar u kunt zich afmelden)",title:"(opt-out)"},purpose:"Verwerkingsdoel",purposes:"Verwerkingsdoeleinden",required:{description:"Deze diensten zijn altijd nodig",title:"(altijd nodig)"}}};var Et={acceptAll:"Godtar alle",acceptSelected:"Godtar valgt",service:{disableAll:{description:"Bruk denne for \xE5 skru av/p\xE5 alle apper.",title:"Bytt alle apper"},optOut:{description:"Denne appen er lastet som standard (men du kan skru det av)",title:"(opt-out)"},purpose:"\xC5rsak",purposes:"\xC5rsaker",required:{description:"Denne applikasjonen er alltid p\xE5krevd",title:"(alltid p\xE5krevd)"}},close:"",close_en:"Close",consentModal:{description:"Her kan du se og velge hvilken informasjon vi samler inn om deg.",privacyPolicy:{name:"personvernerkl\xE6ring",text:"For \xE5 l\xE6re mer, vennligst les v\xE5r {privacyPolicy}."},title:"Informasjon vi samler inn"},consentNotice:{changeDescription:"Det har skjedd endringer siden ditt siste bes\xF8k, vennligst oppdater ditt samtykke.",description:"Vi samler inn og prosesserer din personlige informasjon av f\xF8lgende \xE5rsaker: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"L\xE6r mer",privacyPolicy:{name:"personvernerkl\xE6ring"}},decline:"Avsl\xE5",ok:"OK",poweredBy:"Laget med Klaro!",purposeItem:{service:"",services:""},save:"Opslaan"};var Mt={acceptAll:"Tot acceptar",acceptSelected:"Acceptar \xE7\xF2 seleccionat",close:"Tampar",consentModal:{description:"Aqu\xED pod\xE8tz mesurar e personalizar los servicis que volriam utilizar sus aqueste site web. Av\xE8tz lo darri\xE8r mot ! Activatz o desactivatz segon v\xF2stra causida.",title:"Servicis que volriam utilizar"},consentNotice:{changeDescription:"I agu\xE8t de modificacions dempu\xE8i v\xF2stra darri\xE8ra visita, merc\xE9s de repassar v\xF2stre consentiment.",description:"Adieu\u202F! Poiriam activar mai de servici per {purposes}\u202F? Pod\xE8tz totjorn modificar o tirar v\xF2stre consentiment mai tard.",learnMore:"Me daissar causir",testing:"M\xF2de t\xE8st !"},contextualConsent:{acceptAlways:"Totjorn",acceptOnce:"\xD2c",description:"Vol\xE8tz cargar de contenguts ext\xE8rn provesits per {title}\u202F?"},decline:"Refusi",ok:"Es bon",poweredBy:"Realizat amb Klaro !",privacyPolicy:{name:"politica de confidencialitat",text:"Per ne saber mai, vejatz n\xF2stra {privacyPolicy}."},purposeItem:{service:"servici",services:"servicis"},purposes:{advertising:{description:"Aquestes servicis tractan d\u2019informacions personalas per vos mostrar de reclamas personalizadas o basadas suls inter\xE8sses.",title:"Reclama"},functional:{description:`Aquestes servicis son essencials pel foncionament corr\xE8ct d\u2019aqueste site web. Los pod\xE8tz pas desactivar aqu\xED pr\u2019amor que lo servici foncionari\xE1 pas coma cal autrament.
`,title:"Servici de provision"},marketing:{description:"Aquestes servicis tractan d\u2019informacions personalas per vos mostrar de contenguts a prepaus de produits, de servicis o t\xE8mas que poiri\xE1n vos interessar.",title:"Marketing"},performance:{description:`Aquestes servicis tractan d\u2019informacions per optimizar lo servici qu\u2019aqueste site web prepausa.
`,title:"Optimizacion de las performan\xE7as"}},save:"Salvar",service:{disableAll:{description:"Utilizatz aqueste alternator per activar o desactivar totes los servicis.",title:"Activar o desactivar totes los servicis"},optOut:{description:"Aqueste servici es cargar per defaut (mas lo pod\xE8tz desactivar)",title:"(opt-out)"},purpose:"finalitat",purposes:"finalitat",required:{description:"Aqueste servici es totjorn requesit",title:"(totjorn requesit)"}}};var Pt={acceptAll:"Zaakceptuj wszystkie",acceptSelected:"Zaakceptuj wybrane",close:"Zamknij",consentModal:{description:"Tutaj mog\u0105 Pa\u0144stwo oceni\u0107 i dostosowa\u0107 us\u0142ugi, kt\xF3re chcieliby\u015Bmy wykorzysta\u0107 na tej stronie. W\u0142\u0105czaj lub wy\u0142\u0105czaj us\u0142ugi wed\u0142ug w\u0142asnego uznania.",privacyPolicy:{name:"polityk\u0105 prywatno\u015Bci",text:"Aby dowiedzie\u0107 si\u0119 wi\u0119cej, prosimy o zapoznanie si\u0119 z nasz\u0105 {privacyPolicy}."},title:"Us\u0142ugi, z kt\xF3rych chcieliby\u015Bmy skorzysta\u0107"},consentNotice:{changeDescription:"Od Twojej ostatniej wizyty nast\u0105pi\u0142y zmiany, prosimy o odnowienie zgody.",description:"Czy mo\u017Cemy w\u0142\u0105czy\u0107 dodatkowe us\u0142ugi dla {purposes}? W ka\u017Cdej chwili mog\u0105 Pa\u0144stwo p\xF3\u017Aniej zmieni\u0107 lub wycofa\u0107 swoj\u0105 zgod\u0119.",imprint:{name:"Imprint"},learnMore:"Pozw\xF3l mi wybra\u0107",privacyPolicy:{name:"polityka prywatno\u015Bci"},testing:"Tryb testowy!"},contextualConsent:{acceptAlways:"Zawsze",acceptOnce:"Tak",description:"Czy chc\u0105 Pa\u0144stwo za\u0142adowa\u0107 tre\u015Bci zewn\u0119trzne dostarczane przez {title}?"},decline:"Odmawiam",ok:"Ok",poweredBy:"Technologia dostarczona przez Klaro",privacyPolicy:{name:"polityka prywatno\u015Bci",text:"Aby dowiedzie\u0107 si\u0119 wi\u0119cej, prosimy o zapoznanie si\u0119 z nasz\u0105 {privacyPolicy}."},purposeItem:{service:"us\u0142uga",services:"us\u0142ugi"},purposes:{advertising:{description:"Us\u0142ugi te przetwarzaj\u0105 dane osobowe w celu pokazania Pa\u0144stwu spersonalizowanych lub opartych na zainteresowaniach reklam.",title:"Reklama"},functional:{description:`Us\u0142ugi te s\u0105 niezb\u0119dne do prawid\u0142owego funkcjonowania niniejszej strony internetowej. Nie mog\u0105 Pa\u0144stwo ich tutaj wy\u0142\u0105czy\u0107, poniewa\u017C w przeciwnym razie strona nie dzia\u0142a\u0142aby prawid\u0142owo.
`,title:"\u015Awiadczenie us\u0142ug"},marketing:{description:"Us\u0142ugi te przetwarzaj\u0105 dane osobowe w celu pokazania Pa\u0144stwu istotnych tre\u015Bci dotycz\u0105cych produkt\xF3w, us\u0142ug lub temat\xF3w, kt\xF3rymi mog\u0105 by\u0107 Pa\u0144stwo zainteresowani.",title:"Marketing"},performance:{description:`Us\u0142ugi te przetwarzaj\u0105 dane osobowe w celu optymalizacji us\u0142ug oferowanych przez t\u0119 stron\u0119.
`,title:"Optymalizacja wydajno\u015Bci"}},save:"Zapisz",service:{disableAll:{description:"Za pomoc\u0105 tego prze\u0142\u0105cznika mo\u017Cna w\u0142\u0105cza\u0107 lub wy\u0142\u0105cza\u0107 wszystkie us\u0142ugi.",title:"W\u0142\u0105cz lub wy\u0142\u0105cz wszystkie us\u0142ugi"},optOut:{description:"Ta us\u0142uga jest domy\u015Blnie za\u0142adowana (ale mog\u0105 Pa\u0144stwo z niej zrezygnowa\u0107)",title:"(opt-out)"},purpose:"Cel",purposes:"Cele",required:{description:"Us\u0142ugi te s\u0105 zawsze wymagane",title:"(zawsze wymagane)"}}};var Ot={acceptAll:"Aceitar todos",acceptSelected:"Aceitar selecionados",close:"Fechar",consentModal:{description:"Aqui voc\xEA pode avaliar e personalizar os servi\xE7os que gostar\xEDamos de usar neste website. Voc\xEA est\xE1 no comando! Habilite ou desabilite os servi\xE7os como julgar conveniente.",privacyPolicy:{name:"pol\xEDtica de privacidade",text:"Para saber mais, por favor, leia nossa {privacyPolicy}."},title:"Servi\xE7os que gostar\xEDamos de utilizar"},consentNotice:{changeDescription:"Houve mudan\xE7as desde sua \xFAltima visita, queira renovar seu consentimento.",description:"Ol\xE1! Poder\xEDamos, por favor, habilitar alguns servi\xE7os adicionais para {purposes}? Voc\xEA pode sempre mudar ou retirar seu consentimento mais tarde.",imprint:{name:"imprimir"},learnMore:"Deixe-me escolher",privacyPolicy:{name:"pol\xEDtica de privacidade"},testing:"Modo de teste!"},contextualConsent:{acceptAlways:"Sempre",acceptOnce:"Sim",description:"Voc\xEA deseja carregar conte\xFAdo externo fornecido por {title}?"},decline:"Recusar",ok:"Aceito.",poweredBy:"Realizado com Klaro!",privacyPolicy:{name:"pol\xEDtica de privacidade",text:"Para saber mais, por favor, leia nossa {privacyPolicy}."},purposeItem:{service:"servi\xE7o",services:"servi\xE7os"},purposes:{advertising:{description:"Esses servi\xE7os processam informa\xE7\xF5es pessoais para mostrar a voc\xEA an\xFAncios personalizados ou baseados em interesses.",title:"Publicidade"},functional:{description:`Esses servi\xE7os s\xE3o essenciais para o correto funcionamento deste website. Voc\xEA n\xE3o pode desativ\xE1-los aqui, pois de outra forma o servi\xE7o n\xE3o funcionaria corretamente.
`,title:"Presta\xE7\xE3o de servi\xE7os"},marketing:{description:"Esses servi\xE7os processam informa\xE7\xF5es pessoais para mostrar a voc\xEA conte\xFAdo relevante sobre produtos, servi\xE7os ou t\xF3picos que possam ser do seu interesse.",title:"Marketing"},performance:{description:`Esses servi\xE7os processam informa\xE7\xF5es pessoais para otimizar o servi\xE7o que este website oferece.
`,title:"Otimiza\xE7\xE3o do desempenho"}},save:"Salvar",service:{disableAll:{description:"Use essa chave para habilitar ou desabilitar todos os servi\xE7os.",title:"Habilitar ou desabilitar todos os servi\xE7os"},optOut:{description:"Estes servi\xE7os s\xE3o carregados por padr\xE3o (mas o voc\xEA pode optar por n\xE3o participar).",title:"(opt-out)"},purpose:"Objetivo",purposes:"Objetivos",required:{description:"Esses servi\xE7os s\xE3o sempre necess\xE1rios",title:"(sempre necess\xE1rio)"}}};var Dt={acceptAll:"",acceptAll_en:"Accept all",acceptSelected:"",acceptSelected_en:"Accept selected",service:{disableAll:{description:"Utiliza\u021Bi acest switch pentru a activa/dezactiva toate aplica\u021Biile.",title:"Comuta\u021Bi \xEEntre toate aplica\u021Biile"},optOut:{description:"Aceast\u0103 aplica\u021Bie este \xEEnc\u0103rcat\u0103 \xEEn mod implicit (dar pute\u021Bi renun\u021Ba)",title:"(opt-out)"},purpose:"Scop",purposes:"Scopuri",required:{description:"Aceast\u0103 aplica\u021Bie este \xEEntotdeauna necesar\u0103",title:"(\xEEntotdeauna necesar)"}},close:"",close_en:"Close",consentModal:{description:"Aici pute\u021Bi vedea \u0219i personaliza informa\u021Biile pe care le colect\u0103m despre dvs.",privacyPolicy:{name:"politica privacy",text:"Pentru a afla mai multe, v\u0103 rug\u0103m s\u0103 citi\u021Bi {privacyPolicy}."},title:"Informa\u021Biile pe care le colect\u0103m"},consentNotice:{changeDescription:"Au existat modific\u0103ri de la ultima vizit\u0103, v\u0103 rug\u0103m s\u0103 actualiza\u021Bi consim\u021B\u0103m\xE2ntul.",description:"Colect\u0103m \u0219i proces\u0103m informa\u021Biile dvs. personale \xEEn urm\u0103toarele scopuri: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"Afl\u0103 mai multe",privacyPolicy:{name:"politica privacy"}},decline:"Renun\u021B\u0103",ok:"OK",poweredBy:"Realizat de Klaro!",purposeItem:{service:"",services:""},save:"Salveaz\u0103"};var Tt={acceptAll:"\u041F\u0440\u0438\u043D\u044F\u0442\u044C \u0432\u0441\u0451",acceptSelected:"\u041F\u0440\u0438\u043D\u044F\u0442\u044C \u0432\u044B\u0431\u0440\u0430\u043D\u043D\u044B\u0435",service:{disableAll:{description:"\u0418\u0441\u043F\u043E\u043B\u044C\u0437\u0443\u0439\u0442\u0435 \u044D\u0442\u043E\u0442 \u043F\u0435\u0440\u0435\u043A\u043B\u044E\u0447\u0430\u0442\u0435\u043B\u044C, \u0447\u0442\u043E\u0431\u044B \u0432\u043A\u043B\u044E\u0447\u0438\u0442\u044C/\u043E\u0442\u043A\u043B\u044E\u0447\u0438\u0442\u044C \u0432\u0441\u0435 \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u044F.",title:"\u041F\u0435\u0440\u0435\u043A\u043B\u044E\u0447\u0438\u0442\u044C \u0432\u0441\u0435 \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u044F"},optOut:{description:"\u042D\u0442\u043E \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u0435 \u0432\u043A\u043B\u044E\u0447\u0435\u043D\u043E \u043F\u043E \u0443\u043C\u043E\u043B\u0447\u0430\u043D\u0438\u044E (\u043D\u043E \u0432\u044B \u043C\u043E\u0436\u0435\u0442\u0435 \u043E\u0442\u043A\u0430\u0437\u0430\u0442\u044C\u0441\u044F)",title:"(\u043E\u0442\u043A\u0430\u0437\u0430\u0442\u044C\u0441\u044F)"},purpose:"\u041D\u0430\u043C\u0435\u0440\u0435\u043D\u0438\u0435",purposes:"\u041D\u0430\u043C\u0435\u0440\u0435\u043D\u0438\u044F",required:{description:"\u042D\u0442\u043E \u043E\u0431\u044F\u0437\u0430\u0442\u0435\u043B\u044C\u043D\u043E\u0435 \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u0435",title:"(\u0432\u0441\u0435\u0433\u0434\u0430 \u043E\u0431\u044F\u0437\u0430\u0442\u0435\u043B\u044C\u043D\u044B\u0439)"}},close:"\u0417\u0430\u043A\u0440\u044B\u0442\u044C",consentModal:{description:"\u0417\u0434\u0435\u0441\u044C \u0432\u044B \u043C\u043E\u0436\u0435\u0442\u0435 \u043F\u0440\u043E\u0441\u043C\u043E\u0442\u0440\u0435\u0442\u044C \u0438 \u043D\u0430\u0441\u0442\u0440\u043E\u0438\u0442\u044C, \u043A\u0430\u043A\u0443\u044E \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044E \u043E \u0432\u0430\u0441 \u043C\u044B \u0445\u0440\u0430\u043D\u0438\u043C.",privacyPolicy:{name:"\u0421\u043E\u0433\u043B\u0430\u0448\u0435\u043D\u0438\u0435",text:"\u0427\u0442\u043E\u0431\u044B \u0443\u0437\u043D\u0430\u0442\u044C \u0431\u043E\u043B\u044C\u0448\u0435, \u043F\u043E\u0436\u0430\u043B\u0443\u0439\u0441\u0442\u0430, \u043F\u0440\u043E\u0447\u0438\u0442\u0430\u0439\u0442\u0435 \u043D\u0430\u0448\u0435 {privacyPolicy}."},title:"\u0418\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044F, \u043A\u043E\u0442\u043E\u0440\u0443\u044E \u043C\u044B \u0441\u043E\u0445\u0440\u0430\u043D\u044F\u0435\u043C"},consentNotice:{changeDescription:"\u0421\u043E \u0432\u0440\u0435\u043C\u0435\u043D\u0438 \u0432\u0430\u0448\u0435\u0433\u043E \u043F\u043E\u0441\u043B\u0435\u0434\u043D\u0435\u0433\u043E \u0432\u0438\u0437\u0438\u0442\u0430 \u043F\u0440\u043E\u0438\u0437\u043E\u0448\u043B\u0438 \u0438\u0437\u043C\u0435\u043D\u0435\u043D\u0438\u044F, \u043E\u0431\u043D\u043E\u0432\u0438\u0442\u0435 \u0441\u0432\u043E\u0451 \u0441\u043E\u0433\u043B\u0430\u0441\u0438\u0435.",description:"\u041C\u044B \u0441\u043E\u0431\u0438\u0440\u0430\u0435\u043C \u0438 \u043E\u0431\u0440\u0430\u0431\u0430\u0442\u044B\u0432\u0430\u0435\u043C \u0432\u0430\u0448\u0443 \u043B\u0438\u0447\u043D\u0443\u044E \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u044E \u0434\u043B\u044F \u0441\u043B\u0435\u0434\u0443\u044E\u0449\u0438\u0445 \u0446\u0435\u043B\u0435\u0439: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"\u041D\u0430\u0441\u0442\u0440\u043E\u0438\u0442\u044C",privacyPolicy:{name:"\u043F\u043E\u043B\u0438\u0442\u0438\u043A\u0430 \u043A\u043E\u043D\u0444\u0438\u0434\u0435\u043D\u0446\u0438\u0430\u043B\u044C\u043D\u043E\u0441\u0442\u0438"}},decline:"\u041E\u0442\u043A\u043B\u043E\u043D\u0438\u0442\u044C",ok:"\u041F\u0440\u0438\u043D\u044F\u0442\u044C",poweredBy:"\u0420\u0430\u0431\u043E\u0442\u0430\u0435\u0442 \u043D\u0430 \u041A\u043B\u0430\u0440\u043E!",purposeItem:{service:"",services:""},save:"\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C"};var Rt={acceptAll:"",acceptAll_en:"Accept all",acceptSelected:"",acceptSelected_en:"Accept selected",service:{disableAll:{description:"Koristite ovaj prekida\u010D da omogu\u0107ite/onesposobite sve aplikacije odjednom.",title:"Izmeni sve"},optOut:{description:"Ova aplikacija je u\u010Ditana automatski (ali je mo\u017Eete onesposobiti)",title:"(onesposobite)"},purpose:"Svrha",purposes:"Svrhe",required:{description:"Ova aplikacija je uvek neophodna",title:"(neophodna)"}},close:"Zatvori",consentModal:{description:"Ovde mo\u017Eete videti i podesiti informacije koje prikupljamo o Vama.",privacyPolicy:{name:"politiku privatnosti",text:"Za vi\u0161e informacije pro\u010Ditajte na\u0161u {privacyPolicy}."},title:"Informacije koje prikupljamo"},consentNotice:{changeDescription:"Do\u0161lo je do promena od Va\u0161e poslednje posete, molimo Vas da a\u017Eurirate svoja odobrenja.",description:"Mi prikupljamo i procesiramo Va\u0161e li\u010Dne podatke radi slede\u0107eg: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"Saznajte vi\u0161e",privacyPolicy:{name:"politiku privatnosti"}},decline:"Odbij",ok:"U redu",poweredBy:"Pokre\u0107e Klaro!",purposeItem:{service:"",services:""},save:"Sa\u010Duvaj"};var Lt={consentModal:{title:"\u0418\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u0458\u0435 \u043A\u043E\u0458\u0435 \u043F\u0440\u0438\u043A\u0443\u043F\u0459\u0430\u043C\u043E",description:`\u041E\u0432\u0434\u0435 \u043C\u043E\u0436\u0435\u0442\u0435 \u0432\u0438\u0434\u0435\u0442 \u0438 \u043F\u043E\u0434\u0435\u0441\u0438\u0442\u0438 \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u0458\u0435 \u043A\u043E\u0458\u0435 \u043F\u0440\u0438\u043A\u0443\u043F\u0459\u0430\u043C\u043E \u043E \u0412\u0430\u043C\u0430.
`,privacyPolicy:{name:"\u043F\u043E\u043B\u0438\u0442\u0438\u043A\u0443 \u043F\u0440\u0438\u0432\u0430\u0442\u043D\u043E\u0441\u0442\u0438",text:`\u0417\u0430 \u0432\u0438\u0448\u0435 \u0438\u043D\u0444\u043E\u0440\u043C\u0430\u0446\u0438\u0458\u0430 \u043F\u0440\u043E\u0447\u0438\u0442\u0430\u0458\u0442\u0435 \u043D\u0430\u0448\u0443 {privacyPolicy}.
`}},consentNotice:{changeDescription:"\u0414\u043E\u0448\u043B\u043E \u0458\u0435 \u0434\u043E \u043F\u0440\u043E\u043C\u0435\u043D\u0430 \u043E\u0434 \u0412\u0430\u0448\u0435 \u043F\u043E\u0441\u043B\u0435\u0434\u043D\u0458\u0435 \u043F\u043E\u0441\u0435\u0442\u0435, \u043C\u043E\u043B\u0438\u043C\u043E \u0412\u0430\u0441 \u0434\u0430 \u0430\u0436\u0443\u0440\u0438\u0440\u0430\u0442\u0435 \u0441\u0432\u043E\u0458\u0430 \u043E\u0434\u043E\u0431\u0440\u0435\u045A\u0430.",description:`\u041C\u0438 \u043F\u0440\u0438\u043A\u0443\u043F\u0459\u0430\u043C\u043E \u0438 \u043F\u0440\u043E\u0446\u0435\u0441\u0438\u0440\u0430\u043C\u043E \u0412\u0430\u0448\u0435 \u043B\u0438\u0447\u043D\u0435 \u043F\u043E\u0434\u0430\u0442\u043A\u0435 \u0440\u0430\u0434\u0438 \u0441\u043B\u0435\u0434\u0435\u045B\u0435\u0433: {purposes}.
`,learnMore:"\u0421\u0430\u0437\u043D\u0430\u0458\u0442\u0435 \u0432\u0438\u0448\u0435",privacyPolicy:{name:"\u043F\u043E\u043B\u0438\u0442\u0438\u043A\u0443 \u043F\u0440\u0438\u0432\u0430\u0442\u043D\u043E\u0441\u0442\u0438"}},ok:"\u0423 \u0440\u0435\u0434\u0443",save:"\u0421\u0430\u0447\u0443\u0432\u0430\u0458",decline:"\u041E\u0434\u0431\u0438\u0458",close:"\u0417\u0430\u0442\u0432\u043E\u0440\u0438",service:{disableAll:{title:"\u0418\u0437\u043C\u0435\u043D\u0438 \u0441\u0432\u0435",description:"\u041A\u043E\u0440\u0438\u0441\u0442\u0438\u0442\u0435 \u043E\u0432\u0430\u0458 \u043F\u0440\u0435\u043A\u0438\u0434\u0430\u0447 \u0434\u0430 \u043E\u043C\u043E\u0433\u0443\u045B\u0438\u0442\u0435/\u043E\u043D\u0435\u0441\u043F\u043E\u0441\u043E\u0431\u0438\u0442\u0435 \u0441\u0432\u0435 \u0430\u043F\u043B\u0438\u043A\u0430\u0446\u0438\u0458\u0435 \u043E\u0434\u0458\u0435\u0434\u043D\u043E\u043C."},optOut:{title:"(\u043E\u043D\u0435\u0441\u043F\u043E\u0441\u043E\u0431\u0438\u0442\u0435)",description:"\u041E\u0432\u0430 \u0430\u043F\u043B\u0438\u043A\u0430\u0446\u0438\u0458\u0430 \u0458\u0435 \u0443\u0447\u0438\u0442\u0430\u043D\u0430 \u0430\u0443\u0442\u043E\u043C\u0430\u0442\u0441\u043A\u0438 (\u0430\u043B\u0438 \u0458\u0435 \u043C\u043E\u0436\u0435\u0442\u0435 \u043E\u043D\u0435\u0441\u043F\u043E\u0441\u043E\u0431\u0438\u0442\u0438)"},required:{title:"(\u043D\u0435\u043E\u043F\u0445\u043E\u0434\u043D\u0430)",description:"\u041E\u0432\u0430 \u0430\u043F\u043B\u0438\u043A\u0430\u0446\u0438\u0458\u0430 \u0458\u0435 \u0443\u0432\u0435\u043A \u043D\u0435\u043E\u043F\u0445\u043E\u0434\u043D\u0430."},purposes:"\u0421\u0432\u0440\u0445\u0435",purpose:"\u0421\u0432\u0440\u0445\u0430"},poweredBy:"\u041F\u043E\u043A\u0440\u0435\u045B\u0435 \u041A\u043B\u0430\u0440\u043E!"};var jt={acceptAll:"Acceptera alla",acceptSelected:"Acceptera markerat",service:{disableAll:{description:"Anv\xE4nd detta reglage f\xF6r att aktivera/avaktivera samtliga appar.",title:"\xC4ndra f\xF6r alla appar"},optOut:{description:"Den h\xE4r appen laddas som standardinst\xE4llning (men du kan avaktivera den)",title:"(Avaktivera)"},purpose:"Syfte",purposes:"Syften",required:{description:"Den h\xE4r applikationen kr\xE4vs alltid",title:"(Kr\xE4vs alltid)"}},close:"St\xE4ng",consentModal:{description:"H\xE4r kan du se och anpassa vilken information vi samlar om dig.",privacyPolicy:{name:"Integritetspolicy",text:"F\xF6r att veta mer, l\xE4s v\xE5r {privacyPolicy}."},title:"Information som vi samlar"},consentNotice:{changeDescription:"Det har skett f\xF6r\xE4ndringar sedan ditt senaste bes\xF6k, var god uppdatera ditt medgivande.",description:"Vi samlar och bearbetar din personliga data i f\xF6ljande syften: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"L\xE4s mer",privacyPolicy:{name:"Integritetspolicy"}},decline:"Avb\xF6j",ok:"OK",poweredBy:"K\xF6rs p\xE5 Klaro!",purposeItem:{service:"",services:""},save:"Spara"};var It={acceptAll:"",acceptAll_en:"Accept all",acceptSelected:"",acceptSelected_en:"Accept selected",service:{disableAll:{description:"Toplu a\xE7ma/kapama i\xE7in bu d\xFC\u011Fmeyi kullanabilirsin.",title:"T\xFCm uygulamalar\u0131 a\xE7/kapat"},optOut:{description:"Bu uygulama varsay\u0131landa y\xFCklendi (ancak iptal edebilirsin)",title:"(iste\u011Fe ba\u011Fl\u0131)"},purpose:"Ama\xE7",purposes:"Ama\xE7lar",required:{description:"Bu uygulama her zaman gerekli",title:"(her zaman gerekli)"}},close:"Kapat",consentModal:{description:"Hakk\u0131n\u0131zda toplad\u0131\u011F\u0131m\u0131z bilgileri burada g\xF6rebilir ve \xF6zelle\u015Ftirebilirsiniz.",privacyPolicy:{name:"Gizlilik Politikas\u0131",text:"Daha fazlas\u0131 i\xE7in l\xFCtfen {privacyPolicy} sayfam\u0131z\u0131 okuyun."},title:"Saklad\u0131\u011F\u0131m\u0131z bilgiler"},consentNotice:{changeDescription:"Son ziyaretinizden bu yana de\u011Fi\u015Fiklikler oldu, l\xFCtfen se\xE7iminizi g\xFCncelleyin.",description:"Ki\u015Fisel bilgilerinizi a\u015Fa\u011F\u0131daki ama\xE7larla sakl\u0131yor ve i\u015Fliyoruz: {purposes}.",imprint:{name:"",name_en:"imprint"},learnMore:"Daha fazla bilgi",privacyPolicy:{name:"Gizlilik Politikas\u0131"}},decline:"Reddet",ok:"Tamam",poweredBy:"Klaro taraf\u0131ndan geli\u015Ftirildi!",purposeItem:{service:"",services:""},save:"Kaydet"};var Nt={acceptAll:"\u7167\u5355\u5168\u6536",acceptSelected:"\u63A5\u53D7\u9009\u62E9",close:"\u5BC6\u5207",consentModal:{description:"\u5728\u8FD9\u91CC\uFF0C\u60A8\u53EF\u4EE5\u8BC4\u4F30\u548C\u5B9A\u5236\u6211\u4EEC\u5E0C\u671B\u5728\u672C\u7F51\u7AD9\u4E0A\u4F7F\u7528\u7684\u670D\u52A1\u3002\u60A8\u662F\u8D1F\u8D23\u4EBA\uFF01\u60A8\u53EF\u4EE5\u6839\u636E\u81EA\u5DF1\u7684\u9700\u8981\u542F\u7528\u6216\u7981\u7528\u670D\u52A1\u3002\u542F\u7528\u6216\u7981\u7528\u60A8\u8BA4\u4E3A\u5408\u9002\u7684\u670D\u52A1\u3002",privacyPolicy:{name:"\u9690\u79C1\u653F\u7B56",text:"\u8981\u4E86\u89E3\u66F4\u591A\uFF0C\u8BF7\u9605\u8BFB\u6211\u4EEC\u7684{privacyPolicy} \u3002"},title:"\u6211\u4EEC\u60F3\u4F7F\u7528\u7684\u670D\u52A1"},consentNotice:{changeDescription:"\u81EA\u4E0A\u6B21\u8BBF\u95EE\u540E\u6709\u53D8\u5316\uFF0C\u8BF7\u66F4\u65B0\u60A8\u7684\u540C\u610F\u3002",description:"\u4F60\u597D\uFF01\u6211\u4EEC\u53EF\u4EE5\u4E3A{purposes} \u542F\u7528\u4E00\u4E9B\u989D\u5916\u7684\u670D\u52A1\u5417\uFF1F\u60A8\u53EF\u4EE5\u968F\u65F6\u66F4\u6539\u6216\u64A4\u56DE\u60A8\u7684\u540C\u610F\u3002",imprint:{name:"\u5370\u8BB0"},learnMore:"\u8BA9\u6211\u6765\u9009",privacyPolicy:{name:"\u9690\u79C1\u653F\u7B56"},testing:"\u6D4B\u8BD5\u6A21\u5F0F\uFF01"},contextualConsent:{acceptAlways:"\u603B\u662F",acceptOnce:"\u662F\u7684\uFF0C\u662F\u7684",description:"\u4F60\u60F3\u52A0\u8F7D\u7531{title} \u63D0\u4F9B\u7684\u5916\u90E8\u5185\u5BB9\u5417\uFF1F"},decline:"\u6211\u62D2\u7EDD",ok:"\u6CA1\u4E8B\u7684",poweredBy:"\u4E0EKlaro\u4E00\u8D77\u5B9E\u73B0!",privacyPolicy:{name:"\u9690\u79C1\u653F\u7B56",text:"\u8981\u4E86\u89E3\u66F4\u591A\uFF0C\u8BF7\u9605\u8BFB\u6211\u4EEC\u7684{privacyPolicy} \u3002"},purposeItem:{service:"\u670D\u52A1",services:"\u670D\u52A1"},purposes:{advertising:{description:"\u8FD9\u4E9B\u670D\u52A1\u5904\u7406\u4E2A\u4EBA\u4FE1\u606F\uFF0C\u5411\u60A8\u5C55\u793A\u4E2A\u6027\u5316\u6216\u57FA\u4E8E\u5174\u8DA3\u7684\u5E7F\u544A\u3002",title:"\u5E7F\u544A\u5BA3\u4F20"},functional:{description:`\u8FD9\u4E9B\u670D\u52A1\u5BF9\u4E8E\u672C\u7F51\u7AD9\u7684\u6B63\u5E38\u8FD0\u884C\u662F\u5FC5\u4E0D\u53EF\u5C11\u7684\u3002\u60A8\u4E0D\u80FD\u5728\u8FD9\u91CC\u7981\u7528\u5B83\u4EEC\uFF0C\u5426\u5219\u670D\u52A1\u5C06\u65E0\u6CD5\u6B63\u5E38\u8FD0\u884C\u3002
`,title:"\u670D\u52A1\u63D0\u4F9B"},marketing:{description:"\u8FD9\u4E9B\u670D\u52A1\u4F1A\u5904\u7406\u4E2A\u4EBA\u4FE1\u606F\uFF0C\u5411\u60A8\u5C55\u793A\u60A8\u53EF\u80FD\u611F\u5174\u8DA3\u7684\u4EA7\u54C1\u3001\u670D\u52A1\u6216\u4E3B\u9898\u7684\u76F8\u5173\u5185\u5BB9\u3002",title:"\u5E02\u573A\u8425\u9500"},performance:{description:`\u8FD9\u4E9B\u670D\u52A1\u5904\u7406\u4E2A\u4EBA\u4FE1\u606F\u662F\u4E3A\u4E86\u4F18\u5316\u672C\u7F51\u7AD9\u63D0\u4F9B\u7684\u670D\u52A1\u3002
`,title:"\u6027\u80FD\u4F18\u5316"}},save:"\u633D\u6551",service:{disableAll:{description:"\u4F7F\u7528\u6B64\u5F00\u5173\u53EF\u542F\u7528\u6216\u7981\u7528\u6240\u6709\u670D\u52A1\u3002",title:"\u542F\u7528\u6216\u505C\u7528\u6240\u6709\u670D\u52A1"},optOut:{description:"\u8FD9\u4E2A\u670D\u52A1\u662F\u9ED8\u8BA4\u52A0\u8F7D\u7684(\u4F46\u4F60\u53EF\u4EE5\u9009\u62E9\u9000\u51FA)",title:"(\u9009\u62E9\u9000\u51FA)"},purpose:"\u76EE\u7684",purposes:"\u76EE\u7684",required:{description:"\u8FD9\u79CD\u670D\u52A1\u662F\u5FC5\u987B\u7684",title:"(\u603B\u662F\u9700\u8981)"}}};var Zi={bg:ft,ca:ht,cs:gt,da:vt,de:yt,el:bt,en:kt,es:wt,fi:St,fr:_t,gl:At,hr:xt,hu:Ct,it:$t,nl:zt,no:Et,oc:Mt,pl:Pt,pt:Ot,ro:Dt,ru:Tt,sr:Rt,sr_cyrl:Lt,sv:jt,tr:It,zh:Nt},Bt=Zi;function be(r,e,t){if(typeof e=="string"){if(e.length>=2&&e.startsWith("/")&&e.endsWith("/"))try{return new RegExp(e.slice(1,-1)).test(r)}catch{return!1}return e===r}if(e instanceof RegExp)return e.test(r);if(Array.isArray(e)&&e.length>0){let i=e[0];if(typeof i!="string")return!1;try{return new RegExp(i).test(r)}catch{return i===r}}return typeof e=="object"&&e!==null&&"name"in e&&"requireOrigin"in e&&Gi(e.requireOrigin,t)?be(r,e.name,t):!1}function ke(r,e){if(e instanceof RegExp)return e.test(r);if(typeof e!="string")return!1;if(e.length>=2&&e.startsWith("/")&&e.endsWith("/"))try{return new RegExp(e.slice(1,-1)).test(r)}catch{return!1}if(e.startsWith("*.")){let t=e.slice(2);return r===t||r.endsWith(`.${t}`)}return e===r}function Gi(r,e){for(let t of e)if(ke(t,r))return!0;return!1}var V=class{constructor(e){this.services=e;this.observedOrigins=new Set}classify(e){e.kind!=="cookie"&&e.origin&&this.observedOrigins.add(e.origin);let t=this._findService(e);return t?{matchedService:t,status:"known"}:{status:"unknown"}}hasObservedOrigin(e){return this.observedOrigins.has(e)}get observedOriginsView(){return this.observedOrigins}_findService(e){if(e.kind==="cookie"){for(let t of this.services)if(t.cookies){for(let i of t.cookies)if(be(e.identifier,i,this.observedOrigins))return t.name}return}if(e.origin){for(let t of this.services)if(t.origins){for(let i of t.origins)if(ke(e.origin,i))return t.name}}}};var Ji="simplecmp.recorder.";function Ut(r){return!!(!r||r==="localhost"||r.endsWith(".localhost")||r.endsWith(".local")||r.endsWith(".test")||/^127\.\d+\.\d+\.\d+$/.test(r)||/^192\.168\.\d+\.\d+$/.test(r)||/^10\.\d+\.\d+\.\d+$/.test(r)||r==="::1"||r==="0.0.0.0")}var we=class{constructor(e){this.listeners=new Set;this.settledListeners=new Set;this.detections=new Map;this.active=!1;this.options=e.options,this.classifier=e.classifier,this.services=e.services,this.onDetectionForLibEvent=e.onDetectionForLibEvent;let t=i=>this._ingest(i);this.watchers=e.watcherFactories.map(i=>i(t))}start(){if(this.active)return;this.active=!0;let e=typeof location<"u"?location.hostname:"",t=Ut(e);!t&&!this.options.silenceProductionWarning&&console.warn(`SimpleCMP: recorder is active on a hostname that looks like production (${e||"unknown"}). Set \`record: { silenceProductionWarning: true }\` to suppress this warning if intentional.`),this.options.persistInDev&&t&&this._loadFromStorage();for(let n of this.watchers)n.start();let i=this.options.summaryIntervalMs??3e4;i>0&&typeof setInterval<"u"&&(this.summaryTimer=setInterval(()=>this._logSummary(),i))}stop(){if(this.active){this.active=!1;for(let e of this.watchers)e.stop();this.summaryTimer!==void 0&&(clearInterval(this.summaryTimer),this.summaryTimer=void 0)}}getSnapshot(){return Array.from(this.detections.values())}clear(){this.detections.clear(),this._writeToStorage()}on(e,t){e==="detection"?this.listeners.add(t):e==="detectionSettled"&&this.settledListeners.add(t)}off(e,t){e==="detection"?this.listeners.delete(t):e==="detectionSettled"&&this.settledListeners.delete(t)}recordSyntheticDetection(e){this.active&&this._ingest(e)}enrichDetection(e,t){let i=`${e.kind}:${e.identifier}`,n=this.detections.get(i);if(!n)return;let o={...n,...t,lastSeen:Date.now()};this.detections.set(i,o),this._announce(o),this._writeToStorage()}exportConfig(){let e=new Map;for(let i of this.services){let n={name:i.name};i.cookies&&(n.cookies=i.cookies.slice()),i.origins&&(n.origins=i.origins.slice()),e.set(i.name,n)}let t=1;for(let i of this.detections.values()){if(i.status!=="unknown")continue;let n=i.kind==="cookie"?`unknown-cookie-${t++}`:`unknown-${i.origin?.replace(/[^a-z0-9]+/gi,"-")??"origin"}-${t++}`,o={name:n,purposes:[]};i.kind==="cookie"?o.cookies=[i.identifier]:i.origin&&(o.origins=[i.origin]),e.set(n,o)}return{services:Array.from(e.values())}}assertNoUnknown(){let e=this.getSnapshot().filter(i=>i.status==="unknown");if(e.length===0)return;let t=e.map(i=>`  - [${i.kind}] ${i.identifier}${i.origin?` (${i.origin})`:""}`).join(`
`);throw new Error(`SimpleCMP recorder: ${e.length} unknown detection(s):
${t}
Add a service for each, or pass \`record: { silenceProductionWarning: true }\` if intentional.`)}_ingest(e){if(e.kind==="cookie"&&this.options.ignoreCookies?.includes(e.identifier))return;let t=`${e.kind}:${e.identifier}`,i=Date.now(),n=this.detections.get(t);if(n){n.lastSeen=i,n.count+=1;return}let o=this.classifier.classify(e),{pending:s,...a}=o,c={kind:e.kind,identifier:e.identifier,origin:e.origin,firstSeen:i,lastSeen:i,firstSeenOn:e.firstSeenOn,count:1,...a};this.detections.set(t,c),this._announce(c),this._writeToStorage(),e.kind!=="cookie"&&e.origin&&this._reclassifyUnknownCookiesOnNewOrigin(),s?s.finally(()=>this._announceSettled(t)):this._announceSettled(t)}_announce(e){typeof console<"u"&&typeof console.info=="function"&&console.info(`[SimpleCMP recorder] ${e.kind} ${e.status==="unknown"?"\u{1F7E1} unknown":`\u2192 ${e.matchedService}`}: ${e.identifier}`);for(let t of[...this.listeners])try{t(e)}catch(i){console.warn("SimpleCMP recorder: listener threw:",i)}if(this.onDetectionForLibEvent)try{this.onDetectionForLibEvent(e)}catch(t){console.warn("SimpleCMP recorder: lib-event handler threw:",t)}}_reclassifyUnknownCookiesOnNewOrigin(){for(let e of this.detections.values()){if(e.kind!=="cookie"||e.status!=="unknown")continue;let t=this.classifier.classify({kind:e.kind,identifier:e.identifier,origin:e.origin,firstSeenOn:e.firstSeenOn});if(t.status==="known"&&t.matchedService){let i={status:"known",matchedService:t.matchedService};t.matchedVendor!==void 0&&(i.matchedVendor=t.matchedVendor),this.enrichDetection({kind:e.kind,identifier:e.identifier},i)}}}_announceSettled(e){let t=this.detections.get(e);if(t)for(let i of[...this.settledListeners])try{i(t)}catch(n){console.warn("SimpleCMP recorder: settled listener threw:",n)}}_logSummary(){if(this.detections.size===0)return;let e=Array.from(this.detections.values()).map(t=>({kind:t.kind,identifier:t.identifier,origin:t.origin??"",status:t.status,service:t.matchedService??"",count:t.count,firstSeenOn:t.firstSeenOn??""}));typeof console.table=="function"&&(console.groupCollapsed("[SimpleCMP recorder] catalog"),console.table(e),console.groupEnd())}_storageKey(){return Ji+(this.options.storageName??"default")}_loadFromStorage(){if(!(typeof sessionStorage>"u"))try{let e=sessionStorage.getItem(this._storageKey());if(!e)return;let t=JSON.parse(e);if(t.schema!==1||!Array.isArray(t.detections))return;for(let i of t.detections)this.detections.set(`${i.kind}:${i.identifier}`,i)}catch{}}_writeToStorage(){if(this.options.persistInDev&&!(typeof location>"u"||!Ut(location.hostname))&&!(typeof sessionStorage>"u"))try{let e=JSON.stringify({schema:1,detections:Array.from(this.detections.values())});sessionStorage.setItem(this._storageKey(),e)}catch{}}};function Xi(r){let e=new Set;if(!r)return e;for(let t of r.split(";")){let i=t.indexOf("="),n=(i>=0?t.slice(0,i):t).trim();n&&e.add(n)}return e}var Se=class{constructor(e,t={}){this.seen=new Set;this.sink=e,this.intervalMs=t.intervalMs??1e3,this.readCookies=t.readCookies??(()=>typeof document<"u"?document.cookie:""),this.getPathname=()=>typeof location<"u"?location.pathname+location.search:void 0}start(){this.timerId===void 0&&(this._scan(),this.timerId=setInterval(()=>this._scan(),this.intervalMs))}stop(){this.timerId!==void 0&&(clearInterval(this.timerId),this.timerId=void 0)}scanOnce(){this._scan()}_scan(){let e=Xi(this.readCookies()),t=this.getPathname();for(let i of e)this.seen.has(i)||(this.seen.add(i),this.sink({kind:"cookie",identifier:i,firstSeenOn:t}))}};var qe={SCRIPT:"script",IFRAME:"iframe",IMG:"image",LINK:"link",AUDIO:"request",VIDEO:"request",SOURCE:"request",TRACK:"request",EMBED:"request",OBJECT:"request"};function Yi(r){let e=r.tagName;if(e==="LINK")return r.href||void 0;if(e==="OBJECT")return r.data||void 0;let t=r.getAttribute("src");if(t)try{return new URL(t,location.href).href}catch{return}}function en(r){try{return new URL(r).hostname}catch{return}}var _e=class{constructor(e,t={}){this.seen=new Set;this.sink=e,this.root=t.root??(typeof document<"u"?document.documentElement:null)}start(){!this.root||this.observer||(this._initialScan(),!(typeof MutationObserver>"u")&&(this.observer=new MutationObserver(e=>this._onMutations(e)),this.observer.observe(this.root,{childList:!0,subtree:!0})))}stop(){this.observer&&(this.observer.disconnect(),this.observer=void 0)}_initialScan(){if(!this.root)return;let e=Object.keys(qe).join(","),t=this.root.querySelectorAll(e);for(let i of Array.from(t))this._handleElement(i)}_onMutations(e){for(let t of e)for(let i of Array.from(t.addedNodes)){if(i.nodeType!==1)continue;let n=i;if(this._handleElement(n),n.querySelectorAll){let o=Object.keys(qe).join(",");for(let s of Array.from(n.querySelectorAll(o)))this._handleElement(s)}}}_handleElement(e){let t=qe[e.tagName];if(!t)return;let i=Yi(e);if(!i)return;let n=en(i);if(!n||typeof location<"u"&&n===location.hostname)return;let o=`${t}:${i}`;if(this.seen.has(o))return;this.seen.add(o);let s={kind:t,identifier:i,origin:n,firstSeenOn:typeof location<"u"?location.pathname+location.search:void 0};this.sink(s)}};function tn(r){try{return new URL(r).hostname}catch{return}}var Ae=class{constructor(e,t={}){this.seen=new Set;this.sink=e,this.perf=t.performance??(typeof performance<"u"?performance:void 0),this.Observer=t.PerformanceObserver??(typeof PerformanceObserver<"u"?PerformanceObserver:void 0)}start(){if(!this.observer&&(this._drainExisting(),!!this.Observer))try{this.observer=new this.Observer(e=>this._handleList(e)),this.observer.observe({type:"resource",buffered:!1})}catch{try{this.observer=new this.Observer(e=>this._handleList(e)),this.observer.observe({entryTypes:["resource"]})}catch{this.observer=void 0}}}stop(){this.observer&&(this.observer.disconnect(),this.observer=void 0)}_drainExisting(){if(this.perf)try{let e=this.perf.getEntriesByType("resource");for(let t of e)this._handleEntry(t)}catch{}}_handleList(e){for(let t of e.getEntries())this._handleEntry(t)}_handleEntry(e){let t=e.name;if(!t)return;let i=tn(t);if(!i||typeof location<"u"&&i===location.hostname)return;let n=`request:${t}`;if(this.seen.has(n))return;this.seen.add(n);let o={kind:"request",identifier:t,origin:i,firstSeenOn:typeof location<"u"?location.pathname+location.search:void 0};this.sink(o)}};function qt(r){let e={matcher:r.matcher,consentChecker:r.consentChecker,sameOriginHosts:[window.location.host,...r.sameOriginHosts??[]],onBlock:r.onBlock??(()=>{})},t=[He(HTMLScriptElement.prototype,"script-src",e),He(HTMLIFrameElement.prototype,"iframe-src",e),He(HTMLImageElement.prototype,"img-src",e),nn(e),rn(e),on(e)];return()=>{for(let i of t)i()}}function xe(r,e){if(!r||r==="about:blank")return null;let t;try{t=new URL(r,window.location.href)}catch{return null}let{host:i,hostname:n}=t;if(i===""||e.sameOriginHosts.includes(i))return null;let o=e.matcher(n);return o===null||e.consentChecker(o)?null:o}function He(r,e,t){let i=Object.getOwnPropertyDescriptor(r,"src");if(!i?.get||!i?.set)return()=>{};let n=i.set;return Object.defineProperty(r,"src",{configurable:!0,enumerable:i.enumerable,get:i.get,set(o){let s=this.getAttribute?.("data-name");if(s!=null&&t.consentChecker(s)===!0){n.call(this,o);return}let a=xe(o,t);if(a!==null){t.onBlock({mechanism:e,url:o,service:a});return}n.call(this,o)}}),()=>Object.defineProperty(r,"src",i)}function nn(r){if(typeof window.fetch!="function")return()=>{};let e=window.fetch.bind(window);return window.fetch=function(i,n){let o;typeof i=="string"?o=i:i instanceof URL?o=i.href:o=i.url;let s=xe(o,r);return s!==null?(r.onBlock({mechanism:"fetch",url:o,service:s}),Promise.reject(new TypeError(`SimpleCMP: consent for ${s} not granted`))):e(i,n)},()=>{window.fetch=e}}function rn(r){let e=XMLHttpRequest.prototype.open,t=XMLHttpRequest.prototype.send,i="__simplecmpBlockedService";return XMLHttpRequest.prototype.open=function(o,s,...a){let c=this;c[i]!==void 0&&delete c[i];let p=typeof s=="string"?s:s.href,f=xe(p,r);f!==null&&(r.onBlock({mechanism:"xhr",url:p,service:f}),c[i]=f),e.call(this,o,s,...a)},XMLHttpRequest.prototype.send=function(o){this[i]===void 0&&t.call(this,o)},()=>{XMLHttpRequest.prototype.open=e,XMLHttpRequest.prototype.send=t}}function on(r){if(typeof navigator.sendBeacon!="function")return()=>{};let e=navigator.sendBeacon.bind(navigator);return navigator.sendBeacon=function(i,n){let o=typeof i=="string"?i:i.href,s=xe(o,r);return s!==null?(r.onBlock({mechanism:"sendBeacon",url:o,service:s}),!1):e(i,n)},()=>{navigator.sendBeacon=e}}function Ht(r,e={}){let t=r.filter(n=>Array.isArray(n.origins)&&n.origins.length>0).map(n=>({name:n.name,origins:n.origins})),i=e.blockAllUnknown===!0;return n=>{if(n==="")return null;for(let o of t)for(let s of o.origins)if(ke(n,s))return o.name;return i?n:null}}var Vt="simplecmp.servicedb.";function Ve(r,e){let t=e.cookie?`c:${e.cookie}`:`o:${e.origin??""}`;return`${Vt}${r}.${t}`}function sn(r,e){let t=r.get("Cache-Control");if(!t)return e;let i=/max-age=(\d+)/.exec(t);if(!i||!i[1])return e;let n=Number.parseInt(i[1],10);return Number.isNaN(n)||n<0?e:n*1e3}var F=class{constructor(e){this.inflight=new Map;this.warned=new Set;this.url=e.url.replace(/\/+$/,""),this.host=(()=>{try{return new URL(this.url).host}catch{return this.url}})(),this.auth=e.auth,this.cacheTtlMs=e.cacheTtlMs??864e5,this.timeoutMs=e.timeoutMs??3e3,this.apiVersion=e.apiVersion??"v1",this.fetchFn=e.fetch??(typeof fetch<"u"?fetch.bind(globalThis):void 0),this.storage=e.storage??(typeof localStorage<"u"?localStorage:void 0),this.now=e.now??(()=>Date.now())}async lookup(e){let t=Ve(this.host,e),i=this._readCache(t);return i!==void 0?(this.now()-i.storedAt<i.maxAgeMs||this._revalidate(t,e),i.match):this._fetchAndCache(t,e)}async lookupBatch(e){if(e.length===0)return[];let t=new Array(e.length).fill(void 0),i=[];for(let n=0;n<e.length;n++){let o=e[n];if(!o)continue;let s=Ve(this.host,o),a=this._readCache(s);a!==void 0&&this.now()-a.storedAt<a.maxAgeMs?t[n]=a.match:i.push({index:n,query:o})}if(i.length===0)return t.map(n=>n??null);try{let n=JSON.stringify({items:i.map(a=>a.query)}),s=(await this._request(`/${this.apiVersion}/lookup`,{method:"POST",headers:{"Content-Type":"application/json"},body:n}))?.items??[];for(let a=0;a<i.length;a++){let c=i[a],p=s[a];if(!c)continue;let f=p?.matches?.[0]??null,m=Ve(this.host,c.query);this._writeCache(m,f,this.cacheTtlMs),t[c.index]=f}}catch(n){this._warnOnce("batch-lookup",n);for(let o of i)t[o.index]=null}return t.map(n=>n??null)}clearCache(){if(!this.storage)return;let e=this.storage;if(typeof e.length!="number"||typeof e.key!="function")return;let t=`${Vt}${this.host}.`,i=[];for(let n=0;n<e.length;n++){let o=e.key(n);o?.startsWith(t)&&i.push(o)}for(let n of i)this.storage.removeItem(n)}async health(){try{return await this._request(`/${this.apiVersion}/health`,{method:"GET"})}catch{return null}}async _fetchAndCache(e,t){let i=this.inflight.get(e);if(i)return i;let n=(async()=>{try{let s=(await this._request(`/${this.apiVersion}/lookup`,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({items:[t]})}))?.items?.[0]?.matches?.[0]??null;return this._writeCache(e,s,this.cacheTtlMs),s}catch(o){return this._warnOnce("lookup",o),null}finally{this.inflight.delete(e)}})();return this.inflight.set(e,n),n}async _revalidate(e,t){this.inflight.has(e)||await this._fetchAndCache(e,t).catch(()=>{})}async _request(e,t){if(!this.fetchFn)throw new Error("fetch is unavailable");let i=new Headers(t.headers);if(this.auth){let s=this.auth.header??"Authorization",a=this.auth.scheme??"Bearer";i.set(s,`${a} ${this.auth.token}`.trim())}let n=typeof AbortController<"u"?new AbortController:null,o=n&&typeof setTimeout<"u"?setTimeout(()=>n.abort(),this.timeoutMs):void 0;try{let s=await this.fetchFn(`${this.url}${e}`,{...t,headers:i,signal:n?.signal});if(!s.ok)throw new Error(`Service DB ${e} responded ${s.status}`);return await s.json()}finally{o!==void 0&&clearTimeout(o)}}_readCache(e){if(this.storage)try{let t=this.storage.getItem(e);if(!t)return;let i=JSON.parse(t);return typeof i.storedAt!="number"||typeof i.maxAgeMs!="number"?void 0:i}catch{return}}_writeCache(e,t,i){if(this.storage)try{let n={match:t,storedAt:this.now(),maxAgeMs:i};this.storage.setItem(e,JSON.stringify(n))}catch{}}_absorbCacheControl(e){return sn(e,this.cacheTtlMs)}_warnOnce(e,t){if(this.warned.has(e))return;this.warned.add(e);let i=t instanceof Error?t.message:String(t);console.warn(`SimpleCMP service-db: ${e} failed (${i}). Falling back to local classification for this session category.`)}};var K=class{constructor(e,t){this.dbClient=e;this.listeners=new Set;this.local=new V(t)}classify(e){let t=this.local.classify(e);if(t.status==="known")return t;let i=e.kind==="cookie"?{cookie:e.identifier}:e.origin?{origin:e.origin}:null;if(!i)return t;let n=this.dbClient.lookup(i).then(o=>{o&&(e.kind==="cookie"&&!this._hostQualifierPasses(o,e.identifier)||this._dispatch(e,this._toEnrichment(o)))}).catch(()=>{});return{...t,pending:n}}_hostQualifierPasses(e,t){let i=e.matches?.cookies??[];if(i.length===0)return!0;let n=this.local.observedOriginsView;for(let o of i)if(be(t,o,n))return!0;return!1}onEnrichment(e){this.listeners.add(e)}offEnrichment(e){this.listeners.delete(e)}_dispatch(e,t){for(let i of this.listeners)try{i(e,t)}catch(n){console.warn("SimpleCMP service-db: enrichment listener threw:",n)}}_toEnrichment(e){return{matchedService:e.id,matchedVendor:e.vendor,status:"known"}}};var Ce=globalThis,$e=Ce.ShadowRoot&&(Ce.ShadyCSS===void 0||Ce.ShadyCSS.nativeShadow)&&"adoptedStyleSheets"in Document.prototype&&"replace"in CSSStyleSheet.prototype,Fe=Symbol(),Ft=new WeakMap,re=class{constructor(e,t,i){if(this._$cssResult$=!0,i!==Fe)throw Error("CSSResult is not constructable. Use `unsafeCSS` or `css` instead.");this.cssText=e,this.t=t}get styleSheet(){let e=this.o,t=this.t;if($e&&e===void 0){let i=t!==void 0&&t.length===1;i&&(e=Ft.get(t)),e===void 0&&((this.o=e=new CSSStyleSheet).replaceSync(this.cssText),i&&Ft.set(t,e))}return e}toString(){return this.cssText}},Kt=r=>new re(typeof r=="string"?r:r+"",void 0,Fe),b=(r,...e)=>{let t=r.length===1?r[0]:e.reduce((i,n,o)=>i+(s=>{if(s._$cssResult$===!0)return s.cssText;if(typeof s=="number")return s;throw Error("Value passed to 'css' function must be a 'css' function result: "+s+". Use 'unsafeCSS' to pass non-literal values, but take care to ensure page security.")})(n)+r[o+1],r[0]);return new re(t,r,Fe)},Wt=(r,e)=>{if($e)r.adoptedStyleSheets=e.map(t=>t instanceof CSSStyleSheet?t:t.styleSheet);else for(let t of e){let i=document.createElement("style"),n=Ce.litNonce;n!==void 0&&i.setAttribute("nonce",n),i.textContent=t.cssText,r.appendChild(i)}},Ke=$e?r=>r:r=>r instanceof CSSStyleSheet?(e=>{let t="";for(let i of e.cssRules)t+=i.cssText;return Kt(t)})(r):r;var{is:an,defineProperty:cn,getOwnPropertyDescriptor:ln,getOwnPropertyNames:pn,getOwnPropertySymbols:dn,getPrototypeOf:un}=Object,$=globalThis,Qt=$.trustedTypes,mn=Qt?Qt.emptyScript:"",fn=$.reactiveElementPolyfillSupport,oe=(r,e)=>r,se={toAttribute(r,e){switch(e){case Boolean:r=r?mn:null;break;case Object:case Array:r=r==null?r:JSON.stringify(r)}return r},fromAttribute(r,e){let t=r;switch(e){case Boolean:t=r!==null;break;case Number:t=r===null?null:Number(r);break;case Object:case Array:try{t=JSON.parse(r)}catch{t=null}}return t}},ze=(r,e)=>!an(r,e),Zt={attribute:!0,type:String,converter:se,reflect:!1,useDefault:!1,hasChanged:ze};Symbol.metadata??(Symbol.metadata=Symbol("metadata")),$.litPropertyMetadata??($.litPropertyMetadata=new WeakMap);var _=class extends HTMLElement{static addInitializer(e){this._$Ei(),(this.l??(this.l=[])).push(e)}static get observedAttributes(){return this.finalize(),this._$Eh&&[...this._$Eh.keys()]}static createProperty(e,t=Zt){if(t.state&&(t.attribute=!1),this._$Ei(),this.prototype.hasOwnProperty(e)&&((t=Object.create(t)).wrapped=!0),this.elementProperties.set(e,t),!t.noAccessor){let i=Symbol(),n=this.getPropertyDescriptor(e,i,t);n!==void 0&&cn(this.prototype,e,n)}}static getPropertyDescriptor(e,t,i){let{get:n,set:o}=ln(this.prototype,e)??{get(){return this[t]},set(s){this[t]=s}};return{get:n,set(s){let a=n?.call(this);o?.call(this,s),this.requestUpdate(e,a,i)},configurable:!0,enumerable:!0}}static getPropertyOptions(e){return this.elementProperties.get(e)??Zt}static _$Ei(){if(this.hasOwnProperty(oe("elementProperties")))return;let e=un(this);e.finalize(),e.l!==void 0&&(this.l=[...e.l]),this.elementProperties=new Map(e.elementProperties)}static finalize(){if(this.hasOwnProperty(oe("finalized")))return;if(this.finalized=!0,this._$Ei(),this.hasOwnProperty(oe("properties"))){let t=this.properties,i=[...pn(t),...dn(t)];for(let n of i)this.createProperty(n,t[n])}let e=this[Symbol.metadata];if(e!==null){let t=litPropertyMetadata.get(e);if(t!==void 0)for(let[i,n]of t)this.elementProperties.set(i,n)}this._$Eh=new Map;for(let[t,i]of this.elementProperties){let n=this._$Eu(t,i);n!==void 0&&this._$Eh.set(n,t)}this.elementStyles=this.finalizeStyles(this.styles)}static finalizeStyles(e){let t=[];if(Array.isArray(e)){let i=new Set(e.flat(1/0).reverse());for(let n of i)t.unshift(Ke(n))}else e!==void 0&&t.push(Ke(e));return t}static _$Eu(e,t){let i=t.attribute;return i===!1?void 0:typeof i=="string"?i:typeof e=="string"?e.toLowerCase():void 0}constructor(){super(),this._$Ep=void 0,this.isUpdatePending=!1,this.hasUpdated=!1,this._$Em=null,this._$Ev()}_$Ev(){this._$ES=new Promise(e=>this.enableUpdating=e),this._$AL=new Map,this._$E_(),this.requestUpdate(),this.constructor.l?.forEach(e=>e(this))}addController(e){(this._$EO??(this._$EO=new Set)).add(e),this.renderRoot!==void 0&&this.isConnected&&e.hostConnected?.()}removeController(e){this._$EO?.delete(e)}_$E_(){let e=new Map,t=this.constructor.elementProperties;for(let i of t.keys())this.hasOwnProperty(i)&&(e.set(i,this[i]),delete this[i]);e.size>0&&(this._$Ep=e)}createRenderRoot(){let e=this.shadowRoot??this.attachShadow(this.constructor.shadowRootOptions);return Wt(e,this.constructor.elementStyles),e}connectedCallback(){this.renderRoot??(this.renderRoot=this.createRenderRoot()),this.enableUpdating(!0),this._$EO?.forEach(e=>e.hostConnected?.())}enableUpdating(e){}disconnectedCallback(){this._$EO?.forEach(e=>e.hostDisconnected?.())}attributeChangedCallback(e,t,i){this._$AK(e,i)}_$ET(e,t){let i=this.constructor.elementProperties.get(e),n=this.constructor._$Eu(e,i);if(n!==void 0&&i.reflect===!0){let o=(i.converter?.toAttribute!==void 0?i.converter:se).toAttribute(t,i.type);this._$Em=e,o==null?this.removeAttribute(n):this.setAttribute(n,o),this._$Em=null}}_$AK(e,t){let i=this.constructor,n=i._$Eh.get(e);if(n!==void 0&&this._$Em!==n){let o=i.getPropertyOptions(n),s=typeof o.converter=="function"?{fromAttribute:o.converter}:o.converter?.fromAttribute!==void 0?o.converter:se;this._$Em=n;let a=s.fromAttribute(t,o.type);this[n]=a??this._$Ej?.get(n)??a,this._$Em=null}}requestUpdate(e,t,i,n=!1,o){if(e!==void 0){let s=this.constructor;if(n===!1&&(o=this[e]),i??(i=s.getPropertyOptions(e)),!((i.hasChanged??ze)(o,t)||i.useDefault&&i.reflect&&o===this._$Ej?.get(e)&&!this.hasAttribute(s._$Eu(e,i))))return;this.C(e,t,i)}this.isUpdatePending===!1&&(this._$ES=this._$EP())}C(e,t,{useDefault:i,reflect:n,wrapped:o},s){i&&!(this._$Ej??(this._$Ej=new Map)).has(e)&&(this._$Ej.set(e,s??t??this[e]),o!==!0||s!==void 0)||(this._$AL.has(e)||(this.hasUpdated||i||(t=void 0),this._$AL.set(e,t)),n===!0&&this._$Em!==e&&(this._$Eq??(this._$Eq=new Set)).add(e))}async _$EP(){this.isUpdatePending=!0;try{await this._$ES}catch(t){Promise.reject(t)}let e=this.scheduleUpdate();return e!=null&&await e,!this.isUpdatePending}scheduleUpdate(){return this.performUpdate()}performUpdate(){if(!this.isUpdatePending)return;if(!this.hasUpdated){if(this.renderRoot??(this.renderRoot=this.createRenderRoot()),this._$Ep){for(let[n,o]of this._$Ep)this[n]=o;this._$Ep=void 0}let i=this.constructor.elementProperties;if(i.size>0)for(let[n,o]of i){let{wrapped:s}=o,a=this[n];s!==!0||this._$AL.has(n)||a===void 0||this.C(n,void 0,o,a)}}let e=!1,t=this._$AL;try{e=this.shouldUpdate(t),e?(this.willUpdate(t),this._$EO?.forEach(i=>i.hostUpdate?.()),this.update(t)):this._$EM()}catch(i){throw e=!1,this._$EM(),i}e&&this._$AE(t)}willUpdate(e){}_$AE(e){this._$EO?.forEach(t=>t.hostUpdated?.()),this.hasUpdated||(this.hasUpdated=!0,this.firstUpdated(e)),this.updated(e)}_$EM(){this._$AL=new Map,this.isUpdatePending=!1}get updateComplete(){return this.getUpdateComplete()}getUpdateComplete(){return this._$ES}shouldUpdate(e){return!0}update(e){this._$Eq&&(this._$Eq=this._$Eq.forEach(t=>this._$ET(t,this[t]))),this._$EM()}updated(e){}firstUpdated(e){}};_.elementStyles=[],_.shadowRootOptions={mode:"open"},_[oe("elementProperties")]=new Map,_[oe("finalized")]=new Map,fn?.({ReactiveElement:_}),($.reactiveElementVersions??($.reactiveElementVersions=[])).push("2.1.2");var ce=globalThis,Gt=r=>r,Ee=ce.trustedTypes,Jt=Ee?Ee.createPolicy("lit-html",{createHTML:r=>r}):void 0,ni="$lit$",z=`lit$${Math.random().toFixed(9).slice(2)}$`,ri="?"+z,hn=`<${ri}>`,N=document,le=()=>N.createComment(""),pe=r=>r===null||typeof r!="object"&&typeof r!="function",Ye=Array.isArray,gn=r=>Ye(r)||typeof r?.[Symbol.iterator]=="function",We=`[ 	
\f\r]`,ae=/<(?:(!--|\/[^a-zA-Z])|(\/?[a-zA-Z][^>\s]*)|(\/?$))/g,Xt=/-->/g,Yt=/>/g,j=RegExp(`>|${We}(?:([^\\s"'>=/]+)(${We}*=${We}*(?:[^ 	
\f\r"'\`<>=]|("|')|))|$)`,"g"),ei=/'/g,ti=/"/g,oi=/^(?:script|style|textarea|title)$/i,et=r=>(e,...t)=>({_$litType$:r,strings:e,values:t}),d=et(1),xo=et(2),Co=et(3),A=Symbol.for("lit-noChange"),l=Symbol.for("lit-nothing"),ii=new WeakMap,I=N.createTreeWalker(N,129);function si(r,e){if(!Ye(r)||!r.hasOwnProperty("raw"))throw Error("invalid template strings array");return Jt!==void 0?Jt.createHTML(e):e}var vn=(r,e)=>{let t=r.length-1,i=[],n,o=e===2?"<svg>":e===3?"<math>":"",s=ae;for(let a=0;a<t;a++){let c=r[a],p,f,m=-1,u=0;for(;u<c.length&&(s.lastIndex=u,f=s.exec(c),f!==null);)u=s.lastIndex,s===ae?f[1]==="!--"?s=Xt:f[1]!==void 0?s=Yt:f[2]!==void 0?(oi.test(f[2])&&(n=RegExp("</"+f[2],"g")),s=j):f[3]!==void 0&&(s=j):s===j?f[0]===">"?(s=n??ae,m=-1):f[1]===void 0?m=-2:(m=s.lastIndex-f[2].length,p=f[1],s=f[3]===void 0?j:f[3]==='"'?ti:ei):s===ti||s===ei?s=j:s===Xt||s===Yt?s=ae:(s=j,n=void 0);let v=s===j&&r[a+1].startsWith("/>")?" ":"";o+=s===ae?c+hn:m>=0?(i.push(p),c.slice(0,m)+ni+c.slice(m)+z+v):c+z+(m===-2?a:v)}return[si(r,o+(r[t]||"<?>")+(e===2?"</svg>":e===3?"</math>":"")),i]},de=class r{constructor({strings:e,_$litType$:t},i){let n;this.parts=[];let o=0,s=0,a=e.length-1,c=this.parts,[p,f]=vn(e,t);if(this.el=r.createElement(p,i),I.currentNode=this.el.content,t===2||t===3){let m=this.el.content.firstChild;m.replaceWith(...m.childNodes)}for(;(n=I.nextNode())!==null&&c.length<a;){if(n.nodeType===1){if(n.hasAttributes())for(let m of n.getAttributeNames())if(m.endsWith(ni)){let u=f[s++],v=n.getAttribute(m).split(z),H=/([.?@])?(.*)/.exec(u);c.push({type:1,index:o,name:H[2],strings:v,ctor:H[1]==="."?Ze:H[1]==="?"?Ge:H[1]==="@"?Je:Q}),n.removeAttribute(m)}else m.startsWith(z)&&(c.push({type:6,index:o}),n.removeAttribute(m));if(oi.test(n.tagName)){let m=n.textContent.split(z),u=m.length-1;if(u>0){n.textContent=Ee?Ee.emptyScript:"";for(let v=0;v<u;v++)n.append(m[v],le()),I.nextNode(),c.push({type:2,index:++o});n.append(m[u],le())}}}else if(n.nodeType===8)if(n.data===ri)c.push({type:2,index:o});else{let m=-1;for(;(m=n.data.indexOf(z,m+1))!==-1;)c.push({type:7,index:o}),m+=z.length-1}o++}}static createElement(e,t){let i=N.createElement("template");return i.innerHTML=e,i}};function W(r,e,t=r,i){if(e===A)return e;let n=i!==void 0?t._$Co?.[i]:t._$Cl,o=pe(e)?void 0:e._$litDirective$;return n?.constructor!==o&&(n?._$AO?.(!1),o===void 0?n=void 0:(n=new o(r),n._$AT(r,t,i)),i!==void 0?(t._$Co??(t._$Co=[]))[i]=n:t._$Cl=n),n!==void 0&&(e=W(r,n._$AS(r,e.values),n,i)),e}var Qe=class{constructor(e,t){this._$AV=[],this._$AN=void 0,this._$AD=e,this._$AM=t}get parentNode(){return this._$AM.parentNode}get _$AU(){return this._$AM._$AU}u(e){let{el:{content:t},parts:i}=this._$AD,n=(e?.creationScope??N).importNode(t,!0);I.currentNode=n;let o=I.nextNode(),s=0,a=0,c=i[0];for(;c!==void 0;){if(s===c.index){let p;c.type===2?p=new ue(o,o.nextSibling,this,e):c.type===1?p=new c.ctor(o,c.name,c.strings,this,e):c.type===6&&(p=new Xe(o,this,e)),this._$AV.push(p),c=i[++a]}s!==c?.index&&(o=I.nextNode(),s++)}return I.currentNode=N,n}p(e){let t=0;for(let i of this._$AV)i!==void 0&&(i.strings!==void 0?(i._$AI(e,i,t),t+=i.strings.length-2):i._$AI(e[t])),t++}},ue=class r{get _$AU(){return this._$AM?._$AU??this._$Cv}constructor(e,t,i,n){this.type=2,this._$AH=l,this._$AN=void 0,this._$AA=e,this._$AB=t,this._$AM=i,this.options=n,this._$Cv=n?.isConnected??!0}get parentNode(){let e=this._$AA.parentNode,t=this._$AM;return t!==void 0&&e?.nodeType===11&&(e=t.parentNode),e}get startNode(){return this._$AA}get endNode(){return this._$AB}_$AI(e,t=this){e=W(this,e,t),pe(e)?e===l||e==null||e===""?(this._$AH!==l&&this._$AR(),this._$AH=l):e!==this._$AH&&e!==A&&this._(e):e._$litType$!==void 0?this.$(e):e.nodeType!==void 0?this.T(e):gn(e)?this.k(e):this._(e)}O(e){return this._$AA.parentNode.insertBefore(e,this._$AB)}T(e){this._$AH!==e&&(this._$AR(),this._$AH=this.O(e))}_(e){this._$AH!==l&&pe(this._$AH)?this._$AA.nextSibling.data=e:this.T(N.createTextNode(e)),this._$AH=e}$(e){let{values:t,_$litType$:i}=e,n=typeof i=="number"?this._$AC(e):(i.el===void 0&&(i.el=de.createElement(si(i.h,i.h[0]),this.options)),i);if(this._$AH?._$AD===n)this._$AH.p(t);else{let o=new Qe(n,this),s=o.u(this.options);o.p(t),this.T(s),this._$AH=o}}_$AC(e){let t=ii.get(e.strings);return t===void 0&&ii.set(e.strings,t=new de(e)),t}k(e){Ye(this._$AH)||(this._$AH=[],this._$AR());let t=this._$AH,i,n=0;for(let o of e)n===t.length?t.push(i=new r(this.O(le()),this.O(le()),this,this.options)):i=t[n],i._$AI(o),n++;n<t.length&&(this._$AR(i&&i._$AB.nextSibling,n),t.length=n)}_$AR(e=this._$AA.nextSibling,t){for(this._$AP?.(!1,!0,t);e!==this._$AB;){let i=Gt(e).nextSibling;Gt(e).remove(),e=i}}setConnected(e){this._$AM===void 0&&(this._$Cv=e,this._$AP?.(e))}},Q=class{get tagName(){return this.element.tagName}get _$AU(){return this._$AM._$AU}constructor(e,t,i,n,o){this.type=1,this._$AH=l,this._$AN=void 0,this.element=e,this.name=t,this._$AM=n,this.options=o,i.length>2||i[0]!==""||i[1]!==""?(this._$AH=Array(i.length-1).fill(new String),this.strings=i):this._$AH=l}_$AI(e,t=this,i,n){let o=this.strings,s=!1;if(o===void 0)e=W(this,e,t,0),s=!pe(e)||e!==this._$AH&&e!==A,s&&(this._$AH=e);else{let a=e,c,p;for(e=o[0],c=0;c<o.length-1;c++)p=W(this,a[i+c],t,c),p===A&&(p=this._$AH[c]),s||(s=!pe(p)||p!==this._$AH[c]),p===l?e=l:e!==l&&(e+=(p??"")+o[c+1]),this._$AH[c]=p}s&&!n&&this.j(e)}j(e){e===l?this.element.removeAttribute(this.name):this.element.setAttribute(this.name,e??"")}},Ze=class extends Q{constructor(){super(...arguments),this.type=3}j(e){this.element[this.name]=e===l?void 0:e}},Ge=class extends Q{constructor(){super(...arguments),this.type=4}j(e){this.element.toggleAttribute(this.name,!!e&&e!==l)}},Je=class extends Q{constructor(e,t,i,n,o){super(e,t,i,n,o),this.type=5}_$AI(e,t=this){if((e=W(this,e,t,0)??l)===A)return;let i=this._$AH,n=e===l&&i!==l||e.capture!==i.capture||e.once!==i.once||e.passive!==i.passive,o=e!==l&&(i===l||n);n&&this.element.removeEventListener(this.name,this,i),o&&this.element.addEventListener(this.name,this,e),this._$AH=e}handleEvent(e){typeof this._$AH=="function"?this._$AH.call(this.options?.host??this.element,e):this._$AH.handleEvent(e)}},Xe=class{constructor(e,t,i){this.element=e,this.type=6,this._$AN=void 0,this._$AM=t,this.options=i}get _$AU(){return this._$AM._$AU}_$AI(e){W(this,e)}};var yn=ce.litHtmlPolyfillSupport;yn?.(de,ue),(ce.litHtmlVersions??(ce.litHtmlVersions=[])).push("3.3.2");var ai=(r,e,t)=>{let i=t?.renderBefore??e,n=i._$litPart$;if(n===void 0){let o=t?.renderBefore??null;i._$litPart$=n=new ue(e.insertBefore(le(),o),o,void 0,t??{})}return n._$AI(r),n};var me=globalThis,E=class extends _{constructor(){super(...arguments),this.renderOptions={host:this},this._$Do=void 0}createRenderRoot(){var t;let e=super.createRenderRoot();return(t=this.renderOptions).renderBefore??(t.renderBefore=e.firstChild),e}update(e){let t=this.render();this.hasUpdated||(this.renderOptions.isConnected=this.isConnected),super.update(e),this._$Do=ai(t,this.renderRoot,this.renderOptions)}connectedCallback(){super.connectedCallback(),this._$Do?.setConnected(!0)}disconnectedCallback(){super.disconnectedCallback(),this._$Do?.setConnected(!1)}render(){return A}};E._$litElement$=!0,E.finalized=!0,me.litElementHydrateSupport?.({LitElement:E});var bn=me.litElementPolyfillSupport;bn?.({LitElement:E});(me.litElementVersions??(me.litElementVersions=[])).push("4.2.2");var k=r=>(e,t)=>{t!==void 0?t.addInitializer(()=>{customElements.define(r,e)}):customElements.define(r,e)};var kn={attribute:!0,type:String,converter:se,reflect:!1,hasChanged:ze},wn=(r=kn,e,t)=>{let{kind:i,metadata:n}=t,o=globalThis.litPropertyMetadata.get(n);if(o===void 0&&globalThis.litPropertyMetadata.set(n,o=new Map),i==="setter"&&((r=Object.create(r)).wrapped=!0),o.set(t.name,r),i==="accessor"){let{name:s}=t;return{set(a){let c=e.get.call(this);e.set.call(this,a),this.requestUpdate(s,c,r,!0,a)},init(a){return a!==void 0&&this.C(s,void 0,r,a),a}}}if(i==="setter"){let{name:s}=t;return function(a){let c=this[s];e.call(this,a),this.requestUpdate(s,c,r,!0,a)}}throw Error("Unsupported decorator location: "+i)};function g(r){return(e,t)=>typeof t=="object"?wn(r,e,t):((i,n,o)=>{let s=n.hasOwnProperty(o);return n.constructor.createProperty(o,i),s?Object.getOwnPropertyDescriptor(n,o):void 0})(r,e,t)}function Me(r){return g({...r,state:!0,attribute:!1})}var B=(r,e,t)=>(t.configurable=!0,t.enumerable=!0,Reflect.decorate&&typeof e!="object"&&Object.defineProperty(r,e,t),t);function Pe(r,e){return(t,i,n)=>{let o=s=>s.renderRoot?.querySelector(r)??null;if(e){let{get:s,set:a}=typeof i=="object"?t:n??(()=>{let c=Symbol();return{get(){return this[c]},set(p){this[c]=p}}})();return B(t,i,{get(){let c=s.call(this);return c===void 0&&(c=o(this),(c!==null||this.hasUpdated)&&a.call(this,c)),c}})}return B(t,i,{get(){return o(this)}})}}var tt=r=>r??l;var ci={ATTRIBUTE:1,CHILD:2,PROPERTY:3,BOOLEAN_ATTRIBUTE:4,EVENT:5,ELEMENT:6},li=r=>(...e)=>({_$litDirective$:r,values:e}),Oe=class{constructor(e){}get _$AU(){return this._$AM._$AU}_$AT(e,t,i){this._$Ct=e,this._$AM=t,this._$Ci=i}_$AS(e,t){return this.update(e,t)}update(e,t){return this.render(...t)}};var fe=class extends Oe{constructor(e){if(super(e),this.it=l,e.type!==ci.CHILD)throw Error(this.constructor.directiveName+"() can only be used in child bindings")}render(e){if(e===l||e==null)return this._t=void 0,this.it=e;if(e===A)return e;if(typeof e!="string")throw Error(this.constructor.directiveName+"() called with a non-string value");if(e===this.it)return this._t;this.it=e;let t=[e];return t.raw=t,this._t={_$litType$:this.constructor.resultType,strings:t,values:[]}}};fe.directiveName="unsafeHTML",fe.resultType=1;var Z=li(fe);function x(r){return r.split("-").map(e=>e.slice(0,1).toUpperCase()+e.slice(1)).join(" ")}function pi(r){let e=mt(r),t=Ne(r),i=r.fallbackLang??"zz";return(n,...o)=>dt(e,t,i,n,...o)}function Sn(r){return{update:()=>{r.requestUpdate()}}}var y=class extends E{_t(e,...t){if(this._translator===void 0){if(this.config===void 0)return e;this._translator=pi(this.config)}return this._translator(e,...t)}createRenderRoot(){return this.getAttribute("mode")==="light"?this:super.createRenderRoot()}_detachWatcher(){this._watcher!==void 0&&this._watcherManager!==void 0&&this._watcherManager.unwatch(this._watcher),this._watcher=void 0,this._watcherManager=void 0}_syncWatcher(){this._watcherManager!==this.manager&&(this._detachWatcher(),this.manager!==void 0&&(this._watcher=Sn(this),this._watcherManager=this.manager,this.manager.watch(this._watcher)))}connectedCallback(){super.connectedCallback(),this._syncWatcher()}disconnectedCallback(){this._detachWatcher(),super.disconnectedCallback()}willUpdate(e){super.willUpdate(e),e.has("config")&&(this._translator=void 0),e.has("manager")&&this._syncWatcher()}_emit(e,t){this.dispatchEvent(new CustomEvent(`simplecmp:${e}`,{detail:t,bubbles:!0,composed:!0}))}};h([g({attribute:!1})],y.prototype,"config",2),h([g({attribute:!1})],y.prototype,"manager",2);var w=b`
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

    color: var(--simplecmp-color-text);
    font-family: var(--simplecmp-font-family);
    font-size: var(--simplecmp-font-size);
    line-height: var(--simplecmp-line-height);
  }

  @media (prefers-reduced-motion: reduce) {
    :host {
      --simplecmp-transition: none;
    }
  }
`;function _n(r,e){if(r!==void 0){if(typeof r=="string")return r;if(typeof r=="object")return r[e]??r.default}}var M=class extends y{constructor(){super(...arguments);this.testing=!1;this._handleAccept=()=>{this.manager!==void 0&&(this.manager.changeAll(!0),this.manager.saveAndApplyConsents("accept"),this._emit("accept"))};this._handleDecline=()=>{this.manager!==void 0&&(this.manager.changeAll(!1),this.manager.saveAndApplyConsents("decline"),this._emit("decline"))};this._handleConfigure=t=>{t.preventDefault(),this._emit("configure")}}connectedCallback(){super.connectedCallback(),this.config?.autoFocus===!0&&queueMicrotask(()=>this.focus())}render(){let t=this.config,i=this.manager;if(t===void 0||i===void 0)return l;if(!this.testing&&i.confirmed)return l;if(t.noNotice===!0)return l;let n=this._activeLang(),o=this._resolvePolicyUrl(t.privacyPolicy,["privacyPolicyUrl"],n),s=this._resolvePolicyUrl(t.imprint,["imprintUrl"],n),a=this._t(["!","consentNotice","title"]),c=t.showNoticeTitle===!0&&a!==void 0,p=t.htmlTexts===!0,f=o?d`<a href=${o}>${this._t(["privacyPolicy","name"])}</a>`:"",m=s?d`<a href=${s}>${this._imprintLinkText()}</a>`:"",u=d`<a
      href="#"
      @click=${this._handleConfigure}
      >${this._t(["consentNotice","learnMore"])}</a
    >`,v=this._t(["consentNotice","description"],{purposes:d`<strong>${this._purposesText(t)}</strong>`,privacyPolicy:f,imprint:m,learnMoreLink:u});return d`
      <div
        class="cn-body"
        role="dialog"
        aria-labelledby=${tt(c?"cn-title":void 0)}
        aria-label=${tt(c?void 0:a)}
        aria-describedby="cn-description"
        tabindex="0"
      >
        ${c?d`<h2 id="cn-title">${a}</h2>`:l}
        <p id="cn-description">${p?An(v):v}</p>
        ${this._renderPolicyLinks(o,s)}
        ${i.changed?d`<p class="cn-changes">${this._t(["consentNotice","changeDescription"])}</p>`:l}
        ${this.testing?d`<p>${this._t(["consentNotice","testing"])}</p>`:l}
        <div class="cn-buttons">
          ${t.hideLearnMore===!0?l:d`<button
                type="button"
                class="cn-configure"
                @click=${this._handleConfigure}
              >
                ${this._t(["consentNotice","learnMore"])}
              </button>`}
          ${t.hideDeclineAll===!0?l:d`<button
                type="button"
                class="cn-decline"
                @click=${this._handleDecline}
              >
                ${this._t(["decline"])}
              </button>`}
          <button type="button" class="cn-accept" @click=${this._handleAccept}>
            ${this._t(["ok"])}
          </button>
        </div>
      </div>
    `}_activeLang(){return this.config?.lang??document.documentElement.lang??"en"}_purposesText(t){let i=t.purposeOrder??[],o=lt(t).filter(c=>c!=="functional").sort((c,p)=>i.indexOf(c)-i.indexOf(p)).map(c=>this._tString(["!","purposes",c,"title?"])||x(c));if(o.length<=1)return o[0]??"";let s=o.slice(0,-2),a=o.slice(-2).join(" & ");return[...s,a].join(", ")}_resolvePolicyUrl(t,i,n){let o=_n(t,n);if(o!==void 0)return o;let s=this._tString(["!",...i]);return s===""?void 0:s}_imprintLinkText(){return this._tString(["!","consentNotice","imprint","name"])||this._tString(["!","imprint","name"])||"Imprint"}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}_renderPolicyLinks(t,i){return t===void 0&&i===void 0?l:d`
      <p class="cn-policy-links">
        ${t?d`<a href=${t}>${this._t(["privacyPolicy","name"])}</a>`:l}
        ${t&&i?" \xB7 ":l}
        ${i?d`<a href=${i}>${this._imprintLinkText()}</a>`:l}
      </p>
    `}};M.styles=[w,b`
      :host {
        display: block;
        position: fixed;
        right: var(--simplecmp-spacing);
        bottom: var(--simplecmp-spacing);
        max-width: 30rem;
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

      .cn-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: var(--simplecmp-spacing-sm);
        margin-top: var(--simplecmp-spacing);
      }

      button {
        font: inherit;
        border: 1px solid transparent;
        border-radius: var(--simplecmp-radius);
        padding: var(--simplecmp-spacing-sm) var(--simplecmp-spacing);
        cursor: pointer;
      }

      button.cn-accept {
        background: var(--simplecmp-color-primary);
        color: white;
      }

      button.cn-accept:hover {
        background: var(--simplecmp-color-primary-hover);
      }

      button.cn-decline {
        background: transparent;
        color: var(--simplecmp-color-danger);
        border-color: var(--simplecmp-color-danger);
      }

      button.cn-configure {
        background: transparent;
        color: var(--simplecmp-color-text);
        border-color: var(--simplecmp-color-border);
      }

      a {
        color: var(--simplecmp-color-primary);
      }

      .cn-changes {
        font-size: var(--simplecmp-font-size-sm);
        font-style: italic;
        color: var(--simplecmp-color-text-muted);
      }
    `],h([g({type:Boolean})],M.prototype,"testing",2),M=h([k("simplecmp-banner")],M);function An(r){return typeof r=="string"?Z(r):Array.isArray(r)?r.map(e=>typeof e=="string"?Z(e):e):r}var P=class extends y{constructor(){super(...arguments);this.open=!1;this._onCancel=()=>{};this._onClose=()=>{this.open=!1,this._emit("provider-info-close")};this._onCloseClick=()=>{this.open=!1,this._emit("provider-info-close")};this._onBackdropClick=t=>{t.target===this._dialog&&(this.open=!1,this._emit("provider-info-close"))}}updated(t){if(super.updated(t),t.has("open")){let i=this._dialog;if(i===void 0)return;this.open&&!i.open?i.showModal():!this.open&&i.open&&i.close()}}render(){let t=this.service;if(t===void 0)return l;let i=this._tString(["providerInfo","close"])||"Close";return d`
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
    `}_renderBody(t){let i=[],n=(o,s,a=!1)=>{if(s===void 0||s==="")return;let c=this._tString(["providerInfo","field",o])||o,p=a?d`<a href=${s} target="_blank" rel="noopener noreferrer">${s}</a>`:d`${s}`;i.push(d`<dt>${c}</dt><dd>${p}</dd>`)};return n("vendor",t.vendor),n("description",t.vendorDescription),n("address",t.vendorAddress),n("country",t.vendorCountry),n("privacyPolicy",t.privacyPolicyUrl,!0),n("optOut",t.vendorOptOutUrl,!0),n("partner",t.vendorPartner),i.length===0?d`<p class="empty">
        ${this._tString(["providerInfo","noData"])||"No provider information available."}
      </p>`:d`<dl>${i}</dl>`}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}};P.styles=[w,b`
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
    `],h([g({attribute:!1})],P.prototype,"service",2),h([g({type:Boolean,reflect:!0})],P.prototype,"open",2),h([Pe("dialog")],P.prototype,"_dialog",2),P=h([k("simplecmp-provider-info-modal")],P);var O=class extends y{constructor(){super(...arguments);this._autoPlaceholder=!1;this._providerInfoOpen=!1;this._onAcceptOnce=()=>{let t=this._resolveService();t===void 0||this.manager===void 0||(this.manager.updateConsent(t.name,!0),this.manager.applyConsents(!1,!0,t.name),this.manager.updateConsent(t.name,!1),this._emit("contextual-accept-once",{name:t.name}))};this._onAccept=()=>{let t=this._resolveService();t===void 0||this.manager===void 0||(this.manager.updateConsent(t.name,!0),this.manager.confirmed?(this.manager.saveConsents("contextual-accept"),this.manager.applyConsents(!1,!0,t.name)):this.manager.applyConsents(!1,!0,t.name),this._emit("contextual-accept",{name:t.name}))};this._onConfigure=t=>{t.preventDefault(),this._emit("configure")};this._onProviderInfoOpen=t=>{t.preventDefault(),this._providerInfoOpen=!0};this._onProviderInfoClose=()=>{this._providerInfoOpen=!1}}connectedCallback(){super.connectedCallback(),this._autoPlaceholder=this.hasAttribute("data-simplecmp-auto-placeholder"),this.hasAttribute("role")||this.setAttribute("role","region")}_resolveService(){if(this.service!==void 0)return this.service;if(this.serviceName!==void 0){if(this.config!==void 0){let t=this.config.services.find(i=>i.name===this.serviceName);if(t!==void 0)return t}return{name:this.serviceName,purposes:[]}}}_renderMode(){let t=this.service?.name??this.serviceName;return t===void 0||this.config?.services.some(n=>n.name===t)===!0?"configured":this.getAttribute("data-blocked-source")==="host"?"host":"library"}render(){let t=this._resolveService();if(t===void 0||this.manager===void 0)return l;let i=this._renderMode(),n=i==="host"?t.name:this._resolveTitle(t),o=i==="host"?this._t(["contextualConsent","descriptionUnknownHost"],{title:n}):this._resolveDescription(t,n);if(i==="host")return d`<p>${o}</p>`;let s=this.manager.store.get()!==null,a=i==="configured"&&s,c=i==="configured";return d`
      <p>${o}</p>
      ${this._renderPurposes(t)}
      ${this._renderProviderInfoLink(t)}
      <div class="buttons">
        <button type="button" class="accept-once" @click=${this._onAcceptOnce}>
          ${this._t(["contextualConsent","acceptOnce"])}
        </button>
        ${a?d`<button type="button" class="accept" @click=${this._onAccept}>
              ${this._t(["contextualConsent","acceptAlways"])}
            </button>`:l}
        ${c?d`<button type="button" class="configure" @click=${this._onConfigure}>
              ${this._t(["contextualConsent","modalLinkText"])}
            </button>`:l}
      </div>
      ${this._renderProviderInfoModal(t)}
    `}_renderProviderInfoLink(t){return this._hasProviderData(t)?d`
      <p class="provider-info-link">
        <a href="#" @click=${this._onProviderInfoOpen}>
          ${this._tString(["contextualConsent","providerInfoLink"])||"More information \u203A"}
        </a>
      </p>
    `:l}_renderProviderInfoModal(t){return this._providerInfoOpen?d`
      <simplecmp-provider-info-modal
        .service=${this._resolveProviderService(t)}
        .config=${this.config}
        .manager=${this.manager}
        ?open=${this._providerInfoOpen}
        @simplecmp:provider-info-close=${this._onProviderInfoClose}
      ></simplecmp-provider-info-modal>
    `:l}_resolveProviderService(t){let i=this.config?.libraryFallback?.[t.name];return i===void 0?t:{...t,vendor:t.vendor??i.vendor,vendorCountry:t.vendorCountry??i.vendorCountry,vendorAddress:t.vendorAddress??i.vendorAddress,vendorOptOutUrl:t.vendorOptOutUrl??i.vendorOptOutUrl,vendorPartner:t.vendorPartner??i.vendorPartner,vendorDescription:t.vendorDescription??i.vendorDescription,privacyPolicyUrl:t.privacyPolicyUrl??i.privacyPolicyUrl}}_hasProviderData(t){let i=this._resolveProviderService(t);return!!(i.vendor||i.vendorCountry||i.vendorAddress||i.vendorOptOutUrl||i.vendorPartner||i.vendorDescription||i.privacyPolicyUrl)}_renderPurposes(t){let i=t.purposes??[];if(i.length===0&&this.config!==void 0){let a=this.config.libraryFallback?.[t.name];a?.purposes!==void 0&&(i=a.purposes)}if(i.length===0)return l;let n=i.map(s=>this._tString(["purposes",s,"title"])).filter(s=>s.length>0);if(n.length===0)return l;let o=this._tString(["service","purposes"])||"Purposes";return d`<p class="purposes">${o}: ${n.join(", ")}</p>`}_resolveTitle(t){let i=this.getAttribute("data-simplecmp-title");return i!==null&&i.length>0?i:typeof t.placeholderTitle=="string"&&t.placeholderTitle.length>0?t.placeholderTitle:this._tString(["!",t.name,"placeholderTitle?"])||this._tString(["!",t.name,"title?"])||x(t.name)}_resolveDescription(t,i){let n=this.getAttribute("data-simplecmp-description");if(n!==null&&n.length>0)return n;if(typeof t.placeholderDescription=="string"&&t.placeholderDescription.length>0)return t.placeholderDescription;let o=this._tString(["!",t.name,"placeholderDescription?"]);return o!==""?o:this._t(["contextualConsent","description"],{title:i})}firstUpdated(t){super.firstUpdated?.(t),this._updateAriaLabel(),this._maybeFocusFirstAction()}updated(t){super.updated?.(t),this._updateAriaLabel()}_updateAriaLabel(){let t=this._resolveService();if(t===void 0)return;let i=this._resolveTitle(t),n=this._tString(["!","contextualConsent","ariaLabel?"])||i,o=n.includes("{title}")?n.replace("{title}",i):n;this.getAttribute("aria-label")!==o&&this.setAttribute("aria-label",o)}_maybeFocusFirstAction(){if(!this._autoPlaceholder)return;this.renderRoot.querySelector("button:not([disabled])")?.focus()}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}};O.styles=[w,b`
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
    `],h([g({attribute:!1})],O.prototype,"service",2),h([g({type:String,attribute:"service-name"})],O.prototype,"serviceName",2),h([Me()],O.prototype,"_providerInfoOpen",2),O=h([k("simplecmp-contextual-notice")],O);var U=class extends y{constructor(){super(...arguments);this.visible=!0;this._onChange=t=>{let i=t.target.checked,n=this.service;n===void 0||n.required||(this.manager?.updateConsent(n.name,i),this._emit("service-toggle",{name:n.name,value:i}))}}render(){let t=this.service;if(t===void 0)return l;let i=`simplecmp-service-${t.name}`,n=t.required===!0||this.manager?.consents[t.name]===!0,o=this._tString(["!",t.name,"title?"])||x(t.name),s=this._tString(["!",t.name,"description?"])||void 0;return d`
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
            ${t.required?d`<span class="badge">${this._t(["service","required","title"])}</span>`:l}
            ${t.optOut?d`<span class="badge">${this._t(["service","optOut","title"])}</span>`:l}
          </label>
          ${s?d`<p class="description">${s}</p>`:l}
          ${this._renderPurposes(t)}
        </div>
      </div>
    `}_renderPurposes(t){let i=t.purposes??[];if(i.length===0)return l;let n=i.map(s=>this._tString(["!","purposes",s,"title?"])||x(s)).join(", "),o=this._t(["service",i.length>1?"purposes":"purpose"]);return d`<p class="purposes">${o}: ${n}</p>`}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}};U.styles=[w,b`
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
    `],h([g({attribute:!1})],U.prototype,"service",2),h([g({type:Boolean})],U.prototype,"visible",2),U=h([k("simplecmp-service-toggle")],U);var D=class extends y{constructor(){super(...arguments);this.purpose="";this.services=[];this._expanded=!1;this._onMasterChange=t=>{let i=t.target.checked;if(this.manager!==void 0){for(let n of this.services)n.required!==!0&&this.manager.updateConsent(n.name,i);this._emit("purpose-toggle",{purpose:this.purpose,value:i})}};this._toggleExpanded=t=>{t.preventDefault(),this._expanded=!this._expanded}}render(){if(this.manager===void 0)return l;let t=this._computeStatus(),i=this._tString(["!","purposes",this.purpose,"title?"])||x(this.purpose),n=this._tString(["!","purposes",this.purpose,"description"]),o=`simplecmp-purpose-${this.purpose}`;return d`
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
          ${n?d`<p class="description">${n}</p>`:l}
        </div>
      </div>

      ${this.services.length>0?d`
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
              ${this.services.map(s=>d`
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
          `:l}
    `}_computeStatus(){let t=this.manager?.consents??{},i={allEnabled:!0,allDisabled:!0,onlyRequiredEnabled:!0,allRequired:!0};for(let n of this.services){let o=n.required===!0;o||(i.allRequired=!1),t[n.name]?(o||(i.onlyRequiredEnabled=!1),i.allDisabled=!1):o||(i.allEnabled=!1)}return i.allDisabled&&(i.onlyRequiredEnabled=!1),i}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}};D.styles=[w,b`
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
    `],h([g({type:String})],D.prototype,"purpose",2),h([g({attribute:!1})],D.prototype,"services",2),h([Me()],D.prototype,"_expanded",2),D=h([k("simplecmp-purpose-group")],D);function xn(r,e){if(r!==void 0){if(typeof r=="string")return r;if(typeof r=="object")return r[e]??r.default}}var S=class extends y{constructor(){super(...arguments);this.open=!1;this.testing=!1;this._onKeydown=t=>{if(t.key!=="Tab")return;let i=this._collectFocusable();if(i.length===0)return;let n=i[0],o=i[i.length-1];if(n===void 0||o===void 0)return;let s=this._deepActiveElement();!t.shiftKey&&s===o?(t.preventDefault(),n.focus()):t.shiftKey&&s===n&&(t.preventDefault(),o.focus())};this._onCancel=t=>{this.config?.mustConsent===!0&&t.preventDefault()};this._onClose=()=>{this.open=!1,this._emit("modal-close")};this._onCloseClick=()=>{this.config?.mustConsent!==!0&&(this.open=!1,this._emit("modal-close"))};this._onBackdropClick=t=>{t.target===this._dialog&&this.config?.mustConsent!==!0&&(this.open=!1,this._emit("modal-close"))};this._onAcceptAll=()=>{this.manager!==void 0&&(this.manager.changeAll(!0),this.manager.saveAndApplyConsents("accept"),this._emit("accept"),this.open=!1)};this._onDecline=()=>{this.manager!==void 0&&(this.manager.changeAll(!1),this.manager.saveAndApplyConsents("decline"),this._emit("decline"),this.open=!1)};this._onSave=()=>{this.manager!==void 0&&(this.manager.saveAndApplyConsents("save"),this._emit("save"),this.open=!1)}}updated(t){if(super.updated?.(t),t.has("open")){let i=this._dialog;if(i===void 0)return;this.open&&!i.open?i.showModal():!this.open&&i.open&&i.close()}}render(){let t=this.config,i=this.manager;return t===void 0||i===void 0?l:d`
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
    `}_renderHeader(t){let i=this._activeLang(),n=this._resolvePolicyUrl(t.privacyPolicy,["privacyPolicyUrl"],i),o=this._resolvePolicyUrl(t.imprint,["imprintUrl"],i),s=t.htmlTexts===!0,a=this._t(["consentModal","description"]),c=t.mustConsent!==!0;return d`
      <div class="header">
        ${c?d`<button
              type="button"
              class="close"
              aria-label=${this._t(["close"])}
              @click=${this._onCloseClick}
            >
              ×
            </button>`:l}
        <h1 id="simplecmp-modal-title">${this._t(["consentModal","title"])}</h1>
        <p class="description">
          ${s?Cn(a):a}
        </p>
        ${this._renderPolicyLinks(n,o)}
      </div>
    `}_renderBody(t){if(t.groupByPurpose!==!1){let n=this._collectPurposes(),o=t.purposeOrder??[],s=Array.from(n.keys()).sort((a,c)=>o.indexOf(a)-o.indexOf(c));return d`
        <div class="purposes">
          ${s.map(a=>d`
              <simplecmp-purpose-group
                .config=${this.config}
                .manager=${this.manager}
                .purpose=${a}
                .services=${n.get(a)??[]}
              ></simplecmp-purpose-group>
            `)}
        </div>
      `}return d`
      <ul class="services">
        ${t.services.map(n=>d`
            <li>
              <simplecmp-service-toggle
                .config=${this.config}
                .manager=${this.manager}
                .service=${n}
              ></simplecmp-service-toggle>
            </li>
          `)}
      </ul>
    `}_renderFooter(t,i){let n=t.hideDeclineAll!==!0,o=t.acceptAll===!0,s=i.confirmed?this._t(["save"]):this._t(["acceptSelected"]);return d`
      <div class="footer">
        ${n?d`<button type="button" class="action decline" @click=${this._onDecline}>
              ${this._t(["decline"])}
            </button>`:l}
        <button type="button" class="action save" @click=${this._onSave}>
          ${s}
        </button>
        ${o?d`<button type="button" class="action accept-all" @click=${this._onAcceptAll}>
              ${this._t(["acceptAll"])}
            </button>`:l}
      </div>
    `}_renderPolicyLinks(t,i){return t===void 0&&i===void 0?l:d`
      <p class="policy-links">
        ${t?d`<a href=${t} target="_blank" rel="noopener"
              >${this._t(["privacyPolicy","name"])}</a
            >`:l}
        ${t&&i?" \xB7 ":l}
        ${i?d`<a href=${i} target="_blank" rel="noopener"
              >${this._imprintLinkText()}</a
            >`:l}
      </p>
    `}_activeLang(){return this.config?.lang??document.documentElement.lang??"en"}_collectFocusable(){let t=this._dialog;if(t===void 0)return[];let i=[],n='button:not([disabled]), a[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',o=s=>{if(s.matches(n)&&this._isVisible(s)&&i.push(s),s.shadowRoot!==null)for(let a of s.shadowRoot.children)o(a);for(let a of s.children)o(a)};for(let s of t.children)o(s);return i}_deepActiveElement(){let t=document.activeElement;for(;t?.shadowRoot?.activeElement;)t=t.shadowRoot.activeElement;return t}_isVisible(t){let i=t;return typeof i.checkVisibility=="function"?i.checkVisibility():t.offsetParent!==null}_collectPurposes(){let t=new Map;for(let i of this.config?.services??[])for(let n of i.purposes??[]){let o=t.get(n)??[];o.push(i),t.set(n,o)}return t}_resolvePolicyUrl(t,i,n){let o=xn(t,n);if(o!==void 0)return o;let s=this._tString(["!",...i]);return s===""?void 0:s}_imprintLinkText(){return this._tString(["!","consentNotice","imprint","name"])||this._tString(["!","imprint","name"])||"Imprint"}_tString(t){let i=this._t(t);return typeof i=="string"?i:Array.isArray(i)?i.map(n=>typeof n=="string"?n:"").join(""):""}};S.styles=[w,b`
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
    `],h([g({type:Boolean,reflect:!0})],S.prototype,"open",2),h([g({type:Boolean})],S.prototype,"testing",2),h([Pe("dialog")],S.prototype,"_dialog",2),S=h([k("simplecmp-modal")],S);function Cn(r){return typeof r=="string"?Z(r):Array.isArray(r)?r.map(e=>typeof e=="string"?Z(e):e):r}var C=class extends y{constructor(){super(...arguments);this.position="bottom-right";this._onClick=t=>{t.preventDefault(),this._emit("trigger-click")}}connectedCallback(){super.connectedCallback(),this.setAttribute("position",this.position)}render(){let t=this._resolveLabel();return d`
      <button type="button" aria-label=${t} title=${t} @click=${this._onClick}>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path
            fill="currentColor"
            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10c0-.34-.02-.68-.05-1.01-.71.93-1.83 1.51-3.07 1.51-2.21 0-4-1.79-4-4 0-.34.04-.68.13-1.01-1.65.32-3.13-1.04-3.13-2.49 0-.79.36-1.5.93-1.96A9.95 9.95 0 0 0 12 2zm-1 5h2v2h-2zm-3 4h2v2H8zm6 0h2v2h-2zm-2 4h2v2h-2z"
          />
        </svg>
      </button>
    `}_resolveLabel(){if(this.label!==void 0&&this.label!=="")return this.label;if(this.config!==void 0){let t=this._t(["!","floatingTrigger","label"]);if(typeof t=="string"&&t!=="")return t;if(Array.isArray(t)&&t.length>0)return t.map(i=>typeof i=="string"?i:"").join("")}return"Cookie settings"}};C.styles=[w,b`
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
    `],h([g({type:String})],C.prototype,"position",2),h([g({type:String})],C.prototype,"label",2),C=h([k("simplecmp-trigger")],C);function di(r){let e=ne(r),t=r.domMode==="light",i;r.noNotice!==!0&&(!e.confirmed||r.testing===!0)&&(i=new M,i.config=r,i.manager=e,r.testing===!0&&(i.testing=!0),t&&i.setAttribute("mode","light"),document.body.appendChild(i));let o=new S;o.config=r,o.manager=e,r.testing===!0&&(o.testing=!0),t&&o.setAttribute("mode","light"),document.body.appendChild(o),r.mustConsent===!0&&!e.confirmed&&(o.open=!0);let s=()=>{o.open=!0};document.addEventListener("simplecmp:configure",s);let a={update(f,m){m==="saveConsents"&&i!==void 0&&(i.remove(),i=void 0)}};e.watch(a);let c,p;return r.floatingTrigger&&(c=new C,c.config=r,typeof r.floatingTrigger=="object"&&(r.floatingTrigger.position!==void 0&&(c.position=r.floatingTrigger.position),r.floatingTrigger.label!==void 0&&(c.label=r.floatingTrigger.label)),t&&c.setAttribute("mode","light"),document.body.appendChild(c),p=()=>{o.open=!0},c.addEventListener("simplecmp:trigger-click",p)),e.applyConsents(),{show(){o.open=!0},hide(){o.open=!1},destroy(){e.unwatch(a),document.removeEventListener("simplecmp:configure",s),i?.remove(),o.remove(),c!==void 0&&p!==void 0&&(c.removeEventListener("simplecmp:trigger-click",p),c.remove())},manager:e}}L(Be,te(Bt));var $n="0.0.1",G=null,q=null;function zn(r){Nn(r),Bn(r),G!==null&&(G.destroy(),G=null),q!==null&&(q(),q=null);let e=ne(r);r.record&&Dn(r),r.interceptRuntime&&(q=En(r,e));let t=null,i=[],n=()=>{t=di(r);for(let s of i)s==="show"?t.show():t.hide();i.length=0},o=null;return typeof document<"u"&&document.body!==null?n():typeof document<"u"&&(o=n,document.addEventListener("DOMContentLoaded",o,{once:!0})),G={show:()=>{t!==null?t.show():i.push("show")},hide:()=>{t!==null?t.hide():i.push("hide")},manager:e,destroy:()=>{q!==null&&(q(),q=null),o!==null&&typeof document<"u"&&(document.removeEventListener("DOMContentLoaded",o),o=null),t?.destroy(),t=null,i.length=0}},G}function En(r,e){let t=typeof r.interceptRuntime=="object"&&r.interceptRuntime!==null?r.interceptRuntime:{},i=Ht(r.services,{blockAllUnknown:t.universalBlock===!0}),n=t.onBlock;return qt({matcher:i,consentChecker:o=>e.getConsent(o),sameOriginHosts:t.sameOriginHosts,onBlock:o=>{T!==null&&T.recordSyntheticDetection({kind:Mn(o.mechanism),identifier:o.url,origin:Pn(o.url)}),n?.(o)}})}function Mn(r){switch(r){case"script-src":return"script";case"iframe-src":return"iframe";case"img-src":return"image";case"fetch":case"xhr":case"sendBeacon":return"request"}}function Pn(r){try{return new URL(r,window.location.href).hostname||void 0}catch{return}}function On(){G?.show()}var T=null;function Dn(r){T&&(T.stop(),T=null);let e=typeof r.record=="object"&&r.record!==null?{...r.record}:{};if(!e.storageName&&typeof r.storageName=="string"&&(e.storageName=r.storageName),e.storageName){let s=e.ignoreCookies??[];e.ignoreCookies=s.includes(e.storageName)?s:[e.storageName,...s]}let t=r.services??[],i=r.serviceDbUrl?new K(new F({url:r.serviceDbUrl,auth:r.serviceDbAuth}),t):null,n=i??new V(t),o=new we({options:e,classifier:n,services:t,watcherFactories:[s=>new Se(s,{intervalMs:e.cookieIntervalMs}),s=>new _e(s),s=>new Ae(s)],onDetectionForLibEvent:s=>{Ue("recorderDetection",s)}});if(i&&i.onEnrichment((s,a)=>{o.enrichDetection(s,a)}),r.cmsBridgeUrl){let s=In(),a=new R({url:r.cmsBridgeUrl,auth:r.cmsBridgeAuth,source:r.cmsBridge?.source??e.storageName??"default",dedupTtlMs:r.cmsBridge?.dedupTtlMs,crossSessionDedupMs:s?0:r.cmsBridge?.crossSessionDedupMs,flushDebounceMs:r.cmsBridge?.flushDebounceMs,maxBatchSize:r.cmsBridge?.maxBatchSize,sampleRate:s?1:r.cmsBridge?.sampleRate,respectDoNotTrack:s?!1:r.cmsBridge?.respectDoNotTrack,timeoutMs:r.cmsBridge?.timeoutMs});o.on("detectionSettled",c=>a.onDetection(c))}T=o,T.start()}function Tn(){return T??void 0}var Rn=ut,Ln=ne,jn=ee;function In(){if(typeof window>"u"||typeof URLSearchParams>"u")return!1;try{return new URLSearchParams(window.location.search).get("simplecmp_discover")==="1"}catch{return!1}}function Nn(r){r.cmsBridgeUrl&&!r.record&&console.warn("SimpleCMP: `cmsBridgeUrl` is set but `record` is not enabled. The CMS bridge listens to recorder detections \u2014 without the recorder running, no webhooks will ever fire. Set `record: true` or remove `cmsBridgeUrl`.")}function Bn(r){r.hideDeclineAll&&console.warn('SimpleCMP: `hideDeclineAll: true` hides the "Decline all" button on the first banner level. This is incompatible with German consent requirements (BGH "Cookie II", BGH I ZR 7/16; DSK 2022). Keep the decline option equally prominent or expect compliance issues.')}return vi(Un);})();
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