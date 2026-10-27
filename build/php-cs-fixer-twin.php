<?php

declare(strict_types=1);

/*
 * The style of the shipped twin, apart from the repository's: that one
 * migrates to PHP 8.2 and removes the PHPDoc a native type makes redundant,
 * which would write back the syntax the twin sheds ($x::class, trailing
 * commas in parameter lists) and drop the "mixed" tags that stand for the
 * native types PHP 7.4 cannot declare. Layout rules only, nothing risky.
 */

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        '@Symfony' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true, 'allow_unused_params' => true, 'remove_inheritdoc' => false],
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'array_destructuring']],
        'phpdoc_to_comment' => false,
    ])
    ->setCacheFile(__DIR__.'/../.cache/php-cs-fixer-twin.json');
