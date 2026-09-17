// Convertitore Claude Design (.dc.html) -> HTML statico pulito.
// Gestisce: {{ var }}, <sc-for>, <sc-if>, style-hover -> CSS :hover, <helmet> -> <head>.
// Uso: node build/build.js
const fs = require('fs');
const path = require('path');

const SRC = path.resolve(__dirname, '..');
const OUT = path.resolve(SRC, 'dist');

// Configurazione per-sito (pagine, meta, brand, linkmap) — unico file da editare per progetto.
const { SITE, PAGES, LINKMAP, BRAND } = require('./site.config.js');

// --- Branding parametrizzato (default = NuovoSostenibile, per retro-compat col repo) --------
const B = BRAND || {};
const ACCENT        = B.accent      || '${ACCENT}';
const FONTS_HREF    = B.fontsHref   || 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Public+Sans:wght@400;500;600;700&display=swap';
const BODY_FONT     = B.bodyFont    || "'Public Sans',system-ui,sans-serif";
const HEAD_FONT     = B.headingFont || "'Poppins',sans-serif";
const PAGE_BG       = B.pageBg      || 'oklch(98% 0.008 95)';
const BODY_COLOR    = B.bodyColor   || 'oklch(24% 0.015 260)';
const NAV_SHRINK_BG = B.navShrinkBg || 'oklch(98% 0.008 95 / 0.97)';
const COMPANY       = B.company     || 'M.C. System srl';
const CONTACT_EMAIL = B.email       || '${CONTACT_EMAIL}';
const PRIVACY_PROVIDER = B.privacyProvider || 'Twilio SendGrid';
const BUILD_GUIDA   = B.buildGuida   !== false; // default true (NuovoSostenibile)
const BUILD_PRIVACY = B.buildPrivacy !== false;
function hexToRgba(hex, a) {
  const m = /^#?([0-9a-f]{6})$/i.exec(String(hex || '').trim());
  if (!m) return `rgba(0,0,0,${a})`;
  const n = parseInt(m[1], 16);
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
}
function escapeRe(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

// Trasforma il blocco campi della pagina Contatti in un <form> reale che invia a contatti.php
// Individuazione del contenitore: convenzione data-vb-form="contatti" nel Design (se presente,
// stesso schema di data-vb-list: attributo + bilanciamento tag), altrimenti fallback alla firma
// CSS letterale usata finora (nessun rischio sul Design attuale, che non ha ancora l'attributo).
const FORM_OPEN_SIG = '<div style="display:flex;flex-direction:column;gap:18px">';
function findFormContainer(html, formSlug) {
  const attrRe = new RegExp('<div\\b[^>]*\\bdata-vb-form="' + formSlug + '"[^>]*>');
  const m = attrRe.exec(html);
  if (m) return { start: m.index, openLen: m[0].length };
  const idx = html.indexOf(FORM_OPEN_SIG);
  return idx === -1 ? null : { start: idx, openLen: FORM_OPEN_SIG.length };
}
function wrapContactForm(html, formSlug) {
  const found = findFormContainer(html, formSlug || 'contatti');
  if (!found) return html;
  const idx = found.start;
  // trova il </div> che chiude questo container (matching bilanciato sui soli tag div)
  let depth = 0;
  const tokRe = /<div\b|<\/div>/g; tokRe.lastIndex = idx;
  let m, closeStart = -1, closeEnd = -1;
  while ((m = tokRe.exec(html)) !== null) {
    if (m[0] === '</div>') { depth--; if (depth === 0) { closeStart = m.index; closeEnd = tokRe.lastIndex; break; } }
    else depth++;
  }
  if (closeStart === -1) return html;
  let inner = html.slice(idx + found.openLen, closeStart);
  // aggiunge name/required ai campi
  inner = inner
    .replace('<input type="text"',  '<input type="text" name="nome" required')
    .replace('<input type="email"', '<input type="email" name="email" required')
    .replace('<select',             '<select name="tipo"')
    .replace('<textarea',           '<textarea name="messaggio" required')
    .replace('onClick=""',          'type="submit"');
  // spunta consenso GDPR (obbligatoria) prima del bottone
  const consent = `<label style="display:flex;gap:10px;align-items:flex-start;font-size:12.5px;color:oklch(45% 0.02 260);line-height:1.5"><input type="checkbox" name="privacy" required value="1" style="margin-top:3px;flex-shrink:0">Ho letto l'<a href="privacy.html" target="_blank" rel="noopener" style="color:${ACCENT}">informativa privacy</a> e acconsento al trattamento dei miei dati personali per rispondere alla mia richiesta (art. 6 GDPR).</label>`;
  inner = inner.replace('<button', consent + '<button');
  const formOpen = '<form method="post" action="contatti.php" style="display:flex;flex-direction:column;gap:18px">';
  return html.slice(0, idx) + formOpen + inner + '</form>' + html.slice(closeEnd);
}

// (LINKMAP è definito in site.config.js)

function decodeEntities(s) {
  return s.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&')
          .replace(/&lt;/g, '<').replace(/&gt;/g, '>');
}

// --- estrazione parti dal sorgente ---
function extract(html) {
  const xdcOpen = html.indexOf('<x-dc>');
  const xdcClose = html.lastIndexOf('</x-dc>');
  let inner = html.slice(xdcOpen + '<x-dc>'.length, xdcClose);

  // helmet -> head
  let head = '';
  inner = inner.replace(/<helmet[^>]*>([\s\S]*?)<\/helmet>/, (_, h) => { head = h; return ''; });

  // script data-dc-script
  const scMatch = html.match(/<script[^>]*data-dc-script[^>]*>([\s\S]*?)<\/script>/);
  const scriptBody = scMatch ? scMatch[1] : '';
  const propsMatch = html.match(/data-props="([^"]*)"/);
  const propsMeta = propsMatch ? JSON.parse(decodeEntities(propsMatch[1])) : {};

  return { template: inner, head, scriptBody, propsMeta };
}

// --- valori (esegue renderVals) ---
function computeVals(scriptBody, propsMeta, overrides) {
  const props = {};
  for (const k of Object.keys(propsMeta)) props[k] = propsMeta[k].default;
  Object.assign(props, overrides || {});
  // Pagine statiche senza logica (nessun `class Component`): niente renderVals, i valori sono le sole props.
  if (!/class\s+Component\b/.test(scriptBody)) return props;
  const DCLogic = class { constructor(p) { this.props = p || {}; } setState() {} };
  // `window` non esiste in Node. I Design che pescano i dati da una globale
  // (window.AT in Pagine di Storia) la interrogano già con una guardia e in sua
  // assenza rendono il proprio stato "in caricamento": la zona è data-vb-skip e
  // la riempie il client. Senza questo guscio sarebbe invece un ReferenceError
  // e la pagina non uscirebbe affatto.
  const fn = new Function('DCLogic', 'props', 'window', 'document', scriptBody + '\n;return new Component(props).renderVals();');
  return fn(DCLogic, props, {}, undefined);
}

// --- risoluzione {{ expr }} nello scope ---
function resolve(expr, scope) {
  expr = expr.trim();
  if (expr === 'true') return true;
  if (expr === 'false') return false;
  const parts = expr.split('.');
  let v = scope[parts[0]];
  for (let i = 1; i < parts.length && v != null; i++) v = v[parts[i]];
  return v;
}
function interpolate(str, scope) {
  return str.replace(/\{\{([^}]*)\}\}/g, (_, e) => {
    const v = resolve(e, scope);
    if (v == null || typeof v === 'function') return ''; // le funzioni (handler) non hanno senso nell'HTML statico
    return String(v);
  });
}

// --- parser albero per sc-for / sc-if ---
function parse(tmpl) {
  const re = /<sc-(for|if)\b([^>]*)>|<\/sc-(for|if)>/g;
  const root = { type: 'root', children: [] };
  const stack = [root];
  let last = 0, m;
  while ((m = re.exec(tmpl)) !== null) {
    const top = stack[stack.length - 1];
    if (m.index > last) top.children.push({ type: 'text', value: tmpl.slice(last, m.index) });
    if (m[3]) { // chiusura
      stack.pop();
    } else { // apertura
      const node = { type: m[1], attrs: m[2], children: [] };
      top.children.push(node);
      stack.push(node);
    }
    last = re.lastIndex;
  }
  if (last < tmpl.length) root.children.push({ type: 'text', value: tmpl.slice(last) });
  return root;
}

function attr(attrs, name) {
  const m = attrs.match(new RegExp(name + '="([^"]*)"'));
  return m ? m[1] : null;
}

function render(node, scope) {
  if (node.type === 'text') return interpolate(node.value, scope);
  if (node.type === 'root') return node.children.map(c => render(c, scope)).join('');
  if (node.type === 'if') {
    const cond = resolve(attr(node.attrs, 'value').replace(/[{}]/g, ''), scope);
    return cond ? node.children.map(c => render(c, scope)).join('') : '';
  }
  if (node.type === 'for') {
    const listExpr = attr(node.attrs, 'list').replace(/\{\{|\}\}/g, '').trim();
    const asName = attr(node.attrs, 'as');
    // list non-array (es. loop annidato: nella passata CMS il campo diventa un token
    // scalare ⟦campo⟧, non l'array originale) -> rende vuoto invece di far crashare la build.
    const raw = resolve(listExpr, scope);
    const list = Array.isArray(raw) ? raw : [];
    return list.map(item => {
      const s = Object.assign({}, scope, { [asName]: item });
      return node.children.map(c => render(c, s)).join('');
    }).join('');
  }
  return '';
}

