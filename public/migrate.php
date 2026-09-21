<?php
chdir(__DIR__ . '/../');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

echo "<h2>GIS Laravel - Migration Tool</h2>";

// Jalankan key:generate jika APP_KEY belum ada
$env = file_get_contents(__DIR__ . '/../.env');
if (strpos($env, 'APP_KEY=') !== false && preg_match('/APP_KEY=([^\n]*)/', $env, $m) && trim($m[1]) === '') {
    $kernel->call('key:generate');
    echo "<p>APP_KEY berhasil digenerate.</p>";
}

// Jalankan migrate
$status = $kernel->call('migrate', ['--force' => true]);
echo "<pre>$status</pre>";

// Jalankan cache
$kernel->call('config:cache');
$kernel->call('route:cache');
$kernel->call('view:cache');
echo "<p>Config, route, dan view cache selesai.</p>";

echo "<hr><p style='color:red;font-weight:bold'>PENTING: HAPUS file migrate.php ini sekarang!</p>";
echo "<p><a href='/'>Kembali ke Homepage</a></p>";
