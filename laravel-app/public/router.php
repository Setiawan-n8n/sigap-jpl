<?php

// Router sederhana untuk PHP built-in server. Menggantikan server.php bawaan
// Laravel (vendor/laravel/framework/.../resources/server.php), yang gagal
// saat dijalankan langsung lewat `php -S` (bukan lewat `php artisan serve`)
// karena path __DIR__-nya dihitung relatif terhadap lokasi file di dalam
// vendor/, bukan terhadap direktori proyek -- hasilnya salah mengarah ke
// /var/www/html/index.php padahal seharusnya /var/www/html/public/index.php.
//
// File ini sengaja diletakkan di dalam public/ sendiri supaya __DIR__ selalu
// tepat menunjuk ke folder public/, apa pun cara skrip ini dijalankan.
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Kalau request menunjuk ke file statis yang benar-benar ada (css/js/gambar),
// biarkan PHP built-in server yang melayaninya langsung.
if ($uri !== '/' && file_exists(__DIR__.$uri)) {
    return false;
}

require_once __DIR__.'/index.php';
