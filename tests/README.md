# Test Suite Architecture

This test suite follows the **Testing Pyramid** structure, ensuring fast, reliable, and maintainable tests.

## Directory Structure

- **`tests/Unit/`**: Pure unit tests for individual components (classes, methods) without external dependencies. These tests run instantly (milliseconds) and mock any I/O or external systems.
- **`tests/Integration/`**: Tests combining multiple components or verifying interactions with external libraries (e.g., Symfony bridges, PCRE compliance).
- **`tests/Functional/`**: End-to-end tests touching the filesystem, real file I/O, or broader application behavior (e.g., linting fixtures, pattern extraction).
- **`tests/Documentation/`**: Tests that hold the documentation to the code: the examples call methods that exist, import only public classes, and the tables list what the code holds.
- **`tests/Benchmark/`**: PHPBench benchmarks, run with `composer bench`; they are not PHPUnit tests.
- **`tests/Tools/`**: Scripts that write or check generated files, such as `update_cache_version.php` and `write_config_schema.php`.
- **`tests/Fixtures/`**: Shared test data files (e.g., sample regex patterns, fixture files for functional tests).
- **`tests/Support/`**: Traits and helpers the tests share, such as `PcreGates`, which skips a row the running PCRE2 or PHP does not read, with a message that says why.
- **`tests/TestUtils/`**: Smaller helpers (an in-memory cache pool, the coverage threshold, the engine claims).

## Best Practices

### General
- All test files use `declare(strict_types=1);`.
- Test classes are `final` and extend `PHPUnit\Framework\TestCase`.
- Test methods carry the `#[Test]` attribute and are named `test_{feature}_{scenario}` in snake case: `test_callout_number_past_255_is_refused_where_pcre_refuses_it()`.
- Use strict assertions: `assertSame()` instead of `assertEquals()`.
- Data providers are static methods named `provide...()` that return `iterable` and yield keyed rows: the row's name as its key, each argument by name (`yield 'a reversed range' => ['pattern' => '/a{2,1}/', 'valid' => false];`). A test reads one with `#[DataProvider('provideRanges')]`.
- What a pattern means is checked against PCRE first, in the test itself: run the pattern through `preg_match()` before you assert what the library says about it.

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
- The property names of each PCRE2 release from 10.40, `src/Parser/Syntax/PropertyNameTables.php`, are written by `php tests/Tools/write_property_names.php <release source tree>...` from the release tarballs of https://github.com/PCRE2Project/pcre2/releases, extracted (`--check` writes nothing and fails when the file is out of date). `PropertyNameTablesTest` holds the file to what the script writes and every name to the running engine; add the new release's tree when the library learns a release.
- Lint and static analysis via PHPStan and PHPLint.

For contributions, ensure new tests fit the pyramid: prefer Unit > Integration > Functional.