// --- post-processing HTML ---
function postProcess(html, hoverRules) {
  // style-hover -> classe + regola :hover
  html = html.replace(/<([a-zA-Z0-9]+)((?:[^>]*?))\sstyle-hover="([^"]*)"((?:[^>]*?))>/g,
    (_, tag, before, rules, after) => {
      const cls = 'dch' + hoverRules.length;
      hoverRules.push(`.${cls}:hover{${rules}}`);
      // inserisce/merge classe
      const combined = (before + after);
      if (/\sclass="/.test(combined)) {
        const merged = (before + after).replace(/\sclass="([^"]*)"/, ` class="$1 ${cls}"`);
        return `<${tag}${merged}>`;
      }
      return `<${tag}${before} class="${cls}"${after}>`;
    });
  // rimuove attributi hint / editor residui
  html = html.replace(/\s(hint-placeholder-[a-z]+|data-screen-label|data-dc-atomics)="[^"]*"/g, '');
  // riscrive i link .dc.html -> .html
  html = html.replace(/href="([^"]*)"/g, (full, href) => {
    for (const [from, to] of Object.entries(LINKMAP)) {
      if (href.startsWith(from)) return `href="${to}${href.slice(from.length)}"`;
    }
    return full;
  });
  // "Scarica la guida introduttiva" (href="#") -> landing guida.php.
  // Solo se la landing viene davvero generata (BUILD_GUIDA): con buildGuida:false
  // (es. MC System) guida.php non esiste, quindi lasciamo gli href="#" inerti
  // invece di trasformarli in link a una pagina 404.
  if (BUILD_GUIDA) html = html.replace(/href="#"/g, 'href="guida.php"');
  // tagga la barra di navigazione (prima div sticky) per lo shrink allo scroll
  html = html.replace('<div style="position:sticky;top:0;z-index:50', '<div id="siteNav" style="position:sticky;top:0;z-index:50');
  // FIX sticky: overflow-x:hidden sull'antenato rompe position:sticky -> uso clip (nasconde senza creare scroll container)
  html = html.replace(/overflow-x:hidden/g, 'overflow-x:clip');
  // Nav: rimuove il logo tondo, resta solo la scritta ${SITE.name}
  html = html.replace(/<img src="assets\/logo-stamp\.png"[^>]*>\s*/g, '');
  return html;
}

