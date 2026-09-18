// Pagine di Storia — porta il repertorio mediale dal prototipo (const MOMENTI
// in Media.dc.html) a dati/media.json, applicando i rimandi alle schede
// decisi a mano in dati/collegamenti-media.json. tools/importa_media.php lo
// versa nel database.
//
//   node tools/estrai_media.js
'use strict';
const fs = require('fs');
const path = require('path');
const RADICE = path.resolve(__dirname, '..');

const html = fs.readFileSync(path.join(RADICE, 'src', 'Media.dc.html'), 'utf8');
const js = (html.match(/<script[^>]*data-dc-script[^>]*>([\s\S]*?)<\/script>/) || [])[1];
if (!js) { console.error('✗ Media.dc.html: nessuno script dati'); process.exit(1); }
const { MOMENTI } = new Function(js.slice(0, js.indexOf('class Component')) + ';return { MOMENTI };')();

const decisioni = JSON.parse(fs.readFileSync(path.join(RADICE, 'dati', 'collegamenti-media.json'), 'utf8')).voci;
const perN = new Map(decisioni.map(d => [d.n, d]));

// «In evidenza»: tre voci scritte a mano nel markup, con un testo proprio.
// Si riconoscono per titolo e si segnano nel repertorio, invece di tenerle
// come markup fisso con rimandi sbagliati (E31, che è Seveso, per Moro).
const piatto = html.replace(/\s+/g, ' ');
const evid = [...piatto.matchAll(/<h3[^>]*>([^<]+)<\/h3> <p[^>]*>([^<]+)<\/p>/g)]
  .map(m => ({ titolo: m[1].trim(), testo: m[2].trim() }))
  .filter(e => e.titolo && !e.titolo.includes('{{'));
const norm = s => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();

const anno = t => { const m = String(t).match(/\b(19|20)\d\d\b/); return m ? Number(m[0]) : null; };
const voci = MOMENTI.map((m, i) => {
  const d = perN.get(i);
  if (!d || d.titolo !== m.titolo) throw new Error(`decisione mancante o disallineata per il momento ${i} «${m.titolo}»`);
  const e = evid.find(x => norm(m.titolo).startsWith(norm(x.titolo)));
  return {
    id: 'MD' + String(i + 1).padStart(3, '0'), ordine: i,
    data_testo: m.data, anno: anno(m.data), mezzo: m.mezzo, categoria: m.categoria,
    titolo: m.titolo, programma: m.programma, perche: m.perche, documento: m.documento,
    stato: m.stato, scheda_id: d.scheda, scheda_prototipo: m.scheda,
    evidenza: e ? 1 : 0, evidenza_testo: e ? e.testo : null,
  };
});

fs.writeFileSync(path.join(RADICE, 'dati', 'media.json'), JSON.stringify({ generato: new Date().toISOString().slice(0, 10), voci }, null, 1));
console.log(`momenti ${voci.length} · con scheda ${voci.filter(v => v.scheda_id).length} · in evidenza ${voci.filter(v => v.evidenza).length} (${voci.filter(v => v.evidenza).map(v => v.titolo).join(' / ')})`);
const mezzi = {}; voci.forEach(v => mezzi[v.mezzo] = (mezzi[v.mezzo] || 0) + 1);
console.log('mezzi:', JSON.stringify(mezzi));
