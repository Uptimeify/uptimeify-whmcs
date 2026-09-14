<?php

declare(strict_types=1);

// Paths that do not exist yet (templates/admin, uptimeify.php, hooks.php) are skipped,
// otherwise Symfony Finder throws before later tasks create them.
$dirs = array_values(array_filter(
    [__DIR__ . '/lib', __DIR__ . '/tests', __DIR__ . '/templates/admin'],
    'is_dir',
));
$files = array_values(array_filter(
    [__DIR__ . '/uptimeify.php', __DIR__ . '/hooks.php'],
    'is_file',
));

$finder = PhpCsFixer\Finder::create()
    ->in($dirs)
    ->append($files)
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => true,
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'blank_line_after_opening_tag' => true,
    ])
    ->setFinder($finder);