// escape per attributi meta
function escAttr(s) {
  return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Blocco meta OpenGraph / Twitter / canonical per una pagina
function ogHead(page) {
  const url = SITE.domain + '/' + (page.out === 'index.html' ? '' : page.out);
  const desc = page.desc || SITE.name;
  const img = page.og || SITE.ogImage;
  return [
    `<meta name="description" content="${escAttr(desc)}">`,
    `<link rel="canonical" href="${escAttr(url)}">`,
    `<meta property="og:type" content="website">`,
    `<meta property="og:site_name" content="${escAttr(SITE.name)}">`,
    `<meta property="og:locale" content="it_IT">`,
    `<meta property="og:title" content="${escAttr(page.title)}">`,
    `<meta property="og:description" content="${escAttr(desc)}">`,
    `<meta property="og:url" content="${escAttr(url)}">`,
    `<meta property="og:image" content="${escAttr(img)}">`,
    `<meta name="twitter:card" content="summary_large_image">`,
    `<meta name="twitter:title" content="${escAttr(page.title)}">`,
    `<meta name="twitter:description" content="${escAttr(desc)}">`,
    `<meta name="twitter:image" content="${escAttr(img)}">`,
  ].join('\n');
}

// CSS: transizione + stato "rimpicciolito" della barra allo scroll
const NAV_CSS = `
#siteNav{transition:padding .28s ease, box-shadow .28s ease, background-color .28s ease}
#siteNav.nav-shrink{padding-top:10px !important;padding-bottom:10px !important;box-shadow:0 6px 24px rgba(0,0,0,.10);background:${NAV_SHRINK_BG} !important}
.vb-navcta{transition:transform .2s ease,box-shadow .2s ease}
.vb-navcta:hover{transform:translateY(-1px);box-shadow:0 6px 18px ${hexToRgba(ACCENT, 0.3)}}
@media (prefers-reduced-motion: reduce){#siteNav{transition:none}}
/* Reveal on scroll: le sezioni entrano a comparsa (fade + leggera salita) quando
   si scorre. Lo stato nascosto lo aggiunge SOLO il JS (classe .vb-reveal), così
   senza JS/IntersectionObserver i contenuti restano visibili. Disattivato se
   l'utente preferisce meno animazioni. */
@media (prefers-reduced-motion: no-preference){
.vb-reveal{opacity:0;transform:translateY(26px);transition:opacity .7s cubic-bezier(.16,.72,.3,1),transform .7s cubic-bezier(.16,.72,.3,1)}
.vb-reveal.vb-in{opacity:1;transform:none}
}
`;

// --- Menu di navigazione editabile (Fase 5) ---
// Sostituisce il contenitore dei link del nav con il marker <!--VBNAV--> (reso dal DB in cms.php).
// Individuazione: convenzione data-vb-nav sul contenitore (stesso schema di data-vb-list), con
// fallback alla firma CSS letterale usata finora (il Design attuale non ha ancora l'attributo).
const NAV_LINK_OPEN = '<div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:flex-end">';
// Bilanciamento generico sul TAG reale del contenitore (non solo <div>): serve ai Design
// component-based dove il nav è un <nav> (MC System). Dato l'indice del tag di apertura,
// individua il nome del tag e trova la chiusura bilanciata. Ritorna l'indice di fine (dopo </tag>).
function balancedEnd(tpl, start) {
  const tm = /^<([a-zA-Z][\w-]*)\b/.exec(tpl.slice(start));
  if (!tm) return -1;
  const tag = tm[1];
  const re = new RegExp('<' + tag + '\\b|</' + tag + '>', 'g'); re.lastIndex = start;
  let depth = 0, m;
  while ((m = re.exec(tpl)) !== null) {
    if (m[0][1] === '/') { depth--; if (depth === 0) return re.lastIndex; }
    else depth++;
  }
  return -1;
}
function findNavLinksContainer(tpl) {
  const m = /<[a-zA-Z][\w-]*\b[^>]*\bdata-vb-nav\b[^>]*>/.exec(tpl); // qualunque tag con data-vb-nav
  if (m) return m.index;
  return tpl.indexOf(NAV_LINK_OPEN);
}
// Sostituisce l'elemento (bilanciato sul suo tag) marcato da `attr` con `marker`. Generico:
// usato per data-vb-nav (desktop) e data-vb-nav-mobile (menu mobile).
function replaceMarkedBlock(tpl, attr, marker) {
  const m = new RegExp('<[a-zA-Z][\\w-]*\\b[^>]*\\b' + attr + '\\b[^>]*>').exec(tpl);
  if (!m) return tpl;
  const end = balancedEnd(tpl, m.index);
  return end === -1 ? tpl : tpl.slice(0, m.index) + marker + tpl.slice(end);
}
function replaceNavBlock(tpl, marker) {
  const start = findNavLinksContainer(tpl);
  if (start === -1) return tpl;
  const end = balancedEnd(tpl, start);
  return end === -1 ? tpl : tpl.slice(0, start) + marker + tpl.slice(end);
}
// --- Footer condiviso (Fase A page-builder) ---
// La riga footer (© + logo + certificazioni) è identificata dal logo "invertito" (bianco su verde).
// Viene protetta dalla tokenizzazione e il suo CONTENUTO sostituito con <!--VBFOOTER--> (reso dal DB);
// il contenitore resta per-pagina (contatti non ha il border-top, le altre sì) per fedeltà totale.
const FOOTER_SIG = 'filter:brightness(0) invert(1)';
function sliceFooterRow(html) {
  const i = html.indexOf(FOOTER_SIG);
  if (i === -1) return '';
  const start = html.lastIndexOf('<div', i);
  let depth = 0; const re = /<div\b|<\/div>/g; re.lastIndex = start;
  let m;
  while ((m = re.exec(html)) !== null) {
    if (m[0] === '</div>') { depth--; if (depth === 0) return html.slice(start, re.lastIndex); }
    else depth++;
  }
  return '';
}
// Sostituisce l'interno della riga footer con il marker, conservando il tag contenitore della pagina.
function replaceFooterInner(html) {
  const row = sliceFooterRow(html);
  if (!row) return html;
  const openEnd = row.indexOf('>') + 1;
  const replaced = row.slice(0, openEnd) + '<!--VBFOOTER-->' + '</div>';
  return html.replace(row, () => replaced);
}
// Seed del footer (una volta, dalla home): {left, logo, right}
let FOOTER_SEED = null;
function extractFooterSeed(body) {
  const row = sliceFooterRow(body);
  if (!row) return;
  const spans = [...row.matchAll(/<span>([\s\S]*?)<\/span>/g)].map(x => x[1]);
  if (spans.length < 2) return;
  const imgM = spans[0].match(/<img[^>]*>/);
  const openEnd = row.indexOf('>') + 1;
  FOOTER_SEED = {
    left: spans[0].replace(/<img[^>]*>/, '').trim(),
    logo: imgM ? imgM[0] : '',
    right: spans[1].trim(),
    container: row.slice(0, openEnd), // tag <div style="..."> di apertura, per le pagine senza Design (page-builder)
  };
}

// Seed dell'INTERA barra nav (contenitore #siteNav + brand a sinistra + placeholder testuale al posto dei
// link, sostituiti a runtime da nav_render_html()). Serve alle pagine SENZA Design sorgente (page-builder,
// blog): non hanno un template da cui ereditare la barra, quindi la clonano da questo seed html grezzo.
let NAV_SHELL_SEED = null;
function extractNavShellSeed(body) {
  const navFull = sliceBalancedDiv(body, '<div id="siteNav"');
  if (!navFull) return;
  let shell = replaceNavBlock(navFull, '{{VBNAVLINKS}}');
  shell = replaceMarkedBlock(shell, 'data-vb-nav-mobile', '<!--VBNAVMOBILE-->'); // menu mobile dinamico anche qui
  NAV_SHELL_SEED = shell;
}

// Estrae le voci del menu dal blocco link (una sola volta, dalla home). Anchor locali -> assoluti (index.html#...).
let NAV_SEED = null;
function extractNavSeed(body) {
  const start = findNavLinksContainer(body);
  if (start === -1) return;
  // Questo estrattore piatto assume un contenitore <div>. Per i Design gerarchici
  // (nav = <nav>, seed prodotto dal preprocess) NON deve girare: salta se non è un <div>.
  if (body.slice(start, start + 4) !== '<div') return;
  const re = /<div\b|<\/div>/g; re.lastIndex = start;
  let depth = 0, m, end = -1;
  while ((m = re.exec(body)) !== null) {
    if (m[0] === '</div>') { depth--; if (depth === 0) { end = re.lastIndex; break; } }
    else depth++;
  }
  if (end === -1) return;
  const block = body.slice(start, end);
  const items = [];
  const aRe = /<a\b[^>]*href="([^"]*)"[^>]*style="([^"]*)"[^>]*>([\s\S]*?)<\/a>/g;
  let a;
  while ((a = aRe.exec(block)) !== null) {
    let href = a[1]; const style = a[2]; const label = a[3].replace(/<[^>]+>/g, '').trim();
    if (href.startsWith('#')) href = 'index.html' + href; // anchor locale -> funziona da ogni pagina
    const isCta = new RegExp('background:' + escapeRe(ACCENT)).test(style) ? 1 : 0;
    items.push({ label, href, is_cta: isCta });
  }
  NAV_SEED = items;
}

// JS: aggiunge/toglie la classe .nav-shrink in base allo scroll (pagina che scorre sotto al menu)
const NAV_JS = `<script>
(function(){var n=document.getElementById('siteNav');if(!n)return;
function u(){n.classList.toggle('nav-shrink',(window.scrollY||document.documentElement.scrollTop)>24);}
u();window.addEventListener('scroll',u,{passive:true});})();
// Menu mobile: il toggle JS del Design non esiste nel sito statico -> shim vanilla
// (apre/chiude il pannello .mc-mobmenu al click sul burger .mc-burger; no-op se assenti).
(function(){var b=document.querySelector('.mc-burger'),m=document.querySelector('.mc-mobmenu');if(!b||!m)return;
b.addEventListener('click',function(e){e.preventDefault();m.style.display=(getComputedStyle(m).display==='none')?'block':'none';});})();
// Slideshow hero: track flex + translateX (frecce .mcarrow ‹›, dots, autoplay). No-op fuori home.
// NB: il browser normalizza gli style inline (spazi dopo i ':'), quindi niente selettori CSS
// su substring di style — track individuato via JS, dots = i button che NON sono .mcarrow.
// Autoplay: 5.5s come il Design, in pausa su hover, disattivato con prefers-reduced-motion.
(function(){var divs=document.getElementsByTagName('div'),track=null,q;
for(q=0;q<divs.length;q++){var s=divs[q].getAttribute('style')||'';if(s.indexOf('translateX')>=0&&s.indexOf('transition')>=0&&s.indexOf('absolute')<0){track=divs[q];break;}}
if(!track||track.children.length<2)return;var n=track.children.length,root=track.parentElement;
var allb=root.getElementsByTagName('button'),arrows=[],dots=[];
for(q=0;q<allb.length;q++){((allb[q].className||'').indexOf('mcarrow')>=0?arrows:dots).push(allb[q]);}
var i=0,timer=null;
var reduced=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
function go(k){i=(k%n+n)%n;track.style.transform='translateX(-'+(i*100)+'%)';for(var d=0;d<dots.length;d++)dots[d].style.opacity=(d===i)?'1':'0.3';}
function stop(){if(timer){clearInterval(timer);timer=null;}}
function start(){if(reduced||timer)return;timer=setInterval(function(){go(i+1);},5500);}
function reset(){stop();start();}
if(arrows[0])arrows[0].addEventListener('click',function(e){e.preventDefault();go(i-1);reset();});
if(arrows[1])arrows[1].addEventListener('click',function(e){e.preventDefault();go(i+1);reset();});
for(var d=0;d<dots.length;d++)(function(k){dots[k].addEventListener('click',function(e){e.preventDefault();go(k);reset();});})(d);
root.addEventListener('mouseenter',stop);root.addEventListener('mouseleave',function(){start();});
go(0);start();})();
// Filtro categorie Prodotti: chip .mcchip[data-cat-key] + schede .mccard[data-cat]. No-op altrove.
// Supporta i deep-link del menu (prodotti.html#cat=ufficio o ?cat=...) anche a pagina già aperta.
(function(){var chips=document.querySelectorAll('.mcchip[data-cat-key]'),cards=document.querySelectorAll('.mccard[data-cat]');
if(!chips.length||!cards.length)return;
var SEL={bg:'#d92231',col:'#ffffff',bd:'#d92231'},UNS={bg:'#ffffff',col:'#3a3a3a',bd:'#dcdcdc'};
function key(el){return el.getAttribute('data-cat-key');}
function apply(k){for(var j=0;j<chips.length;j++){var s=(key(chips[j])===k)?SEL:UNS;
chips[j].style.background=s.bg;chips[j].style.color=s.col;chips[j].style.borderColor=s.bd;}
for(var c=0;c<cards.length;c++){var cc=cards[c].getAttribute('data-cat');cards[c].style.display=(k==='tutti'||cc===k)?'':'none';}}
function fromUrl(){var m=/[?#&]cat=([a-z0-9_-]+)/i.exec(location.search+' '+location.hash);
if(!m)return null;var k=m[1].toLowerCase();
for(var j=0;j<chips.length;j++)if(key(chips[j])===k)return k;return null;}
for(var j=0;j<chips.length;j++)(function(el){el.addEventListener('click',function(){apply(key(el));});})(chips[j]);
window.addEventListener('hashchange',function(){var k=fromUrl();if(k)apply(k);});
var init=fromUrl();if(init)apply(init);})();
// Reveal on scroll: le sezioni entrano a comparsa (fade + leggera salita) quando
// entrano nel viewport. Solo wrapper di contenuto centrati (max-width + margin
// auto), niente header. Le sezioni gia' in vista al caricamento NON vengono
// nascoste (nessun flash). Usa IntersectionObserver (standard, robusto): rivela
// in base all'intersezione reale. Lo stato nascosto lo aggiunge SOLO questo JS:
// senza JS, senza IntersectionObserver o con prefers-reduced-motion i contenuti
// restano pienamente visibili.
(function(){
if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
if(!('IntersectionObserver' in window))return;
var all=[].slice.call(document.querySelectorAll('div[style*="max-width"]'));
var t=all.filter(function(el){var s=el.getAttribute('style')||'';
  if(s.indexOf('auto')<0)return false; if(el.closest('#siteNav'))return false; return true;});
t=t.filter(function(el){return !t.some(function(o){return o!==el&&o.contains(el);});});
if(!t.length)return;
var io=new IntersectionObserver(function(es){es.forEach(function(e){
  if(e.isIntersecting){e.target.classList.add('vb-in');io.unobserve(e.target);}});},
  {rootMargin:'0px 0px -12% 0px',threshold:0});
var vh=Math.max(window.innerHeight||0,document.documentElement.clientHeight||0,300);
t.forEach(function(el){if(el.getBoundingClientRect().top<vh*0.9)return; // gia' in vista: lascia visibile
  el.classList.add('vb-reveal'); io.observe(el);});
})();
</script>`;

// Manifest CMS: per ogni pagina i campi di testo editabili (riempito da buildPage)
const CMS_PAGES_MANIFEST = { pages: {}, lists: {} };

function assembleDoc(page, bodyContent, head, hoverRules) {
  return `<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${page.title}</title>
${ogHead(page)}
${head.trim()}
<style>
${NAV_CSS}
${hoverRules.join('\n')}
</style>
</head>
<body>
${bodyContent.trim()}
${NAV_JS}
</body>
</html>
`;
}

// Estrae qualunque elemento marcato data-vb-skip (bilanciamento tag generico, stesso principio
// di data-vb-list) e lo sostituisce con un placeholder prima della tokenizzazione testo — permette
// di escludere una regione dall'editing senza doverne conoscere ID/firma CSS in anticipo.
function extractSkipBlocks(body) {
  const blocks = [];
  let work = body;
  const openRe = /<([a-zA-Z0-9-]+)\b[^>]*\bdata-vb-skip\b[^>]*>/;
  let guard = 0, m;
  while (guard++ < 50 && (m = openRe.exec(work))) {
    const tag = m[1], start = m.index, innerStart = start + m[0].length;
    const tagRe = new RegExp('<' + tag + '\\b|</' + tag + '>', 'g'); tagRe.lastIndex = innerStart;
    let depth = 1, mm, end = -1;
    while ((mm = tagRe.exec(work)) !== null) {
      if (mm[0].slice(0, 2) === '</') { depth--; if (depth === 0) { end = tagRe.lastIndex; break; } }
      else depth++;
    }
    if (end === -1) break;
    const ph = '<!--VBSKIPPH' + blocks.length + '-->';
    blocks.push({ ph, full: work.slice(start, end) });
    work = work.slice(0, start) + ph + work.slice(end);
  }
  return { work, blocks };
}

// Estrae i testi editabili dal body e li sostituisce con token ⟦fN⟧.
// Salta la barra di navigazione (non editabile per ora) e qualunque data-vb-skip. Ritorna {template, fields}.
function tokenizeEditable(body) {
  const nav = sliceBalancedDiv(body, '<div id="siteNav"');
  const foot = sliceFooterRow(body); // il footer condiviso non va tokenizzato (verrà dal DB)
  const PH = '<!--VBNAVPH-->', PHF = '<!--VBFOOTPH-->';
  let work = nav ? body.replace(nav, () => PH) : body;
  if (foot) work = work.replace(foot, () => PHF);
  const skip = extractSkipBlocks(work);
  work = skip.work;
  const fields = [];
  let i = 0;
  const tok = work.replace(/>([^<]+)</g, (m, txt) => {
    const t = txt.trim();
    if (t.length < 2 || !/[A-Za-zÀ-ÿ]{2,}/.test(t)) return m; // solo testo con lettere vere
    const key = 'f' + (i++);
    const lead = txt.slice(0, txt.length - txt.trimStart().length);
    const trail = txt.slice(txt.trimEnd().length);
    fields.push({ key, default: t, type: t.length > 70 ? 'textarea' : 'text', label: t.length > 52 ? t.slice(0, 52) + '…' : t });
    return '>' + lead + '⟦' + key + '⟧' + trail + '<';
  });
  let template = tok;
  skip.blocks.forEach(b => { template = template.replace(b.ph, () => b.full); });
  template = nav ? template.replace(PH, () => nav) : template;
  if (foot) template = template.replace(PHF, () => foot);
  return { template, fields };
}

function slugOf(page) { return page.out === 'index.html' ? 'home' : page.out.replace('.html', ''); }

// Sostituisce il blocco <sc-for list="{{ families }}">...</sc-for> con un marker (i prodotti verranno dal DB)
// Individuazione: convenzione data-vb-collection="products" sul loop (stesso schema di data-vb-list),
// con fallback alla stringa letterale usata finora (il Design attuale non ha ancora l'attributo).
function findProductsBlockStart(tpl) {
  const m = /<sc-for\b[^>]*\bdata-vb-collection="products"[^>]*>/.exec(tpl);
  if (m) return m.index;
  return tpl.indexOf('<sc-for list="{{ families }}"');
}
function replaceFamiliesBlock(tpl, marker) {
  const start = findProductsBlockStart(tpl);
  if (start === -1) return tpl;
  const re = /<sc-for\b|<\/sc-for>/g; re.lastIndex = start;
  let depth = 0, m, end = -1;
  while ((m = re.exec(tpl)) !== null) {
    if (m[0] === '</sc-for>') { depth--; if (depth === 0) { end = re.lastIndex; break; } }
    else depth++;
  }
  return end === -1 ? tpl : tpl.slice(0, start) + marker + tpl.slice(end);
}
// CSS hover delle schede prodotto (rese lato PHP dal DB nella pagina Prodotti)
const PRODUCTS_CSS = '.vb-pcard{transition:transform .25s ease,box-shadow .25s ease}.vb-pcard:hover{transform:translateY(-6px);box-shadow:0 18px 36px rgba(0,0,0,.1)}.vb-plink{transition:opacity .2s ease}.vb-plink:hover{opacity:.6}';

// --- Liste ripetibili (Fase 3): <sc-for ... data-vb-list="KEY"> -> marker <!--VBLIST:KEY--> reso dal DB ---
const LIST_SEED = {}; // {KEY: [{field:val}]}
const LIST_LABELS = { n:'Numero', num:'Numero', numero:'Numero', title:'Titolo', titolo:'Titolo', desc:'Descrizione', descrizione:'Descrizione', text:'Testo', testo:'Testo', body:'Testo', label:'Etichetta', color:'Colore', colore:'Colore', value:'Valore', valore:'Valore', sub:'Sottotitolo', subtitle:'Sottotitolo', sottotitolo:'Sottotitolo', icon:'Icona', name:'Nome', nome:'Nome', image:'Immagine', img:'Immagine', immagine:'Immagine', foto:'Immagine', alt:'Testo alternativo', caption:'Didascalia', didascalia:'Didascalia', eyebrow:'Etichetta', cta_label:'Testo pulsante', cta_link:'Link pulsante', position:'Posizione testo', cat:'Categoria (chiave filtro)', catlabel:'Etichetta categoria', href:'Link scheda prodotto', pos:'Posizione immagine (object-position)' };
function humanize(s) { return String(s).replace(/[_-]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); }
function listFieldLabel(f) { return LIST_LABELS[f.toLowerCase()] || humanize(f); }
// Tipo di campo (per l'editor admin): colore, immagine (media picker), testo lungo, o testo breve.
function listFieldType(f) {
  if (/^colou?re?$/i.test(f)) return 'color';
  if (/^(image|img|immagine|foto|photo|cover|picture)$/i.test(f)) return 'image';
  if (/desc|text|body|testo|descr/i.test(f)) return 'textarea';
  return 'text';
}

// Trova i loop marcati data-vb-list / data-vb-slider / data-vb-gallery, li rimpiazza con marker,
// ed estrae schema (campi nominati) + seed.
//  - data-vb-list    -> lista generica: l'inner del Design diventa itemTemplate (⟦field⟧).
//  - data-vb-slider  -> reso a runtime da block_render_slider() (transizioni/overlay/autoplay).
//  - data-vb-gallery -> reso a runtime da block_render_galleria() (griglia + lightbox).
// Slider/galleria NON usano l'inner del Design come template: i campi nominati ({{ s.image }},
// {{ s.title }}, ...) alimentano il renderer PHP dei blocchi. Config opzionale sul tag:
// data-vb-transition / data-vb-autoplay / data-vb-interval / data-vb-height / data-vb-columns.
function extractListBlocks(tpl, vals, hoverRules) {
  const lists = {}, seed = {};
  const openRe = /<sc-for\b[^>]*\bdata-vb-(list|slider|gallery)="([a-z0-9_]+)"[^>]*>/i;
  let guard = 0, m;
  while (guard++ < 50 && (m = openRe.exec(tpl))) {
    const kind = m[1].toLowerCase(), key = m[2], openTag = m[0], start = m.index, innerStart = start + openTag.length;
    const re = /<sc-for\b|<\/sc-for>/g; re.lastIndex = innerStart;
    let depth = 1, mm, closeStart = -1, end = -1;
    while ((mm = re.exec(tpl)) !== null) {
      if (mm[0] === '</sc-for>') { depth--; if (depth === 0) { closeStart = mm.index; end = re.lastIndex; break; } }
      else depth++;
    }
    if (end === -1) break;
    const inner = tpl.slice(innerStart, closeStart);
    const asName = (openTag.match(/\bas="([^"]+)"/) || [])[1] || 'item';
    const listExpr = ((openTag.match(/\blist="\{\{([^}]+)\}\}"/) || [])[1] || '').trim();
    // campi nominati {{ as.field }}. NB: lists.php sostituisce solo token minuscoli
    // ([a-z0-9_]+): un campo camelCase (es. catLabel) verrebbe seminato ma mai reso.
    // Normalizziamo: il nome originale serve per leggere i dati del Design, la chiave
    // token/manifest/seed è sempre lowercase.
    const fields = [];
    const fre = new RegExp('\\{\\{\\s*' + asName + '\\.([a-zA-Z0-9_]+)', 'g');
    let fm; while ((fm = fre.exec(inner)) !== null) if (!fields.includes(fm[1])) fields.push(fm[1]);
    // seed dai valori reali del Design. I valori che sono link interni al Design
    // (es. href "VersaLinkC625.dc.html") vengono riscritti via LINKMAP: postProcess
    // riscrive solo l'HTML del template, non i DATI che finiscono nel DB.
    const mapSeedLink = (v) => {
      for (const [from, to] of Object.entries(LINKMAP)) if (v.startsWith(from)) return to + v.slice(from.length);
      return v;
    };
    const arr = (listExpr ? resolve(listExpr, vals) : []) || [];
    seed[key] = arr.map(it => { const o = {}; fields.forEach(f => o[f.toLowerCase()] = (it && it[f] != null) ? mapSeedLink(String(it[f])) : ''); return o; });
    const fieldDefs = fields.map(f => ({ key: f.toLowerCase(), label: listFieldLabel(f), type: listFieldType(f) }));
    if (kind === 'list') {
      // itemTemplate: render dell'inner con i campi come token ⟦field⟧
      const scope = Object.assign({}, vals); scope[asName] = {}; fields.forEach(f => scope[asName][f] = '⟦' + f.toLowerCase() + '⟧');
      const itemTemplate = postProcess(render(parse(inner), scope), hoverRules);
      lists[key] = { as: asName, fields: fieldDefs, itemTemplate };
    } else {
      // slider/galleria: config opzionale dal tag (con default), resa dal renderer PHP dei blocchi
      const cfgAttr = (n, d) => { const a = openTag.match(new RegExp('\\bdata-vb-' + n + '="([^"]*)"')); return a ? a[1] : d; };
      const config = kind === 'slider'
        ? { transition: cfgAttr('transition', 'fade'), autoplay: cfgAttr('autoplay', '1'), interval: cfgAttr('interval', '5'), height: cfgAttr('height', '70vh') }
        : { columns: cfgAttr('columns', '3') };
      lists[key] = { as: asName, fields: fieldDefs, render: kind, config };
    }
    tpl = tpl.slice(0, start) + '<!--VBLIST:' + key + '-->' + tpl.slice(end);
  }
  return { tpl, lists, seed };
}

// --- Opzioni globali dal Design: social/maps riconosciuti OVUNQUE + data-vb-opt manuale ---
// Nel template CMS l'href di questi <a> diventa il token ⟦opt_KEY⟧ (reso da cms_render dalle
// impostazioni globali, default = link originale del Design). Nel sito statico resta il link reale.
// Così un link social/maps si edita una volta dal pannello "Opzioni" e vale ovunque appaia.
const OPTIONS_SEED = {}; // key -> { default, label, group }
// I pattern si applicano all'HOST, non all'href intero, e sono ancorati: senza ancoraggio
// /x\.com/ matcha "workflowcentral.services.xerox.com" (xero-X.COM) e dirotta un link di
// prodotto sull'opzione social X/Twitter. Stessa famiglia del bug regex già visto nel sameAs.
const SOCIAL_HOSTS = [
  [/(?:^|\.)(?:facebook\.com|fb\.com|fb\.me)$/i,            'social_facebook',  'Facebook'],
  [/(?:^|\.)instagram\.com$/i,                              'social_instagram', 'Instagram'],
  [/(?:^|\.)linkedin\.com$/i,                               'social_linkedin',  'LinkedIn'],
  [/(?:^|\.)(?:twitter\.com|x\.com)$/i,                     'social_twitter',   'X (Twitter)'],
  [/(?:^|\.)(?:youtube\.com|youtube-nocookie\.com|youtu\.be)$/i, 'social_youtube', 'YouTube'],
  [/(?:^|\.)tiktok\.com$/i,                                 'social_tiktok',    'TikTok'],
  [/(?:^|\.)(?:wa\.me|whatsapp\.com)$/i,                    'social_whatsapp',  'WhatsApp'],
  [/(?:^|\.)pinterest\.[a-z.]+$/i,                          'social_pinterest', 'Pinterest'],
  [/(?:^|\.)(?:t\.me|telegram\.me)$/i,                      'social_telegram',  'Telegram'],
];
const MAPS_RE = /(?:google\.[a-z.]+\/maps|maps\.google|maps\.app\.goo\.gl|goo\.gl\/maps)/i;
function hostOf(href) {
  const m = /^(?:https?:)?\/\/([^/?#]+)/i.exec(String(href || ''));
  return m ? m[1].toLowerCase().replace(/:\d+$/, '') : '';
}
function optionForHref(attrs, href) {
  const m = /\bdata-vb-opt="([a-z0-9_]+)"/i.exec(attrs);
  if (m) return { key: m[1], label: humanize(m[1]), group: 'Opzioni' };
  const host = hostOf(href);
  if (host) for (const [re, key, label] of SOCIAL_HOSTS) if (re.test(host)) return { key, label, group: 'Social' };
  if (MAPS_RE.test(href)) return { key: 'maps_url', label: 'Link Google Maps', group: 'Mappa' };
  return null;
}
function extractOptions(html) {
  return html.replace(/<a\b([^>]*?)\shref="([^"]*)"([^>]*)>/gi, (full, pre, href, post) => {
    const opt = optionForHref(pre + ' ' + post, href);
    if (!opt) return full;
    if (!OPTIONS_SEED[opt.key]) OPTIONS_SEED[opt.key] = { default: href, label: opt.label, group: opt.group };
    return '<a' + pre + ' href="⟦opt_' + opt.key + '⟧"' + post + '>';
  });
}

