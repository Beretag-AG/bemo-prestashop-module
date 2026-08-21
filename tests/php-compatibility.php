<?php

$repositoryRoot = dirname(__DIR__);
$composer = json_decode(file_get_contents($repositoryRoot . '/composer.json'), true);
$module = file_get_contents($repositoryRoot . '/bemoliveshopping.php');
$readme = file_get_contents($repositoryRoot . '/README.md');

$failures = array();

if ($composer['require']['php'] !== '>=7.0 <8.2') {
    $failures[] = 'Composer must advertise PHP 7.0 through 8.1 support.';
}

if ($composer['config']['platform']['php'] !== '7.2.5') {
    $failures[] = 'Composer must resolve development dependencies against PHP 7.2.5.';
}

if (strpos($module, "version_compare(PHP_VERSION, '7.0', '<')") === false) {
    $failures[] = 'The installer must accept PHP 7.0.';
}

if (strpos($module, 'requires PHP 7.0 or newer.') === false) {
    $failures[] = 'The installer error must state the PHP 7.0 requirement.';
}

if (strpos($module, "version_compare(PHP_VERSION, '8.2', '>=')") === false) {
    $failures[] = 'The installer must enforce the advertised PHP 8.1 upper bound.';
}

if (strpos($readme, '| PHP | 7.0 through 8.1 |') === false) {
    $failures[] = 'The README must document PHP 7.0 through 8.1 support.';
}

if (strpos($module, "'min' => '1.7.3.1'") === false) {
    $failures[] = 'The module must accept PrestaShop 1.7.3.1.';
}

if (strpos($readme, '| PrestaShop | 1.7.3.1 through 8.x |') === false) {
    $failures[] = 'The README must document PrestaShop 1.7.3.1 support.';
}

if ($failures !== array()) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "PHP compatibility metadata is consistent.\n";
