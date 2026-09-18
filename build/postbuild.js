// Pagine di Storia — riscrive i collegamenti dei prototipi sugli indirizzi veri,
// e toglie quelli che porterebbero a una scheda inesistente.
//
// Il problema, in una riga: nelle pagine costruite restano 193 link del tipo
// «Scheda.dc.html?tipo=evento&titolo=…&id=M18», che sono i nomi dei file di
// prototipazione del Design. Il motore riscrive solo i link elencati in
// LINKMAP (pagina → pagina); questi invece sono uno per record, e l'indirizzo
// di un record lo sa il database.
//
// La seconda metà del lavoro conta quanto la prima: i prototipi citano schede
// che nell'edizione v1.15 NON esistono — sport (S##), catastrofi (C##), alcuni
// eventi e fatti del mondo. Lì non si inventa un bersaglio: si toglie il link e
// resta il testo, e il buco finisce in dati/schede-mancanti.json, che è
// l'elenco di ciò che manca da scrivere.
//
//   node build/postbuild.js
'use strict';
const fs = require('fs');
const path = require('path');

const RADICE = path.resolve(__dirname, '..');
const DIST = path.join(RADICE, 'dist');
const MAPPA = path.join(RADICE, 'dati', 'mappa.json');
const INDICE_TACCUINO = path.join(RADICE, 'dati', 'indice-taccuino.html');
const MANCANTI = path.join(RADICE, 'dati', 'schede-mancanti.json');

// I file su cui si lavora: le pagine (dist/*.html), i modelli con cui il
// pannello le ripubblica (dist/inc/tpl/*.html) e quelli da cui il PHP compone
// pagine a partire dal database (dist/modelli/*.html). Correggere solo le
// pagine lasciava i modelli sbagliati, e una modifica dal pannello avrebbe
// riportato indietro tutto.
function fileHtml() {
  const out = [];
  for (const d of ['', 'inc/tpl', 'inc/tpl/modelli', 'modelli']) {
    const dir = path.join(DIST, d);
    if (!fs.existsSync(dir)) continue;
    for (const f of fs.readdirSync(dir)) if (f.endsWith('.html')) out.push({ nome: f, file: path.join(dir, f), cartella: d });
  }
  return out;
}

const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
  .replace(/[^a-z0-9]+/g, ' ').trim();

// ── la mappa degli indirizzi ────────────────────────────────────────────────
// Preferita quella del server (il database è la fonte). Se non c'è — FTP giù,
// chiave non configurata — si ricostruisce in locale: id e slug bastano,
// perché l'indirizzo di una scheda è deterministico.
let M;
if (fs.existsSync(MAPPA)) {
  M = JSON.parse(fs.readFileSync(MAPPA, 'utf8'));
} else {
  const atl = path.join(RADICE, 'dati', 'atlante.json');
  if (!fs.existsSync(atl)) {
    console.log('  ! né dati/mappa.json né dati/atlante.json: i link ai record restano quelli del prototipo.');
    process.exit(0);
  }
  const A = JSON.parse(fs.readFileSync(atl, 'utf8'));
  M = { schede: {}, titoli: {}, fonti: {}, post: {} };
  for (const s of A.schede) {
    const url = `scheda-${String(s.id).toLowerCase()}-${s.slug}.html`;
    M.schede[s.id] = url;
    M.titoli[s.titolo] = url;
  }
  for (const f of A.fonti) M.fonti[f.id] = `fonte-${String(f.id).toLowerCase()}.html`;
  console.log('  · mappa ricostruita in locale da dati/atlante.json');
}

const perTitolo = new Map();
for (const [t, u] of Object.entries(M.titoli || {})) perTitolo.set(norm(t), u);

