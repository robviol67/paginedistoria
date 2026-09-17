// Pagine di Storia — porta i post del Taccuino dai prototipi del Design a un
// JSON, che tools/importa_taccuino.php versa nelle tabelle del blog.
//
// I sette prototipi sono fatti in due modi diversi, e lo scopriamo qui:
//   · sei hanno un `class Component` con renderVals(), e il testo sta nei dati;
//   · uno ("Taccuino post 2") non ha script: il testo è scritto nel markup.
// Trattarli con un metodo solo avrebbe voluto dire perderne uno per strada.
//
//   node tools/estrai_taccuino.js      → dati/taccuino.json
'use strict';
const fs = require('fs');
const path = require('path');

const RADICE = path.resolve(__dirname, '..');
const SRC = path.join(RADICE, 'src');
const USCITA = path.join(RADICE, 'dati', 'taccuino.json');

const esc = (s) => String(s == null ? '' : s)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// I mesi servono a dare una data vera a stringhe come «2 giugno» o «9 agosto 2026».
const MESI = { gennaio: 1, febbraio: 2, marzo: 3, aprile: 4, maggio: 5, giugno: 6,
               luglio: 7, agosto: 8, settembre: 9, ottobre: 10, novembre: 11, dicembre: 12 };
function dataISO(testo) {
  const m = /(\d{1,2})\s+([a-zà-ù]+)(?:\s+(\d{4}))?/i.exec(String(testo || ''));
  if (!m) return null;
  const mese = MESI[m[2].toLowerCase()];
  if (!mese) return null;
  // Senza anno è una ricorrenza («2 giugno»): l'anno di pubblicazione è quello
  // dell'edizione. Meglio una data plausibile e dichiarata che nessuna data.
  const anno = m[3] ? Number(m[3]) : 2026;
  return `${anno}-${String(mese).padStart(2, '0')}-${String(Number(m[1])).padStart(2, '0')}`;
}

// Gli href dei prototipi puntano alle schede con un parametro che contiene
// l'identificativo («Scheda.dc.html?tipo=evento&titolo=E04 · Referendum…»).
// Ma non sempre: in un caso su due il link è il solo «Scheda.dc.html» e l'id
// sta nell'etichetta. Si guardano entrambi, altrimenti metà dei collegamenti
// sparisce senza dire niente. L'indirizzo finale lo sa il database, e lo
// scrive l'importatore.
function idScheda(...pezzi) {
  for (const p of pezzi) {
    const m = /\b([A-Z]{1,2}\d{2,3})\b/.exec(decodeURIComponent(String(p || '')));
    if (m) return m[1];
  }
  return null;
}

function corpoDaValori(v) {
  const parti = [];
  const paragrafi = (lista) => (lista || []).forEach(p => {
    const t = (p && (p.testo || p.text)) || '';
    if (t.trim()) parti.push(`<p>${esc(t)}</p>`);
  });

  paragrafi(v.apertura);

  if (v.citazione) {
    const fonte = [esc(v.citazioneFonte || '')];
    if (v.citazioneUrl) fonte.push(`<a href="${esc(v.citazioneUrl)}" target="_blank" rel="noopener">${esc(v.citazioneDominio || v.citazioneUrl)}</a>`);
    parti.push(`<blockquote class="pds-citazione"><p>${esc(v.citazione)}</p><footer>${fonte.join(' ')}</footer></blockquote>`);
  }

  paragrafi(v.centro);
  if (v.sottotitolo) parti.push(`<h2>${esc(v.sottotitolo)}</h2>`);
  paragrafi(v.chiusura);

  if (v.nota) parti.push(`<aside class="pds-nota"><p class="pds-nota-titolo">Nota di metodo</p><p>${esc(v.nota)}</p></aside>`);

  const schede = (v.schede || []).map(s => ({
    id: idScheda(s.link || s.href, s.titolo, s.etichetta),
    etichetta: (s.titolo || s.etichetta || s.testo || '').replace(/^[A-Z]{1,2}\d{2,3}\s*·\s*/, '').trim(),
  })).filter(s => s.id);

  if (schede.length) {
    // Segnaposto, non indirizzi: l'importatore li sostituisce con l'indirizzo
    // vero della scheda, che dipende dallo slug nel database.
    const voci = schede.map(s => `<li>[[scheda:${s.id}|${esc(s.etichetta)}]]</li>`).join('');
    parti.push(`<aside class="pds-collegate"><p class="pds-nota-titolo">Schede collegate</p><ul>${voci}</ul></aside>`);
  }

  return { html: parti.join('\n'), schede };
}

