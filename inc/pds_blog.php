<?php
// Pagine di Storia — il Taccuino nella forma del Design.
//
// Il motore sa già pubblicare un blog, ma con il suo impianto: fondo chiaro,
// pastiglie verdi, font da Google, nessuna intestazione del sito. Qui le due
// funzioni di resa vengono sostituite (inc/blog.php le cerca se esistono), e
// il resto del motore — tabelle, pannello, indici, pubblicazione — resta suo.
require_once __DIR__ . '/pds_shell.php';
require_once __DIR__ . '/settings.php';
// (blog.php non si richiama qui: è lui a includere questo file, in coda)

const TACCUINO_INTRO = 'Saggi brevi sui Nessi, ricorrenze politiche e istituzionali, letture di documenti, aggiornamenti dell’atlante. Qui le ipotesi si possono dichiarare: nell’atlante no.';

function pds_data_it(string $sql): string {
  $mesi = [1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
           'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
  $t = strtotime($sql);
  return $t ? ((int)date('j', $t) . ' ' . $mesi[(int)date('n', $t)] . ' ' . date('Y', $t)) : '';
}

function pds_categoria_nome($post): string {
  if (empty($post['category_id'])) return 'Taccuino';
  $c = blog_category_get($post['category_id']);
  return $c ? $c['name'] : 'Taccuino';
}

// ── la pagina di un post ───────────────────────────────────────────────────
function pds_post_render_doc($post): string {
  $titolo = $post['title'];
  $categoria = pds_categoria_nome($post);
  $data = pds_data_it($post['created_at']);
  $lettura = trim((string)($post['eyebrow'] ?? ''));

  $testatina = array_filter([pesc($categoria), pesc($data), $lettura !== '' ? pesc($lettura) : null]);

  $h  = pds_head(
    $post['seo_title'] ?: ($titolo . ' — Taccuino | Pagine di Storia'),
    $post['seo_desc'] ?: $post['excerpt'],
    blog_route($post),
    $post['seo_image'] ?: $post['cover_image']
  );
  // Una puntata di un Dossier appartiene alla sezione Dossier: percorso, voce
  // di menu attiva e ritorno portano lì, non al Taccuino.
  require_once __DIR__ . '/pds_dossier.php';
  $dossier = $categoria === 'Dossier' ? pds_dossier_di_post($post) : null;
  $h .= pds_header($dossier ? 'dossier.html' : 'blog.html');
  $h .= '<main class="pds-lettura" data-print-urls>' . "\n";
  $h .= $dossier
    ? '<nav class="pds-percorso" aria-label="Percorso"><a href="dossier.html">Dossier</a><span>›</span><a href="' . pesc(pds_dossier_file($dossier)) . '">' . pesc($dossier['titolo']) . "</a></nav>\n"
    : '<nav class="pds-percorso" aria-label="Percorso"><a href="blog.html">Taccuino</a><span>›</span><span>' . pesc($categoria) . "</span></nav>\n";
  $h .= '<p class="pds-testatina">' . implode(' · ', $testatina) . "</p>\n";
  $h .= '<h1>' . pesc($titolo) . "</h1>\n";
  if (!empty($post['subtitle'])) $h .= '<p class="pds-occhiello">' . pesc($post['subtitle']) . "</p>\n";
  $autore = blog_author_name($post);
  if ($autore) $h .= '<p class="pds-firma">' . pesc($autore) . "</p>\n";

  // Il corpo è HTML dell'editor: esce così com'è, come ogni testo del pannello
  // (stesso livello di fiducia degli altri contenuti, scritti da chi ha le
  // chiavi del pannello, non dal pubblico).
  $h .= '<div class="pds-corpo">' . $post['body'] . "</div>\n";

  $h .= $dossier
    ? '<p style="margin-top:var(--space-8)"><a href="' . pesc(pds_dossier_file($dossier)) . '">← Tutte le puntate di «' . pesc($dossier['titolo']) . '»</a></p>' . "\n"
    : '<p style="margin-top:var(--space-8)"><a href="blog.html">← Torna al Taccuino</a></p>' . "\n";
  $h .= "</main>\n";
  $h .= pds_footer();
  $h .= pds_chiudi();
  return $h;
}

// ── l'indice, con in evidenza il più recente ───────────────────────────────
function pds_index_render_doc($pagina = 1): string {
  $pagina = max(1, (int)$pagina);
  $totale = blog_posts_public_count();
  $pagine = max(1, (int)ceil($totale / BLOG_PER_PAGE));
  $post = blog_posts_public(BLOG_PER_PAGE, ($pagina - 1) * BLOG_PER_PAGE);

  $h  = pds_head(
    $pagina > 1 ? "Taccuino, pagina $pagina — Pagine di Storia" : 'Taccuino — Pagine di Storia',
    setting_get('taccuino_intro', TACCUINO_INTRO),
    blog_index_file($pagina)
  );
  $h .= pds_header('blog.html');
  $h .= '<main class="pds-elenco pds-apertura" style="--pds-cover:url(/assets/immagini/copertina-taccuino.svg)">' . "\n";
  // Titolo e cappello vengono dalle impostazioni, con il testo del Design come
  // valore di partenza: così restano modificabili dal pannello e non sono
  // murati in una funzione PHP.
  $h .= '<h1>' . pesc(setting_get('taccuino_titolo', 'Taccuino')) . "</h1>\n";
  $h .= '<p class="pds-occhiello" style="max-width:60ch">' . pesc(setting_get('taccuino_intro', TACCUINO_INTRO)) . "</p>\n";

  // Le categorie sono quelle del database, non un elenco scritto a mano.
  $categorie = blog_categories();
  if ($categorie) {
    $h .= '<div class="pds-categorie"><span class="pds-categorie-titolo">Categorie</span>';
    foreach ($categorie as $c)
      $h .= '<span class="tag tag-neutral">' . pesc($c['name']) . '</span>';
    $h .= "</div>\n";
  }

  if (!$post) {
    $h .= "<p>Nessun articolo pubblicato.</p>\n";
  } else {
    // In evidenza solo sulla prima pagina: dalla seconda in poi sarebbe un
    // «in evidenza» che cambia scorrendo, cioè niente.
    $inizio = 0;
    if ($pagina === 1) {
      $p = $post[0]; $inizio = 1;
      $h .= '<article class="pds-evidenza"><div>';
      $h .= '<p class="pds-testatina">In evidenza · ' . pesc(pds_categoria_nome($p)) . ' · ' . pesc(pds_data_it($p['created_at'])) . '</p>';
      $h .= '<h2><a href="' . pesc(blog_route($p)) . '">' . pesc($p['title']) . '</a></h2>';
      if (!empty($p['excerpt'])) $h .= '<p>' . pesc($p['excerpt']) . '</p>';
      if (!empty($p['eyebrow'])) $h .= '<p class="pds-card-meta">' . pesc($p['eyebrow']) . '</p>';
      $h .= "</div></article>\n";
    }

    $h .= '<div class="pds-griglia">' . "\n";
    for ($i = $inizio; $i < count($post); $i++) {
      $p = $post[$i];
      $h .= '<article class="pds-scheda-card">';
      $h .= '<p class="pds-testatina">' . pesc(pds_categoria_nome($p)) . ' · ' . pesc(pds_data_it($p['created_at'])) . '</p>';
      $h .= '<h3><a href="' . pesc(blog_route($p)) . '">' . pesc($p['title']) . '</a></h3>';
      if (!empty($p['excerpt'])) $h .= '<p>' . pesc($p['excerpt']) . '</p>';
      if (!empty($p['eyebrow'])) $h .= '<p class="pds-card-meta">' . pesc($p['eyebrow']) . '</p>';
      $h .= "</article>\n";
    }
    $h .= "</div>\n";
  }

  if ($pagine > 1) {
    $h .= '<nav class="pds-pagine" aria-label="Pagine del Taccuino">';
    for ($i = 1; $i <= $pagine; $i++) {
      $h .= $i === $pagina
        ? '<span aria-current="page">' . $i . '</span>'
        : '<a href="' . pesc(blog_index_file($i)) . '">' . $i . '</a>';
    }
    $h .= "</nav>\n";
  }

  $h .= "</main>\n" . pds_footer() . pds_chiudi();
  return $h;
}
