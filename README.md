# VelociBuilder LITE

Mini-CMS/toolkit riutilizzabile per trasformare un output di **Claude Design** (`.dc.html`)
in un **sito statico veloce + pannello di amministrazione**, senza framework.

Pensato per hosting economico (Aruba/Plesk, PHP + MariaDB) con deploy via FTP.

## Cosa offre il pannello

- **Pagine + SEO** — editor generico dei testi di ogni pagina (estratti automaticamente dal Design) e dei meta SEO/social.
- **Prodotti** — catalogo con famiglie/categorie, schede, immagini, brochure PDF, riordino drag&drop, filtro categorie e doppia vista (schede / lista).
- **Media Library** — libreria immagini centralizzata con **editor integrato** (ritaglio, ruota, flip, luminosità/contrasto/saturazione, nitidezza, resize, WebP) — tutto client-side, salva copia o sovrascrive.
- **Menu e piè di pagina** — voci di navigazione + footer editabili, drag&drop, pubblicazione su tutte le pagine.
- **Pagine libere (page-builder a blocchi)** — crea nuove pagine componendo blocchi curati (Hero, Testo, **Testo libero con editor HTML**, Testo+immagine, **Immagine**, Banner CTA, Colonne, Modulo) senza bisogno di un file Design sorgente. Editor con **palette dei blocchi a sinistra** (trascina o clicca per aggiungere, drag&drop per riordinare) e tab **Struttura / Anteprima** (anteprima live dello stato corrente, anche non pubblicato). Le pagine ereditano automaticamente menu e footer del sito e si pubblicano come pagine statiche vere e proprie.
- **Moduli (form builder)** — crea form con campi personalizzati (testo/email/telefono/testo lungo/menu/checkbox/consenso privacy), destinatari, messaggio di conferma ed **email di auto-risposta** con segnaposto `{{campo}}`. Ogni modulo è selezionabile come blocco "Modulo" nell'editor pagine. Integrazione opzionale, per-modulo, con VelociTracker.
- **Blog/News** — post con occhiello/sottotitolo/testo (editor HTML), testo per anteprima, categoria, copertina, autore; stati pubblicato/in evidenza/archiviato; filtri in elenco (pubblicazione, archiviazione, evidenza, categoria, ricerca). Route numerata (`blog/006-titolo.html`, come i CMS a blocchi di riferimento) + indice paginato (`blog.html`, `blog-2.html`, ...). Post e indice ereditano menu e footer ed escono come pagine statiche.
- **Messaggi / Consensi** — archivio delle richieste dai form + registro consensi GDPR (export CSV).
- **Utenti** — login su DB (password cifrate).
- **VelociTracker (CRM)** — i form del sito creano anagrafiche/opportunità nel CRM via API (dedup su email → bozza/opportunità). Configurabile e disattivabile dal pannello.

## Architettura

- **Rigenerazione statica al salvataggio**: i contenuti stanno nel DB; al "Pubblica" il motore riscrive i file `.html` statici da template + DB. Il sito pubblico è statico (veloce, SEO), niente PHP per i visitatori.
- **Motore generico** (questo repo) separato dai **contenuti/config per-sito**.

```
build/build.js      convertitore Design(.dc.html) -> statico + template tokenizzati + manifest
inc/                motore PHP (db, cms, auth, media, nav, footer, blocks, pagebuilder, forms, blog, products, settings, velocitracker, mailer, ...)
admin/              pannello
api/migrate_*.php   creazione tabelle (idempotenti)
forms.php           handler pubblico generico per l'invio di TUTTI i moduli creati (root, accanto a index.html)
```

## Per-sito (NON nel repo — vedi `.gitignore`)

| File | Copia da | Contiene |
|------|----------|----------|
| `config.php` | `config.example.php` | credenziali DB |
| `build/site.config.js` | `build/site.config.example.js` | pagine, meta, brand, linkmap |
| `inc/mail-config.php` | `inc/mail-config.example.php` | SendGrid / mittente email |
| `src/*.dc.html` | — | i file Design del progetto |

## Quick start

```bash
cp config.example.php config.php                      # 1. credenziali DB
cp build/site.config.example.js build/site.config.js  # 2. pagine + brand
cp inc/mail-config.example.php inc/mail-config.php     # 3. email (opzionale)
# metti i .dc.html in src/ e allineali a site.config.js
node build/build.js                                   # 4. genera statico + template
# 5. deploy via FTP, poi apri /api/migrate_*.php per creare le tabelle
```

Guida completa all'onboarding di un nuovo Design: [`docs/ADAPTING.md`](docs/ADAPTING.md).
