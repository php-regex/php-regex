# PHPRegex demo applications

Everything needed to see the library working, in one place.

- **[examples/](examples/)** — standalone PHP scripts: validation, parsing,
  ReDoS analysis, a real-world email validator. Each script runs on its own:

  ```bash
  php demo/examples/basic/validate.php
  ```

- **[symfony/](symfony/)** — a complete Symfony application (created from
  `symfony/skeleton`) with the PHPRegex bundle enabled: a demo endpoint, a
  route whose requirement is itself a regex, and a test suite that boots the
  real kernel and registers the `regex:*` console commands.

- **[laravel/](laravel/)** — a complete Laravel application with the package
  auto-discovered by Composer: demo routes behind the facade, artisan
  commands, and a feature test suite.

Both applications consume this repository through a Composer path repository
(the `repositories` entry of their `composer.json` points at the repository
root), so what you run is always the code from this checkout — never a
released version.

The Symfony application tracks the latest stable release resolved when it was
created; the Laravel application is pinned to the major release the Laravel
bridge supports.

## Running

```bash
# Symfony (PHP 8.4+)
composer install -d demo/symfony
php demo/symfony/bin/console list --raw | grep 'regex:'   # the six bridge commands
(cd demo/symfony && bin/phpunit)

# Laravel (PHP 8.2+)
composer install -d demo/laravel
cp -n demo/laravel/.env.example demo/laravel/.env
php demo/laravel/artisan key:generate
(cd demo/laravel && vendor/bin/phpunit)
```

Both suites also run in CI on every change that touches the bridges or the
applications themselves, and once a week to catch drift in the frameworks.
