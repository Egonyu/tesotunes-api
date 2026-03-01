<?php
// Get all controller files
$controllers = [];
$dir = new RecursiveDirectoryIterator('app/Http/Controllers');
$iter = new RecursiveIteratorIterator($dir);
foreach ($iter as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = str_replace('\\', '/', $file->getPathname());
        $basename = $file->getBasename('.php');
        if ($basename === 'Controller') continue;
        $controllers[$basename][] = $path;
    }
}

// Get all route files content
$routeContent = '';
$routeDir = new RecursiveDirectoryIterator('routes');
$routeIter = new RecursiveIteratorIterator($routeDir);
foreach ($routeIter as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $routeContent .= file_get_contents($file->getPathname()) . "\n";
    }
}

// Check each controller
$orphaned = [];
$referenced = [];
foreach ($controllers as $name => $paths) {
    if (strpos($routeContent, $name) === false) {
        $orphaned[$name] = $paths;
    } else {
        $referenced[$name] = $paths;
    }
}

echo "=== ORPHANED CONTROLLERS (exist as files, NOT referenced in any route) ===\n\n";
foreach ($orphaned as $name => $paths) {
    foreach ($paths as $p) {
        echo "  $name => $p\n";
    }
}
echo "\nTotal orphaned: " . count($orphaned) . "\n";

echo "\n=== REFERENCED CONTROLLERS (for verification) ===\n\n";
foreach ($referenced as $name => $paths) {
    foreach ($paths as $p) {
        echo "  $name => $p\n";
    }
}
echo "\nTotal referenced: " . count($referenced) . "\n";

// Now for orphaned controllers, get their public methods
echo "\n=== ORPHANED CONTROLLER PUBLIC METHODS ===\n\n";
foreach ($orphaned as $name => $paths) {
    foreach ($paths as $p) {
        echo "--- $name ($p) ---\n";
        $content = file_get_contents($p);
        preg_match_all('/public\s+function\s+(\w+)\s*\(/', $content, $matches);
        foreach ($matches[1] as $method) {
            if ($method === '__construct') continue;
            echo "  - $method()\n";
        }
        echo "\n";
    }
}