// --- Form come MODULO dal Design (Fase form): <form data-vb-form="KEY"> -> cms_forms ---
// Nel template CMS il form diventa <!--VBFORM:KEY--> (reso da cms_render con render_form_html
// dal DB). Nel sito statico il form del Design resta (look originale) ma punta a forms.php
// (handler universale del modulo) + hidden form_slug, così funziona anche prima di pubblicare.
const FORMS_SEED = {}; // key -> { name, slug, recipients, subject, success_message, fields[] }
function labelBefore(inner, pos) {
  const before = inner.slice(0, pos);
  const labels = [...before.matchAll(/<label\b[^>]*>([\s\S]*?)<\/label>/gi)];
  if (labels.length) return labels[labels.length - 1][1].replace(/<[^>]+>/g, '').replace(/\*/g, '').trim();
  return '';
}
function extractFormFields(inner) {
  const fields = [];
  const re = /<(input|textarea|select)\b([^>]*?)\/?>/gi;
  let mm;
  while ((mm = re.exec(inner)) !== null) {
    const tag = mm[1].toLowerCase(), attrs = mm[2];
    const type0 = (attrs.match(/\btype="([^"]*)"/i) || [])[1] || (tag === 'input' ? 'text' : tag);
    if (['hidden', 'submit', 'button', 'reset', 'image'].includes(type0)) continue;
    const name = (attrs.match(/\bname="([^"]*)"/i) || [])[1] || ('campo' + (fields.length + 1));
    const required = /\brequired\b/i.test(attrs) ? 1 : 0;
    const placeholder = (attrs.match(/\bplaceholder="([^"]*)"/i) || [])[1] || '';
    let ftype;
    if (tag === 'textarea') ftype = 'textarea';
    else if (tag === 'select') ftype = 'select';
    else if (type0 === 'checkbox') ftype = /privac|consenso|gdpr/i.test(name + attrs) ? 'consent' : 'checkbox';
    else if (type0 === 'email') ftype = 'email';
    else if (type0 === 'tel') ftype = 'tel';
    else ftype = 'text';
    let label = ftype === 'consent' ? 'Consenso privacy' : labelBefore(inner, mm.index);
    if (!label) label = placeholder || humanize(name);
    const f = { field_key: name, label, type: ftype, required, placeholder, options: '' };
    if (/^e?-?mail$/i.test(name)) f.vt_map = 'email';
    else if (/nome|name/i.test(name)) f.vt_map = 'nome';
    else if (/tel|phone|telefono/i.test(name)) f.vt_map = 'telefono';
    else if (/messagg|message|msg/i.test(name)) f.vt_map = 'messaggio';
    else f.vt_map = '';
    fields.push(f);
  }
  return fields;
}
function findFormBlock(html) {
  const m = /<form\b[^>]*\bdata-vb-form="([a-z0-9_]+)"[^>]*>/i.exec(html);
  if (!m) return null;
  const key = m[1], start = m.index, innerStart = start + m[0].length;
  const re = /<form\b|<\/form>/gi; re.lastIndex = innerStart;
  let depth = 1, mm, closeStart = -1, end = -1;
  while ((mm = re.exec(html)) !== null) {
    if (mm[0].toLowerCase() === '</form>') { depth--; if (depth === 0) { closeStart = mm.index; end = re.lastIndex; break; } }
    else depth++;
  }
  if (end === -1) return null;
  return { key, start, innerStart, closeStart, end };
}
// Template CMS: sostituisce il form con il marker e registra il modulo (campi + destinatario).
function extractFormModule(html) {
  const b = findFormBlock(html);
  if (!b) return html;
  const inner = html.slice(b.innerStart, b.closeStart);
  if (!FORMS_SEED[b.key]) {
    FORMS_SEED[b.key] = {
      name: humanize(b.key), slug: b.key, recipients: CONTACT_EMAIL,
      subject: 'Nuova richiesta dal sito ' + SITE.name,
      success_message: 'Grazie! Abbiamo ricevuto la tua richiesta e ti risponderemo al più presto.',
      fields: extractFormFields(inner),
    };
  }
  return html.slice(0, b.start) + '<!--VBFORM:' + b.key + '-->' + html.slice(b.end);
}
// Sito statico: il form del Design resta ma punta a forms.php (+ hidden form_slug) così funziona
// tramite l'handler del modulo anche prima della pubblicazione.
function staticFormToModule(html) {
  const b = findFormBlock(html);
  if (!b) return html;
  const openTag = html.slice(b.start, b.innerStart);
  const attrs = openTag.replace(/^<form\b/i, '').replace(/>$/, '').replace(/\saction="[^"]*"/i, '');
  const newOpen = '<form' + attrs + ' action="forms.php">' + '<input type="hidden" name="form_slug" value="' + b.key + '">';
  return html.slice(0, b.start) + newOpen + html.slice(b.innerStart);
}

