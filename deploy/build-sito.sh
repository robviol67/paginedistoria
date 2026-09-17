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

node build/build.js >/dev/null

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
RedirectMatch 403 (?i)/inc/

# dati/ è il JSON di partenza dell'importazione: lo legge il server. Quello che
# serve al browser è l'indice generato, che sta in assets/.
RedirectMatch 403 (?i)/dati/

<IfModule mod_headers.c>
  Header set X-Content-Type-Options "nosniff"
  Header set Referrer-Policy "strict-origin-when-cross-origin"
  # Font e immagini del kit sono immutabili: si cachano a lungo.
  <FilesMatch "\.(woff2|woff|svg|png|jpg|webp)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>
HT

find "$DIST" -type f -exec chmod 644 {} \;
find "$DIST" -type d -exec chmod 755 {} \;
chmod 600 "$DIST/config.php"

echo "Fatto: $DIST/ ($(find "$DIST" -type f | wc -l | tr -d ' ') file, $(du -sh "$DIST" | cut -f1))"
