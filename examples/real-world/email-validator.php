<?php

declare(strict_types=1);

use PhpRegex\Toolkit\Regex;
use PhpRegex\Redos\RedosSeverity;

/**
 * Example: Email validation with RegexParser
 *
 * This example demonstrates:
 * - Email pattern validation
 * - Error handling with user-friendly messages
 * - ReDoS risk checking
 * - Testing with real email addresses
 */

require_once __DIR__ . '/../vendor/autoload.php';

$emailPattern = '/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i';

echo "=== Email Validation ===\n";
echo "Pattern: {$emailPattern}\n\n";

$regex = Regex::create();

// 1. Validate pattern
$validation = $regex->validate($emailPattern);

if (!$validation->isValid) {
    echo "✗ Pattern is invalid\n";
    echo "Error: {$validation->error}\n";
    if ($validation->hint !== null && $validation->hint !== '') {
        echo "Hint: {$validation->hint}\n";
    }
    echo "\n";
    exit(1);
}

echo "✓ Pattern is valid\n";
echo "Complexity Score: {$validation->complexityScore}\n\n";

// 2. Check ReDoS risk
$redos = $regex->redos($emailPattern);

$severityOrder = [RedosSeverity::Safe, RedosSeverity::Low, RedosSeverity::Medium, RedosSeverity::High, RedosSeverity::Critical];
$riskLevel = array_search($redos->severity, $severityOrder, true);

if ($riskLevel >= 2) {  // MEDIUM or worse
    echo "⚠️  ReDoS Risk: {$redos->severity->value}\n";
    echo "Consider using possessive quantifiers or atomic groups.\n\n";
} else {
    echo "✓ ReDoS Risk: {$redos->severity->value}\n\n";
}

// 3. Test with real email addresses
$emails = [
    'user@example.com',
    'user.name@example.com',
    'user+tag@example.com',
    'a@b.co',
    'invalid@',           // Should fail
    'user@example..com',   // Should fail
    'user@.com',         // Should fail
    'user@exam ple.com',   // Should fail (space)
];

echo "=== Testing with Email Addresses ===\n";

foreach ($emails as $email) {
    $isMatch = preg_match($emailPattern, $email) === 1;
    $status = $isMatch ? '✓' : '✗';

    echo "{$status} {$email}\n";
}

// Expected output:
// ✓ user@example.com
// ✓ user.name@example.com
// ✓ user+tag@example.com
// ✓ a@b.co
// ✗ invalid@
// ✗ user@example..com
// ✗ user@.com
// ✗ user@exam ple.com

echo "\n=== Recommendations ===\n\n";
echo "1. This is a simplified email pattern (RFC 5322 compliant but simplified)\n";
echo "2. For production use, consider more robust email validation libraries:\n";
echo "   - eguliasas/email-validator\n";
echo "   - laminas/laminas-validator\n";
echo "   - symfony/mailer (EmailValidator)\n";
echo "3. For strict RFC compliance, use more complex patterns with proper TLD validation\n";
