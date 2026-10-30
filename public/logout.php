<?php
/**
 * public/logout.php
 * Hanya memproses POST dengan token CSRF valid (mencegah logout paksa lewat link/gambar dari situs lain).
 * Tombol logout di layout (sider.php) harus berupa form:
 *
 *   <form method="post" action="/logout"><?= csrf_field() ?><button type="submit">Keluar</button></form>
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && csrf_verify($_POST['csrf'] ?? null)) {
    auth_clear();
    redirect('/login');
}

// Akses langsung via GET atau token tidak valid: tidak melakukan apa pun
redirect(auth_check() ? '/dashboard' : '/login');