function buildPage(page) {
  const raw = fs.readFileSync(path.join(SRC, page.src), 'utf8');
  const { template, head, scriptBody, propsMeta } = extract(raw);
  const vals = computeVals(scriptBody, propsMeta, page.props);
  const tree = parse(template);
  let body = render(tree, vals);
  const hoverRules = [];
  body = postProcess(body, hoverRules);
  if (page.form) body = wrapContactForm(body);
  body = staticFormToModule(body); // <form data-vb-form> statico -> punta a forms.php (modulo)

  const doc = assembleDoc(page, body, head, hoverRules);
  fs.mkdirSync(OUT, { recursive: true });
  fs.writeFileSync(path.join(OUT, page.out), doc, 'utf8');

  // CMS: template tokenizzato + campi editabili nel manifest
  const slug = slugOf(page);
  if (slug === 'home') { extractNavSeed(body); extractFooterSeed(body); extractNavShellSeed(body); } // menu, footer e barra intera si seminano una volta dalla home
  // Template CMS: prodotti e liste ripetibili diventano marker (resi dal DB); il resto resta testo editabile.
  const cmsHover = [];
  let cmsTpl = template;
  if (page.products) cmsTpl = replaceFamiliesBlock(cmsTpl, '<!--VBFAMILIES-->');
  const listRes = extractListBlocks(cmsTpl, vals, cmsHover);
  cmsTpl = listRes.tpl;
  let cmsBody = postProcess(render(parse(cmsTpl), vals), cmsHover);
  if (page.form) cmsBody = wrapContactForm(cmsBody);
  cmsBody = extractOptions(cmsBody); // social/maps/data-vb-opt -> ⟦opt_KEY⟧ (globali dal pannello)
  cmsBody = extractFormModule(cmsBody); // <form data-vb-form> -> <!--VBFORM:KEY--> (modulo dal DB)
  if (page.products) cmsHover.push(PRODUCTS_CSS);
  // registra le liste nel manifest globale + seed
  for (const k in listRes.lists) {
    CMS_PAGES_MANIFEST.lists[k] = Object.assign({ label: humanize(k), page: slug }, listRes.lists[k]);
    LIST_SEED[k] = listRes.seed[k];
  }
  if (page.products) {
    // seed prodotti per la migration
    const seed = (vals.families || []).map(f => ({
      name: f.name, tagline: f.tagline,
      products: (f.products || []).map(p => ({ model: p.model, category: p.category, speed: p.speed || '', cycle: p.cycle || '', image: p.image || '', bullets: p.bullets || [] })),
    }));
    fs.mkdirSync(path.join(OUT, 'inc'), { recursive: true });
    fs.writeFileSync(path.join(OUT, 'inc', 'products-seed.json'), JSON.stringify(seed, null, 1), 'utf8');
  }
  const { template: tokBody, fields } = tokenizeEditable(cmsBody);
  let tplDoc = assembleDoc(page, tokBody, head, cmsHover);

  // SEO editabile: sostituisce titolo/descrizione/immagine social con token nel <head>
  const seoFields = [];
  const ogimg = page.og || SITE.ogImage;
  tplDoc = tplDoc.split(page.title).join('⟦seo_title⟧');
  seoFields.push({ key: 'seo_title', default: page.title, type: 'text', label: 'Titolo pagina (scheda browser + Google)' });
  if (page.desc) {
    tplDoc = tplDoc.split(page.desc).join('⟦seo_desc⟧');
    seoFields.push({ key: 'seo_desc', default: page.desc, type: 'textarea', label: 'Descrizione (Google + anteprima condivisione)' });
  }
  tplDoc = tplDoc.split(ogimg).join('⟦seo_image⟧');
  seoFields.push({ key: 'seo_image', default: ogimg, type: 'text', label: 'Immagine condivisione social (URL completo)' });

  tplDoc = replaceNavBlock(tplDoc, '<!--VBNAV-->'); // il menu verrà reso dal DB (nav.php)
  tplDoc = replaceMarkedBlock(tplDoc, 'data-vb-nav-mobile', '<!--VBNAVMOBILE-->'); // menu mobile dal DB
  tplDoc = replaceFooterInner(tplDoc);              // il footer verrà dal DB (footer.php)
  fs.mkdirSync(path.join(OUT, 'inc', 'tpl'), { recursive: true });
  fs.writeFileSync(path.join(OUT, 'inc', 'tpl', slug + '.html'), tplDoc, 'utf8');
  const listKeys = Object.keys(listRes.lists);
  CMS_PAGES_MANIFEST.pages[slug] = { title: page.title, out: page.out, slug, fields: [...seoFields, ...fields], hasProducts: !!page.products, lists: listKeys };

  console.log(`  ✓ ${page.src}  ->  dist/${page.out}  (${(doc.length/1024).toFixed(1)} KB, ${hoverRules.length} hover, ${fields.length} campi CMS${page.products ? ', +prodotti DB' : ''}${listKeys.length ? ', +' + listKeys.length + ' lista/e (' + listKeys.join(',') + ')' : ''})`);
}

