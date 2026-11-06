<?php
/** Route disposable PHP development-server requests through real WordPress. */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($_SERVER['DOCUMENT_ROOT'] . $path);
if ($file && str_starts_with($file, realpath($_SERVER['DOCUMENT_ROOT']) . '/') && is_file($file)) {
    return false;
}
// Subdirectory multisite shares wp-admin, wp-includes and plugin asset paths.
$shared = preg_replace('#^/(nat|dormant|broken|empty)/(?=wp-(admin|includes|content)/|wp-login\.php)#', '/', $path);
if (str_ends_with($shared, '/wp-admin/')) {
    $shared .= 'index.php';
}
if ($shared !== $path && is_file($_SERVER['DOCUMENT_ROOT'] . $shared)) {
    if (str_ends_with($shared, '.php')) {
        $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . $shared;
        $_SERVER['PHP_SELF'] = $shared;
        $_SERVER['SCRIPT_NAME'] = $shared;
        require $_SERVER['DOCUMENT_ROOT'] . $shared;
    } else {
        $types = ['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'woff2' => 'font/woff2'];
        header('Content-Type: ' . ($types[pathinfo($shared, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($_SERVER['DOCUMENT_ROOT'] . $shared);
    }
    return true;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