// ── i post del Taccuino ─────────────────────────────────────────────────────
// Il loro indirizzo lo decide il motore alla pubblicazione (blog/NNN-slug), e
// il modo più solido di saperlo è leggere l'indice pubblicato: risponde in
// HTTPS anche quando l'FTP è fermo. Lo scarica deploy/build-sito.sh.
const postPerTitolo = new Map();
for (const [t, u] of Object.entries(M.post || {})) postPerTitolo.set(norm(t), u);
if (!postPerTitolo.size && fs.existsSync(INDICE_TACCUINO)) {
  const idx = fs.readFileSync(INDICE_TACCUINO, 'utf8');
  for (const m of idx.matchAll(/<a href="(blog\/[^"]+\.html)"[^>]*>([\s\S]*?)<\/a>/g)) {
    const testo = m[2].replace(/<[^>]+>/g, '').trim();
    if (testo) postPerTitolo.set(norm(testo), m[1]);
  }
  if (postPerTitolo.size) console.log(`  · ${postPerTitolo.size} post letti dall'indice pubblicato`);
}

const perFilePost = new Map();
try {
  const t = JSON.parse(fs.readFileSync(path.join(RADICE, 'dati', 'taccuino.json'), 'utf8'));
  for (const p of t.post || []) {
    const url = postPerTitolo.get(norm(p.titolo));
    if (url && p.file) perFilePost.set(p.file, url);
  }
} catch (e) { /* senza taccuino.json i link ai post restano com'erano */ }

// ── risoluzione di un singolo collegamento ─────────────────────────────────
const conti = { schede: 0, fonti: 0, post: 0, categorie: 0, sciolti: 0, intatti: 0 };
const mancanti = new Map();   // id → titolo citato: è l'elenco da scrivere
const intatti = new Map();

function indirizzoPer(href, testo) {
  const grezzo = decodeURIComponent(href.replace(/&amp;/g, '&'));
  const file = grezzo.split('?')[0];
  const par = new URLSearchParams(grezzo.includes('?') ? grezzo.slice(grezzo.indexOf('?') + 1) : '');

  if (/^Scheda/i.test(file)) {
    const id = par.get('id');
    if (id && M.schede[id]) { conti.schede++; return M.schede[id]; }

    const titolo = par.get('titolo') || '';
    const conId = /^([A-Z]{1,2}\d{2,3})\s*·/.exec(titolo);
    if (conId && M.schede[conId[1]]) { conti.schede++; return M.schede[conId[1]]; }
    const perT = perTitolo.get(norm(titolo.replace(/^[A-Z]{1,2}\d{2,3}\s*·\s*/, '')));
    if (perT) { conti.schede++; return perT; }

    if (!id && !titolo) { conti.schede++; return 'atlante.html'; }

    // Citata ma inesistente: si scioglie il link e si registra la mancanza.
    mancanti.set(id || norm(titolo), {
      id: id || null,
      titolo: (titolo.replace(/^[A-Z]{1,2}\d{2,3}\s*·\s*/, '') || testo || '').trim(),
    });
    return 'SCIOGLI';
  }

  if (/^Fonte/i.test(file)) {
    const id = par.get('id');
    if (id && M.fonti[id]) { conti.fonti++; return M.fonti[id]; }
    if (!id) { conti.fonti++; return 'fonti.html'; }
    return 'SCIOGLI';
  }

  if (/^Taccuino post/i.test(file)) {
    const u = perFilePost.get(file);
    if (u) { conti.post++; return u; }
    return null;
  }

  // Le pagine di categoria non esistono ancora: l'indice del Taccuino è il
  // posto più vicino e vero, invece di un 404.
  if (/^Taccuino categoria/i.test(file)) { conti.categorie++; return 'blog.html'; }

  return null;
}

// ── applicazione ────────────────────────────────────────────────────────────
let toccati = 0;
for (const { file } of fileHtml()) {
  const prima = fs.readFileSync(file, 'utf8');

  // Si lavora sull'ANCORA intera, non sul solo href: per sciogliere un link
  // servono anche il testo e il tag di chiusura.
  const dopo = prima.replace(/<a\b([^>]*?)href="([^"]*\.dc\.html[^"]*)"([^>]*)>([\s\S]*?)<\/a>/g,
    (tutto, prA, href, dopoA, testo) => {
      const esito = indirizzoPer(href, testo.replace(/<[^>]+>/g, '').trim());
      if (esito === null) {
        conti.intatti++;
        intatti.set(decodeURIComponent(href).split('?')[0], (intatti.get(decodeURIComponent(href).split('?')[0]) || 0) + 1);
        return tutto;
      }
      if (esito === 'SCIOGLI') {
        conti.sciolti++;
        // Resta un elemento, non un <a>: il testo si vede tutto, ma non promette
        // una pagina che non c'è. L'attributo dice perché, a chi guarda il sorgente.
        return `<span data-scheda-assente="1"${prA}${dopoA}>${testo}</span>`;
      }
      return `<a${prA}href="${esito}"${dopoA}>${testo}</a>`;
    });

  if (dopo !== prima) { fs.writeFileSync(file, dopo, 'utf8'); toccati++; }
}

