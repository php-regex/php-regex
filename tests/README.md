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
- Unit: `phpunit --testsuite Unit`
- Integration: `phpunit --testsuite Integration`
- Functional: `phpunit --testsuite Functional`
- All: `phpunit`

### CI/CD
- Tests run in GitHub Actions (see `.github/workflows/tests.yml`, and `engines.yml` for the runs on each engine).
- Lint and static analysis via PHPStan and PHPLint.

For contributions, ensure new tests fit the pyramid: prefer Unit > Integration > Functional.