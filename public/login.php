<?php
/**
 * public/login.php
 * Dipanggil oleh router (api/index.php). Verifikasi: gs_login() -> password_verify() di PHP.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_guest();
send_no_cache();

$error    = '';
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (strlen($username) > 100 || strlen($password) > 200) {
        $error = 'Username atau password salah';
    } else {
        $res = gs_login($username, $password);

        if ($res['success']) {
            auth_issue((string) $res['user_id'], (string) $res['username']);
            redirect('/dashboard');
        }

        // Jeda acak untuk memperlambat tebak-tebakan password
        usleep(random_int(300000, 700000));
        $error = (string) $res['message'];
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Masuk · Dompetku</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 flex items-center justify-center p-4">
    <main class="w-full max-w-sm">
        <div class="text-center mb-6">
            <div class="mx-auto mb-3 h-12 w-12 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-2xl font-bold">D</div>
            <h1 class="text-2xl font-bold text-slate-800">Dompetku</h1>
            <p class="text-sm text-slate-500">Masuk untuk mengelola keuanganmu</p>
        </div>

        <form method="post" action="/login" autocomplete="on"
              class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-4">
            <?= csrf_field() ?>

            <?php if ($error !== ''): ?>
                <div role="alert" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <div>
                <label for="username" class="block text-sm font-medium text-slate-700 mb-1">Username</label>
                <input id="username" name="username" type="text" required autofocus
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       value="<?= e($username) ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                <div class="relative">
                    <input id="password" name="password" type="password" required
                           autocomplete="current-password"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 pr-16 text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <button type="button" id="togglePw"
                            class="absolute inset-y-0 right-0 px-3 text-xs font-medium text-slate-500 hover:text-slate-700">
                        Lihat
                    </button>
                </div>
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5 transition">
                Masuk
            </button>
        </form>
    </main>

    <script>
        const pw = document.getElementById('password');
        const btn = document.getElementById('togglePw');
        btn.addEventListener('click', () => {
            const show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Sembunyi' : 'Lihat';
        });
    </script>
</body>
</html>