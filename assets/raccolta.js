/* Pagine di Storia — la raccolta.
 *
 * Il Design la descrive così: «una barra fissa in basso: conta gli elementi
 * messi da parte e li esporta in Markdown o negli appunti». Si mette da parte
 * una scheda o una fonte con un pulsante [data-raccogli]; la barra compare
 * appena c'è qualcosa, su ogni pagina del sito.
 *
 * Dove sta: nel browser di chi legge (localStorage). È una comodità personale,
 * non un dato del sito: nessuno la vede tranne chi la fa, e non passa dal
 * server. Se il browser non lascia salvare (navigazione privata, dati
 * bloccati) la raccolta vive finché resta aperta la pagina, e nient'altro si
 * rompe.
 */
(function () {
  'use strict';
  var CHIAVE = 'pds-raccolta-v1';
  var memoria = [];

  function leggi() {
    try { memoria = JSON.parse(localStorage.getItem(CHIAVE) || '[]') || []; }
    catch (e) { /* senza storage si lavora in memoria */ }
    if (!Array.isArray(memoria)) memoria = [];
    return memoria;
  }
  function scrivi(elenco) {
    memoria = elenco;
    try { localStorage.setItem(CHIAVE, JSON.stringify(elenco)); } catch (e) { /* idem */ }
    disegna();
  }
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  };
  var chiave = function (x) { return x.tipo + ':' + x.id; };
  var assoluto = function (u) { try { return new URL(u, document.baseURI).href; } catch (e) { return u; } };

  function conteggio(el) {
    var s = el.filter(function (x) { return x.tipo === 'scheda'; }).length;
    var f = el.length - s;
    var p = [];
    if (s) p.push(s + (s === 1 ? ' scheda' : ' schede'));
    if (f) p.push(f + (f === 1 ? ' fonte' : ' fonti'));
    return p.join(' · ') || 'vuota';
  }

  // Il Markdown è ciò che si incolla in un appunto o in una bibliografia:
  // titolo, indirizzo, e il giorno in cui si è consultato.
  function markdown(el) {
    var oggi = new Date().toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' });
    var righe = ['# Raccolta da Pagine di Storia', '', 'Consultata il ' + oggi + '.', ''];
    var gruppi = [['scheda', 'Schede'], ['fonte', 'Fonti']];
    gruppi.forEach(function (g) {
      var voci = el.filter(function (x) { return x.tipo === g[0]; });
      if (!voci.length) return;
      righe.push('## ' + g[1], '');
      voci.forEach(function (x) {
        righe.push('- [' + x.id + ' · ' + x.titolo + '](' + assoluto(x.url) + ')' + (x.nota ? ' — ' + x.nota : ''));
      });
      righe.push('');
    });
    return righe.join('\n');
  }

  function avvisa(bottone, testo) {
    var prima = bottone.textContent;
    bottone.textContent = testo;
    setTimeout(function () { bottone.textContent = prima; }, 2000);
  }

  // ── la barra ──────────────────────────────────────────────────────────────
  var barra = null, aperta = false;
  function disegna() {
    var el = memoria;
    // Pulsanti «Aggiungi»: dicono se l'elemento è già dentro.
    document.querySelectorAll('[data-raccogli]').forEach(function (b) {
      var dentro = el.some(function (x) { return chiave(x) === b.getAttribute('data-tipo') + ':' + b.getAttribute('data-id'); });
      b.setAttribute('aria-pressed', dentro ? 'true' : 'false');
      b.textContent = dentro ? 'Nella raccolta ✓' : 'Aggiungi alla raccolta';
    });
    document.querySelectorAll('[data-raccolta-conto]').forEach(function (n) {
      // «1 scheda messe da parte» sarebbe sbagliato: l'accordo va sul numero.
      n.textContent = !el.length ? 'Nessun elemento messo da parte.'
        : (el.length === 1 ? '1 elemento messo da parte' : el.length + ' elementi messi da parte') + ' (' + conteggio(el) + ').';
    });

    if (!el.length) { if (barra) { barra.remove(); barra = null; } document.body.classList.remove('pds-con-raccolta'); return; }
    if (!barra) {
      barra = document.createElement('aside');
      barra.className = 'pds-raccolta';
      barra.setAttribute('aria-label', 'Raccolta');
      barra.setAttribute('data-print-hide', '');
      document.body.appendChild(barra);
      document.body.classList.add('pds-con-raccolta');
    }
    barra.innerHTML =
      '<div class="pds-raccolta-testa">' +
        '<button type="button" class="pds-raccolta-titolo" data-r-apri aria-expanded="' + aperta + '">Raccolta</button>' +
        '<span class="num pds-raccolta-conto">' + esc(conteggio(el)) + '</span>' +
        '<div class="pds-raccolta-azioni">' +
          '<button class="btn btn-primary" type="button" data-r-md>Scarica in Markdown</button>' +
          '<button class="btn btn-secondary" type="button" data-r-copia>Copia negli appunti</button>' +
          '<button class="btn btn-ghost" type="button" data-r-svuota>Svuota</button>' +
        '</div>' +
      '</div>' +
      (aperta ? '<ul class="num pds-raccolta-elenco">' + el.map(function (x) {
        return '<li><span class="id">' + esc(x.id) + '</span><a href="' + esc(x.url) + '">' + esc(x.titolo) + '</a>' +
          '<button type="button" class="pds-raccolta-togli" data-r-togli="' + esc(chiave(x)) + '" aria-label="Togli ' + esc(x.titolo) + '">×</button></li>';
      }).join('') + '</ul>' : '');
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-raccogli]');
    if (b) {
      var voce = { tipo: b.getAttribute('data-tipo'), id: b.getAttribute('data-id'), titolo: b.getAttribute('data-titolo'),
                   url: b.getAttribute('data-url'), nota: b.getAttribute('data-nota') || '' };
      var el = leggi().slice(), i = el.findIndex(function (x) { return chiave(x) === chiave(voce); });
      if (i === -1) el.push(voce); else el.splice(i, 1);
      scrivi(el);
      return;
    }
    var t = e.target.closest('[data-r-apri],[data-r-md],[data-r-copia],[data-r-svuota],[data-r-togli]');
    if (!t) return;
    if (t.hasAttribute('data-r-apri')) { aperta = !aperta; disegna(); }
    else if (t.hasAttribute('data-r-togli')) { scrivi(leggi().filter(function (x) { return chiave(x) !== t.getAttribute('data-r-togli'); })); }
    else if (t.hasAttribute('data-r-svuota')) { if (confirm('Svuotare la raccolta?')) scrivi([]); }
    else if (t.hasAttribute('data-r-md')) {
      var blob = new Blob([markdown(memoria)], { type: 'text/markdown;charset=utf-8' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob); a.download = 'raccolta-pagine-di-storia.md';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    } else if (t.hasAttribute('data-r-copia')) {
      var testo = markdown(memoria);
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(testo).then(function () { avvisa(t, 'Copiata ✓'); }, function () { window.prompt('Raccolta:', testo); });
      } else window.prompt('Raccolta:', testo);
    }
  });

  // Un'altra scheda aperta in un'altra finestra cambia la raccolta: si segue.
  window.addEventListener('storage', function (e) { if (e.key === CHIAVE) { leggi(); disegna(); } });

  function avvia() { leggi(); disegna(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', avvia); else avvia();
})();
