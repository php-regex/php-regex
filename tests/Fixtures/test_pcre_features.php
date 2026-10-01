<?php

require_once "vendor/autoload.php";
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

$tests = [
    // 1. {,n} quantifier syntax (PHP 8.4+)
    "/a{,3}/" => "{,n} quantifier",
    "/a{,5}b/" => "{,n} quantifier with following char",

    // 2. Spaces in quantifier braces (PHP 8.4+)
    "/a{ 2, 5 }/" => "spaces in quantifier",
    "/a{ 2 }/" => "spaces in exact quantifier",
    "/a{ , 3 }/" => "spaces in {,n} quantifier",

    // 3. Newline convention verbs
    "/(*CR)test/" => "CR verb",
    "/(*LF)test/" => "LF verb",
    "/(*CRLF)test/" => "CRLF verb",
    "/(*ANYCRLF)test/" => "ANYCRLF verb",
    "/(*ANY)test/" => "ANY verb",

    // 4. Control verbs
    "/(*MARK:name)test/" => "MARK verb",
    "/(*PRUNE)test/" => "PRUNE verb",
    "/(*SKIP)test/" => "SKIP verb",
    "/(*THEN)test/" => "THEN verb",

    // 5. Encoding verbs
    "/(*UTF8)test/" => "UTF8 verb",
    "/(*UCP)test/" => "UCP verb",
    "/(*UTF)test/" => "UTF verb",

    // 6. Match control verbs
    "/(*NOTEMPTY)test/" => "NOTEMPTY verb",
    "/(*NOTEMPTY_ATSTART)test/" => "NOTEMPTY_ATSTART verb",
    "/(*NO_START_OPT)test/" => "NO_START_OPT verb",

    // 7. \R handling
    "/\\R/" => "\\R char type",
    "/a\\Rb/" => "\\R in pattern",

    // 8. Possessive quantifiers
    "/a++/" => "possessive +",
    "/a*+/" => "possessive *",
    "/a?+/" => "possessive ?",
    "/a{2,5}+/" => "possessive {n,m}",

    // 9. Unicode properties
    "/\\p{L}/" => "\\p{L}",
    "/\\p{Lu}/" => "\\p{Lu}",
    "/\\p{Script=Greek}/" => "\\p{Script=Greek}",
    "/\\p{IsGreek}/" => "\\p{IsGreek}",

    // 10. Callout
    "/(?C)test/" => "callout empty",
    "/(?C1)test/" => "callout with number",
    "/(?C\"arg\")test/" => "callout with string arg",
];

$passed = 0;
$failed = 0;

foreach ($tests as $pattern => $description) {
    try {
        $ast = $regex->parse($pattern);
        echo "✓ PASS: {$description} - {$pattern}\n";
        $passed++;
    } catch (Exception $e) {
        echo "✗ FAIL: {$description} - {$pattern}\n";
        echo "  Error: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