const riscritti = conti.schede + conti.fonti + conti.post + conti.categorie;
console.log(`  ✓ collegamenti riscritti: ${riscritti} in ${toccati} pagine`);
console.log(`    schede ${conti.schede} · fonti ${conti.fonti} · post ${conti.post} · categorie→indice ${conti.categorie}`);
if (conti.sciolti) console.log(`  · link sciolti (scheda non in archivio): ${conti.sciolti}, su ${mancanti.size} schede diverse`);
if (conti.intatti) {
  console.log(`  ! lasciati come sono: ${conti.intatti}`);
  for (const [k, n] of intatti) console.log(`      ${n}× ${k}`);
}

if (mancanti.size) {
  const elenco = [...mancanti.values()].sort((a, b) => String(a.id).localeCompare(String(b.id)));
  fs.writeFileSync(MANCANTI, JSON.stringify({
    generato: new Date().toISOString().slice(0, 10),
    nota: 'Schede citate dalle pagine del Design ma assenti nell\'edizione dati v1.15. Il link è stato sciolto: il testo resta, la promessa no. Questo è l\'elenco di ciò che manca da scrivere.',
    schede: elenco,
  }, null, 1), 'utf8');
  console.log(`  · elenco delle schede mancanti in dati/schede-mancanti.json (${elenco.length})`);
}

// ── pulizia: ciò che è del prototipo non va in pubblico ─────────────────────
// 1 · Le sezioni «Mockup ·» sono materiale di consegna del Design (come si
//     vedono i filtri a 360 px, com'è la raccolta), con dati finti: «5 schede
//     · 9 fonti», «Mani Pulite». Erano pubblicate su Storia e Cronologia.
// 2 · I vecchi file dati (dati.js, dati-schede.js, fonti-reg.js, indice.json)
//     erano la sorgente del prototipo. La fonte ora è il database: tenerli
//     online vorrebbe dire servire dati che invecchiano senza che nessuno lo veda.
// 3 · Il modulo di ricerca della Home inviava a «Atlante.dc.html»: 404. LINKMAP
//     riscrive gli href, non gli action.
// 4 · I contatori della Home erano scritti a mano («170 schede»): §5.1 vuole
//     che vengano dai dati.
const PAGINE = { 'Home': 'index.html', 'Atlante': 'atlante.html', 'Cronologia': 'cronologia.html', 'Fonti': 'fonti.html',
  'Nessi': 'nessi.html', 'Media': 'media.html', 'Metodo': 'metodo.html', 'Segnala': 'segnala.html', 'Privacy': 'privacy.html', 'Taccuino': 'blog.html' };
const CON_APP = new Set(['atlante.html', 'cronologia.html', 'fonti.html', 'nessi.html', 'media.html']);

// I conteggi: dal server se la mappa li porta, altrimenti dai dati estratti.
let CONTI = M.conti || null;
if (!CONTI) {
  try {
    const A = JSON.parse(fs.readFileSync(path.join(RADICE, 'dati', 'atlante.json'), 'utf8'));
    CONTI = { schede: A.schede.length, fonti: A.fonti.length,
      periodi: A.tassonomie.filter(t => t.tipo === 'periodo').length, riferimenti: (A.documenti || []).length };
  } catch (e) { CONTI = null; }
}