console.log('Conversione pagine:');
for (const p of PAGES) {
  try { buildPage(p); }
  catch (e) { console.error(`  ✗ ${p.src}: ${e.message}`); }
}
// manifest CMS multi-pagina
try {
  fs.mkdirSync(path.join(OUT, 'inc'), { recursive: true });
  fs.writeFileSync(path.join(OUT, 'inc', 'cms-pages.json'), JSON.stringify(CMS_PAGES_MANIFEST, null, 1), 'utf8');
  const tot = Object.values(CMS_PAGES_MANIFEST.pages).reduce((s, p) => s + p.fields.length, 0);
  const nl = Object.keys(CMS_PAGES_MANIFEST.lists).length;
  console.log(`  ✓ CMS manifest: ${Object.keys(CMS_PAGES_MANIFEST.pages).length} pagine, ${tot} campi editabili${nl ? ', ' + nl + ' liste ripetibili' : ''}`);
  // seed delle liste ripetibili (valori iniziali dal Design)
  if (nl) {
    fs.writeFileSync(path.join(OUT, 'inc', 'lists-seed.json'), JSON.stringify(LIST_SEED, null, 1), 'utf8');
    const totItems = Object.values(LIST_SEED).reduce((s, a) => s + a.length, 0);
    console.log(`  ✓ lists-seed.json: ${totItems} elementi in ${nl} liste`);
  }
} catch (e) { console.error('  ✗ manifest: ' + e.message); }
// seed del menu di navigazione (una volta, dalla home)
try {
  if (NAV_SEED && NAV_SEED.length) {
    fs.writeFileSync(path.join(OUT, 'inc', 'nav-seed.json'), JSON.stringify(NAV_SEED, null, 1), 'utf8');
    console.log(`  ✓ nav-seed.json: ${NAV_SEED.length} voci di menu`);
  } else console.log('  ! nav-seed.json non generato (blocco nav non trovato)');
} catch (e) { console.error('  ✗ nav-seed: ' + e.message); }
// seed del footer condiviso (una volta, dalla home)
try {
  if (FOOTER_SEED) {
    fs.writeFileSync(path.join(OUT, 'inc', 'footer-seed.json'), JSON.stringify(FOOTER_SEED, null, 1), 'utf8');
    console.log('  ✓ footer-seed.json: "' + FOOTER_SEED.left + '" | "' + FOOTER_SEED.right + '"');
  } else console.log('  ! footer-seed.json non generato (riga footer non trovata)');
} catch (e) { console.error('  ✗ footer-seed: ' + e.message); }
// seed dell'intera barra nav (per le pagine senza Design: page-builder, blog)
try {
  if (NAV_SHELL_SEED) {
    fs.writeFileSync(path.join(OUT, 'inc', 'nav-shell-seed.html'), NAV_SHELL_SEED, 'utf8');
    console.log('  ✓ nav-shell-seed.html: ' + (NAV_SHELL_SEED.length / 1024).toFixed(1) + ' KB');
  } else console.log('  ! nav-shell-seed.html non generato (barra #siteNav non trovata)');
} catch (e) { console.error('  ✗ nav-shell-seed: ' + e.message); }
// seed delle opzioni globali (social/maps/data-vb-opt riconosciuti nel Design)
try {
  if (Object.keys(OPTIONS_SEED).length) {
    fs.writeFileSync(path.join(OUT, 'inc', 'options-seed.json'), JSON.stringify(OPTIONS_SEED, null, 1), 'utf8');
    console.log('  ✓ options-seed.json: ' + Object.keys(OPTIONS_SEED).length + ' opzioni (' + Object.keys(OPTIONS_SEED).join(', ') + ')');
  } else console.log('  · nessuna opzione social/maps rilevata nel Design');
} catch (e) { console.error('  ✗ options-seed: ' + e.message); }
// seed dei moduli (form riconosciuti nel Design via data-vb-form)
try {
  if (Object.keys(FORMS_SEED).length) {
    fs.writeFileSync(path.join(OUT, 'inc', 'forms-seed.json'), JSON.stringify(FORMS_SEED, null, 1), 'utf8');
    const nf = Object.values(FORMS_SEED).reduce((s, f) => s + f.fields.length, 0);
    console.log('  ✓ forms-seed.json: ' + Object.keys(FORMS_SEED).length + ' modulo/i (' + Object.keys(FORMS_SEED).join(', ') + '), ' + nf + ' campi');
  } else console.log('  · nessun form (data-vb-form) rilevato nel Design');
} catch (e) { console.error('  ✗ forms-seed: ' + e.message); }

// --- Landing "Scarica la guida" (guida.php) con form + invio email ---
function sliceBalancedDiv(html, openTag) {
  const idx = html.indexOf(openTag);
  if (idx === -1) return '';
  let depth = 0; const re = /<div\b|<\/div>/g; re.lastIndex = idx;
  let m;
  while ((m = re.exec(html)) !== null) {
    if (m[0] === '</div>') { depth--; if (depth === 0) return html.slice(idx, re.lastIndex); }
    else depth++;
  }
  return '';
}

