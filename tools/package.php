<?php

/** Build a production ZIP without test doubles, developer tools, or local artifacts. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!extension_loaded('zip')) {
    throw new RuntimeException('Packaging requires the PHP zip extension.');
}

$root = dirname(__DIR__);
if (!preg_match("/define\('PLUGIN_NOTIFICATIONBELL_VERSION', '([^']+)'\);/", file_get_contents($root . '/setup.php'), $match)) {
    throw new RuntimeException('Could not read the plugin version.');
}

$files = ['setup.php', 'hook.php', 'README.md', 'LICENSE'];
foreach (['front', 'inc', 'public', 'locales', 'images'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}
sort($files);

$dist = $root . '/dist';
if (!is_dir($dist) && !mkdir($dist, 0775, true)) {
    throw new RuntimeException('Could not create the output directory.');
}
$path = $dist . '/notificationbell-' . $match[1] . '.zip';
$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not create the release ZIP.');
}
foreach ($files as $file) {
    if (!$zip->addFile($root . '/' . $file, 'notificationbell/' . $file)) {
        throw new RuntimeException('Could not package ' . $file);
    }
}
if (!$zip->close()) {
    throw new RuntimeException('Could not finish the release ZIP.');
}
if ($zip->open($path, ZipArchive::CHECKCONS) !== true || $zip->numFiles !== count($files)) {
    throw new RuntimeException('Release ZIP integrity check failed.');
}
foreach ($files as $file) {
    $content = $zip->getFromName('notificationbell/' . $file);
    if ($content === false || hash('sha256', $content) !== hash_file('sha256', $root . '/' . $file)) {
        throw new RuntimeException('Release ZIP content mismatch: ' . $file);
    }
}
$zip->close();
$checksum = hash_file('sha256', $path);
if (file_put_contents($path . '.sha256', $checksum . '  ' . basename($path) . PHP_EOL) === false) {
    throw new RuntimeException('Could not write the release checksum.');
}
echo $path, PHP_EOL, count($files), ' files verified; SHA-256: ', $checksum, PHP_EOL;
