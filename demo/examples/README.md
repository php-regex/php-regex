# PHPRegex Examples

This directory contains ready-to-use examples demonstrating PHPRegex's features.

## Basic Examples

Introduction to core functionality:

- [validate.php](basic/validate.php) - Validate patterns and handle errors gracefully
- [parse.php](basic/parse.php) - Parse patterns and explore the AST

## Advanced Examples

More complex scenarios and in-depth analysis:

- [redos-analysis.php](advanced/redos-analysis.php) - ReDoS detection, mitigation strategies, and performance benchmarking

## Symfony Integration

Examples specifically for Symfony applications:

- [route-analyzer.php](symfony/route-analyzer.php) - Check the requirements of Symfony routes, as the route compiler anchors them, for errors and ReDoS risks

## Real-World Examples

Practical implementations for common use cases:

- [email-validator.php](real-world/email-validator.php) - Complete email validation with error handling

## Running the Examples

Each example includes:

```bash
# From the project root
php demo/examples/basic/validate.php
php demo/examples/advanced/redos-analysis.php
php demo/examples/real-world/email-validator.php
```

## Requirements

- PHP 8.2 or higher
- PHPRegex installed via Composer

```bash
composer install
```

## Learning Resources

- [Quick Start Guide](../../docs/quick-start.md)
- [API Reference](../../docs/reference/README.md)
- [ReDoS Guide](../../docs/guides/redos.md)
- [Cookbook](../../docs/cookbook.md)