function buildGuida() {
  const G = ACCENT;
  const indexHtml = fs.readFileSync(path.join(OUT, 'index.html'), 'utf8');
  const nav = sliceBalancedDiv(indexHtml, '<div id="siteNav"');
  const page = {
    out: 'guida.php',
    title: 'Scarica la guida introduttiva — ${SITE.name}',
    desc: 'Scarica la guida introduttiva al ${SITE.name}: lascia i tuoi dati e ricevi subito il manuale via email.',
  };
  const php = `<?php
require_once __DIR__ . '/inc/mailer.php';
require_once __DIR__ . '/inc/consent.php';
require_once __DIR__ . '/inc/messages.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$sent = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nome = trim($_POST['nome'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $azienda = trim($_POST['azienda'] ?? '');
  $privacy = isset($_POST['privacy']);
  if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$privacy) {
    $err = 'Controlla i campi: nome, email valida e consenso privacy sono obbligatori.';
  } else {
    $testo = "Ciao $nome,\\n\\ngrazie per l'interesse verso ${SITE.name}.\\n"
           . "In allegato trovi la guida introduttiva."
           . (defined('GUIDE_PUBLIC_URL') ? "\\nPuoi anche scaricarla qui: " . GUIDE_PUBLIC_URL : "")
           . "\\n\\nUn saluto,\\nIl team ${SITE.name} — M.C. System";
    $att = [];
    if (defined('GUIDE_FILE') && is_readable(GUIDE_FILE)) {
      $att[] = ['path' => GUIDE_FILE, 'name' => 'Guida introduttiva NuovoSostenibile.pdf', 'type' => 'application/pdf'];
    }
    nc_log_consent('guida', $nome, $email); // registra consenso (data/ora/IP)
    nc_save_message('guida', $nome, $email, $azienda, 'Richiesta guida introduttiva', nc_client_ip()); // archivia messaggio
    $lead_ok = true; // dati validi e salvati: candidato all'invio al CRM
    $sent = nc_send_mail($email, 'La tua guida introduttiva al ${SITE.name}', $testo, $att);
    if (!$sent) $err = 'Invio non riuscito, riprova tra poco o scrivi a ' . (defined('MAIL_REPLYTO') ? MAIL_REPLYTO : '${CONTACT_EMAIL}') . '.';
  }
}
?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${page.title}</title>
${ogHead(page)}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="${FONTS_HREF}" rel="stylesheet">
<style>
  body{margin:0;background:${PAGE_BG};font-family:${BODY_FONT};color:${BODY_COLOR}}
  ::placeholder{color:oklch(65% 0.02 260)}
  input:focus{outline:none;border-color:${G}}
${NAV_CSS}
</style>
</head>
<body>
${nav}
<div style="max-width:1080px;margin:0 auto;padding:72px 32px 96px;display:grid;grid-template-columns:1.05fr .95fr;gap:56px;align-items:center">
  <div>
    <div style="display:inline-block;padding:7px 16px;background:oklch(90% 0.05 152);color:oklch(34% 0.10 152);border-radius:100px;font-size:13px;font-weight:600;margin-bottom:22px">Guida gratuita</div>
    <h1 style="font-family:${HEAD_FONT};font-size:40px;line-height:1.12;font-weight:700;margin:0 0 20px">Scarica la guida introduttiva al ${SITE.name}</h1>
    <p style="font-size:17px;line-height:1.65;color:oklch(52% 0.02 260);margin:0 0 26px;max-width:520px">Cos'è la riFabbricazione, come nasce un dispositivo Fabbricato nuovo e certificato CE, e perché conviene — all'ambiente e al tuo bilancio di sostenibilità. Lascia i tuoi dati: ti inviamo subito la guida via email.</p>
    <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:12px;font-size:15px;color:oklch(40% 0.02 260)">
      <li>✓ Il processo di riFabbricazione in sei fasi</li>
      <li>✓ Usato, rigenerato o ${SITE.name}: le differenze</li>
      <li>✓ I numeri ambientali, modello per modello</li>
    </ul>
  </div>
  <div style="background:#fff;border:1px solid oklch(90% 0.01 260);border-radius:20px;padding:34px;box-shadow:0 12px 40px rgba(0,0,0,.06)">
    <?php if ($sent): ?>
      <div style="text-align:center;padding:24px 6px">
        <div style="width:60px;height:60px;border-radius:50%;background:${G};color:#fff;display:flex;align-items:center;justify-content:center;font-size:26px;margin:0 auto 18px">&#10003;</div>
        <div style="font-family:${HEAD_FONT};font-size:21px;font-weight:700;margin-bottom:10px">Guida in arrivo!</div>
        <p style="font-size:15px;color:oklch(52% 0.02 260);margin:0 0 18px">Ti abbiamo inviato la guida all'indirizzo indicato. Controlla anche lo spam.</p>
        <?php if (defined('GUIDE_PUBLIC_URL') && @is_readable(GUIDE_FILE)): ?>
          <a href="<?= h(GUIDE_PUBLIC_URL) ?>" style="display:inline-block;padding:13px 24px;background:${G};color:#fff;border-radius:100px;font-weight:700;text-decoration:none">Scarica subito</a>
        <?php endif; ?>
        <p style="margin-top:22px"><a href="index.html" style="color:${G};font-weight:600;text-decoration:none">← Torna alla home</a></p>
      </div>
    <?php else: ?>
      <div style="font-family:${HEAD_FONT};font-size:19px;font-weight:700;margin-bottom:6px">Ricevi la guida via email</div>
      <p style="font-size:14px;color:oklch(52% 0.02 260);margin:0 0 20px">Compila il form: è gratis e senza impegno.</p>
      <?php if ($err): ?><div style="background:#fdecec;border:1px solid #f3c2c2;color:#b23a3a;padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:14px;font-weight:600"><?= h($err) ?></div><?php endif; ?>
      <form method="post" style="display:flex;flex-direction:column;gap:16px">
        <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:7px">Nome e cognome</label><input type="text" name="nome" required value="<?= h($_POST['nome'] ?? '') ?>" style="width:100%;box-sizing:border-box;padding:13px 14px;border-radius:10px;border:1px solid oklch(88% 0.01 260);font-size:15px;font-family:'Public Sans',sans-serif"></div>
        <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:7px">Email</label><input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" style="width:100%;box-sizing:border-box;padding:13px 14px;border-radius:10px;border:1px solid oklch(88% 0.01 260);font-size:15px;font-family:'Public Sans',sans-serif"></div>
        <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:7px">Azienda <span style="color:oklch(65% 0.02 260);font-weight:400">(facoltativo)</span></label><input type="text" name="azienda" value="<?= h($_POST['azienda'] ?? '') ?>" style="width:100%;box-sizing:border-box;padding:13px 14px;border-radius:10px;border:1px solid oklch(88% 0.01 260);font-size:15px;font-family:'Public Sans',sans-serif"></div>
        <label style="display:flex;gap:10px;align-items:flex-start;font-size:13px;color:oklch(45% 0.02 260);line-height:1.5"><input type="checkbox" name="privacy" required style="margin-top:2px">Acconsento al trattamento dei dati per ricevere la guida e comunicazioni commerciali, secondo l'informativa privacy.</label>
        <button type="submit" style="margin-top:4px;padding:15px 28px;background:${G};color:#fff;border:none;border-radius:100px;font-size:15px;font-weight:700;cursor:pointer;font-family:'Public Sans',sans-serif">Inviami la guida</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<div style="background:${G};color:#fff;padding:36px 32px">
  <div style="max-width:1080px;margin:0 auto;display:flex;justify-content:space-between;font-size:13px;color:oklch(90% 0.03 152);flex-wrap:wrap;gap:10px">
    <span>© ${COMPANY}</span>
    <span>${SITE.name}</span>
  </div>
</div>
${NAV_JS}
</body>
</html>
<?php
// Integrazione VelociTracker: dopo la risposta, crea/aggiorna l'opportunità nel CRM (non blocca il form).
if (!empty($lead_ok)) {
  if (function_exists('fastcgi_finish_request')) { @ob_end_flush(); @flush(); @fastcgi_finish_request(); }
  require_once __DIR__ . '/inc/velocitracker.php';
  vt_push('guida', ['email' => $email, 'nome' => $nome, 'azienda' => $azienda]);
}
`;
  fs.writeFileSync(path.join(OUT, 'guida.php'), php, 'utf8');
  console.log('  ✓ landing guida.php generata');
}
if (BUILD_GUIDA) { try { buildGuida(); } catch (e) { console.error('  ✗ guida.php: ' + e.message); } }
else console.log('  · guida.php saltata (BRAND.buildGuida = false)');

