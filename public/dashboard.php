<?php
/**
 * public/dashboard.php  (STUB Fase 3 - akan diganti penuh di Fase 5)
 * Tujuan: membuktikan login/cookie berfungsi dan koneksi Apps Script berjalan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$user = require_login();

$summary = gs_get_summary();
$ok      = (bool) ($summary['success'] ?? false);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Dashboard · Dompetku</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 p-4">
    <main class="mx-auto max-w-md space-y-4">
        <header class="flex items-center justify-between">
            <h1 class="text-xl font-bold text-slate-800">Halo, <?= e($user['username']) ?></h1>
            <form method="post" action="/logout">
                <?= csrf_field() ?>
                <button type="submit" class="text-sm text-slate-500 hover:text-red-600">Keluar</button>
            </form>
        </header>

        <section class="bg-white rounded-2xl border border-slate-200 p-5">
            <?php if ($ok): ?>
                <p class="text-sm text-slate-500">Sisa saldo</p>
                <p class="text-3xl font-bold text-emerald-600"><?= e(format_rupiah($summary['sisa_saldo'] ?? 0)) ?></p>
                <p class="mt-2 text-xs text-slate-400">Koneksi Google Sheets berfungsi.</p>
            <?php else: ?>
                <p class="text-sm text-red-600">Gagal mengambil data: <?= e((string) ($summary['message'] ?? 'tidak diketahui')) ?></p>
            <?php endif; ?>
        </section>

        <p class="text-xs text-slate-400 text-center">Halaman sementara (Fase 3). Dashboard lengkap dibuat di Fase 5.</p>
    </main>
</body>
</html>