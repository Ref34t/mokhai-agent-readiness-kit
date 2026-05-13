<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../vendor/autoload.php';

$classes = [
    'WPContext\\Main',
    'WPContext\\Requirements',
    'WPContext\\Asset_Loader',
];

$exit = 0;
foreach ($classes as $class) {
    $ok = class_exists($class, true);
    fwrite(STDOUT, sprintf("%-30s %s\n", $class, $ok ? 'ok' : 'FAIL'));
    if (!$ok) {
        $exit = 1;
    }
}
exit($exit);
