// Pagine di Storia — ripulisce i file del Design PRIMA del build.
//
//   src/*.dc.html  →  src-clean/*.dc.html  →  node build/build.js
//
// Perché prima e non dopo: il build produce due cose, la pagina (dist/) e il
// modello che il pannello usa per ripubblicarla quando si cambia un testo
// (inc/tpl/). Correggere solo la pagina lasciava il modello com'era: una
// modifica dal pannello avrebbe riportato online i mockup del Design e i link
// al prototipo. Correggendo il sorgente, pagina e modello nascono puliti.
//
// Che cosa fa, e solo questo:
//   1. toglie le sezioni «Mockup ·», materiale di consegna con dati finti;
//   2. nelle pagine Nessi e Media sostituisce gli elenchi scritti a mano con un punto
//      d'aggancio vuoto (#pds-nessi, data-vb-skip): l'elenco lo scrive il
//      database (inc/pds_nessi.php), e il pannello non deve mostrare 269 campi
//      di un elenco che non esiste più.
// I sorgenti in src/ restano quelli consegnati: si possono sempre confrontare.
'use strict';
const fs = require('fs');
const path = require('path');

const RADICE = path.resolve(__dirname, '..');
const SRC = path.join(RADICE, 'src');
const OUT = path.join(RADICE, 'src-clean');

// Toglie l'elemento <tag> che si apre all'indice `inizio`, bilanciando i tag
// annidati dello stesso nome: una regex «pigra» si fermerebbe alla prima
// chiusura interna e taglierebbe a metà.
function fineBilanciata(html, inizio, tag) {
  const re = new RegExp('<' + tag + '\\b|</' + tag + '>', 'gi');
  re.lastIndex = inizio;
  let prof = 0, m;
  while ((m = re.exec(html))) {
    if (m[0][1] === '/') { if (--prof === 0) return re.lastIndex; } else prof++;
  }
  return -1;
}

function togliSezioni(html, prova) {
  let fatti = 0, da = 0;
  for (;;) {
    const i = html.indexOf('<section', da);
    if (i === -1) break;
    const f = fineBilanciata(html, i, 'section');
    if (f === -1) break;
    const blocco = html.slice(i, f);
    const r = prova(blocco);
    if (r !== null) { html = html.slice(0, i) + r + html.slice(f); fatti++; da = i + r.length; }
    else da = i + 8;
  }
  return { html, fatti };
}

fs.mkdirSync(OUT, { recursive: true });
let totMockup = 0;
for (const nome of fs.readdirSync(SRC).filter(f => f.endsWith('.dc.html'))) {
  let html = fs.readFileSync(path.join(SRC, nome), 'utf8');

  // Una colonna sola per tutto il sito: 1180 px, la stessa di intestazione e
  // piè di pagina (--pds-colonna in pds-generate.css). Il Design usava 820,
  // 1000, 1080, 1180, 1280 a seconda della pagina, e su uno schermo largo il
  // bordo del corpo non coincideva con quello del logo.
  html = html.replace(/(<main\b[^>]*style="[^"]*?)max-width:\s*\d+px/, '$1max-width:1180px');

  const m = togliSezioni(html, b => /<h2\b[^>]*>\s*Mockup\s*·/.test(b) ? '' : null);
  html = m.html; totMockup += m.fatti;

  if (nome === 'Nessi.dc.html') {
    const n = togliSezioni(html, b => />\s*Candidati\s*<\/h2>/.test(b)
      ? '<section id="pds-nessi" data-vb-skip style="margin-bottom:var(--space-8)"></section>' : null);
    html = n.html;
    if (!n.fatti) console.warn('  ! Nessi: la sezione «Candidati» non c\'è più nel Design: controllare');
  }

  if (nome === 'Media.dc.html') {
    // «In evidenza» (markup fisso, rimandi sbagliati) e il repertorio (array
    // scritto a mano) diventano un solo aggancio: li scrive inc/pds_media.php.
    let messo = false;
    const r = togliSezioni(html, b => {
      if (!/>\s*(In evidenza|Repertorio dei momenti mediali)\s*<\/h2>/.test(b)) return null;
      if (messo) return '';
      messo = true;
      return '<section id="pds-media" data-vb-skip></section>';
    });
    html = r.html;
    if (r.fatti !== 2) console.warn(`  ! Media: attese 2 sezioni dati, trovate ${r.fatti}: controllare il Design`);
  }

  if (nome === 'Home.dc.html') {
    // Le tre zone data-vb-skip della Home prendono un nome (data-pds): il PHP
    // le riscrive dal database (inc/pds_home.php) lasciando intatto il resto,
    // perché index.html è la pagina da cui il motore legge intestazione e piè.
    // Il contenuto del Design resta dentro come riserva finché non si pubblica.
    const nomi = ['linea-del-tempo', 'nessi-in-evidenza', 'taccuino'];
    let i = 0;
    html = html.replace(/<div data-vb-skip/g, (m) => nomi[i] ? `<div data-vb-skip data-pds="${nomi[i++]}"` : m);
    if (i !== 3) console.warn(`  ! Home: attese 3 zone dati, trovate ${i}`);
    // Gli interruttori Fascia/Verticale chiamavano il runtime del prototipo,
    // che non si pubblica: diventano attributi che legge assets/atlante.js.
    html = html.replace('onClick="{{ mostraFascia }}"', 'data-pds-vista="fascia"').replace('onClick="{{ mostraVerticale }}"', 'data-pds-vista="verticale"');
  }

  fs.writeFileSync(path.join(OUT, nome), html, 'utf8');
}
console.log(`  ✓ preprocess: sorgenti puliti in src-clean/ (${totMockup} mockup tolti)`);
