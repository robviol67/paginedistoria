// ============================================================================
// Pagine di Storia — configurazione per-sito di VelociBuilder LITE.
// Non versionata (vedi .gitignore). Design: design_handoff_pagine_di_storia,
// edizione dati v1.15, kit grafico v1.
// ============================================================================

const SITE = {
  domain: 'https://www.paginedistoria.it',
  name: 'Pagine di Storia',
  ogImage: 'https://www.paginedistoria.it/assets/immagini/social.png',
  accent: '#B83A2C', // rosso mattone del kit v1
};

// Le pagine che nascono da un file Design, letto da src-clean/: i sorgenti
// ripuliti da build/preprocess.js (niente mockup, elenchi dati sganciati). Le schede (~172), le pagine fonte
// (157) e i post del Taccuino NON stanno qui: le genera lo script dei dati.
const PAGES = [
  { src: 'src-clean/Home.dc.html', out: 'index.html',
    title: 'Pagine di Storia — l\'Italia dalla caduta del fascismo all\'euro',
    desc: 'Portale di ricerca sulla storia politica e istituzionale italiana dal 1943 al 2002: schede, fonti con localizzatore, cronologia e nessi.' },

  { src: 'src-clean/Atlante.dc.html', out: 'atlante.html',
    title: 'Storia — Pagine di Storia',
    desc: 'Cerca fra 172 schede fra periodi, temi, personaggi, eventi e nessi, con sette filtri componibili.' },

  { src: 'src-clean/Cronologia.dc.html', out: 'cronologia.html',
    title: 'Cronologia — Pagine di Storia',
    desc: 'La linea del tempo dal 1943 al 2002 su due corsie: Italia e quel che accade nel mondo.' },

  { src: 'src-clean/Fonti.dc.html', out: 'fonti.html',
    title: 'Fonti — Pagine di Storia',
    desc: '157 fonti con localizzatore preciso e verifica datata: atti parlamentari, sentenze, archivi, libri, audio e video.' },

  // Nessi: il build ne fa il MODELLO (modelli/nessi.html), non la pagina.
  // La pagina la compone inc/pds_nessi.php mettendo nel modello l'elenco preso
  // dal database. Se il build scrivesse nessi.html, ogni deploy la
  // riporterebbe a un elenco vuoto fino alla pubblicazione successiva.
  { src: 'src-clean/Nessi.dc.html', out: 'modelli/nessi.html',
    title: 'Nessi — Pagine di Storia',
    desc: 'I collegamenti fra eventi, con il verdetto sul grado di prova e le fonti che lo sostengono.' },

  { src: 'src-clean/Media.dc.html', out: 'media.html',
    title: 'Media — Pagine di Storia',
    desc: 'Audio e video d\'archivio: Rai Teche, RaiPlay Sound, podcast e documentari.' },

  { src: 'src-clean/Metodo.dc.html', out: 'metodo.html',
    title: 'Metodo — Pagine di Storia',
    desc: 'Come sono scelte le fonti, come si misura il grado di prova e che cosa significano i verdetti.' },

  { src: 'src-clean/Segnala.dc.html', out: 'segnala.html', form: true,
    title: 'Segnala una correzione — Pagine di Storia',
    desc: 'Segnala un errore, una fonte mancante o una precisazione su una scheda.' },

  { src: 'src-clean/Privacy.dc.html', out: 'privacy.html',
    title: 'Privacy — Pagine di Storia',
    desc: 'Informativa sul trattamento dei dati personali.' },

  // Taccuino: l'indice NON si costruisce più dal prototipo. I post stanno nel
  // database e blog.html lo rigenera il motore a ogni pubblicazione
  // (inc/pds_blog.php). Se restasse qui, ogni deploy riporterebbe indietro
  // l'indice alla versione finta del prototipo, cancellando i post veri.
];

// I prototipi si collegano fra loro con i nomi dei file Design: qui diventano
// i nomi finali. Schede, fonti e post hanno un indirizzo per record, quindi i
// loro link li riscrive il generatore, non questa mappa.
const LINKMAP = {
  'Home.dc.html': 'index.html',
  'Atlante.dc.html': 'atlante.html',
  'Cronologia.dc.html': 'cronologia.html',
  'Fonti.dc.html': 'fonti.html',
  'Nessi.dc.html': 'nessi.html',
  'Media.dc.html': 'media.html',
  'Metodo.dc.html': 'metodo.html',
  'Segnala.dc.html': 'segnala.html',
  'Privacy.dc.html': 'privacy.html',
  'Taccuino.dc.html': 'blog.html',
};

// Le pagine che nascono dal Design portano il CSS del kit (pds.css, atlante.css).
// Questi valori servono solo alle pagine generate dal motore.
const BRAND = {
  accent: '#B83A2C',
  navShrinkBg: 'rgba(245,242,235,.97)', // carta del kit
  pageBg: '#F5F2EB',
  bodyColor: '#20211F',
  headingFont: "'Poppins',sans-serif",
  bodyFont: "'Poppins',sans-serif",
  fontsHref: '',                 // font auto-ospitati in assets/font: niente Google Fonts
  company: 'Pagine di Storia',
  email: 'info@paginedistoria.it',
  privacyProvider: 'il fornitore di hosting',
  buildGuida: false,
  buildPrivacy: false,           // la privacy arriva dal Design
};

module.exports = { SITE, PAGES, LINKMAP, BRAND };
