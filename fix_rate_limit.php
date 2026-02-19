<?php

$file = __DIR__.'/bootstrap/app.php';
$content = file_get_contents($file);

if (empty($content)) {
    echo "ERROR: File is empty!\n";
    exit(1);
}

// Add throttleApi('') to disable default API throttling
// Only add if not already present
if (strpos($content, 'throttleApi') === false) {
    $search = '->withMiddleware(function (Middleware $middleware) {';
    $replace = '->withMiddleware(function (Middleware $middleware) {
        // Disable default API throttling
        $middleware->throttleApi(\'\');
';
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo "Updated bootstrap/app.php to disable API throttling\n";
} else {
    echo "throttleApi already configured\n";
}
