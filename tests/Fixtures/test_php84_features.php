<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpRegex\Toolkit\Regex;

$regex = Regex::create();

echo "Testing PHP 8.4+ regex features...\n\n";

$features = [
    // 1. {,n} quantifier syntax
    '{,n} quantifier' => [
        'patterns' => ['/a{,3}/', '/b{,5}/'],
        'description' => 'Patterns like /a{,3}/ should be valid'
    ],
    
    // 2. Spaces in quantifier braces  
    'Spaces in quantifiers' => [
        'patterns' => ['/a{ 2, 5 }/', '/b{ 3 }/', '/c{ , 4 }/'],
        'description' => '{ 2, 5 } with spaces should be valid'
    ],
    
    // 3. Newline convention verbs
    'Newline convention verbs' => [
        'patterns' => ['/(?(*CR)a)/', '/(?(*LF)b)/', '/(?(*CRLF)c)/'],
        'description' => '(*CR), (*LF), (*CRLF) verbs should be valid'
    ],
    
    // 4. Control verbs
    'Control verbs' => [
        'patterns' => ['/(?(*MARK:name)a)/', '/(?(*PRUNE)b)/', '/(?(*SKIP)c)/', '/(?(*THEN)d)/'],
        'description' => '(*MARK), (*PRUNE), (*SKIP), (*THEN) verbs should be valid'
    ],
    
    // 5. Encoding control verbs
    'Encoding control verbs' => [
        'patterns' => ['/(?(*UTF8)a)/', '/(?(*UCP)b)/'],
        'description' => '(*UTF8), (*UCP) verbs should be valid'
    ],
    
    // 6. Match control verbs
    'Match control verbs' => [
        'patterns' => ['/(?(*NOTEMPTY)a)/', '/(?(*NOTEMPTY_ATSTART)b)/'],
        'description' => '(*NOTEMPTY), (*NOTEMPTY_ATSTART) verbs should be valid'
    ],
    
    // 7. \R backreference
    '\\R backreference' => [
        'patterns' => ['/(a)\R/', '/(b)(\R)/'],
        'description' => '\\R should be handled as backreference, not just char type'
    ],
    
    // 8. Possessive quantifiers in char classes
    'Possessive quantifiers in char classes' => [
        'patterns' => ['/[a++]/', '/[b*+]/', '/[c?]/'],
        'description' => 'Possessive quantifiers should work in char classes'
    ],
    
    // 9. Extended \p{...} properties
    'Extended \\p{...} properties' => [
        'patterns' => ['/\p{L}/', '/\p{Script=Greek}/', '/\p{Block=Basic_Latin}/'],
        'description' => 'Extended unicode properties should be validated'
    ],
    
    // 10. (?C) callout
    '(?C) callout syntax' => [
        'patterns' => ['/(?C)/', '/(?C1)/', '/(?C"test")/', '/(?Cname)/'],
        'description' => '(?C) callout syntax should be parsed'
    ]
];

$results = [];

foreach ($features as $featureName => $feature) {
    echo "Testing: {$featureName}\n";
    echo "Description: {$feature['description']}\n";
    
    $featureResults = [];
    
    foreach ($feature['patterns'] as $pattern) {
        echo "  Pattern: {$pattern} - ";
        
        try {
            $ast = $regex->parse($pattern);
            $validation = $regex->validate($pattern);
            
            if ($validation->isValid) {
                echo "✓ PASSED\n";
                $featureResults[] = ['pattern' => $pattern, 'status' => 'passed'];
            } else {
                echo "✗ FAILED (validation): " . $validation->error . "\n";
                $featureResults[] = ['pattern' => $pattern, 'status' => 'failed', 'error' => $validation->error];
            }
        } catch (Exception $e) {
            echo "✗ FAILED (exception): " . $e->getMessage() . "\n";
            $featureResults[] = ['pattern' => $pattern, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }
    
    $results[$featureName] = $featureResults;
    echo "\n";
}

// Summary
echo "=== SUMMARY ===\n";
$allPassed = true;

foreach ($results as $featureName => $featureResults) {
    $passed = count(array_filter($featureResults, fn($r) => $r['status'] === 'passed'));
    $total = count($featureResults);
    $status = $passed === $total ? '✓' : '✗';
    
    echo "{$status} {$featureName}: {$passed}/{$total} passed\n";
    
    if ($passed < $total) {
        $allPassed = false;
        foreach ($featureResults as $result) {
            if ($result['status'] === 'failed') {
                echo "    ✗ {$result['pattern']}: " . ($result['error'] ?? 'Unknown error') . "\n";
            }
        }
    }
}

if ($allPassed) {
    echo "\n🎉 All PHP 8.4+ features are implemented!\n";
} else {
    echo "\n⚠️  Some PHP 8.4+ features need implementation.\n";
}