<?php
// Pagine di Storia — l'atlante: lettura delle schede, delle fonti e delle
// tassonomie dal database. Qui stanno i meccanismi condivisi; le schermate
// del pannello e il generatore delle pagine li usano, non li riscrivono.
require_once __DIR__ . '/db.php';

function atlante_pronto(): bool {
  static $ok = null;
  if ($ok !== null) return $ok;
  try { db()->query('SELECT 1 FROM pds_schede LIMIT 1'); $ok = true; }
  catch (Throwable $e) { $ok = false; }
  return $ok;
}

// L'indirizzo di una scheda: id e slug, alla radice del sito.
// È un vincolo del committente dichiarato nell'handoff (pagine scheda in
// radice), e sta in una funzione sola perché lo usano il generatore, il
// Taccuino e il pannello: se un giorno cambia, cambia qui.
function atlante_url_scheda($scheda): string {
  $id = is_array($scheda) ? ($scheda['id'] ?? '') : (string)$scheda;
  $slug = is_array($scheda) ? ($scheda['slug'] ?? '') : '';
  $id = strtolower($id);
  return $slug !== '' ? "scheda-$id-$slug.html" : "scheda-$id.html";
}

function atlante_url_fonte($fonte): string {
  $id = is_array($fonte) ? ($fonte['id'] ?? '') : (string)$fonte;
  return 'fonte-' . strtolower($id) . '.html';
}

function atlante_scheda(string $id) {
  if (!atlante_pronto()) return null;
  $st = db()->prepare('SELECT * FROM pds_schede WHERE id=?');
  $st->execute([$id]);
  return $st->fetch() ?: null;
}

function atlante_scheda_per_slug(string $slug) {
  $st = db()->prepare('SELECT * FROM pds_schede WHERE slug=?');
  $st->execute([$slug]);
  return $st->fetch() ?: null;
}

function atlante_fonte(string $id) {
  if (!atlante_pronto()) return null;
  $st = db()->prepare('SELECT * FROM pds_fonti WHERE id=?');
  $st->execute([$id]);
  return $st->fetch() ?: null;
}

// Le citazioni di una scheda, con i dati della fonte accanto: è la coppia che
// il design mostra sempre insieme (fonte + localizzatore).
function atlante_fonti_di_scheda(string $schedaId): array {
  $st = db()->prepare('SELECT sf.*, f.titolo AS fonte_titolo, f.autore_ente, f.natura, f.categoria,
                              f.url AS fonte_url, f.accesso, f.limiti
                       FROM pds_scheda_fonte sf
                       LEFT JOIN pds_fonti f ON f.id = sf.fonte_id
                       WHERE sf.scheda_id = ?
                       ORDER BY sf.ordine, sf.id');
  $st->execute([$schedaId]);
  return $st->fetchAll();
}

function atlante_schede_che_usano(string $fonteId): array {
  $st = db()->prepare('SELECT s.id, s.slug, s.titolo, s.tipologia, sf.localizzatore
                       FROM pds_scheda_fonte sf
                       JOIN pds_schede s ON s.id = sf.scheda_id
                       WHERE sf.fonte_id = ?
                       ORDER BY s.tipologia, s.titolo');
  $st->execute([$fonteId]);
  return $st->fetchAll();
}

function atlante_periodi_di_scheda(string $schedaId): array {
  $st = db()->prepare('SELECT periodo_id, principale FROM pds_scheda_periodo WHERE scheda_id=? ORDER BY periodo_id');
  $st->execute([$schedaId]);
  return $st->fetchAll();
}

function atlante_temi_di_scheda(string $schedaId, string $genere = 'tema'): array {
  $st = db()->prepare('SELECT tema FROM pds_scheda_tema WHERE scheda_id=? AND genere=? ORDER BY tema');
  $st->execute([$schedaId, $genere]);
  return array_column($st->fetchAll(), 'tema');
}

// Le tassonomie si leggono una volta sola per richiesta: sono elenchi brevi
// consultati da ogni scheda, e una query per scheda sarebbe uno spreco.
function atlante_tassonomia(string $tipo): array {
  static $cache = [];
  if (isset($cache[$tipo])) return $cache[$tipo];
  if (!atlante_pronto()) return $cache[$tipo] = [];
  $st = db()->prepare('SELECT * FROM pds_tassonomie WHERE tipo=? ORDER BY ordine, codice');
  $st->execute([$tipo]);
  return $cache[$tipo] = $st->fetchAll();
}

function atlante_etichetta(string $tipo, string $codice): string {
  foreach (atlante_tassonomia($tipo) as $t) if ($t['codice'] === $codice) return $t['etichetta'];
  return $codice;
}

// Elenco filtrato per il pannello. I filtri pubblici girano nel browser sul
// file indice generato; questa serve a chi lavora, non a chi legge.
function atlante_schede_filtrate(array $f = []): array {
  if (!atlante_pronto()) return [];
  $dove = []; $par = [];
  if (!empty($f['tipologia'])) { $dove[] = 's.tipologia = ?'; $par[] = $f['tipologia']; }
  if (!empty($f['stato']))     { $dove[] = 's.stato = ?';     $par[] = $f['stato']; }
  if (isset($f['pubblicata']) && $f['pubblicata'] !== '') { $dove[] = 's.pubblicata = ?'; $par[] = (int)$f['pubblicata']; }
  if (!empty($f['periodo'])) {
    $dove[] = 'EXISTS (SELECT 1 FROM pds_scheda_periodo p WHERE p.scheda_id = s.id AND p.periodo_id = ?)';
    $par[] = $f['periodo'];
  }
  if (!empty($f['tema'])) {
    $dove[] = 'EXISTS (SELECT 1 FROM pds_scheda_tema t WHERE t.scheda_id = s.id AND t.tema = ?)';
    $par[] = $f['tema'];
  }
  if (!empty($f['q'])) {
    $dove[] = '(s.titolo LIKE ? OR s.sintesi LIKE ? OR s.id LIKE ?)';
    $like = '%' . $f['q'] . '%';
    array_push($par, $like, $like, $like);
  }
  $sql = 'SELECT s.*, (SELECT COUNT(*) FROM pds_scheda_fonte sf WHERE sf.scheda_id = s.id) AS n_fonti
          FROM pds_schede s';
  if ($dove) $sql .= ' WHERE ' . implode(' AND ', $dove);
  $sql .= ' ORDER BY s.tipologia, s.ordine, s.id';
  if (!empty($f['limite'])) $sql .= ' LIMIT ' . (int)$f['limite'];
  $st = db()->prepare($sql);
  $st->execute($par);
  return $st->fetchAll();
}

function atlante_conta(): array {
  if (!atlante_pronto()) return [];
  return [
    'schede' => (int)db()->query('SELECT COUNT(*) FROM pds_schede')->fetchColumn(),
    'fonti' => (int)db()->query('SELECT COUNT(*) FROM pds_fonti')->fetchColumn(),
    'citazioni' => (int)db()->query('SELECT COUNT(*) FROM pds_scheda_fonte')->fetchColumn(),
    'da_verificare' => (int)db()->query("SELECT COUNT(*) FROM pds_schede WHERE stato <> 'verificata'")->fetchColumn(),
  ];
}
