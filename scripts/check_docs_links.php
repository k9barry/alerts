#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$mdFiles = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($rii as $file) {
    if ($file->isDir()) continue;
    $path = $file->getPathname();
    // Skip vendor third-party documentation to avoid scanning upstream packages
    if (strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) continue;
    if (preg_match('/\.md$/i', $path)) $mdFiles[] = $path;
}

$broken = [];
foreach ($mdFiles as $md) {
    $text = file_get_contents($md);
    if (!$text) continue;
    // match markdown links [text](target)
    if (preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $text, $m)) {
        foreach ($m[1] as $target) {
            // only check internal relative links (no scheme or starting with http)
            if (preg_match('#^https?://#i', $target)) continue;
            $url = trim(explode('#', $target)[0]);
            if ($url === '') continue; // anchor only
            // Resolve relative to the markdown file directory
            $baseDir = dirname($md);
            $candidate = realpath($baseDir . '/' . $url);
            if ($candidate === false) {
                $broken[] = [ 'file' => $md, 'link' => $target ];
            }
        }
    }
}

if (empty($broken)) {
    echo "No broken internal markdown links found.\n";
    exit(0);
}

foreach ($broken as $b) {
    echo "Broken link in {$b['file']}: {$b['link']}\n";
}
exit(1);
