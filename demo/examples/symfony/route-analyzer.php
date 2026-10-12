<?php

declare(strict_types=1);

use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

/**
 * Example: checking the requirements of Symfony routes
 *
 * This example demonstrates:
 * - Building the pattern Symfony's route compiler matches a requirement with
 * - Validating each requirement before it reaches production
 * - Spotting a requirement open to ReDoS
 *
 * A route path such as "/blog/{slug}" is not a regex: Symfony compiles it,
 * and each requirement becomes an anchored fragment of "{^...$}sD", plus "u"
 * under the route's utf8 option. That compiled form is what is checked here.
 */

require_once __DIR__.'/../../../vendor/autoload.php';

// Example routes from a Symfony application: path => requirements
$routes = [
    '/article/{id}' => ['id' => '\d+'],
    '/archive/{year}/{month}' => ['year' => '[0-9]{4}', 'month' => '0[1-9]|1[0-2]'],
    '/category/{category}' => ['category' => '[a-z]+(?:-[a-z]+)*'],
    '/blog/{slug}' => ['slug' => '([a-z0-9]+-?)+'],
    '/tag/{tag}' => ['tag' => '[a-z'],
];

// The anchored pattern Symfony matches a requirement with: a leading ^ and
// a trailing $ are stripped, and the requirement gets a group of its own.
$compile = static function (string $requirement): string {
    $body = preg_replace(['/^\^/', '/\$$/'], '', $requirement) ?? $requirement;

    return '{^(?:'.$body.')$}sDu';
};

echo "=== Symfony Route Requirement Analysis ===\n\n";

$regex = Regex::create();

foreach ($routes as $path => $requirements) {
    echo "Route: {$path}\n";

    foreach ($requirements as $name => $requirement) {
        $pattern = $compile($requirement);
        echo "  {$name}: {$pattern}\n";

        $validation = $regex->validate($pattern);
        if (!$validation->isValid) {
            echo "    ✗ Invalid requirement: {$validation->error}\n";

            continue;
        }

        $redos = $regex->redos($pattern);
        if ($redos->severity->rank() >= RedosSeverity::Medium->rank()) {
            echo "    ⚠️  ReDoS risk: {$redos->severity->value}\n";

            continue;
        }

        echo "    ✓ Valid\n";
    }

    echo "\n";
}

echo "=== Recommendations ===\n\n";
echo "1. Check every route at once: php bin/console regex:routes\n";
echo "2. Lint the requirements in CI: vendor/bin/regex lint src/\n";
echo "3. Bound the repetition, or make it possessive, where a ReDoS risk is reported\n";
