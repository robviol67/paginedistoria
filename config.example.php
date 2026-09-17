<?php
// ============================================================================
// VelociBuilder LITE — config per-sito. COPIA questo file in "config.php" e
// compila i valori. config.php è gitignored (non finisce nel repo).
// ============================================================================

// --- Database (MariaDB / MySQL) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'nome_database');
define('DB_USER', 'utente_database');
define('DB_PASS', 'password_database');

// --- Password di bootstrap del pannello (usata solo al primo accesso, se non ci sono utenti) ---
if (!defined('CMS_ADMIN_PASS')) define('CMS_ADMIN_PASS', 'cambia-questa-password');

// --- Identità del sito (per OG/canonical delle pagine create dal page-builder e dal blog) ---
if (!defined('SITE_DOMAIN'))   define('SITE_DOMAIN', 'https://www.esempio.it');
if (!defined('SITE_NAME'))     define('SITE_NAME', 'Nome del sito');
if (!defined('SITE_OG_IMAGE')) define('SITE_OG_IMAGE', 'https://www.esempio.it/assets/social.png');

// --- Stile dei moduli (render_form_html): per far combaciare i form-modulo col Design ---
if (!defined('FORM_ACCENT')) define('FORM_ACCENT', '#1F7A3D'); // colore pulsante/link del form
if (!defined('FORM_FONT'))   define('FORM_FONT', "'Public Sans',sans-serif");

// --- Migrazione in blocco headless (vb update-all). Metti una stringa lunga e segreta. ---
if (!defined('MIGRATION_KEY')) define('MIGRATION_KEY', '');
