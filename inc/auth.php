<?php
// VelociBuilder LITE — autenticazione utenti su DB (password cifrate).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cms.php'; // CMS_ADMIN_PASS (bootstrap di emergenza)
if (session_status() === PHP_SESSION_NONE) session_start();

function nc_users_count() {
  try { return (int) db()->query('SELECT COUNT(*) FROM cms_users')->fetchColumn(); }
  catch (Throwable $e) { return -1; } // -1 = tabella assente
}

function nc_login($username, $password) {
  $username = trim($username);
  try {
    $st = db()->prepare('SELECT * FROM cms_users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $u = $st->fetch();
    if ($u && password_verify($password, $u['pass_hash'])) {
      $_SESSION['vb_user'] = ['id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => $u['role']];
      return true;
    }
  } catch (Throwable $e) { /* tabella non ancora creata */ }
  // Bootstrap: se non ci sono utenti (o manca la tabella), accetta la password unica CMS_ADMIN_PASS
  $cnt = nc_users_count();
  if ($cnt <= 0 && defined('CMS_ADMIN_PASS') && $password === CMS_ADMIN_PASS) {
    $_SESSION['vb_user'] = ['id' => 0, 'username' => ($username ?: 'admin'), 'name' => 'Admin', 'role' => 'admin'];
    return true;
  }
  return false;
}

function nc_current_user() { return $_SESSION['vb_user'] ?? null; }
function nc_require_login() { if (!nc_current_user()) { header('Location: index.php'); exit; } }
function nc_logout() { $_SESSION = []; if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); }

// --- gestione utenti (CRUD) ---
function nc_users_all() {
  try { return db()->query('SELECT id, username, name, role, created_at FROM cms_users ORDER BY id')->fetchAll(); }
  catch (Throwable $e) { return []; }
}
function nc_user_create($username, $password, $name, $role = 'admin') {
  $st = db()->prepare('INSERT INTO cms_users (username, pass_hash, name, role, created_at) VALUES (?,?,?,?,NOW())');
  $st->execute([trim($username), password_hash($password, PASSWORD_DEFAULT), trim($name), $role]);
  return db()->lastInsertId();
}
function nc_user_set_password($id, $password) {
  $st = db()->prepare('UPDATE cms_users SET pass_hash = ? WHERE id = ?');
  $st->execute([password_hash($password, PASSWORD_DEFAULT), (int) $id]);
}
function nc_user_delete($id) {
  $st = db()->prepare('DELETE FROM cms_users WHERE id = ?');
  $st->execute([(int) $id]);
}
