// ============================================================================
// VelociBuilder LITE — config per-sito. COPIA questo file in "site.config.js"
// e adattalo al progetto. site.config.js è gitignored (non finisce nel repo).
// È l'UNICO file da editare per un nuovo sito, insieme a config.php (DB).
// ============================================================================

// Dati globali del sito (OpenGraph / canonical / immagine social di default)
const SITE = {
  domain: 'https://www.esempio.it',
  name: 'Nome del sito',
  ogImage: 'https://www.esempio.it/assets/social.png',
  accent: '#1F7A3D', // colore d'accento del brand (usato dalle landing generate)
};

// Elenco pagine: file sorgente Design (.dc.html) -> file statico di destinazione.
//   title / desc : SEO + OpenGraph
//   props        : override delle props del componente Design (opzionale)
//   form: true   : la pagina contiene il modulo contatti (handler + consenso + CRM)
//   products:true: la pagina contiene la sezione prodotti gestita da DB
const PAGES = [
  { src: 'Home.dc.html',     out: 'index.html',    title: 'Nome del sito — payoff',
    desc: 'Descrizione della home per Google e social.' },
  { src: 'Contatti.dc.html', out: 'contatti.html', title: 'Contatti — Nome del sito', form: true,
    desc: 'Mettiti in contatto con noi.' },
];

// Mappa link .dc.html -> .html (i link interni del Design vengono riscritti)
const LINKMAP = {
  'Home.dc.html': 'index.html',
  'Contatti.dc.html': 'contatti.html',
};

// Branding — rende il motore indipendente da un singolo progetto. OPZIONALE:
// se omesso, build.js usa i default storici (NuovoSostenibile: verde + Poppins/Public Sans),
// così i progetti esistenti restano identici. Serve per pagine GENERATE (privacy, contatti,
// landing) e per i colori della barra nav; le pagine da Design usano il CSS del loro Design.
const BRAND = {
  accent: '#1F7A3D',                 // CTA/link/bottoni delle pagine generate
  navShrinkBg: 'rgba(255,255,255,.97)', // sfondo barra nav rimpicciolita allo scroll
  pageBg: '#ffffff',
  bodyColor: '#1a1c22',
  headingFont: "'Archivo',sans-serif",
  bodyFont: "'Barlow',sans-serif",
  fontsHref: 'https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800;900&family=Barlow:wght@400;500;600;700;800&display=swap',
  company: 'Ragione Sociale s.r.l.', // titolare del trattamento nella privacy
  email: 'info@esempio.it',          // destinatario moduli + contatto privacy
  privacyProvider: 'il fornitore di hosting', // responsabile del trattamento (hosting/email)
  buildGuida: false,                 // genera la landing guida.php (lead-magnet)?
  buildPrivacy: true,                // genera privacy.html?
};

module.exports = { SITE, PAGES, LINKMAP, BRAND };
