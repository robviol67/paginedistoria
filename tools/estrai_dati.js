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

Object.entries(T.stati_scheda || {}).forEach(([cod, descr], i) => tass.push({
  tipo: 'stato_scheda', codice: cod, etichetta: cod.replace(/_/g, ' '), descrizione: descr, ordine: i,
}));
// I ruoli hanno due facce: la definizione di metodo (tassonomie.ruoli_fonte) e
// il nome con cui la scheda raggruppa le fonti (RUOLI: «Da dove cominciare»…).
const ruoliPagina = {};
(D.RUOLI || []).forEach((r, i) => { ruoliPagina[r.ruolo] = { nome: r.nome, nota: r.nota || null, ordine: i }; });
Object.entries(T.ruoli_fonte || {}).forEach(([cod, descr], i) => tass.push({
  tipo: 'ruolo_fonte', codice: cod,
  etichetta: (ruoliPagina[cod] && ruoliPagina[cod].nome) || cod,
  descrizione: descr,
  ordine: ruoliPagina[cod] ? ruoliPagina[cod].ordine : 10 + i,
  dati: ruoliPagina[cod] && ruoliPagina[cod].nota ? { nota: ruoliPagina[cod].nota } : null,
}));
Object.entries(D.ACCESSO_AVVISO || {}).forEach(([cod, avviso], i) => tass.push({
  tipo: 'avviso_accesso', codice: cod, etichetta: avviso, ordine: i,
}));
Object.entries(T.mappa_temi_v1_v2 || {}).forEach(([v1, v2], i) => tass.push({
  tipo: 'tema_v1', codice: v1, etichetta: Array.isArray(v2) ? v2.join(' | ') : String(v2), ordine: i,
}));
(Array.isArray(T.etichette) ? T.etichette : []).forEach((e, i) => tass.push({
  tipo: 'etichetta', codice: e, etichetta: e, ordine: i,
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
    stato: (c.verifica && c.verifica.stato) || r.stato || 'da_verificare',
    verifica_data: (c.verifica && c.verifica.data) || null,
    verifica_note: (c.verifica && c.verifica.note) || null,
    verifica_requisiti: (c.verifica && c.verifica.requisiti) || null,
    rilevanza_politica: c.rilevanza_politica || null,
    mondo_nel_mondo: (c.accade_nel_mondo && c.accade_nel_mondo.nel_mondo) || null,
    mondo_risposta: (c.accade_nel_mondo && c.accade_nel_mondo.risposta_istituzioni_italiane) || null,
    mondo_ricadute: (c.accade_nel_mondo && c.accade_nel_mondo.ricadute_politica_interna) || null,
    mondo_cosa_cambia: (c.accade_nel_mondo && c.accade_nel_mondo.cosa_cambia_per_italia) || null,
    collegamenti: (c.collegamenti || []).map((k, j) => ({ verso_id: k.id, relazione: k.relazione || 'collegata', ordine: j })),
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
    verifica_data: null, verifica_note: null, verifica_requisiti: null, rilevanza_politica: null,
    mondo_nel_mondo: null, mondo_risposta: null, mondo_ricadute: null, mondo_cosa_cambia: null,
    collegamenti: [],
    ordine: schede.length, fonti: [],
  });
  nessi++;
}


