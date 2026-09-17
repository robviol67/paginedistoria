// Pagine di Storia — porta i dati dell'edizione v1.15 dai file del Design a un
// JSON normalizzato, che tools/importa_atlante.php versa nel database.
//
// Perché esiste: i dati del Design sono file JavaScript che assegnano globali
// (window.AT, window.AT_SCHEDE) e si montano fra loro a runtime. Leggerli da
// PHP significherebbe interpretare JavaScript; leggerli da Node è invece
// naturale: basta dargli un `window` finto e lasciarli montare.
//
// Questo script è un PONTE, non una fonte: gira una volta per la migrazione
// iniziale e poi ogni volta che il Design consegna un'edizione nuova. Dopo
// l'importazione la fonte unica è il database.
//
//   node tools/estrai_dati.js          → dati/atlante.json
'use strict';
const fs = require('fs');
const path = require('path');

const RADICE = path.resolve(__dirname, '..');
const ASSETS = path.join(RADICE, 'assets');
const USCITA = path.join(RADICE, 'dati', 'atlante.json');

// I file del Design si aspettano un browser: gliene diamo l'ombra.
global.window = {};
global.setTimeout = () => {};
global.setInterval = () => {};
global.clearInterval = () => {};

require(path.join(ASSETS, 'dati.js'));
require(path.join(ASSETS, 'dati-schede.js'));
if (typeof window.AT_MONTA_SCHEDE === 'function') window.AT_MONTA_SCHEDE(window.AT);

const D = window.AT;
// `D.schede` è un ELENCO, non una mappa per id: montarlo come mappa è il primo
// passo, altrimenti ogni corpo risulta mancante e le schede escono senza fonti.
const corpoDi = {};
for (const c of (D && Array.isArray(D.schede) ? D.schede : [])) if (c && c.id) corpoDi[c.id] = c;

if (!D || !D.indice) { console.error('✗ window.AT non si è montato: controlla assets/dati.js'); process.exit(1); }
if (!Object.keys(corpoDi).length) { console.error('✗ i corpi delle schede non si sono montati: controlla assets/dati-schede.js'); process.exit(1); }

const indiceEsempio = JSON.parse(fs.readFileSync(path.join(ASSETS, 'indice.json'), 'utf8'));

// ── tassonomie ───────────────────────────────────────────────────────────────
const tass = [];
const T = D.tassonomie || {};
(T.periodi || []).forEach((p, i) => tass.push({
  tipo: 'periodo', codice: p.id || p.codice, etichetta: p.titolo || p.etichetta || p.nome,
  descrizione: p.sintesi || p.descrizione || null,
  anno_inizio: p.inizio || p.anno_inizio || null, anno_fine: p.fine || p.anno_fine || null,
  ordine: i, dati: p,
}));
(T.temi || []).forEach((t, i) => tass.push({
  tipo: 'tema', codice: typeof t === 'string' ? t : (t.id || t.codice || t.titolo),
  etichetta: typeof t === 'string' ? t : (t.titolo || t.etichetta), ordine: i,
  dati: typeof t === 'string' ? null : t,
}));
(T.tipologie || []).forEach((t, i) => tass.push({
  tipo: 'tipologia', codice: typeof t === 'string' ? t : (t.id || t.codice || t.nome),
  etichetta: typeof t === 'string' ? t : (t.nome || t.etichetta || t.titolo), ordine: i,
  dati: typeof t === 'string' ? null : t,
}));
(T.verdetti_nesso || []).forEach((v, i) => tass.push({
  tipo: 'verdetto', codice: typeof v === 'string' ? v : (v.id || v.codice),
  etichetta: typeof v === 'string' ? v : (v.etichetta || v.titolo),
  descrizione: typeof v === 'string' ? null : (v.spiegazione || v.descrizione || null), ordine: i,
  dati: typeof v === 'string' ? null : v,
}));
(T.nature_fonte || []).forEach((n, i) => tass.push({
  tipo: 'natura_fonte', codice: typeof n === 'string' ? n : (n.id || n.codice),
  etichetta: typeof n === 'string' ? n : (n.etichetta || n.titolo), ordine: i,
}));
(T.accessi_fonte || []).forEach((a, i) => tass.push({
  tipo: 'accesso_fonte', codice: typeof a === 'string' ? a : (a.id || a.codice),
  etichetta: typeof a === 'string' ? a : (a.etichetta || a.titolo), ordine: i,
}));
(T.relazioni || []).forEach((r, i) => tass.push({
  tipo: 'relazione', codice: typeof r === 'string' ? r : (r.id || r.codice),
  etichetta: typeof r === 'string' ? r : (r.etichetta || r.titolo), ordine: i,
}));

