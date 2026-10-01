<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PhpRegex\Parser\Token\Token;
use PhpRegex\Parser\Token\TokenStream;
use PhpRegex\Parser\Token\TokenType;

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/../vendor/autoload.php';

// Create a large token array for benchmarking
$tokens = [];
$pattern = '';
for ($i = 0; $i < 10000; $i++) {
    $tokens[] = new Token(TokenType::Literal, (string) $i, $i);
    $pattern .= (string) $i;
}
$tokens[] = new Token(TokenType::Eof, '', 10000);

echo "Benchmarking TokenStream performance...\n";
echo 'Token count: '.\count($tokens)."\n\n";

$iterations = 1000;

// Benchmark forward iteration
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $stream = new TokenStream($tokens, $pattern);
    while ($stream->hasMore() && !$stream->isAtEnd()) {
        $stream->next();
    }
}
$forwardTime = microtime(true) - $start;

echo \sprintf("Forward iteration (%d iterations): %.4f seconds\n", $iterations, $forwardTime);

// Benchmark peek operations
$start = microtime(true);
$peekOperations = 0;
for ($i = 0; $i < $iterations; $i++) {
    $stream = new TokenStream($tokens, $pattern);
    while ($stream->hasMore() && !$stream->isAtEnd()) {
        $stream->peek(1);
        $stream->peek(2);
        $stream->peek(3);
        $peekOperations += 3;
        $stream->next();
    }
}
$peekTime = microtime(true) - $start;

echo \sprintf("Peek operations (%d operations): %.4f seconds\n", $peekOperations, $peekTime);

// Benchmark rewind operations
$start = microtime(true);
$rewindOperations = 0;
for ($i = 0; $i < $iterations; $i++) {
    $stream = new TokenStream($tokens, $pattern);
    $count = 0;
    while ($stream->hasMore() && !$stream->isAtEnd() && $count < 100) {
        $stream->next();
        $count++;
    }
    // Rewind halfway
    $stream->rewind(50);
    $rewindOperations++;
}
$rewindTime = microtime(true) - $start;

echo \sprintf("Rewind operations (%d operations): %.4f seconds\n", $rewindOperations, $rewindTime);

echo "\nMemory usage: ".memory_get_peak_usage(true) / 1024 / 1024 ." MB\n";
