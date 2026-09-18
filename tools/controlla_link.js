// Pagine di Storia — giro completo del sito pubblicato: parte dalla home,
// segue ogni collegamento interno a una pagina .html e dice quali non
// rispondono. È la verifica 12 del §7.1 dell'handoff («zero 404»), e va
// rifatta dopo ogni pubblicazione: i link rotti non si vedono a occhio.
//
//   node tools/controlla_link.js [https://www.paginedistoria.it]
'use strict';
const BASE = (process.argv[2] || 'https://www.paginedistoria.it').replace(/\/$/, '');
const visti = new Set(), rotti = new Map(), coda = ['/'];
const PARALLELI = 4;   // gentile con l'hosting: non è l'FTP, ma resta condiviso

function interni(html, da) {
  const out = [];
  for (const m of html.matchAll(/href="([^"#?]+\.html)(?:[?#][^"]*)?"/g)) {
    let u = m[1];
    if (/^https?:/.test(u)) { if (!u.startsWith(BASE)) continue; u = u.slice(BASE.length); }
    if (!u.startsWith('/')) u = '/' + u;          // le pagine usano <base> alla radice
    out.push(u);
  }
  return out;
}

async function visita(p) {
  const r = await fetch(BASE + p + (p.includes('?') ? '&' : '?') + 'v=' + Date.now()).catch(() => null);
  if (!r || !r.ok) return { stato: r ? r.status : 'rete', html: '' };
  return { stato: 200, html: await r.text() };
}

(async () => {
  const provenienza = new Map();
  while (coda.length) {
    const lotto = coda.splice(0, PARALLELI).filter(p => !visti.has(p));
    lotto.forEach(p => visti.add(p));
    const esiti = await Promise.all(lotto.map(visita));
    esiti.forEach((e, i) => {
      const p = lotto[i];
      if (e.stato !== 200) { rotti.set(p, { stato: e.stato, da: provenienza.get(p) }); return; }
      for (const l of interni(e.html, p)) {
        if (!visti.has(l) && !coda.includes(l)) { coda.push(l); if (!provenienza.has(l)) provenienza.set(l, p); }
      }
    });
  }
  console.log(`pagine visitate: ${visti.size}`);
  if (!rotti.size) { console.log('link rotti: 0'); return; }
  console.log(`link rotti: ${rotti.size}`);
  for (const [p, r] of rotti) console.log(`  ${r.stato}  ${p}   ← da ${r.da || '?'}`);
  process.exitCode = 1;
})();
