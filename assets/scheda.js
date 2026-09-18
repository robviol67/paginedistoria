// Pagine di Storia — i due comandi della pagina scheda che non hanno bisogno
// dell'app dell'atlante: stampa e «Cita questa scheda».
// Sta in un file a parte perché il Design non vuole JavaScript in linea nel
// corpo delle pagine (nota di consegna, tema.js).
(function () {
  document.addEventListener('click', function (e) {
    var stampa = e.target.closest('[data-stampa]');
    if (stampa) { window.print(); return; }

    var cita = e.target.closest('[data-cita]');
    if (!cita) return;
    var testo = cita.getAttribute('data-cita-testo') || '';
    var oggi = new Date().toLocaleDateString('it-IT', { day: '2-digit', month: 'long', year: 'numeric' });
    var completo = testo + ' (consultata il ' + oggi + ')';
    var fatto = function () {
      var prima = cita.textContent;
      cita.textContent = 'Citazione copiata';
      setTimeout(function () { cita.textContent = prima; }, 2200);
    };
    // Senza appunti disponibili (pagina non sicura, browser vecchio) la
    // citazione si mostra: meglio copiarla a mano che non averla.
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(completo).then(fatto, function () { window.prompt('Citazione:', completo); });
    } else {
      window.prompt('Citazione:', completo);
    }
  });
})();
