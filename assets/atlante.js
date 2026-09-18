/* Pagine di Storia — ricerca, filtri e cronologia.
 *
 * Tre applicazioni nello stesso file, ciascuna si accende solo se trova il suo
 * punto d'aggancio nella pagina: #atlante-filtri (Storia), #fonti-app (Fonti),
 * #cronologia-app (Cronologia), #pds-nessi (Nessi).
 *
 * La logica NON è inventata: è quella dei componenti dei prototipi
 * (Atlante.dc.html, Fonti.dc.html, Cronologia.dc.html), portata in JavaScript
 * senza il runtime di prototipazione. L'handoff lo dice al §5.2: cambia la
 * sorgente dei dati, non la logica dei filtri. Stessi parametri d'indirizzo,
 * stessi conteggi, stessi testi, stessi valori di stile.
 *
 * I dati arrivano da assets/dati/atlante.json, generato dal database a ogni
 * pubblicazione (inc/pds_dati.php): indice leggero, fonti, tassonomie, indice
 * inverso fonte → schede. Una sola richiesta.
 */
(function () {
  'use strict';

  // ── attrezzi ──────────────────────────────────────────────────────────────
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  };
  var cmp = function (a, b) { return String(a).localeCompare(String(b), 'it'); };
  var anni = function (r) {
    return r.fine && String(r.fine) !== String(r.inizio) ? r.inizio + '–' + r.fine : String(r.inizio || '');
  };
  var SLUG = { evento: 'Evento', periodo: 'Periodo', tema: 'Tema', personaggio: 'Personaggio', mondo: 'Accade nel mondo', nesso: 'Nesso' };
  var slugDi = function (tipo) { for (var k in SLUG) if (SLUG[k] === tipo) return k; return tipo; };

  function scriviUrl(p) {
    var qs = p.toString();
    history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
  }

  // I dati si chiedono una volta sola, anche se la pagina avesse due app.
  var promessaDati = null;
  function dati() {
    if (!promessaDati) {
      // cache: 'no-cache' = ricontrolla sempre (ETag): i dati cambiano a ogni
      // pubblicazione e una copia vecchia mostrerebbe schede che non ci sono più.
      promessaDati = fetch('assets/dati/atlante.json', { cache: 'no-cache' })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (D) {
          D.perId = {}; D.indice.forEach(function (r) { D.perId[r.id] = r; });
          D.fontePer = {}; D.fonti.forEach(function (f) { D.fontePer[f.id] = f; });
          D.periodoPer = {}; D.tassonomie.periodi.forEach(function (p) { D.periodoPer[p.id] = p; });
          return D;
        });
    }
    return promessaDati;
  }

  function erroreDati(nodo, e) {
    nodo.innerHTML = '<p style="border:1px solid var(--color-text);padding:var(--space-4);font-size:15px">' +
      'I dati dell’atlante non si sono caricati (' + esc(e.message) + '). Ricarica la pagina; se il problema resta, ' +
      '<a href="segnala.html">segnalalo</a>.</p>';
  }

  // ════════════════════════════════════════════════════════════════════════
  // STORIA — sette filtri componibili (handoff §7.1)
  // ════════════════════════════════════════════════════════════════════════
  function storia(form) {
    var contenitore = form.parentElement;
    var s = { q: '', tipi: [], periodo: '', tema: '', fonte: '', stato: 'Tutte', soloDoc: false, ordine: 'anno', limite: 12 };

    // Stato dall'indirizzo: così un link a «?tipo=periodo&periodo=P10» apre
    // la ricerca già fatta, e un reload riproduce lo stesso stato.
    var p = new URLSearchParams(location.search);
    if (p.get('q')) s.q = p.get('q');
    if (p.get('tipo')) s.tipi = [SLUG[p.get('tipo').toLowerCase()] || p.get('tipo')];
    if (p.get('periodo')) s.periodo = p.get('periodo').toUpperCase();
    if (p.get('tema')) s.tema = p.get('tema');
    if (p.get('fonte')) s.fonte = p.get('fonte').toUpperCase();
    if (p.get('stato')) { var v = p.get('stato').toLowerCase(); s.stato = v.indexOf('rev') === 0 ? 'In revisione' : (v.indexOf('ver') === 0 ? 'Verificate' : 'Tutte'); }
    if (p.get('doc')) s.soloDoc = true;

    contenitore.innerHTML = '<p class="num" style="margin:0;font-family:var(--font-heading);font-weight:600;font-size:17px">Caricamento dei dati…</p>';

    dati().then(function (D) {
      var tipologie = D.tassonomie.tipologie.map(function (t) {
        return { id: t.id, nome: t.nome, n: D.indice.filter(function (r) { return r.tipologia === t.id; }).length };
      }).filter(function (t) { return t.n > 0; });
      var temi = Array.from(new Set(D.indice.reduce(function (a, r) { return a.concat(r.temi || []); }, []))).sort(cmp);
      var fontiOrd = D.fonti.slice().sort(function (a, b) { return cmp(a.titolo, b.titolo); });

      // Il modulo si disegna UNA volta: ridisegnarlo a ogni tasto toglierebbe
      // il fuoco al campo di ricerca mentre si scrive.
      contenitore.innerHTML =
        '<form id="atlante-filtri" role="search" style="flex:1 1 262px;max-width:340px;position:sticky;top:96px;display:flex;flex-direction:column;gap:var(--space-6);border:1px solid var(--color-divider);padding:var(--space-4)">' +
          '<div class="field"><label for="q">Ricerca libera</label><input class="input" id="q" type="search" placeholder="Titoli, sintesi, temi, id, fonti"></div>' +
          '<fieldset style="border:0;padding:0;margin:0"><legend style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-neutral-700);padding:0;margin-bottom:var(--space-2)">Tipologia</legend>' +
            '<div style="display:flex;flex-direction:column;gap:6px;font-size:14px">' +
            tipologie.map(function (t) {
              return '<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" data-tipo="' + esc(t.id) + '"><span>' + esc(t.nome) +
                ' <span class="num" style="color:var(--color-neutral-600)">' + t.n + '</span></span></label>';
            }).join('') + '</div></fieldset>' +
          '<div class="field"><label for="fper">Periodo</label><select class="input" id="fper"><option value="">Tutti i periodi</option>' +
            D.tassonomie.periodi.map(function (x) { return '<option value="' + esc(x.id) + '">' + esc(x.inizio + '–' + x.fine + ' · ' + x.titolo) + '</option>'; }).join('') + '</select></div>' +
          '<div class="field"><label for="ftema">Tema</label><select class="input" id="ftema"><option value="">Tutti i temi</option>' +
            temi.map(function (t) { return '<option>' + esc(t) + '</option>'; }).join('') + '</select></div>' +
          '<div class="field"><label for="ffonte">Fonte citata</label><select class="input" id="ffonte"><option value="">Tutte le fonti</option>' +
            fontiOrd.map(function (f) { return '<option value="' + esc(f.id) + '">' + esc(f.titolo + ' · ' + f.n_schede) + '</option>'; }).join('') + '</select></div>' +
          '<fieldset style="border:0;padding:0;margin:0"><legend style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-neutral-700);padding:0;margin-bottom:var(--space-2)">Stato di verifica</legend>' +
            '<div class="seg" role="group" aria-label="Stato di verifica" style="width:100%">' +
            ['Tutte', 'Verificate', 'In revisione'].map(function (x) {
              return '<label class="seg-opt" style="flex:1"><input type="radio" name="stato" value="' + x + '"><span>' + x + '</span></label>';
            }).join('') + '</div></fieldset>' +
          '<label style="display:flex;align-items:flex-start;gap:8px;font-size:14px"><input type="checkbox" id="fdoc"><span>Solo schede con almeno un documento preciso</span></label>' +
          '<div style="display:flex;gap:var(--space-2);border-top:1px solid var(--color-divider);padding-top:var(--space-4)"><button class="btn btn-primary" type="button" data-azzera>Azzera i filtri</button></div>' +
        '</form>' +
        '<div style="flex:2 1 440px;min-width:0" id="atlante-risultati" aria-live="polite"></div>';

      var f = contenitore.querySelector('form');
      var risultati = contenitore.querySelector('#atlante-risultati');
      var campo = f.querySelector('#q');

      function sincronizzaModulo() {
        campo.value = s.q;
        f.querySelectorAll('[data-tipo]').forEach(function (c) { c.checked = s.tipi.indexOf(c.getAttribute('data-tipo')) !== -1; });
        f.querySelector('#fper').value = s.periodo;
        f.querySelector('#ftema').value = s.tema;
        f.querySelector('#ffonte').value = s.fonte;
        f.querySelectorAll('input[name=stato]').forEach(function (r) { r.checked = r.value === s.stato; });
        f.querySelector('#fdoc').checked = s.soloDoc;
      }

      function aggiorna(patch, senzaModulo) {
        Object.assign(s, { limite: 12 }, patch);
        var u = new URLSearchParams();
        if (s.q.trim()) u.set('q', s.q.trim());
        // Il tipo va nell'indirizzo solo se è UNO: è il comportamento voluto (§7.1.3).
        if (s.tipi.length === 1) u.set('tipo', slugDi(s.tipi[0]));
        if (s.periodo) u.set('periodo', s.periodo);
        if (s.tema) u.set('tema', s.tema);
        if (s.fonte) u.set('fonte', s.fonte);
        if (s.stato !== 'Tutte') u.set('stato', s.stato === 'Verificate' ? 'verificate' : 'revisione');
        if (s.soloDoc) u.set('doc', '1');
        scriviUrl(u);
        if (!senzaModulo) sincronizzaModulo();
        disegna();
      }

      var testo = function (r) {
        return (r.id + ' ' + r.titolo + ' ' + (r.sintesi || '') + ' ' + (r.temi || []).join(' ') + ' ' + r.tipologia + ' ' +
          anni(r) + ' ' + (r.etichette || []).join(' ')).toLowerCase();
      };
      // Il filtro «fonte citata» passa dall'indice inverso precalcolato: è il
      // controllo che l'handoff usa per smascherare un indice non rigenerato.
      var citaFonte = function (r) { var u = D.usi[s.fonte]; return u ? u.indexOf(r.id) !== -1 : false; };

      function passa(r, q) {
        if (q && testo(r).indexOf(q) === -1) return false;
        if (s.tipi.length && s.tipi.indexOf(r.tipologia) === -1) return false;
        if (s.periodo && (r.periodi || []).indexOf(s.periodo) === -1) return false;
        if (s.tema && (r.temi || []).indexOf(s.tema) === -1) return false;
        if (s.fonte && !citaFonte(r)) return false;
        if (s.stato === 'Verificate' && r.stato !== 'verificata') return false;
        if (s.stato === 'In revisione' && r.stato === 'verificata') return false;
        if (s.soloDoc && !(r.n_doc > 0)) return false;
        return true;
      }

      function disegna() {
        var q = s.q.trim().toLowerCase();
        var trovate = D.indice.filter(function (r) { return passa(r, q); }).sort(function (a, b) {
          return s.ordine === 'anno'
            ? (parseInt(a.inizio, 10) - parseInt(b.inizio, 10)) || cmp(a.titolo, b.titolo)
            : cmp(a.titolo, b.titolo);
        });

        var chips = [];
        if (s.q.trim()) chips.push({ label: '“' + s.q.trim() + '”', via: { q: '' } });
        s.tipi.forEach(function (n) { chips.push({ label: n, via: { tipi: s.tipi.filter(function (x) { return x !== n; }) } }); });
        if (s.periodo) chips.push({ label: (D.periodoPer[s.periodo] || {}).titolo || s.periodo, via: { periodo: '' } });
        if (s.tema) chips.push({ label: s.tema, via: { tema: '' } });
        if (s.fonte) chips.push({ label: 'Fonte: ' + ((D.fontePer[s.fonte] || {}).titolo || s.fonte), via: { fonte: '' } });
        if (s.stato !== 'Tutte') chips.push({ label: s.stato, via: { stato: 'Tutte' } });
        if (s.soloDoc) chips.push({ label: 'Con documento preciso', via: { soloDoc: false } });

        var visibili = trovate.slice(0, s.limite);
        var resto = trovate.length - visibili.length;
        var h = '';

        h += '<div style="display:flex;flex-wrap:wrap;gap:var(--space-3);align-items:center;border-bottom:1px solid var(--color-divider);padding-bottom:var(--space-3);margin-bottom:var(--space-4)">' +
          '<p class="num" style="margin:0;font-family:var(--font-heading);font-weight:600;font-size:17px">' +
          trovate.length + (trovate.length === 1 ? ' scheda' : ' schede') + ' su ' + D.indice.length + '</p>' +
          '<div style="display:flex;gap:var(--space-2);margin-left:auto;flex-wrap:wrap"><button class="btn btn-secondary" type="button" data-ordine>' +
          (s.ordine === 'anno' ? 'Ordine: cronologico' : 'Ordine: alfabetico') + '</button></div></div>';

        h += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:var(--space-4);align-items:center">' +
          '<span style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-neutral-700)">Filtri attivi</span>' +
          (chips.length
            ? chips.map(function (c, i) { return '<button class="btn btn-secondary" type="button" style="font-size:12px" data-chip="' + i + '" aria-label="Togli il filtro ' + esc(c.label) + '">' + esc(c.label) + ' ×</button>'; }).join('')
            : '<span style="font-size:12px;color:var(--color-neutral-700)">nessuno</span>') + '</div>';

        if (!trovate.length) {
          // Lo stato vuoto elenca i filtri PER NOME (§7.1.9), non «nessun risultato».
          h += '<div style="border:1px solid var(--color-text);padding:var(--space-6)">' +
            '<p style="display:flex;align-items:center;gap:var(--space-2);margin:0 0 var(--space-2);font-family:var(--font-heading);font-weight:600;font-size:20px">' +
            '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="round"><circle cx="10" cy="10" r="6"></circle><path d="M15 15l6 6"></path></g></svg>' +
            ' Nessuna scheda con questi filtri</p>' +
            '<p style="margin:0 0 var(--space-4);font-size:15px;font-family:var(--font-reading);line-height:1.62;max-width:58ch;color:var(--color-neutral-800)">' +
            esc(chips.length ? 'Nessuna scheda soddisfa insieme ' + chips.map(function (c) { return c.label; }).join(' + ') + '. Togli un filtro per allargare la ricerca.' : 'Nessuna scheda disponibile.') + '</p>' +
            '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:var(--space-6)"><button class="btn btn-secondary" type="button" data-azzera>Azzera i filtri</button>' +
            '<a class="btn btn-secondary" href="cronologia.html" style="text-decoration:none">Sfoglia la cronologia</a></div>' +
            '<div style="border-top:1px solid var(--color-text);padding-top:var(--space-4);display:flex;flex-wrap:wrap;gap:var(--space-3);align-items:center">' +
            '<p style="margin:0;font-size:14px;max-width:44ch">Se la scheda dovrebbe esistere e non c’è, è una lacuna dell’atlante: segnalala e finisce nella lista delle priorità.</p>' +
            '<a class="btn btn-primary" href="segnala.html" style="margin-left:auto;text-decoration:none">Segnala una lacuna</a></div></div>';
        }

        h += '<div id="atlante-app" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:var(--space-4)">' +
          visibili.map(function (r) {
            return '<article style="border:1px solid var(--color-divider);border-left:4px solid var(--color-text);padding:var(--space-4);display:flex;flex-direction:column;gap:var(--space-2)">' +
              '<div style="display:flex;align-items:center;gap:var(--space-2)"><span style="font-size:10px;letter-spacing:0.1em;text-transform:uppercase;font-weight:600">' + esc(r.tipologia) + '</span>' +
              '<span class="num" style="margin-left:auto;font-size:12px;color:var(--color-neutral-700)">' + esc(anni(r)) + '</span></div>' +
              '<h2 style="font-size:20px;margin:0"><a href="' + esc(r.url) + '" style="color:var(--color-text);text-decoration:none">' + esc(r.titolo) + '</a></h2>' +
              '<p style="margin:0;font-size:13px;color:var(--color-neutral-800)">' + esc(r.sintesi) + '</p>' +
              '<p style="margin:0;display:flex;flex-wrap:wrap;gap:4px">' + (r.temi || []).slice(0, 2).map(function (t) { return '<span class="tag tag-neutral">' + esc(t) + '</span>'; }).join('') + '</p>' +
              '<p class="num" style="margin:0;display:flex;gap:var(--space-3);border-top:1px solid var(--color-divider);padding-top:var(--space-2);font-size:11px;color:var(--color-neutral-700)">' +
              '<span>' + r.n_fonti + ' fonti · ' + r.n_doc + ' documenti</span><span style="margin-left:auto">' + (r.stato === 'verificata' ? 'Verificata' : 'In revisione') + '</span></p></article>';
          }).join('') + '</div>';

        if (resto > 0) {
          h += '<div style="display:flex;justify-content:center;gap:var(--space-2);margin-top:var(--space-8)"><button class="btn btn-secondary" type="button" data-altre>Mostra altre ' + resto + '</button></div>';
        }

        risultati.innerHTML = h;
        risultati._chips = chips;
      }

      // ── eventi ──
      campo.addEventListener('input', function () { aggiorna({ q: campo.value }, true); });
      f.addEventListener('submit', function (e) { e.preventDefault(); });
      f.addEventListener('change', function (e) {
        var t = e.target;
        if (t.hasAttribute('data-tipo')) {
          var cur = s.tipi.slice(), id = t.getAttribute('data-tipo'), i = cur.indexOf(id);
          if (i === -1) cur.push(id); else cur.splice(i, 1);
          aggiorna({ tipi: cur }, true);
        } else if (t.id === 'fper') aggiorna({ periodo: t.value }, true);
        else if (t.id === 'ftema') aggiorna({ tema: t.value }, true);
        else if (t.id === 'ffonte') aggiorna({ fonte: t.value }, true);
        else if (t.name === 'stato') aggiorna({ stato: t.value }, true);
        else if (t.id === 'fdoc') aggiorna({ soloDoc: t.checked }, true);
      });
      contenitore.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) return;
        if (b.hasAttribute('data-azzera')) aggiorna({ q: '', tipi: [], periodo: '', tema: '', fonte: '', stato: 'Tutte', soloDoc: false });
        else if (b.hasAttribute('data-ordine')) { s.ordine = s.ordine === 'anno' ? 'titolo' : 'anno'; disegna(); }
        else if (b.hasAttribute('data-altre')) { s.limite += 12; disegna(); }
        else if (b.hasAttribute('data-chip')) {
          var c = risultati._chips[+b.getAttribute('data-chip')];
          if (c) aggiorna(c.via);
        }
      });

      sincronizzaModulo();
      disegna();
    }).catch(function (e) { erroreDati(contenitore, e); });
  }

  // ════════════════════════════════════════════════════════════════════════
  // FONTI — cinque filtri (handoff §7.2)
  // ════════════════════════════════════════════════════════════════════════
  function fonti(app) {
    var main = app.closest('main');
    var ESITO = { raggiungibile: 'Raggiungibile', spostata: 'Spostata', irraggiungibile: 'Irraggiungibile', 'a pagamento': 'A pagamento' };
    var NATURE = ['primaria', 'storiografica', 'strumento', 'divulgazione'];
    var s = { q: '', natura: 'Tutte', materiale: 'Tutti i materiali', ambito: 'Tutti', accesso: 'Tutti' };

    // Tutto ciò che sta sotto il titolo e il cappello lo disegna l'app: nella
    // pagina costruita le parti dinamiche sono vuote.
    var intro = main.querySelector('p');
    while (intro && intro.nextSibling) intro.parentNode.removeChild(intro.nextSibling);
    var radice = document.createElement('div');
    main.appendChild(radice);
    radice.innerHTML = '<p class="num" style="margin:0;font-family:var(--font-heading);font-weight:600;font-size:17px">Caricamento del repertorio…</p>';

    dati().then(function (D) {
      var p = new URLSearchParams(location.search);
      if (p.get('q')) s.q = p.get('q');
      if (p.get('natura')) { var v = p.get('natura').toLowerCase(); var n = NATURE.find(function (x) { return x.indexOf(v) === 0; }); if (n) s.natura = n.charAt(0).toUpperCase() + n.slice(1); }
      if (p.get('materiale')) { var m = p.get('materiale').toLowerCase(); var mm = D.fonti.map(function (f) { return f.categoria; }).find(function (x) { return x && (x.toLowerCase() === m || x.toLowerCase().indexOf(m) === 0); }); if (mm) s.materiale = mm; }
      if (p.get('ambito')) s.ambito = p.get('ambito').toLowerCase().indexOf('int') === 0 ? 'Internazionale' : 'Italiana';
      // Il prototipo scriveva ?accesso= ma non lo rileggeva al caricamento: il
      // reload perdeva il filtro (verifica (d) del §7). Qui si rilegge.
      if (p.get('accesso')) { var a = p.get('accesso').toLowerCase(); var aa = ['Libero', 'Biblioteca', 'A pagamento', 'In sede'].find(function (x) { return x.toLowerCase().indexOf(a) === 0; }); if (aa) s.accesso = aa; }

      var materiali = Array.from(new Set(D.fonti.map(function (f) { return f.categoria; }).filter(Boolean))).sort(cmp);
      var sel = function (id, voci, lab, flex) {
        return '<div class="field" style="flex:' + flex + '"><label for="' + id + '">' + lab + '</label><select class="input" id="' + id + '">' +
          voci.map(function (x) { return '<option>' + esc(x) + '</option>'; }).join('') + '</select></div>';
      };
      // La data della verifica tecnica viene dai dati, non da una stringa fissa.
      var dataVer = D.verifica_tecnica ? D.verifica_tecnica.split('-').reverse().join('/') : '';

      radice.innerHTML =
        '<div style="display:flex;flex-wrap:wrap;gap:var(--space-3);align-items:flex-end;border-top:1px solid var(--color-divider);border-bottom:1px solid var(--color-divider);padding:var(--space-4) 0;margin-bottom:var(--space-6)" role="search">' +
          '<div class="field" style="flex:1 1 220px"><label for="fq">Cerca una fonte</label><input class="input" id="fq" type="search" placeholder="Ente, titolo, autore, sigla"></div>' +
          sel('fnat', ['Tutte', 'Primaria', 'Storiografica', 'Strumento', 'Divulgazione'], 'Natura', '0 1 180px') +
          sel('fmat', ['Tutti i materiali'].concat(materiali), 'Tipo di materiale', '0 1 210px') +
          sel('famb', ['Tutti', 'Italiana', 'Internazionale'], 'Ambito', '0 1 160px') +
          sel('facc', ['Tutti', 'Libero', 'Biblioteca', 'A pagamento', 'In sede'], 'Accesso', '0 1 180px') +
          '<button class="btn btn-secondary" type="button" style="height:36px;white-space:nowrap" data-azzera>Azzera i filtri</button>' +
        '</div>' +
        '<div style="display:flex;flex-wrap:wrap;gap:var(--space-6);margin-bottom:var(--space-4)">' +
          '<p class="num" style="margin:0;font-family:var(--font-heading);font-weight:600;font-size:17px" data-conteggio aria-live="polite"></p>' +
          '<p style="margin:0;font-size:13px;color:var(--color-neutral-700)">Ordinate per natura, poi per ente. Il materiale dice che cosa si ha in mano: un libro, un atto, una registrazione.</p>' +
        '</div>' +
        '<div id="fonti-app" style="overflow-x:auto;-webkit-overflow-scrolling:touch"></div>' +
        '<section style="margin-top:var(--space-8);display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:var(--space-6)" data-riquadri></section>';

      var q = radice.querySelector('#fq'), tab = radice.querySelector('#fonti-app');

      function sincronizza() {
        q.value = s.q;
        radice.querySelector('#fnat').value = s.natura;
        radice.querySelector('#fmat').value = s.materiale;
        radice.querySelector('#famb').value = s.ambito;
        radice.querySelector('#facc').value = s.accesso;
      }
      function aggiorna(patch, senzaModulo) {
        Object.assign(s, patch);
        var u = new URLSearchParams();
        if (s.q.trim()) u.set('q', s.q.trim());
        if (s.natura !== 'Tutte') u.set('natura', s.natura);
        if (s.materiale !== 'Tutti i materiali') u.set('materiale', s.materiale);
        if (s.ambito !== 'Tutti') u.set('ambito', s.ambito);
        if (s.accesso !== 'Tutti') u.set('accesso', s.accesso);
        scriviUrl(u);
        if (!senzaModulo) sincronizza();
        disegna();
      }
      function passa(f, qq) {
        var t = (f.id + ' ' + f.titolo + ' ' + (f.autore_ente || '') + ' ' + f.natura + ' ' + (f.categoria || '') + ' ' + (f.editore || '')).toLowerCase();
        if (qq && t.indexOf(qq) === -1) return false;
        if (s.natura !== 'Tutte' && f.natura !== s.natura.toLowerCase()) return false;
        if (s.materiale !== 'Tutti i materiali' && f.categoria !== s.materiale) return false;
        if (s.ambito !== 'Tutti' && f.ambito !== s.ambito.toLowerCase()) return false;
        if (s.accesso !== 'Tutti') {
          var a = (f.accesso || '').toLowerCase(), v = s.accesso.toLowerCase();
          // indexOf e non uguaglianza: «libero previa registrazione» rientra (§7.2.3).
          if (v === 'libero' && a.indexOf('libero') === -1) return false;
          if (v === 'biblioteca' && a.indexOf('biblioteca') === -1) return false;
          if (v === 'a pagamento' && a !== 'a pagamento') return false;
          if (v === 'in sede' && a !== 'in sede') return false;
        }
        return true;
      }
      var ordN = { primaria: 0, storiografica: 1, strumento: 2, divulgazione: 3 };

      function disegna() {
        var qq = s.q.trim().toLowerCase();
        var trovate = D.fonti.filter(function (f) { return passa(f, qq); }).sort(function (a, b) {
          return (ordN[a.natura] - ordN[b.natura]) || cmp(a.autore_ente || a.titolo, b.autore_ente || b.titolo);
        });
        radice.querySelector('[data-conteggio]').textContent = trovate.length + (trovate.length === 1 ? ' fonte' : ' fonti') + ' su ' + D.fonti.length;

        tab.innerHTML = '<table class="table num" style="min-width:760px"><thead><tr><th scope="col">Sigla</th><th scope="col">Fonte</th><th scope="col">Natura</th><th scope="col">Materiale</th><th scope="col">Ambito</th><th scope="col">Accesso</th><th scope="col">Verifica</th><th scope="col">Schede</th></tr></thead><tbody>' +
          trovate.map(function (f) {
            return '<tr><td style="font-weight:600">' + esc(f.id) + '</td>' +
              '<td><a href="' + esc(f.url) + '" style="color:var(--color-text)">' + esc(f.titolo) + '</a><span style="display:block;font-size:12px;color:var(--color-neutral-700)">' + esc(f.autore_ente || '') + '</span></td>' +
              '<td>' + esc(f.natura.charAt(0).toUpperCase() + f.natura.slice(1)) + '</td>' +
              '<td>' + esc(f.categoria || '—') + '</td>' +
              '<td>' + esc(f.ambito === 'internazionale' ? 'Internazionale · ' + (f.paese || '') : 'Italiana') + '</td>' +
              '<td>' + esc(f.accesso || '—') + '</td>' +
              '<td>' + esc((ESITO[f.esito] || f.esito || '—') + (f.riscontrato ? '' : ' · solo tecnica')) + '</td>' +
              '<td>' + f.n_schede + '</td></tr>';
          }).join('') + '</tbody></table>' +
          (trovate.length ? '' : '<p style="margin:var(--space-4) 0 0;border:1px solid var(--color-text);padding:var(--space-4);font-size:15px">Nessuna fonte con questi criteri: prova ad azzerare i filtri.</p>');
      }

      // I tre riquadri in fondo non dipendono dai filtri: si disegnano una volta.
      var divulgazione = D.fonti.filter(function (f) { return f.natura === 'divulgazione'; });
      var nonRiscontrate = D.fonti.filter(function (f) { return !f.riscontrato; });
      var orfane = D.fonti.filter(function (f) { return !f.n_schede; });
      radice.querySelector('[data-riquadri]').innerHTML =
        '<div style="border:1px solid var(--color-text);padding:var(--space-4);background:var(--color-neutral-100)"><h2 style="font-size:12px;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:var(--space-2)">Da dove cominciare</h2>' +
          '<p style="margin:0 0 var(--space-3);font-size:14px">I portali generici servono a orientarsi, non a provare: stanno qui e non valgono per i minimi di una scheda.</p>' +
          '<ul style="margin:0;padding-left:1.1em;font-size:14px;display:flex;flex-direction:column;gap:4px">' +
          D.fonti.filter(function (f) { return f.natura === 'strumento'; }).slice(0, 4).map(function (f) { return '<li><a href="' + esc(f.url) + '" style="color:var(--color-text)">' + esc(f.titolo) + '</a></li>'; }).join('') + '</ul></div>' +
        '<div style="border:2px dashed var(--color-divider);padding:var(--space-4)"><h2 style="font-size:12px;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:var(--space-2)">Per ascoltare e vedere</h2>' +
          '<p style="margin:0 0 var(--space-3);font-size:14px;color:var(--color-neutral-800)">' + esc('Divulgazione con autori identificati: ' + divulgazione.slice(0, 4).map(function (f) { return f.titolo; }).join(', ') + '. Elencata a parte in ogni scheda.') + '</p>' +
          '<p class="num" style="margin:0;font-size:12px;color:var(--color-neutral-700)">' + divulgazione.length + ' voci · non contate tra le fonti</p></div>' +
        '<div style="border:1px solid var(--color-accent);padding:var(--space-4);background:var(--color-accent-100)"><h2 style="font-size:12px;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:var(--space-2);color:var(--color-accent-700)">Verifica in corso</h2>' +
          '<p style="margin:0 0 var(--space-3);font-size:14px;color:var(--color-accent-900)">' +
          esc(nonRiscontrate.length + ' fonti su ' + D.fonti.length + ' hanno solo la verifica tecnica' + (dataVer ? ' del ' + dataVer : '') + ': il contenuto non è ancora riscontrato' + (orfane.length ? '; ' + orfane.length + ' non sono collegate a nessuna scheda' : '') + '.') +
          '</p><a class="btn btn-secondary" href="metodo.html" style="text-decoration:none">Il protocollo di verifica</a></div>';

      q.addEventListener('input', function () { aggiorna({ q: q.value }, true); });
      radice.addEventListener('change', function (e) {
        var t = e.target;
        if (t.id === 'fnat') aggiorna({ natura: t.value }, true);
        else if (t.id === 'fmat') aggiorna({ materiale: t.value }, true);
        else if (t.id === 'famb') aggiorna({ ambito: t.value }, true);
        else if (t.id === 'facc') aggiorna({ accesso: t.value }, true);
      });
      radice.addEventListener('click', function (e) {
        if (e.target.closest('[data-azzera]')) aggiorna({ q: '', natura: 'Tutte', materiale: 'Tutti i materiali', ambito: 'Tutti', accesso: 'Tutti' });
      });

      sincronizza();
      disegna();
    }).catch(function (e) { erroreDati(radice, e); });
  }

  // ════════════════════════════════════════════════════════════════════════
  // CRONOLOGIA — due corsie per periodo (handoff §5.3, §7.4)
  // ════════════════════════════════════════════════════════════════════════
  function cronologia(app) {
    var main = app.closest('main');
    var intro = main.querySelector('p');
    while (intro && intro.nextSibling) intro.parentNode.removeChild(intro.nextSibling);
    var radice = document.createElement('div');
    main.appendChild(radice);
    radice.innerHTML = '<p class="num">Caricamento della cronologia…</p>';

    // L'handoff suggerisce ?corsia= se serve indirizzabilità: costa poco, e un
    // link a «solo il mondo» diventa condivisibile.
    var corsia = ({ italia: 'italia', mondo: 'mondo' })[new URLSearchParams(location.search).get('corsia')] || 'entrambe';

    dati().then(function (D) {
      var perAnno = function (a, b) { return (parseInt(a.inizio, 10) - parseInt(b.inizio, 10)) || cmp(a.titolo, b.titolo); };
      var voce = function (r, mondo) {
        return '<li style="padding:0 0 var(--space-3) var(--space-4);position:relative">' +
          (mondo
            ? '<span style="position:absolute;left:-6px;top:8px;width:9px;height:9px;border:1px solid var(--color-neutral-600);background:var(--color-bg)"></span>'
            : '<span style="position:absolute;left:-5px;top:8px;width:8px;height:8px;background:var(--color-text)"></span>') +
          '<span style="display:block;font-size:12px;color:var(--color-neutral-700)">' + esc(anni(r)) + '</span>' +
          '<a href="' + esc(r.url) + '" style="color:var(--color-text);font-weight:600;font-size:15px">' + esc(r.id + ' · ' + r.titolo) + '</a></li>';
      };

      function disegna() {
        var mostraItalia = corsia !== 'mondo', mostraMondo = corsia !== 'italia';
        radice.innerHTML =
          '<div style="display:flex;flex-wrap:wrap;gap:var(--space-2);align-items:center;border-top:1px solid var(--color-divider);border-bottom:1px solid var(--color-divider);padding:var(--space-3) 0;margin-bottom:var(--space-8);position:sticky;top:64px;background:var(--color-bg);z-index:10" data-print-hide>' +
            '<span style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-neutral-700)">Salta a</span>' +
            '<div class="num" style="display:flex;flex-wrap:wrap;gap:4px">' +
            D.tassonomie.periodi.map(function (p) { return '<a class="tag tag-neutral" href="#' + esc(p.id) + '" style="text-decoration:none">' + p.inizio + '–' + p.fine + '</a>'; }).join('') + '</div>' +
            '<div style="display:flex;gap:var(--space-2);margin-left:auto">' +
              '<button class="btn btn-secondary" type="button" data-corsia="italia" aria-pressed="' + (corsia === 'italia') + '">' + (corsia === 'italia' ? 'Mostra tutto' : 'Solo Italia') + '</button>' +
              '<button class="btn btn-secondary" type="button" data-corsia="mondo" aria-pressed="' + (corsia === 'mondo') + '">' + (corsia === 'mondo' ? 'Mostra tutto' : 'Solo mondo') + '</button>' +
            '</div></div>' +
          '<div id="cronologia-app" style="display:flex;flex-direction:column;gap:var(--space-8)">' +
          D.tassonomie.periodi.map(function (p) {
            var dentro = D.indice.filter(function (r) { return (r.periodi || []).indexOf(p.id) !== -1; });
            var italia = dentro.filter(function (r) { return r.tipologia === 'Evento'; }).sort(perAnno);
            var mondo = dentro.filter(function (r) { return r.tipologia === 'Accade nel mondo'; }).sort(perAnno);
            return '<section id="' + esc(p.id) + '" style="scroll-margin-top:140px">' +
              '<div style="display:flex;flex-wrap:wrap;align-items:baseline;gap:var(--space-3);border-bottom:1px solid var(--color-text);padding-bottom:var(--space-2);margin-bottom:var(--space-4)">' +
                '<h2 class="num" style="margin:0;font-size:clamp(19px,1.8vw,24px)">' + p.inizio + '–' + p.fine + '</h2>' +
                '<p style="margin:0;font-family:var(--font-heading);font-weight:600;font-size:18px">' + esc(p.titolo) + '</p>' +
                '<a class="btn btn-ghost" href="' + esc(p.url) + '" style="margin-left:auto;text-decoration:none;white-space:nowrap">Scheda del periodo →</a></div>' +
              '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--space-6)">' +
              (mostraItalia ? '<div><p style="margin:0 0 var(--space-3);font-size:11px;letter-spacing:0.1em;text-transform:uppercase;font-weight:600">Italia <span class="num" style="color:var(--color-neutral-700)">' + italia.length + '</span></p>' +
                '<ul class="num" style="list-style:none;margin:0;padding:0;border-left:1px solid var(--color-text)">' + italia.map(function (r) { return voce(r, false); }).join('') + '</ul></div>' : '') +
              (mostraMondo ? '<div><p style="margin:0 0 var(--space-3);font-size:11px;letter-spacing:0.1em;text-transform:uppercase;font-weight:600;color:var(--color-neutral-700)">Accade nel mondo <span class="num">' + mondo.length + '</span></p>' +
                '<ul class="num" style="list-style:none;margin:0;padding:0;border-left:4px double var(--color-neutral-500)">' + mondo.map(function (r) { return voce(r, true); }).join('') + '</ul></div>' : '') +
              '</div></section>';
          }).join('') + '</div>';
      }

      radice.addEventListener('click', function (e) {
        var b = e.target.closest('[data-corsia]');
        if (!b) return;
        var v = b.getAttribute('data-corsia');
        corsia = corsia === v ? 'entrambe' : v;
        var u = new URLSearchParams(location.search);
        if (corsia === 'entrambe') u.delete('corsia'); else u.set('corsia', corsia);
        scriviUrl(u);
        disegna();
      });

      // Il margine di scorrimento si misura, non si fissa: il prototipo diceva
      // 140 px, ma con gli anni su due righe la barra «Salta a» è più alta e il
      // titolo del periodo finiva nascosto sotto (§7.4 chiede il contrario).
      function margini() {
        var barra = radice.querySelector('[data-print-hide]');
        if (!barra) return;
        // La barra si aggancia sotto l'intestazione del sito, che misura 84 px
        // su desktop e 68 su telefono (§7.4): il prototipo la fissava a 64, e
        // l'intestazione ne copriva la prima riga.
        var testata = document.querySelector('.pds-header');
        if (testata && getComputedStyle(testata).position === 'sticky') barra.style.top = Math.round(testata.getBoundingClientRect().height) + 'px';
        var alto = parseFloat(getComputedStyle(barra).top) || 0;
        var m = Math.ceil(alto + barra.getBoundingClientRect().height + 16) + 'px';
        radice.querySelectorAll('#cronologia-app > section').forEach(function (s) { s.style.scrollMarginTop = m; });
      }
      var disegnaPrima = disegna;
      disegna = function () { disegnaPrima(); margini(); };
      window.addEventListener('resize', margini);

      disegna();
      // Arrivando da un link con l'ancora (#P10) la sezione nasce dopo il
      // caricamento: il browser non ci è ancora saltato. Lo si fa qui.
      if (location.hash) { var t = document.getElementById(location.hash.slice(1)); if (t) t.scrollIntoView(); }
    }).catch(function (e) { erroreDati(radice, e); });
  }


  // ════════════════════════════════════════════════════════════════════════
  // NESSI — tema e verdetto, da prop di prototipo a filtri veri (§5.7, §7.5)
  // ════════════════════════════════════════════════════════════════════════
  // L'elenco lo scrive il server dal database (inc/pds_nessi.php): qui si
  // restringe e basta. Non servono i dati: bastano gli attributi delle voci.
  function nessi(sezione) {
    var voci = [].slice.call(sezione.querySelectorAll('.pds-nessi-voce'));
    var selTema = sezione.querySelector('#ntema'), selVer = sezione.querySelector('#nverdetto');
    var conto = sezione.querySelector('[data-nessi-conto]'), vuoto = sezione.querySelector('[data-nessi-vuoto]');
    if (!selTema || !selVer) return;
    var coda = conto.textContent.replace(/^\d+ su \d+/, '');

    var p = new URLSearchParams(location.search);
    var scegli = function (sel, v) {
      if (!v) return;
      var o = [].slice.call(sel.options).find(function (x) { return (x.value || x.textContent).toLowerCase() === v.toLowerCase(); });
      if (o) sel.value = o.value || o.textContent;
    };
    scegli(selTema, p.get('tema'));
    scegli(selVer, p.get('verdetto'));

    function applica() {
      var tema = selTema.value, ver = selVer.value, n = 0;
      voci.forEach(function (v) {
        var ok = (!tema || (v.getAttribute('data-temi') || '').split('|').indexOf(tema) !== -1) &&
                 (!ver || v.getAttribute('data-verdetto') === ver);
        v.hidden = !ok; if (ok) n++;
      });
      conto.textContent = n + ' su ' + voci.length + coda;
      vuoto.hidden = n !== 0;
      var u = new URLSearchParams(location.search);
      if (tema) u.set('tema', tema); else u.delete('tema');
      if (ver) u.set('verdetto', ver); else u.delete('verdetto');
      scriviUrl(u);
    }
    selTema.addEventListener('change', applica);
    selVer.addEventListener('change', applica);
    applica();
  }


  // ════════════════════════════════════════════════════════════════════════
  // MEDIA — categoria, mezzo, solo in revisione (§5.8) + esportazione
  // ════════════════════════════════════════════════════════════════════════
  function media(radice) {
    var voci = [].slice.call(radice.querySelectorAll('.pds-media-voce'));
    var cat = radice.querySelector('#mcat'), mez = radice.querySelector('#mmezzo'), rev = radice.querySelector('#mrev');
    var conto = radice.querySelector('[data-media-conto]'), vuoto = radice.querySelector('[data-media-vuoto]');
    if (!cat || !mez || !rev) return;
    var coda = conto.textContent.replace(/^\d+ voci su \d+/, '');

    var p = new URLSearchParams(location.search);
    var scegli = function (sel, v) {
      if (!v) return;
      var o = [].slice.call(sel.options).find(function (x) { return x.textContent.toLowerCase().indexOf(v.toLowerCase()) === 0; });
      if (o) sel.value = o.value || o.textContent;
    };
    scegli(cat, p.get('categoria')); scegli(mez, p.get('mezzo'));
    rev.checked = !!p.get('revisione');

    function visibili() { return voci.filter(function (v) { return !v.hidden; }); }
    function applica() {
      var c = cat.value, m = mez.value, r = rev.checked, n = 0;
      voci.forEach(function (v) {
        // «Radio e TV» rientra sia in Radio sia in Televisione, come nel
        // prototipo (includes): è un'unica trasmissione su due mezzi.
        var mezzo = v.getAttribute('data-mezzo') || '';
        var ok = (!c || v.getAttribute('data-categoria') === c) &&
                 (!m || mezzo.indexOf(m) !== -1 || (m === 'Televisione' && mezzo.indexOf('TV') !== -1)) &&
                 (!r || v.getAttribute('data-stato') === 'In revisione');
        v.hidden = !ok; if (ok) n++;
      });
      conto.textContent = n + ' voci su ' + voci.length + coda;
      vuoto.hidden = n !== 0;
      var u = new URLSearchParams(location.search);
      if (c) u.set('categoria', c); else u.delete('categoria');
      if (m) u.set('mezzo', m); else u.delete('mezzo');
      if (r) u.set('revisione', '1'); else u.delete('revisione');
      scriviUrl(u);
    }
    [cat, mez, rev].forEach(function (el) { el.addEventListener('change', applica); });

    // Esporta ciò che si vede (i filtri valgono anche qui) in CSV: si apre in
    // qualunque foglio di calcolo, con il separatore italiano.
    radice.addEventListener('click', function (e) {
      if (!e.target.closest('[data-media-esporta]')) return;
      var q = function (s) { return '"' + String(s || '').replace(/"/g, '""') + '"'; };
      var righe = [['id', 'data', 'mezzo', 'categoria', 'titolo', 'programma', 'documento', 'stato', 'scheda'].join(';')];
      visibili().forEach(function (v) {
        var sc = v.getAttribute('data-scheda');
        righe.push([v.dataset.id, v.dataset.data, v.dataset.mezzo, v.dataset.categoria, v.dataset.titolo, v.dataset.programma, v.dataset.documento, v.dataset.stato,
          sc ? location.origin + '/' + sc : ''].map(q).join(';'));
      });
      var blob = new Blob(['﻿' + righe.join('\r\n')], { type: 'text/csv;charset=utf-8' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'pagine-di-storia-repertorio-media.csv';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    });
    applica();
  }

  // ── accensione ────────────────────────────────────────────────────────────
  function avvia() {
    var f = document.getElementById('atlante-filtri');
    if (f) storia(f);
    var fo = document.getElementById('fonti-app');
    if (fo) fonti(fo);
    var c = document.getElementById('cronologia-app');
    if (c) cronologia(c);
    var n = document.getElementById('pds-nessi');
    if (n) nessi(n);
    var m = document.getElementById('pds-media');
    if (m && m.querySelector('.pds-media-voce')) media(m);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', avvia);
  else avvia();
})();