let mockup = 0, script = 0, azioni = 0, contatori = 0;
for (const { nome: nomeFile, file, cartella } of fileHtml()) {
  // Nei modelli del pannello il nome è lo slug (home.html per index.html).
  const nome = cartella === 'inc/tpl' && nomeFile === 'home.html' ? 'index.html' : nomeFile;
  let h = fs.readFileSync(file, 'utf8');
  const prima = h;

  h = h.replace(/<section\b[^>]*>\s*<h2\b[^>]*>\s*Mockup\b[\s\S]*?<\/section>\s*(?=<\/main>|<section|<div)/g, () => { mockup++; return ''; });

  h = h.replace(/\s*<script src="assets\/(dati|dati-schede|fonti-reg)\.js[^"]*"><\/script>/g, () => { script++; return ''; });
  // La raccolta vive su ogni pagina: la barra compare dovunque ci sia
  // qualcosa messo da parte.
  if (!h.includes('assets/raccolta.js')) h = h.replace('</head>', '<script src="assets/raccolta.js" defer></script>\n</head>');
  // Gli stili della raccolta (e delle parti generate) stanno in
  // pds-generate.css: tutte regole con prefisso pds-, quindi innocue sulle
  // pagine del Design. Va dopo pds.css, che rimappa i token del kit.
  if (!h.includes('assets/pds-generate.css')) h = h.replace(/(<link rel="stylesheet" href="assets\/pds\.css[^"]*">)/, '$1\n<link rel="stylesheet" href="assets/pds-generate.css">');
  if (CON_APP.has(nome) && !h.includes('assets/atlante.js')) {
    h = h.replace('</head>', '<script src="assets/atlante.js" defer></script>\n</head>');
  }

  h = h.replace(/action="([^"]*?)\.dc\.html"/g, (tutto, pag) => {
    const dest = PAGINE[decodeURIComponent(pag)];
    if (!dest) return tutto;
    azioni++;
    return `action="${dest}"`;
  });

  if (nome === 'index.html' && CONTI) {
    for (const [etichetta, chiave] of [['Schede', 'schede'], ['Fonti', 'fonti'], ['Periodi', 'periodi'], ['Riferimenti', 'riferimenti']]) {
      const re = new RegExp('(<dt[^>]*>' + etichetta + '</dt><dd[^>]*>)\\d+(</dd>)');
      if (re.test(h)) { h = h.replace(re, '$1' + CONTI[chiave] + '$2'); contatori++; }
    }
  }

  if (h !== prima) fs.writeFileSync(file, h, 'utf8');
}
for (const vecchio of ['dati.js', 'dati-schede.js', 'dati-fonti.js', 'fonti-reg.js', 'indice.json']) {
  const f = path.join(DIST, 'assets', vecchio);
  if (fs.existsSync(f)) fs.unlinkSync(f);
}
console.log(`  ✓ tolti ${mockup} mockup del Design e ${script} script dei vecchi dati; ${azioni} moduli riparati; ${contatori} contatori dai dati`);

// ── impronte sui file statici ───────────────────────────────────────────────
// Stessa regola delle pagine generate (pds_asset in inc/pds_shell.php): ogni
// foglio di stile e script locale si chiama con l'impronta del contenuto.
// Cambia il file, cambia l'indirizzo, e nessun browser resta indietro.
const crypto = require('crypto');
const impronte = new Map();
function impronta(rel) {
  if (!impronte.has(rel)) {
    const f = path.join(DIST, rel);
    impronte.set(rel, fs.existsSync(f) ? crypto.createHash('md5').update(fs.readFileSync(f)).digest('hex').slice(0, 10) : null);
  }
  return impronte.get(rel);
}
let versionati = 0;
for (const { file } of fileHtml()) {
  const prima = fs.readFileSync(file, 'utf8');
  const dopo = prima.replace(/(href|src)="(assets\/[^"?#]+\.(?:css|js|svg))(?:\?v=[a-f0-9]+)?"/g, (tutto, attr, rel) => {
    const h = impronta(rel);
    if (!h) return tutto;
    versionati++;
    return `${attr}="${rel}?v=${h}"`;
  });
  if (dopo !== prima) fs.writeFileSync(file, dopo, 'utf8');
}
console.log(`  ✓ impronte su ${versionati} riferimenti a stili, script e icone`);
