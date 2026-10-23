<?php

declare(strict_types=1);

use CodeIgniter\CodingStandard\CodeIgniter4;
use Nexus\CsConfig\Factory;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->files()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config'])
    ->name('*.php');

$overrides = [
    // The package files declare strict types, as the rest of the code base does.
    'declare_strict_types' => true,
    // assertEquals is deliberate where objects are compared by value.
    'php_unit_strict' => false,
];

$options = [
    'cacheFile' => 'build/.php-cs-fixer.cache',
    'finder'    => $finder,
];

return Factory::create(new CodeIgniter4(), $overrides, $options)->forProjects();
