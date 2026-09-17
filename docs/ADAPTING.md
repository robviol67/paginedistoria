# Adattare un Design a VelociBuilder LITE

Ricetta per trasformare uno o più file **Claude Design** (`.dc.html`) in un sito
gestibile dal pannello VB LITE. Tempo tipico: poche ore.

## 1. Prepara il progetto

```bash
cp config.example.php config.php                      # credenziali DB
cp build/site.config.example.js build/site.config.js  # config sito
cp inc/mail-config.example.php inc/mail-config.php     # email (opzionale)
mkdir -p src && cp /percorso/*.dc.html src/            # i Design del progetto
```

## 2. Compila `build/site.config.js`

Per ogni pagina del sito aggiungi una riga a `PAGES`:

```js
{ src: 'Home.dc.html', out: 'index.html', title: '...', desc: '...' }
```

Flag utili:
- `form: true` → la pagina ha il **modulo contatti** (handler email + consenso + CRM).
- `products: true` → la pagina ha la **sezione prodotti** gestita dal DB.

Aggiorna anche `SITE` (dominio, nome, immagine social, colore accento) e `LINKMAP`
(mappa dei link interni `.dc.html → .html`).

## 3. Genera

```bash
node build/build.js
```

Produce in `dist/` (o nella cartella di output): pagine statiche, `inc/tpl/*.html`
(template tokenizzati), `inc/cms-pages.json` (manifest dei campi editabili),
seed prodotti/nav. **Round-trip**: template + default = pagina identica al Design.

## 4. Come il motore riconosce le parti (heuristiche attuali)

- **Testi editabili**: tutti i nodi di testo con lettere vere → token `⟦fN⟧`, mappati nel manifest.
- **SEO**: titolo/descrizione/immagine social nel `<head>` → campi `seo_*`.
- **Menu**: il contenitore dei link nella barra sticky → marker `<!--VBNAV-->` (reso dal DB).
- **Prodotti**: il blocco `<sc-for families>` → marker `<!--VBFAMILIES-->` (reso dal DB).
- **Form contatti**: il container dei campi → `<form>` reale verso `contatti.php`.
- **Liste ripetibili** (implementato): un loop `<sc-for list="{{ X }}" as="y" data-vb-list="nome">`
  diventa una lista gestita dal DB (aggiungi/rimuovi/riordina dal pannello → sezione **Liste**).
  I campi sono quelli nominati nel loop (`{{ y.title }}`, `{{ y.desc }}`, ...). Al build vengono
  estratti `inc/lists-seed.json` (valori iniziali) e lo schema nel manifest; a runtime il marker
  `<!--VBLIST:nome-->` è reso da `inc/lists.php`. Migration: `/api/migrate_lists.php`.

- **Slider dal Design** (implementato): `<sc-for list="{{ slides }}" as="s" data-vb-slider="nome">`.
  Diventa uno slider gestito dal DB, reso a runtime da `block_render_slider()` (immagini + testo in
  overlay + transizioni + autoplay). I campi nominati riconosciuti sono `{{ s.image }}` (obbligatorio),
  `{{ s.eyebrow }}`, `{{ s.title }}`, `{{ s.subtitle }}`, `{{ s.cta_label }}`, `{{ s.cta_link }}`,
  `{{ s.position }}` (left|center|right). Config opzionale sul tag: `data-vb-transition="fade|slide"`,
  `data-vb-autoplay="1|0"`, `data-vb-interval="5"`, `data-vb-height="50vh|70vh|100vh"` (default:
  fade / autoplay / 5s / 70vh). Si edita dalla sezione **Liste** (contenuto per-slide + card
  "Impostazioni slider"). Riusa `cms_list_items` + `/api/migrate_lists.php` (nessuna migration nuova).
- **Galleria dal Design** (implementato): `<sc-for list="{{ photos }}" as="p" data-vb-gallery="nome">`.
  Resa a runtime da `block_render_galleria()` (griglia + lightbox). Campi: `{{ p.image }}`
  (obbligatorio), `{{ p.alt }}`, `{{ p.caption }}`. Config: `data-vb-columns="2|3|4"` (default 3).
  Stessa infrastruttura delle liste (editing in **Liste**, storage `cms_list_items`).

  > I campi con nome `image`/`img`/`immagine`/`foto`/`cover` sono riconosciuti come **immagine**
  > (media picker nel pannello); `color`/`colore` come selettore colore; `desc`/`text`/`body`/`testo`
  > come testo lungo; il resto come testo breve. La detection è in `build.js::listFieldType()`.