// --- Informativa privacy (privacy.html) ---
function buildPrivacy() {
  const G = ACCENT;
  const indexHtml = fs.readFileSync(path.join(OUT, 'index.html'), 'utf8');
  const nav = sliceBalancedDiv(indexHtml, '<div id="siteNav"');
  // Eredita gli <style> di index.html (CSS del sito dall'helmet del Design): senza,
  // il nav basato su classi resterebbe non stilizzato e il menu mobile visibile.
  const siteStyles = (indexHtml.match(/<style>[\s\S]*?<\/style>/g) || []).join('\n');
  const page = { out: 'privacy.html', title: 'Informativa privacy — ' + SITE.name,
    desc: 'Informativa sul trattamento dei dati personali (GDPR) del sito ' + SITE.name + '.' };
  const s = (t) => `<h2 style="font-family:${HEAD_FONT};font-size:20px;font-weight:700;margin:32px 0 10px">${t}</h2>`;
  const p = (t) => `<p style="font-size:15px;line-height:1.7;color:oklch(40% 0.02 260);margin:0 0 12px">${t}</p>`;
  const body = `
${s('1. Titolare del trattamento')}
${p('Il titolare del trattamento dei dati è <strong>' + COMPANY + '</strong>. Per qualsiasi richiesta relativa alla privacy puoi scrivere a <a href="mailto:' + CONTACT_EMAIL + '" style="color:' + G + '">' + CONTACT_EMAIL + '</a>.')}
${s('2. Dati raccolti')}
${p('Raccogliamo i dati che ci fornisci volontariamente compilando i moduli del sito (modulo contatti): nome e cognome, indirizzo email, eventuale azienda e il contenuto del messaggio. Al momento dell’invio registriamo inoltre, come prova del consenso, la data e l’ora e l’indirizzo IP di provenienza.')}
${s('3. Finalità e base giuridica')}
${p('I dati sono trattati per rispondere alle tue richieste ed eventualmente ricontattarti in merito (es. per un preventivo). La base giuridica è il tuo <strong>consenso</strong> (art. 6, par. 1, lett. a del Regolamento UE 2016/679) e, per il riscontro alle richieste, l’esecuzione di misure precontrattuali (art. 6, par. 1, lett. b).')}
${s('4. Modalità e conservazione')}
${p('I dati sono trattati con strumenti informatici e misure di sicurezza adeguate, e conservati per il tempo necessario alle finalità indicate e comunque nei limiti previsti dalla legge. Per l’hosting e l’invio delle email ci avvaliamo di ' + PRIVACY_PROVIDER + ', che agisce come responsabile del trattamento.')}
${s('5. Comunicazione dei dati')}
${p('I dati non sono diffusi. Possono essere trattati dal personale autorizzato del titolare e dai fornitori di servizi tecnici (hosting, invio email) nominati responsabili del trattamento.')}
${s('6. Diritti dell’interessato')}
${p('Hai diritto di accedere ai tuoi dati, chiederne la rettifica o la cancellazione, la limitazione o l’opposizione al trattamento, la portabilità, e di revocare in qualsiasi momento il consenso prestato (senza pregiudicare la liceità del trattamento precedente). Puoi esercitare questi diritti scrivendo a <a href="mailto:' + CONTACT_EMAIL + '" style="color:' + G + '">' + CONTACT_EMAIL + '</a>. Hai inoltre diritto di proporre reclamo al Garante per la protezione dei dati personali.')}
${p('<em style="font-size:13px;color:oklch(58% 0.02 260)">Questa informativa è una versione standard: verificane i contenuti con il titolare/consulente prima della pubblicazione definitiva.</em>')}
`;
  const doc = `<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${page.title}</title>
${ogHead(page)}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="${FONTS_HREF}" rel="stylesheet">
${siteStyles}
<style>
  body{margin:0;background:${PAGE_BG};font-family:${BODY_FONT};color:${BODY_COLOR}}
</style>
</head>
<body>
${nav}
<div style="max-width:820px;margin:0 auto;padding:64px 32px 88px">
  <h1 style="font-family:${HEAD_FONT};font-size:34px;font-weight:700;margin:0 0 6px">Informativa sulla privacy</h1>
  <p style="font-size:14px;color:oklch(55% 0.02 260);margin:0 0 8px">Trattamento dei dati personali ai sensi del Regolamento UE 2016/679 (GDPR)</p>
  ${body}
  <p style="margin-top:36px"><a href="index.html" style="color:${G};font-weight:600;text-decoration:none">← Torna alla home</a></p>
</div>
<div style="background:${G};color:#fff;padding:36px 32px">
  <div style="max-width:820px;margin:0 auto;display:flex;justify-content:space-between;font-size:13px;color:oklch(90% 0.03 152);flex-wrap:wrap;gap:10px">
    <span>© ${COMPANY}</span>
    <span>${SITE.name}</span>
  </div>
</div>
${NAV_JS}
</body>
</html>
`;
  fs.writeFileSync(path.join(OUT, 'privacy.html'), doc, 'utf8');
  console.log('  ✓ privacy.html generata');
}
if (BUILD_PRIVACY) { try { buildPrivacy(); } catch (e) { console.error('  ✗ privacy.html: ' + e.message); } }
else console.log('  · privacy.html saltata (BRAND.buildPrivacy = false)');

// --- Supporto CMS: template Home + default esatti per il prototipo admin ---
try {
  const homePage = PAGES[0];
  const raw = fs.readFileSync(path.join(SRC, homePage.src), 'utf8');
  const { scriptBody, propsMeta } = extract(raw);
  const vals = computeVals(scriptBody, propsMeta, homePage.props);
  const defaults = {
    accentColor: vals.accentColor,
    heroEyebrow: vals.heroEyebrow,
    heroTitle: 'Il nuovo, con un<br>cuore sostenibile.', // hardcoded nel template originale
    heroSub: vals.heroSub,
    ctaPrimaryLabel: vals.ctaPrimaryLabel,
    stats: vals.stats, // [{value,label,sub} x4]
  };
  fs.mkdirSync(path.join(OUT, 'inc'), { recursive: true });
  // home.html = copia del sito reale (sorgente di verità per il rendering dinamico)
  fs.copyFileSync(path.join(OUT, 'index.html'), path.join(OUT, 'inc', 'home.html'));
  fs.writeFileSync(path.join(OUT, 'inc', 'cms-defaults.json'), JSON.stringify(defaults, null, 2), 'utf8');
  console.log('  ✓ CMS: inc/home.html + inc/cms-defaults.json');
} catch (e) { console.error('  ✗ CMS defaults: ' + e.message); }

// copia solo assets/ (uploads/ contiene materiale interno non pubblicabile e non è referenziato dalle pagine)
for (const dir of ['assets']) {
  const from = path.join(SRC, dir), to = path.join(OUT, dir);
  if (fs.existsSync(from)) { fs.cpSync(from, to, { recursive: true }); console.log(`  ✓ copiata cartella ${dir}/`); }
}
// Handler PHP del form contatti (invio email su Aruba)
const contattiPhp = `<?php
// Handler form Contatti ${SITE.name} — usa il mailer condiviso (SendGrid o mail())
require_once __DIR__ . '/inc/mailer.php';
require_once __DIR__ . '/inc/consent.php';
require_once __DIR__ . '/inc/messages.php';
$DEST = defined('MAIL_REPLYTO') ? MAIL_REPLYTO : '${CONTACT_EMAIL}';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: contatti.html'); exit; }

$nome = trim($_POST['nome'] ?? '');
$email = trim($_POST['email'] ?? '');
$tipo = trim($_POST['tel'] ?? $_POST['tipo'] ?? '');
$messaggio = trim($_POST['messaggio'] ?? '');
$privacy = isset($_POST['privacy']);

$errore = '';
if ($nome === '' || $messaggio === '') $errore = 'Compila i campi obbligatori.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errore = 'Inserisci un indirizzo email valido.';
if (!$privacy) $errore = 'Per procedere devi accettare l\\'informativa privacy.';

$inviato = false;
if ($errore === '') {
  // registra il consenso (data/ora/IP) e lo riporta nell'email
  nc_log_consent('contatti', $nome, $email);
  $ip = nc_client_ip(); $quando = date('d/m/Y H:i:s');
  nc_save_message('contatti', $nome, $email, $tipo, $messaggio, $ip); // archivia per la sezione Messaggi
  $lead_ok = true; // dati validi e salvati: candidato all'invio al CRM
  $oggetto = 'Richiesta dal sito ${SITE.name} — ' . $nome;
  $corpo = "Nome: $nome\\nEmail: $email\\nTelefono: $tipo\\n\\nMessaggio:\\n$messaggio\\n\\n"
         . "-- Consenso privacy --\\nPrestato: SI\\nData/ora: $quando\\nIP: $ip\\nTesto: " . CONSENT_TEXT . "\\n";
  // reply-to = email del cliente, così si risponde direttamente a lui
  $inviato = nc_send_mail($DEST, $oggetto, $corpo, [], $email);
  if (!$inviato) $errore = 'Invio non riuscito. Scrivi pure a ' . $DEST;
}
$h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contatti — ${SITE.name}</title>
<link href="${FONTS_HREF}" rel="stylesheet">
<style>body{margin:0;background:${PAGE_BG};font-family:${BODY_FONT};color:${BODY_COLOR}}</style></head>
<body>
<div style="max-width:640px;margin:0 auto;padding:100px 24px;text-align:center">
<?php if ($inviato): ?>
  <div style="width:64px;height:64px;border-radius:50%;background:${ACCENT};color:#fff;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 24px">&#10003;</div>
  <h1 style="font-family:${HEAD_FONT};font-size:28px;margin:0 0 12px">Richiesta inviata</h1>
  <p style="font-size:16px;color:oklch(52% 0.02 260)">Grazie <?= $h($nome) ?>! Ti risponderemo al pi&ugrave; presto.</p>
<?php else: ?>
  <h1 style="font-family:${HEAD_FONT};font-size:26px;margin:0 0 12px">Ops</h1>
  <p style="font-size:16px;color:oklch(52% 0.02 260)"><?= $h($errore) ?></p>
<?php endif; ?>
  <p style="margin-top:28px"><a href="contatti.html" style="color:${ACCENT};font-weight:600;text-decoration:none">&larr; Torna ai contatti</a></p>
</div>
</body></html>
<?php
// Integrazione VelociTracker: dopo aver risposto all'utente, crea/aggiorna l'opportunità nel CRM (non blocca il form).
if (!empty($lead_ok)) {
  if (function_exists('fastcgi_finish_request')) { @ob_end_flush(); @flush(); @fastcgi_finish_request(); }
  require_once __DIR__ . '/inc/velocitracker.php';
  vt_push('contatti', ['email' => $email, 'nome' => $nome, 'tipo' => $tipo, 'messaggio' => $messaggio]);
}
`;
fs.writeFileSync(path.join(OUT, 'contatti.php'), contattiPhp, 'utf8');
console.log('  ✓ generato contatti.php (handler email)');

console.log('Fatto. Output in dist/');