// ── il post scritto nel markup: si prende il contenuto di <main> ────────────
function daMarkup(html) {
  const i = html.indexOf('<main');
  const j = html.lastIndexOf('</main>');
  if (i < 0 || j < 0) return null;
  let main = html.slice(html.indexOf('>', i) + 1, j);

  main = main.replace(/<nav[\s\S]*?<\/nav>/i, '');                       // il percorso lo rifà il motore
  const occhielloRiga = /<p class="num"[^>]*>([\s\S]*?)<\/p>/i.exec(main);
  const testataGrezza = occhielloRiga ? occhielloRiga[1].replace(/<[^>]+>/g, '').trim() : '';
  main = main.replace(/<p class="num"[^>]*>[\s\S]*?<\/p>/i, '');

  const h1 = /<h1[^>]*>([\s\S]*?)<\/h1>/i.exec(main);
  const titolo = h1 ? h1[1].replace(/<[^>]+>/g, '').trim() : null;
  main = main.replace(/<h1[^>]*>[\s\S]*?<\/h1>/i, '');

  const primoP = /<p[^>]*>([\s\S]*?)<\/p>/i.exec(main);
  const occhiello = primoP ? primoP[1].replace(/<[^>]+>/g, '').trim() : '';
  main = main.replace(/<p[^>]*>[\s\S]*?<\/p>/i, '');
  main = main.replace(/<p[^>]*>\s*Redazione dell[’']atlante\s*<\/p>/i, '');

  // «Categoria · data · lettura» sta tutto nella riga sopra il titolo.
  const pezzi = testataGrezza.split('·').map(s => s.trim());
  return {
    titolo,
    occhiello,
    categoria: pezzi[0] || 'Taccuino',
    data: pezzi[1] || '',
    lettura: pezzi[2] || '',
    corpo: main.trim(),
    schede: [],
  };
}

const post = [];
for (const file of fs.readdirSync(SRC).filter(f => /^Taccuino post/.test(f)).sort()) {
  const html = fs.readFileSync(path.join(SRC, file), 'utf8');
  const script = html.match(/<script[^>]*data-dc-script[^>]*>([\s\S]*?)<\/script>/);
  let p = null;

  if (script && /class\s+Component\b/.test(script[1])) {
    const DCLogic = class { constructor(pr) { this.props = pr || {}; } setState() {} };
    const v = new Function('DCLogic', 'props', 'window', 'document',
      script[1] + '\n;return new Component(props).renderVals();')(DCLogic, {}, {}, undefined);
    const corpo = corpoDaValori(v);
    p = { titolo: v.titolo, occhiello: v.occhiello, categoria: v.categoria,
          data: v.data, lettura: v.lettura, corpo: corpo.html, schede: corpo.schede };
  } else {
    p = daMarkup(html);
  }

  if (!p || !p.titolo) { console.error(`  ⚠ ${file}: non ne ricavo un post, lo salto`); continue; }
  p.file = file;
  p.data_iso = dataISO(p.data);
  post.push(p);
}

post.sort((a, b) => String(a.data_iso || '').localeCompare(String(b.data_iso || '')));

fs.mkdirSync(path.dirname(USCITA), { recursive: true });
fs.writeFileSync(USCITA, JSON.stringify({ generato: new Date().toISOString().slice(0, 10), post }, null, 1), 'utf8');

console.log(`post estratti: ${post.length}`);
for (const p of post)
  console.log(`  ${(p.data_iso || 'senza data').padEnd(11)} ${(p.categoria || '—').padEnd(16)} ${p.titolo.slice(0, 52)}${p.schede.length ? '  (' + p.schede.length + ' schede collegate)' : ''}`);
console.log(`\nScritto ${path.relative(RADICE, USCITA)}`);