### Convenzioni `data-vb-*` esplicite (implementate, con fallback all'euristica)

Nav/form/prodotti riconoscono ora anche un attributo esplicito nel Design, stesso schema di
`data-vb-list` (regex sull'attributo + bilanciamento tag). Se l'attributo non c'è, si torna
all'euristica di sempre — **zero rischio per i Design esistenti** (build verificato byte-identico
prima/dopo, come già fatto per l'estrazione config della Fase 1):

- `data-vb-nav` sul contenitore dei link del nav (`findNavLinksContainer()` in `build.js`) — in
  alternativa alla firma CSS letterale (`display:flex;align-items:center;gap:18px;...`).
- `data-vb-form="contatti"` sul contenitore dei campi del form (`findFormContainer()`) — in
  alternativa alla firma CSS letterale. **Nota**: la rinomina dei campi (`nome`/`email`/`tipo`/
  `messaggio`) resta hardcoded su questo unico form esistente — generalizzarla per-slug (una
  mappa campi tipo `FORM_FIELD_MAP`) è rimandato a quando esisterà un secondo form via Design
  (oggi sarebbe astrazione senza un secondo caso d'uso reale; il Form builder generico in
  `inc/forms.php`, usato dalle pagine libere, è un motore separato e non c'entra con questo punto).
- `data-vb-collection="products"` sul loop `<sc-for>` (`findProductsBlockStart()`) — in
  alternativa alla stringa letterale `list="{{ families }}"`. Resta legato solo a "products": un
  motore CRUD generico per collezioni arbitrarie è fuori scope (vedi ROADMAP).
- `data-vb-skip` su qualunque elemento (`extractSkipBlocks()` in `tokenizeEditable()`) — lo esclude
  dalla tokenizzazione testo senza bisogno di conoscerne ID o firma CSS in anticipo (stesso
  meccanismo a placeholder già usato per nav/footer, ma generico).

## 5. Deploy

1. Carica i file via FTP nella web root (es. `httpdocs/`). **Non** caricare `config.php`
   di un altro sito né sovrascrivere le pagine pubblicate dal CMS con quelle appena buildate
   se il cliente ha già personalizzato contenuti (i contenuti stanno nel DB).
2. Apri una volta gli endpoint di migrazione per creare le tabelle:
   `/api/migrate_cms.php`, `migrate_consent.php`, `migrate_velocibuilder.php`,
   `migrate_products.php`, `migrate_media.php`, `migrate_nav.php`, `migrate_velocitracker.php`,
   `migrate_lists.php`, `migrate_pagebuilder.php`.
3. Entra in `/admin/` (primo accesso: utente `admin`, password = `CMS_ADMIN_PASS`).

## Note

- **Aruba cacha gli URL `.php`**: in test via curl aggiungi `?x=timestamp`.
- Ricostruendo con `node build/build.js` si rigenerano i template: dopo, **ripubblica**
  dal pannello le pagine che avevano contenuti personalizzati (gli override sono nel DB).
- Ogni helper globale PHP (`pesc`, `h`) va dichiarato con `if(!function_exists())`:
  i moduli si cross-includono a runtime.

## Pagine libere (page-builder a blocchi)

Oltre alle pagine derivate dal Design, il pannello (**Pagine → Pagine libere**) permette di creare
pagine nuove componendo blocchi curati (Hero, Testo, Testo+immagine, Banner CTA, Colonne) con
drag&drop, senza un file `.dc.html` sorgente. Ereditano automaticamente menu e footer del sito
(`inc/nav.php` → `nav_render_bar()`, `inc/footer.php` → `footer_render_full()`, seminati una volta
dalla home in `inc/nav-shell-seed.html` / `inc/footer-seed.json`) e vengono pubblicate come file
statici veri (`inc/pagebuilder.php` → `pb_publish()`). Nuovi tipi di blocco si aggiungono in
`inc/blocks.php` (`blocks_registry()`): uno schema di campi + una funzione di rendering, stesso
pattern delle Liste ripetibili. Editor: `admin/builder.php`. Migration: `/api/migrate_pagebuilder.php`.

## Form builder

Il pannello (**Moduli**) permette di creare form con campi personalizzati (testo, email,
telefono, testo lungo, menu a tendina, checkbox, **consenso privacy** — quest'ultimo sempre
obbligatorio e loggato via `nc_log_consent()`), destinatari, messaggio di conferma ed
**email di auto-risposta** al mittente con segnaposto `{{chiave_campo}}`. Ogni campo può
essere mappato su un "campo CRM" (email/nome/azienda/telefono/messaggio/tipo): se il modulo
ha l'integrazione VelociTracker attiva, all'invio chiama `vt_push($form['slug'], $in)` con
i valori mappati (stesso motore già usato da contatti/guida, generalizzato per-form).

Un modulo si inserisce in qualsiasi pagina libera scegliendo il blocco **"Modulo"** (che ha
un campo a tendina con l'elenco dei moduli creati). Tutti i moduli condividono un unico
handler pubblico, `forms.php` (root del sito): ogni `<form>` generato posta lì con un campo
nascosto `form_slug` che identifica quale modulo processare — non serve generare un file per
modulo. File: `inc/forms.php` (CRUD + validazione + invio), `admin/forms.php` (editor).
Migration: `/api/migrate_forms.php`.

> **Nota**: i campi "obbligatorio" mostrati disabilitati in UI (es. il consenso privacy) non
> vengono MAI inviati dal browser nei form HTML — se un salvataggio leggesse `$_POST` per quel
> flag lo azzererebbe silenziosamente. `field_save()` forza `required=1` lato server per i
> campi di tipo `consent`, ignorando l'input. Tienilo a mente aggiungendo nuovi tipi di campo
> "sempre obbligatori".

## Editor del page-builder: palette + tab Struttura/Anteprima

`admin/builder.php` ha una **colonna palette a sinistra** (i tipi di blocco dal registro
`blocks_registry()`) e due tab: **Struttura** (editing: impostazioni pagina + blocchi con i loro
campi) e **Anteprima** (un `<iframe>` che carica `admin/builder_preview.php`, il quale chiama
direttamente `pb_render_doc()` sullo stato CORRENTE del DB — quindi mostra anche modifiche non
ancora pubblicate). Dalla palette: **clic** aggiunge un blocco in fondo (submit di un form
nascosto), **trascinare** un blocco nella lista lo inserisce nella posizione di rilascio (SortableJS
con due liste in gruppo condiviso: la palette usa `pull:'clone', put:false, sort:false`, la lista
blocchi usa `put:true`; l'evento `onAdd` chiama l'endpoint AJAX `block_add_at` con `type`+`index`,
che crea il blocco e poi richiama `blocks_reorder()` per inserirlo nella posizione esatta — l'evento
`onEnd` gestisce invece il normale riordino quando `evt.from === evt.to`, cioè quando il trascinamento
è avvenuto TUTTO dentro la lista blocchi, non dalla palette).

Due blocchi "standard" aggiuntivi, pensati per contenuti generici:
- **`html`** ("Testo libero"): un campo `type:'html'` reso con `vb_richtext_field()`
  (`inc/richtext_editor.php`) — un editor minimale contenteditable + toolbar (grassetto, corsivo,
  sottolineato, titoli, elenchi, link, allineamento) basato su `document.execCommand` (deprecato ma
  ancora ampiamente supportato per queste funzioni base; nessuna libreria esterna). Il contenuto è
  salvato come HTML grezzo e reso SENZA escaping (stesso livello di fiducia degli altri testi CMS:
  contenuto di un admin autenticato, non input pubblico).
- **`immagine`**: blocco standalone con immagine (dalla Media Library), alt, link opzionale,
  larghezza (contenuta / a tutta pagina).

Il campo tipo `'html'` va gestito in OGNI punto che itera sui campi di un blocco aspettandosi
`text`/`textarea`/`select`/`color`/`image` (oggi: `admin/builder.php`). Se aggiungi un nuovo tipo di
campo, cercalo in quel file.

## Blog/News

File: `inc/blog.php` (CRUD categorie+post, rendering pubblico, pubblicazione), `admin/blog.php`
(elenco con filtri: pubblicazione/archiviazione/evidenza/categoria/ricerca, + gestione categorie),
`admin/blog_post.php` (editor: occhiello/sottotitolo/testo con `vb_richtext_field()`/testo per
anteprima/categoria/tag/copertina dalla Media Library/autore/SEO). Migration: `/api/migrate_blog.php`.

- **Slug numerato**: alla creazione un post riceve uno slug placeholder, poi — noto l'id — viene
  rinominato `sprintf('%03d-%s', $id, blog_slugify($title))` (es. `006-titolo-post`), stesso stile
  del CMS di riferimento. Route pubblica: `blog/{slug}.html`.
- **Post = nav + intestazione + corpo (HTML grezzo) + footer**, stesso principio delle pagine
  libere. Riusa `pb_og_head()`/`PB_NAV_CSS`/`PB_NAV_JS` da `inc/pagebuilder.php` (niente duplicazione
  dell'assemblaggio head/CSS/JS di base).
- **`<base href="...">` obbligatorio nell'head dei post**: il post vive in `blog/{slug}.html` (una
  cartella più in profondità della root), ma il nav/footer condivisi generano link ROOT-relativi
  (`processo.html`, non `../processo.html`) perché pensati per pagine in root. Il tag `<base>`
  (verso `SITE_DOMAIN`) fa risolvere quei link correttamente senza dover riscrivere nav.php/footer.php
  né gli href dentro blog.php — ogni percorso nel post (copertina, link "torna al blog") va scritto
  **root-relativo, senza `../`**, esattamente come nav/footer già fanno. **Se aggiungi altre pagine
  che vivono in una sottocartella, applica lo stesso pattern `<base>`**, non prefissi manuali.
- **Indice paginato**: `blog.html` (pagina 1), `blog-2.html`, `blog-3.html`… (`BLOG_PER_PAGE = 9`).
  `blog_index_publish_all()` rigenera TUTTE le pagine indice e cancella quelle in eccesso se il
  numero di post pubblicati si riduce.
- **Ogni azione che cambia la visibilità pubblica di un post (pubblica/spubblica, archivia/
  disarchivia, elimina) deve rigenerare l'indice**, non solo il file del singolo post — a scoprirlo
  è stato un bug reale (vedi sotto): `admin/blog.php` lo fa esplicitamente dopo `delete_post` e nel
  toggle di `published`/`archived` (calcola se il post deve essere visibile DOPO il cambiamento —
  pubblicato AND non archiviato — e pubblica o rimuove il file di conseguenza).
- Il blog partecipa al dropdown "pagina" del Menu (`blog.html`) e a **"Pubblica su tutte le
  pagine"** in `admin/menu.php` (ripubblica tutti i post pubblicati + gli indici quando cambia
  nav/footer). **Attenzione a guardie tipo `&& blog_posts_public()`**: sembra un'ottimizzazione
  ("salta se non ci sono post") ma impedisce la rigenerazione proprio nel caso che conta di più —
  quando l'ultimo post viene rimosso e l'indice deve svuotarsi/eliminare le pagine in eccesso. Basta
  `blog_table_ready()` come guardia.

## ROADMAP

- **Liste ripetibili** — ✅ fatto (marcatore `data-vb-list`, vedi sopra).
- **Page-builder a blocchi** — ✅ fatto (vedi sopra). Nuovi blocchi: ✅ **slider** (immagini + testo
  in overlay + transizioni fade/scorrimento, autoplay) e ✅ **galleria fotografica** (griglia +
  lightbox). Prossimo: embed prodotti/liste come blocco.
- **Campo "repeater"** (nuovo, `inc/repeater_field.php`) — field-type generico per liste di
  sotto-elementi con propri campi (immagine/testo/select), riusato da slider, galleria, e dalla
  **galleria fotografica allegabile** ad articoli blog (`cms_blog_posts.gallery_images`) e prodotti
  (`cms_products.gallery_images`, click sull'immagine → lightbox). Migration: `/api/migrate_galleries.php`.
  Rendering condiviso in `inc/gallery.php` (`vb_gallery_grid_html()` per griglie, `vb_gallery_trigger_attrs()`
  per un singolo elemento cliccabile come l'immagine prodotto) — un solo lightbox per pagina.
- **Riconoscimento Slider/Galleria dal Design** — ✅ fatto (`data-vb-slider` / `data-vb-gallery`, vedi
  sezione 4). Ora i due blocchi non sono più solo componibili a mano nel page-builder: se compaiono nel
  `.dc.html` sorgente vengono estratti, seminati e resi come slider/galleria DB-backed (via i renderer
  dei blocchi), editabili dalla sezione **Liste** con i campi immagine (media picker) e una card
  impostazioni. Reso possibile riusando il rail liste (`cms_list_items` + `<!--VBLIST:key-->`), con
  `inc/lists.php::lists_render()` che smista sul tipo `render` (list|slider|gallery).
- **Form builder** — ✅ fatto (vedi sopra), registrato come blocco "Modulo".
- **Motore blog/news** — ✅ fatto (vedi sopra). Non ancora fatto: pagine categoria dedicate, feed RSS, blocco "post correlati".
- **Convenzioni `data-vb-*` estese a nav/form/prodotti/skip** — ✅ fatto (vedi sopra), con fallback
  automatico all'euristica quando l'attributo non è presente nel Design.
- Nav/footer per-pagina, multi-lingua.
