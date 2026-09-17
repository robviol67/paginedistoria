# Come VelociBuilder LITE importa i Design di Claude

> Documento di hand-off tecnico. Descrive la pipeline che trasforma un output di
> **Claude Design** (`.dc.html`) in **sito statico pubblico + template CMS gestibile
> dal pannello**. Pensato per chi sviluppa un motore analogo (es. vb2): spiega *perché*
> l'impianto è fatto così, non solo *come*.

---

## Il principio di fondo

L'import **non è** "parsare l'HTML e capirlo". È una **trasformazione deterministica e
reversibile**: dal Design si generano due artefatti gemelli — una **pagina statica pubblica**
e un **template tokenizzato** — legati da un **manifest** di campi editabili. Il contratto che
regge tutto:

> **template + default del manifest (senza override DB) === la pagina statica originale, byte per byte.**

Questo invariante ("round-trip byte-identico") è ciò che rende sicuro aggiungere euristiche di
riconoscimento: se il build resta identico prima/dopo, non hai rotto nessun Design esistente.
È la cosa più importante da preservare in un motore analogo.

---

## Cos'è un `.dc.html`

L'export di Claude Design è un file con:

- un payload `<x-dc>…</x-dc>` (l'HTML del componente);
- un `<helmet>` → diventa il `<head>`;
- uno `<script data-dc-script>` con una `class Component { renderVals() {…} }` (la logica/dati);
- un attributo `data-props="…"` con JSON dei prop e dei loro default;
- una micro-sintassi di templating: `{{ espressione }}`, `<sc-for list="{{ x }}" as="y">`,
  `<sc-if value="{{ cond }}">`.

---

## Stadio 0 — `build/preprocess.js` (per-sito, opzionale)

Serve **solo** per i Design "component-based" (quelli con `<dc-import name="Header|Footer">` e
payload JS-escaped). Fa tre cose e scrive in `src-clean/`:

1. **De-escape** del payload `<x-dc>` (l'export salva l'HTML come stringa con `\"`, `\n`, `\uXXXX`).
2. **Risolve gli `<dc-import>`**: renderizza i componenti Header/Footer con lo stesso mini-engine
   e li **inlinea** nella pagina, così `build.js` vede pagine autoconsistenti.
   Header → avvolto in `<div id="siteNav">` (per nav-shrink + estrazione nav);
   Footer → avvolto in `data-vb-skip` (resta statico, escluso dalla tokenizzazione).
3. **Inietta** il `<link>` Google Fonts del brand (i `@font-face` del Design puntano a ID asset
   non risolvibili fuori dallo standalone).

È gitignored e per-sito **apposta**: il formato d'export varia da progetto a progetto, e questo
è l'unico punto che assorbe quella varianza. Per i Design "standalone" (single-file già pulito)
lo stadio 0 si salta.

> **Nota per un motore analogo**: questa è la giuntura giusta dove isolare le differenze di
> formato d'export — il motore generico (`build.js`) non va toccato.

---

## Stadio 1 — `build/build.js` (il motore generico)

Per ogni pagina in `PAGES` (`build/site.config.js`):

### A. Rendering → pagina statica pubblica

1. `extract()` separa inner-template, head, script, `propsMeta`.
2. `computeVals()` costruisce lo scope dati: default dei prop + override di pagina; se c'è
   `class Component`, la istanzia in sandbox (`new Function`) e chiama `renderVals()`. Pagine
   senza logica → solo i prop.
3. `parse()` + `render()`: mini-engine che espande `sc-for`/`sc-if` e interpola `{{…}}` → HTML statico.
4. `postProcess()`: `style-hover="…"` → classe + regola CSS `:hover`.
5. `assembleDoc()` → scrive **`dist/<out>`** (es. `index.html`).
   **Questo è il file che vede il visitatore: statico puro, niente PHP, niente DB.**

### B. Dallo stesso body → il template CMS reversibile (`inc/tpl/<slug>.html`)

Qui avviene la parte deterministica vera. In ordine:

1. **Regioni dinamiche → marker (commenti HTML)**, ognuna riconosciuta da un attributo
   `data-vb-*` esplicito (regex) con **fallback euristico**, e ritagliata con **bilanciamento
   dei tag** (`balancedEnd`, conta apertura/chiusura sul tag reale):

   | Regione nel Design | Marker nel template |
   |---|---|
   | prodotti `<sc-for data-vb-collection="products">` | `<!--VBFAMILIES-->` |
   | liste/slider/gallerie `data-vb-list\|slider\|gallery` | `<!--VBLIST:key-->` (+ semi in `lists-seed.json`) |
   | link nav | `<!--VBNAV-->` |
   | menu mobile | `<!--VBNAVMOBILE-->` |
   | inner del footer | `<!--VBFOOTER-->` |
   | `<form data-vb-form>` | `<!--VBFORM:key-->` |
   | link social/maps `<a href>` | token `⟦opt_KEY⟧` |

2. **`tokenizeEditable()` — la mossa centrale.** Protegge nav (`#siteNav`), footer e ogni
   `data-vb-skip` sostituendoli con placeholder; poi scorre i **nodi di testo** con la regex
   `>([^<]+)<` e trasforma **ogni testo che contenga almeno 2 lettere vere** in un token
   posizionale `⟦fN⟧` (`f0`, `f1`, …), preservando gli spazi iniziali/finali. Ogni token genera
   un campo nel manifest:

   ```js
   { key:'f7', default:'<testo originale>', type: len>70 ? 'textarea':'text', label:'…' }
   ```

   Poi ripristina skip/nav/footer. Il resto della struttura HTML (tag, classi, attributi) **resta
   intatto**: è "scenografia", non è editabile.

3. **SEO**: nel `<head>` assemblato, le stringhe `title`/`desc`/`ogImage` vengono sostituite con
   `⟦seo_title⟧`, `⟦seo_desc⟧`, `⟦seo_image⟧`.

4. Scrive `inc/tpl/<slug>.html` e registra la voce nel manifest:

   ```js
   pages[slug] = { title, out, slug, fields:[...seoFields, ...textFields], hasProducts, lists }
   ```

### C. Output globali

`inc/cms-pages.json` (manifest di tutte le pagine + campi), `lists-seed.json`,
`products-seed.json`, `nav-seed`, `footer-seed`, `nav-shell-seed`, `nav-theme.json`.
I **seed** servono a versare nel DB il contenuto iniziale del Design, così la prima "Pubblica"
combacia con la pagina buildata.

---

## Perché i delimitatori `⟦ ⟧`

Sono `U+27E6` / `U+27E7` (parentesi bianche matematiche): **non compaiono mai** in HTML o testo
normale, quindi tokenizzare e de-tokenizzare è privo di ambiguità. È una scelta di design, non
estetica — è ciò che rende la sostituzione a runtime una semplice `preg_replace` senza rischio
di collisioni.

---

## Runtime PHP — `inc/cms.php`

- **`cms_render($slug)`**: carica il template, calcola i valori (default del manifest **←**
  override DB in `cms_content`, chiave namespacizzata `slug::fN`), sostituisce
  `⟦([a-z0-9_]+)⟧` coi valori (`opt_*` dalle Opzioni, `seo_image` reso assoluto), poi rimpiazza
  i marker-commento includendo i moduli giusti (`nav.php`, `products.php`, `lists.php`,
  `forms.php`, `footer.php`, JSON-LD da `seo.php`).
- **`cms_publish($slug)`**: riscrive il file statico reale (con `.bak`).
  Quindi: **modifichi nel pannello → "Pubblica" → la pagina statica viene rigenerata
  deterministicamente da template + DB.**

Il file pubblico e il template divergono **solo** nelle regioni dinamiche: il primo ha
nav/footer/prodotti inlineati dal Design; nel secondo sono marker, e la fonte di verità
diventa il DB.

---

## I 3 gotcha da tenere presenti

1. **`⟦fN⟧` è posizionale.** I token si numerano nell'ordine di apparizione. Se ribuildi e il
   Design è cambiato, `f7` può riferirsi a un altro testo → gli override DB (chiave `slug::f7`)
   "scivolano". Regola operativa: **dopo ogni rebuild, ripubblica dal pannello** le pagine con
   contenuti personalizzati.
2. **Template e manifest sono inseparabili.** Il `.html` tokenizzato senza il suo
   `cms-pages.json` non ha né i default né i tipi campo. Vanno versionati/deployati insieme
   (o rigenerati insieme).
3. **I marker sono commenti HTML apposta.** Restano HTML valido: la pagina pubblica prima della
   prima "Pubblica" è comunque servibile, e il ritaglio a bilanciamento tag permette di
   riconoscere blocchi senza conoscerne ID/firma CSS in anticipo (meccanismo `data-vb-skip`
   generalizzato).

---

## Filosofia trasferibile

Il motore resta **generico e cieco al dominio**. Tutta la conoscenza specifica del sito sta o
nel per-sito (`preprocess.js`, `site.config.js`) o nei dati (seed → DB). Riconoscimento sempre
come *attributo esplicito `data-vb-*` + fallback euristico*, mai hardcoded, e ogni aggiunta
validata contro l'invariante round-trip byte-identico.

---

## Mappa dei file

| File | Ruolo | Nel repo? |
|---|---|---|
| `build/preprocess.js` | Stadio 0, adattatore per-sito (Design component-based) | no (gitignored, per-sito) |
| `build/build.js` | Stadio 1, motore generico | sì |
| `build/site.config.js` | Config del sito: `PAGES`, `SITE`, `LINKMAP`, `BRAND` | no (gitignored, per-sito) |
| `src/*.dc.html` | Design sorgente | no |
| `src-clean/*.dc.html` | Design de-escaped/risolti (output stadio 0) | no |
| `dist/<out>.html` | Pagine statiche pubbliche | no |
| `inc/tpl/<slug>.html` | Template tokenizzati (`⟦fN⟧` + marker) | no |
| `inc/cms-pages.json` | Manifest campi editabili | no |
| `inc/*-seed.json` | Contenuto iniziale → DB | no |
| `inc/cms.php` | Runtime: render + publish | sì |

Vedi anche [`docs/ADAPTING.md`](ADAPTING.md) per la ricetta operativa passo-passo di onboarding
di un nuovo Design.