// ── schede: l'indice dà i campi di ricerca, il corpo dà il testo ────────────
const schede = D.indice.map((r, i) => {
  const c = corpoDi[r.id] || {};
  return {
    id: r.id,
    slug: r.slug || c.slug,
    tipologia: r.tipologia,
    titolo: r.titolo || c.titolo,
    data_inizio: c.data_inizio || (r.inizio != null ? String(r.inizio) : null),
    data_fine: c.data_fine || (r.fine != null ? String(r.fine) : null),
    periodo_principale: c.periodo_principale || r.periodo || null,
    periodi: c.periodo_ids || r.periodi || [],
    temi: c.temi || r.temi || [],
    etichette: c.etichette || r.etichette || [],
    sintesi: c.sintesi || r.sintesi || null,
    perche_studiarla: c.perche_studiarla || null,
    cautela: c.cautela || null,
    verdetto: null,
    verdetto_nota: null,
    stato: r.stato || 'da_verificare',
    n_doc: r.n_doc || 0,
    ordine: i,
    fonti: (c.fonti || []).map((f, j) => ({
      fonte_id: f.fonte_id, ruolo: f.ruolo || null, localizzatore: f.localizzatore || null,
      tipo_documento: f.tipo_documento || null, data_documento: f.data_documento || null,
      url_specifico: f.url_specifico || null, nota: f.nota || null,
      verificato_il: f.verificato_il || null, ordine: j,
    })),
  };
});

// ── i Nessi stanno solo nell'indice d'esempio, con altri nomi di campo ──────
// Non hanno corpo: entrano come schede della sesta tipologia, da completare
// nel pannello. Meglio averli dentro incompleti e dichiarati che perderli.
const giaPresenti = new Set(schede.map(s => s.id));
let nessi = 0;
for (const r of (indiceEsempio.schede || [])) {
  if (giaPresenti.has(r.id)) continue;
  if (!/nesso/i.test(r.tipologia || '')) continue;
  schede.push({
    id: r.id, slug: r.slug, tipologia: r.tipologia, titolo: r.titolo,
    data_inizio: r.anno_inizio != null ? String(r.anno_inizio) : null,
    data_fine: r.anno_fine != null ? String(r.anno_fine) : null,
    periodo_principale: r.periodo_principale || (r.periodo_ids || [])[0] || null,
    periodi: r.periodo_ids || [], temi: r.temi || [], etichette: [],
    sintesi: r.sintesi_breve || r.sintesi || null,
    perche_studiarla: null, cautela: null,
    verdetto: r.verdetto || null, verdetto_nota: null,
    stato: r.stato || 'da_verificare', n_doc: 0,
    ordine: schede.length, fonti: [],
  });
  nessi++;
}

// ── fonti ───────────────────────────────────────────────────────────────────
const fonti = (D.fonti || []).map(f => ({
  id: f.id, titolo: f.titolo, autore_ente: f.autore_ente || null, natura: f.natura || null,
  categoria: f.categoria || null, ambito: f.ambito || null, paese: f.paese || null,
  lingua: f.lingua || null, url: f.url || null, accesso: f.accesso || null,
  accesso_nota: f.accesso_nota || null, limiti: f.limiti || null,
  come_usarla: f.come_usarla || null, copertura: f.copertura || null,
  esito: f.esito || null, riscontrato: f.riscontrato || null, verifica_data: f.verifica_data || null,
}));

// ── snapshot Wayback ────────────────────────────────────────────────────────
// Non c'è una mappa url→snapshot: il Design tiene UNA data e costruisce
// l'indirizzo al volo (wb(u) = web.archive.org/web/<data>/<url>). Quindi è un
// dato solo, e sta nei meta: una tabella per un valore sarebbe debito.
const waybackData = typeof D.WB_DATA === 'string' ? D.WB_DATA : null;

const fuori = new Set(fonti.map(f => f.id));
const orfane = [];
for (const s of schede) for (const f of s.fonti) if (!fuori.has(f.fonte_id)) orfane.push(s.id + '→' + f.fonte_id);

const uscita = {
  generato: new Date().toISOString().slice(0, 10),
  edizione: (D.meta && D.meta.dati_versione) || 'sconosciuta',
  wayback_data: waybackData,
  tassonomie: tass, schede, fonti,
};

fs.mkdirSync(path.dirname(USCITA), { recursive: true });
fs.writeFileSync(USCITA, JSON.stringify(uscita, null, 1), 'utf8');

const conLoc = schede.reduce((n, s) => n + s.fonti.length, 0);
console.log(`edizione dati ${uscita.edizione}`);
console.log(`  schede          ${schede.length}  (di cui ${nessi} Nessi senza corpo, dall'indice)`);
console.log(`  fonti           ${fonti.length}`);
console.log(`  localizzatori   ${conLoc}`);
console.log(`  tassonomie      ${tass.length}`);
console.log(`  wayback         istantanea del ${waybackData || '—'}`);
if (orfane.length) console.log(`  ⚠ citazioni a fonti inesistenti: ${orfane.length} (${orfane.slice(0, 3).join(', ')}…)`);
console.log(`\nScritto ${path.relative(RADICE, USCITA)} (${(fs.statSync(USCITA).size / 1024).toFixed(0)} KB)`);
