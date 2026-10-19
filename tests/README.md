# Test Suite Architecture

This test suite follows the **Testing Pyramid** structure, ensuring fast, reliable, and maintainable tests.

## Directory Structure

- **`tests/Unit/`**: Pure unit tests for individual components (classes, methods) without external dependencies. These tests run instantly (milliseconds) and mock any I/O or external systems.
- **`tests/Integration/`**: Tests combining multiple components or verifying interactions with external libraries (e.g., Symfony bridges, PCRE compliance).
- **`tests/Functional/`**: End-to-end tests touching the filesystem, real file I/O, or broader application behavior (e.g., linting fixtures, pattern extraction).
- **`tests/Benchmark/`**: Performance measurement tests (do not modify).
- **`tests/Tools/`**: Utility scripts for test data extraction, validation, or setup.
- **`tests/Fixtures/`**: Shared test data files (e.g., sample regex patterns, fixture files for functional tests).
- **`tests/TestUtils/`**: Helper classes and utilities for tests (e.g., accessors for internal state).

## Best Practices

### General
- All test files use `declare(strict_types=1);`.
- Test classes are `final` and extend `PHPUnit\Framework\TestCase`.
- Test methods use `#[Test]` attributes (PHPUnit 10+).
- Use strict assertions: `assertSame()` instead of `assertEquals()`.
- Test methods follow camelCase: `testMethodName()`.
- Data providers use `#[DataProvider('methodName')]` and are static methods.

### PHPUnit Versions
- The suite runs on PHPUnit 10.5 (PHP 8.1, `tools/phpunit-81`) through the newest major `tools/phpunit` installs. A test that needs a method only the newest PHPUnit has, such as `expectExceptionMessageIsOrContains()`, uses the `PhpUnitForwardCompat` trait from `tests/Support/PhpUnitCompat/`: the native method runs where PHPUnit has it, a polyfill on the older API elsewhere. Its docblock says how to polyfill one more method.

### Unit Tests
- Focus on isolated logic; mock external dependencies (filesystem, network) using PHPUnit mocks or stubs.
- Avoid touching real files or databases.
- Run in milliseconds; no setup/teardown unless necessary.

### Integration Tests
- Test component interactions, including bridges to frameworks.
- May use real dependencies if mocking is complex, but prefer isolation.

### Functional Tests
- Test real-world scenarios, including I/O operations.
- Use fixtures from `tests/Fixtures/` for reproducible data.
- Keep fixture loading clean and minimal.

### Running Tests
- All, in one process: `composer phpunit`
- All, in parallel: `composer test:parallel` (ParaTest, one process per CPU core; `--processes=N` to choose)
- One directory or one file: `composer phpunit -- tests/Unit/Lexer`

A test runs alone in a process or beside any other test class: each test
writes its files under a temporary directory of its own, and resets in
`tearDown()` any static state it changes.

### CI/CD
- Tests run in GitHub Actions (see `.github/workflows/tests.yml`, and `engines.yml` for the runs on each engine).
- The engine claims, `tests/Fixtures/EngineClaims/engine-claims.json`, are what the library claims of the engines PHP 7.3 to 8.6 run; `engines.yml` checks them inside each official `php:X-cli` image with `tests/Tools/verify_engine_claims.php`, which needs no dependency and runs on PHP 7.3. When a verdict moves, `EngineClaimsTest` fails until `php tests/Tools/write_engine_claims.php` writes them again.
- Lint and static analysis via PHPStan and PHPLint.

For contributions, ensure new tests fit the pyramid: prefer Unit > Integration > Functional.