// ── il corpo dei Nessi ──────────────────────────────────────────────────────
// Nei dati del Design i Nessi arrivano senza corpo. Ma il corpo esiste: sta
// scritto a mano nella pagina Nessi (const NESSI, dieci candidati N03–N12) e,
// per N01, nell'esempio di impaginazione di «Scheda nesso.dc.html». Qui lo si
// porta nelle schede, dichiarando da dove viene: nesso_provenienza.
const SRC = path.join(RADICE, 'src');
function corpiNessi() {
  const out = {};
  const pagina = fs.readFileSync(path.join(SRC, 'Nessi.dc.html'), 'utf8');
  const js = (pagina.match(/<script[^>]*data-dc-script[^>]*>([\s\S]*?)<\/script>/) || [])[1] || '';
  const i = js.indexOf('const NESSI');
  if (i !== -1) {
    const NESSI = new Function(js.slice(i, js.indexOf('class Component')) + ';return NESSI;')();
    for (const n of NESSI) out[n.id] = {
      nesso_arco: n.arco || null,
      nesso_a_data: n.aData || null, nesso_a_testo: n.aTesto || null,
      nesso_b_data: n.bData || null, nesso_b_testo: n.bTesto || null,
      nesso_test: n.test || null, nesso_meccanismo: n.meccanismo || null,
      nesso_favore: n.favore || null, nesso_contro: n.contro || null,
      nesso_rischio: n.rischio || null, nesso_ricadute: n.ricadute || null,
      nesso_fonti_da_acquisire: n.fonti || null,
      nesso_provenienza: 'prototipi/Nessi.dc.html',
      // Il verdetto della pagina Nessi porta il numero sulla scala («03 ·
      // Antecedente»): il database tiene il codice della tassonomia.
      _verdetto: String(n.verdetto || '').replace(/^\d+\s*·\s*/, '').toLowerCase() || null,
    };
  }

  // N01: il blocco d'esempio della Scheda nesso è scritto su N01 («Lo
  // yuppismo deriva dal berlusconismo?»). Le prove sono elenchi con la sigla
  // della fonte fra quadre: si tengono come testo, sigla compresa.
  const sn = fs.readFileSync(path.join(SRC, 'Scheda nesso.dc.html'), 'utf8').replace(/\s+/g, ' ');
  const blocco = (sn.match(/specNessoProprio \}\}">([\s\S]*?)<\/sc-if> <sc-if value="\{\{ specNessoAssente/) || [])[1];
  if (blocco) {
    const testo = (h) => String(h || '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
    const dopo = (titolo) => { const m = new RegExp('>' + titolo + '</h2>\\s*<p[^>]*>([\\s\\S]*?)</p>').exec(blocco); return m ? testo(m[1]) : null; };
    const elenco = (titolo) => {
      const m = new RegExp('>' + titolo + '</p>\\s*<ul[^>]*>([\\s\\S]*?)</ul>').exec(blocco);
      return m ? [...m[1].matchAll(/<li[^>]*>([\s\S]*?)<\/li>/g)].map(x => testo(x[1])).join(' · ') : null;
    };
    const verdettoNota = (blocco.match(/>Verdetto<\/h2>[\s\S]*?<\/div>\s*<p[^>]*>([\s\S]*?)<\/p>/) || [])[1];
    const ricadute = (blocco.match(/>Ricadute politiche<\/p>\s*<p[^>]*>([\s\S]*?)<\/p>/) || [])[1];
    out.N01 = Object.assign(out.N01 || {}, {
      nesso_meccanismo: dopo('Meccanismo ipotizzato'),
      nesso_favore: elenco('Prove a favore'), nesso_contro: elenco('Prove contro'),
      nesso_ricadute: ricadute ? testo(ricadute) : null,
      _verdetto_nota: verdettoNota ? testo(verdettoNota) : null,
      nesso_provenienza: 'prototipi/Scheda nesso.dc.html (esempio d’impaginazione, scritto su N01)',
    });
  }
  return out;
}
const CORPI_NESSI = corpiNessi();

// ── fonti ───────────────────────────────────────────────────────────────────
const fonti = (D.fonti || []).map(f => ({
  id: f.id, titolo: f.titolo, autore_ente: f.autore_ente || null, natura: f.natura || null,
  categoria: f.categoria || null, ambito: f.ambito || null, paese: f.paese || null,
  lingua: f.lingua || null, url: f.url || null, accesso: f.accesso || null,
  accesso_nota: f.accesso_nota || null, limiti: f.limiti || null,
  come_usarla: f.come_usarla || null,
  // La copertura è un oggetto {inizio, fine}: si spezza in due numeri e si
  // tiene anche la forma da leggere. Passata tale e quale, in una colonna di
  // testo diventava la parola «Array» su tutte le 157 fonti.
  copertura_inizio: f.copertura && f.copertura.inizio != null ? Number(f.copertura.inizio) : null,
  copertura_fine: f.copertura && f.copertura.fine != null ? Number(f.copertura.fine) : null,
  copertura: f.copertura && f.copertura.inizio != null ? `${f.copertura.inizio}–${f.copertura.fine != null ? f.copertura.fine : 'oggi'}` : null,
  // riscontrato è un sì/no (o assente): in colonna 1, 0 o vuoto, non true/false.
  esito: f.esito || null, riscontrato: f.riscontrato === true ? '1' : (f.riscontrato === false ? '0' : null),
  verifica_data: f.verifica_data || null,
  editore: f.editore || null, anno: f.anno != null ? String(f.anno) : null,
  edizione: f.edizione || null, isbn: f.isbn || null,
}));

// ── documenti: la raccolta AT_LOC, l'atto preciso dentro una fonte ───────────
const documenti = (window.AT_LOC || []).map((d, i) => ({
  scheda_id: d.scheda_id, fonte_id: d.fonte_id, descrizione: d.descrizione || null,
  citazione: d.citazione || null, tipo_documento: d.tipo_documento || null,
  data_documento: d.data_documento || null, url: d.url || null,
  verificata_il: d.verificata_il || null, ordine: i,
}));

// ── snapshot Wayback ────────────────────────────────────────────────────────
// Non c'è una mappa url→snapshot: il Design tiene UNA data e costruisce
// l'indirizzo al volo (wb(u) = web.archive.org/web/<data>/<url>). Quindi è un
// dato solo, e sta nei meta: una tabella per un valore sarebbe debito.
const waybackData = typeof D.WB_DATA === 'string' ? D.WB_DATA : null;

// Guardia: un oggetto in un campo semplice finisce nel database come «Array»
// senza un errore. Meglio fermarsi qui che scoprirlo sulle pagine.
for (const [nome, righe, ammessi] of [['fonti', fonti, []], ['schede', schede, ['periodi', 'temi', 'etichette', 'fonti', 'collegamenti', 'verifica_requisiti']]]) {
  for (const r of righe) for (const [k, v] of Object.entries(r)) {
    if (v && typeof v === 'object' && !ammessi.includes(k)) {
      console.error(`✗ ${nome}.${k} di ${r.id} è un oggetto: va spezzato in campi semplici prima dell'importazione`);
      process.exit(1);
    }
  }
}

const CAMPI_NESSO = ['nesso_arco', 'nesso_a_data', 'nesso_a_testo', 'nesso_b_data', 'nesso_b_testo', 'nesso_test',
  'nesso_meccanismo', 'nesso_favore', 'nesso_contro', 'nesso_rischio', 'nesso_ricadute', 'nesso_fonti_da_acquisire', 'nesso_provenienza'];
let corpiApplicati = 0; const verdettiDiversi = [];
for (const sc of schede) {
  for (const c of CAMPI_NESSO) if (!(c in sc)) sc[c] = null;
  const corpo = CORPI_NESSI[sc.id];
  if (!corpo) continue;
  for (const c of CAMPI_NESSO) if (corpo[c] != null) sc[c] = corpo[c];
  if (corpo._verdetto_nota && !sc.verdetto_nota) sc.verdetto_nota = corpo._verdetto_nota;
  // Due fonti per lo stesso verdetto: se non concordano lo si dice, non si sceglie.
  if (corpo._verdetto && sc.verdetto && corpo._verdetto !== sc.verdetto) verdettiDiversi.push(`${sc.id}: indice «${sc.verdetto}», pagina Nessi «${corpo._verdetto}»`);
  corpiApplicati++;
}

const fuori = new Set(fonti.map(f => f.id));
const orfane = [];
for (const s of schede) for (const f of s.fonti) if (!fuori.has(f.fonte_id)) orfane.push(s.id + '→' + f.fonte_id);

const uscita = {
  generato: new Date().toISOString().slice(0, 10),
  edizione: (D.meta && D.meta.dati_versione) || 'sconosciuta',
  wayback_data: waybackData,
  meta: D.meta || {},
  tassonomie: tass, schede, fonti, documenti,
};

fs.mkdirSync(path.dirname(USCITA), { recursive: true });
fs.writeFileSync(USCITA, JSON.stringify(uscita, null, 1), 'utf8');

const conLoc = schede.reduce((n, s) => n + s.fonti.length, 0);
console.log(`edizione dati ${uscita.edizione}`);
console.log(`  schede          ${schede.length}  (di cui ${nessi} Nessi, che arrivano dall'indice)`);
console.log(`  fonti           ${fonti.length}`);
console.log(`  localizzatori   ${conLoc}`);
console.log(`  documenti       ${documenti.length}`);
console.log(`  corpi dei Nessi ${corpiApplicati} (dalla pagina Nessi e dalla Scheda nesso)`);
if (verdettiDiversi.length) console.log(`  ⚠ verdetti che non concordano: ${verdettiDiversi.join('; ')}`);
console.log(`  collegamenti    ${schede.reduce((n, s) => n + s.collegamenti.length, 0)}`);
console.log(`  mondo           ${schede.filter(s => s.mondo_nel_mondo).length} schede con le quattro sezioni`);
console.log(`  tassonomie      ${tass.length}`);
console.log(`  wayback         istantanea del ${waybackData || '—'}`);
if (orfane.length) console.log(`  ⚠ citazioni a fonti inesistenti: ${orfane.length} (${orfane.slice(0, 3).join(', ')}…)`);
console.log(`\nScritto ${path.relative(RADICE, USCITA)} (${(fs.statSync(USCITA).size / 1024).toFixed(0)} KB)`);
