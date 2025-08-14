<?php
// Router for php -S so paths like /abc are forwarded to index.php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // serve the requested resource as-is
}
require_once __DIR__ . '/index.php';
