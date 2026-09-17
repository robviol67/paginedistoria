/* Tema chiaro/scuro. File esterno: nessun JavaScript in linea nel corpo del Design.
   L'attributo viene messo su <html> E su <body>: nell'ambiente di anteprima
   l'attributo su <html> può essere riscritto, quello su <body> regge. */
(function () {
  var KEY = 'atlante-tema';
  var current = null;

  function stored() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }

  function apply(t) {
    current = t;
    document.documentElement.setAttribute('data-theme', t);
    if (document.body) document.body.setAttribute('data-theme', t);
    var labels = document.querySelectorAll('[data-tema-label]');
    for (var i = 0; i < labels.length; i++) {
      labels[i].textContent = t === 'dark' ? 'Chiaro' : 'Scuro';
    }
    var btns = document.querySelectorAll('[data-tema-toggle]');
    for (var j = 0; j < btns.length; j++) {
      btns[j].setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
      btns[j].setAttribute('aria-label', t === 'dark' ? 'Passa al tema chiaro' : 'Passa al tema scuro');
    }
  }

  apply(stored() || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));

  document.addEventListener('DOMContentLoaded', function () { apply(current); });
  /* il contenuto viene disegnato dopo il caricamento: si riapplica a valle */
  setTimeout(function () { apply(current); }, 0);
  setTimeout(function () { apply(current); }, 300);

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-tema-toggle]') : null;
    if (!t) return;
    var next = current === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(KEY, next); } catch (err) {}
    apply(next);
  });
})();
