<?php

declare(strict_types=1);

/**
 * Writes the order each class of src/Parser declares its properties in, as
 * reflection reads it, for PropertyOrderRector to give the twin the same:
 * the order is observable (reflection, get_object_vars(), serialize(), the
 * structural fingerprint), and the downgrade writes a promoted property
 * where it unfolds the constructor, not where PHP 8 counts it.
 *
 *   php build/property-order.php OUT.json
 */
require __DIR__.'/../vendor/autoload.php';

$source = __DIR__.'/../src/Parser';
$order = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ('php' !== $file->getExtension()) {
        continue;
    }

    $class = 'PHPRegex\\Parser\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($source) + 1, -4));
    if (!class_exists($class) && !trait_exists($class)) {
        continue;
    }

    $reflection = new ReflectionClass($class);
    $names = [];
    foreach ($reflection->getProperties() as $property) {
        if ($property->getDeclaringClass()->getName() === $class) {
            $names[] = $property->getName();
        }
    }

    if (count($names) > 1) {
        $order[$class] = $names;
    }
}

ksort($order);
file_put_contents($argv[1], json_encode($order, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
echo 'classes whose property order is kept: ', count($order), "\n";
