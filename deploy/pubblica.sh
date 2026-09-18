#!/bin/bash
# Pagine di Storia — il deploy completo, nell'ordine giusto, in un comando.
#
#   bash deploy/pubblica.sh
#
# Perché un comando solo: le pagine dal Design (build) e quelle dal database
# (pubblicazione) si scrivono in due tempi. Se si carica e non si pubblica, la
# Home torna per un momento al contenuto del Design e le pagine Nessi e Media
# restano quelle di prima. L'ordine qui è fisso:
#   1 build e caricamento FTP (deploy/build-sito.sh + deploy/carica.sh)
#   2 pubblicazione dal database: schede, fonti, Nessi, Media, Home, Taccuino
#   3 giro del sito: link rotti e pagine senza i fogli del kit
set -uo pipefail
cd "$(dirname "$0")/.."
[ -f deploy/mappa.conf ] && . deploy/mappa.conf

CHIAVE=$(grep -o "MIGRATION_KEY', '[^']*" config.php | cut -d"'" -f3)
SITO="${SITO_URL:-https://www.paginedistoria.it}"
[ -n "$CHIAVE" ] || { echo "✗ MIGRATION_KEY assente in config.php"; exit 1; }

echo "── 1 · build e caricamento"
bash deploy/build-sito.sh | tail -1
bash deploy/carica.sh | grep -E "caricati|✗|Niente da fare" || { echo "✗ caricamento non riuscito: mi fermo"; exit 1; }

echo "── 2 · pubblicazione dal database"
curl -fsS -m 300 "$SITO/tools/pubblica_atlante.php?key=$CHIAVE&v=$(date +%s)" | sed 's/^/   /'
curl -fsS -m 180 "$SITO/tools/pubblica_taccuino.php?key=$CHIAVE&v=$(date +%s)" | tail -2 | sed 's/^/   /'

echo "── 3 · controllo del sito"
node tools/controlla_link.js "$SITO" | sed 's/^/   /'
