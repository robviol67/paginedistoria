#!/bin/bash
# Pagine di Storia — assembla in _dist/ l'albero esatto da caricare su Aruba.
#
# Perché esiste: `node build/build.js` produce le pagine statiche in dist/, ma
# il pannello è fatto di file PHP che stanno fuori da lì (inc/, admin/, api/).
# Il server vuole le due cose insieme, nella stessa cartella. Assemblare qui
# evita di duplicarle nel repo.
#
#   bash deploy/build-sito.sh        → rigenera _dist/
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f deploy/mappa.conf ] && . deploy/mappa.conf

# La mappa degli indirizzi la sa il database, che sta sul server: si scarica
# prima del build, così postbuild.js può riscrivere i link ai record. Se il
# server non risponde si continua con l'ultima mappa scaricata.
if [ -n "${MAPPA_URL:-}" ]; then
  mkdir -p dati
  if curl -fsS -m 60 "$MAPPA_URL&v=$(date +%s)" -o dati/mappa.json.nuova 2>/dev/null; then
    mv dati/mappa.json.nuova dati/mappa.json
    echo "  ✓ mappa degli indirizzi aggiornata dal server"
  else
    rm -f dati/mappa.json.nuova
    echo "  ! mappa non scaricata: uso quella locale, se c'è"
  fi
fi

# L'indice pubblicato del Taccuino dice gli indirizzi veri dei post: si legge
# in HTTPS, che risponde anche quando l'FTP è fermo.
if [ -n "${SITO_URL:-}" ]; then
  curl -fsS -m 30 "$SITO_URL/blog.html?v=$(date +%s)" -o dati/indice-taccuino.html 2>/dev/null \
    && echo "  ✓ indice del Taccuino aggiornato" || echo "  ! indice del Taccuino non letto: uso l'ultimo"
fi

node build/preprocess.js
node build/build.js >/dev/null
node build/postbuild.js

DIST=_dist
rm -rf "$DIST"
mkdir -p "$DIST"

# 1 · le pagine statiche, gli asset e i seed prodotti dal build
cp -R dist/. "$DIST/"

# 2 · il motore PHP: pannello, API, librerie, handler pubblici.
# La barra finale e il "/." non sono un vezzo: `cp -R inc _dist/inc` con la
# destinazione GIÀ esistente (il build ci ha messo tpl/ e i json) annida
# _dist/inc/inc e il pannello non trova più le sue librerie.
for d in inc admin api; do mkdir -p "$DIST/$d"; cp -R "$d/." "$DIST/$d/"; done
for f in forms.php blog-comment.php preview.php; do [ -f "$f" ] && cp "$f" "$DIST/$f"; done

# 2b · gli attrezzi dell'atlante e il JSON di partenza: l'importazione gira sul
# server, dove sta il database. dati/ è negato dal web (vedi .htaccess).
mkdir -p "$DIST/tools" "$DIST/dati"
cp tools/*.php "$DIST/tools/" 2>/dev/null || true
cp dati/*.json "$DIST/dati/" 2>/dev/null || true

# 3 · la configurazione: sta solo sul server, mai nel repo
cp config.php "$DIST/config.php"

# 4 · cartelle di runtime che il pannello scrive
mkdir -p "$DIST/uploads"

# 5 · le regole del server
cat > "$DIST/.htaccess" <<'HT'
# Pagine di Storia — Apache/Aruba
DirectoryIndex index.html index.php
Options -Indexes

# La configurazione non si serve MAI dal web.
<FilesMatch "^config(\.example)?\.php$">
  Require all denied
</FilesMatch>

# inc/ è la libreria del pannello: la include il server, non il browser.
RedirectMatch 403 ^/inc/

# modelli/ sono le pagine-modello da cui il PHP compone quelle vere (Nessi):
# non si servono, si leggono.
RedirectMatch 403 ^/modelli/

# dati/ (alla radice) è il JSON di partenza dell'importazione: lo legge il
# server. Ancorata con ^: senza, la regola colpiva anche assets/dati/, cioè il
# file che i filtri del browser DEVONO leggere, e Storia restava vuota.
RedirectMatch 403 ^/dati/

<IfModule mod_headers.c>
  Header set X-Content-Type-Options "nosniff"
  Header set Referrer-Policy "strict-origin-when-cross-origin"
  # Font e immagini del kit sono immutabili: si cachano a lungo.
  <FilesMatch "\.(woff2|woff|png|jpg|webp)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
  # Stili, script, pagine e dati invece cambiano a ogni pubblicazione. Senza
  # un'istruzione esplicita il browser tiene la copia vecchia «a occhio» per
  # ore: è successo, e le schede si vedevano senza impaginazione. no-cache non
  # vuol dire «non tenere»: vuol dire «ricontrolla», e con l'ETag la risposta è
  # un 304 di pochi byte.
  <FilesMatch "\.(css|js|json|html|svg)$">
    Header set Cache-Control "no-cache"
  </FilesMatch>
</IfModule>
HT

find "$DIST" -type f -exec chmod 644 {} \;
find "$DIST" -type d -exec chmod 755 {} \;
chmod 600 "$DIST/config.php"

echo "Fatto: $DIST/ ($(find "$DIST" -type f | wc -l | tr -d ' ') file, $(du -sh "$DIST" | cut -f1))"
