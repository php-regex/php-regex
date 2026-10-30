<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\LanguageServer;

use PHPRegex\LanguageServer\Document\PatternDeclarations;
use PHPRegex\LanguageServer\Protocol\TraceLevel;
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\Linter\Config\LintArguments;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintConfigResult;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;

/**
 * What the "initialize" request says of the workspace: its root, the first
 * workspace folder else rootUri, regex.json there, and the
 * initializationOptions the server reads.
 *
 * The target is initializationOptions.phpVersion / pcreVersion, then
 * regex.json, then composer.json at the root, then the running PHP; the
 * LC_CTYPE locale initializationOptions.locale, then regex.json, then the
 * runtime's. The ReDoS analysis runs when initializationOptions.redos, else
 * checks.redos.enabled in regex.json, turns it on. A value that cannot be
 * read is a warning on the editor's log, and the next source is read.
 *
 * @phpstan-import-type LintConfig from LintConfigResult
 *
 * @internal
 */
final class WorkspaceSettings
{
    /**
     * @param LintConfig              $config  regex.json at the root, empty when there is none
     * @param array<array-key, mixed> $options the initializationOptions
     */
    private function __construct(
        public readonly ?string $root,
        public readonly array $config,
        private readonly array $options,
        private readonly Transport $transport,
    ) {}

    /**
     * The workspace of the "initialize" params; regex.json there, a file
     * that cannot be read being an error shown to the user, and no
     * configuration: the server reads as without the file, every
     * extraction preset included, rather than a part of it.
     *
     * @param array<array-key, mixed> $params the "initialize" params
     */
    public static function read(array $params, Transport $transport): self
    {
        $root = self::rootDirectory($params);
        $options = \is_array($params['initializationOptions'] ?? null) ? $params['initializationOptions'] : [];

        return new self($root, null === $root ? [] : self::loadConfig($root, $transport), $options, $transport);
    }

    /**
     * The frame log's level "initialize" asks for: initializationOptions.trace,
     * else the protocol's "trace", else off. A value that is not a level is
     * ignored, with a warning, and the next source is read.
     *
     * @param array<array-key, mixed> $params the "initialize" params
     */
    public static function traceLevel(array $params, Transport $transport): TraceLevel
    {
        $options = \is_array($params['initializationOptions'] ?? null) ? $params['initializationOptions'] : [];

        foreach (['initializationOptions.trace' => $options['trace'] ?? null, 'trace' => $params['trace'] ?? null] as $source => $value) {
            if (null === $value) {
                continue;
            }

            $level = \is_string($value) ? TraceLevel::tryFrom($value) : null;
            if (null !== $level) {
                return $level;
            }

            self::log($transport, Transport::LOG_WARNING, \sprintf('%s %s is not off, messages or verbose: ignored.', $source, \is_string($value) ? '"'.$value.'"' : get_debug_type($value)));
        }

        return TraceLevel::Off;
    }

    /**
     * The lint rules, the calls read and the ReDoS options of regex.json, as
     * `regex lint` reads them.
     */
    public function lintArguments(): LintArguments
    {
        return LintArguments::fromDefaults((new LintDefaultsBuilder())->build($this->config));
    }

    /**
     * The ReDoS analysis to run: none unless initializationOptions.redos,
     * else checks.redos.enabled in regex.json, turns it on; the mode and
     * the threshold of regex.json, high by default, as `regex lint` reads
     * them.
     *
     * @return array{RedosMode, RedosSeverity}
     */
    public function redos(LintArguments $arguments): array
    {
        $enabled = \is_bool($this->options['redos'] ?? null) ? $this->options['redos'] : $arguments->checkRedos;
        $threshold = RedosSeverity::tryFrom($arguments->redosThreshold ?? '');

        return [
            $enabled ? (RedosMode::tryFrom($arguments->redosMode) ?? RedosMode::Theoretical) : RedosMode::Off,
            null === $threshold || RedosSeverity::Safe === $threshold || RedosSeverity::Unknown === $threshold ? RedosSeverity::High : $threshold,
        ];
    }

    /**
     * The PHP, the PCRE2 and the locale the workspace's patterns are judged
     * for.
     *
     * @throws UnsupportedTargetException  when the PHP named ships PCRE1
     * @throws InvalidRegexOptionException when regex.json names a locale this machine cannot set
     */
    public function target(): ProjectTarget
    {
        $configPhp = $this->config['phpVersion'] ?? null;
        $configPcre = $this->config['pcreVersion'] ?? null;
        $configLocale = $this->config['locale'] ?? null;
        $pcre = $this->readOption('pcreVersion', 'pcre_version');
        $locale = $this->readOption('locale', 'locale', 'a locale this machine can set');

        // regex.json was checked against its schema when it was loaded.
        return ProjectTarget::fromSources(
            [
                'initializationOptions' => $this->readOption('phpVersion', 'php_version'),
                'regex.json' => \is_string($configPhp) || \is_int($configPhp) ? $configPhp : null,
            ],
            [
                'initializationOptions' => \is_string($pcre) ? $pcre : null,
                'regex.json' => \is_string($configPcre) ? $configPcre : null,
            ],
            $this->root,
            getenv(),
            [
                'initializationOptions' => \is_string($locale) ? $locale : null,
                'regex.json' => \is_string($configLocale) ? $configLocale : null,
            ],
        );
    }

    /**
     * The paths regex.json names, none when it names none: the root.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return self::stringList($this->config['paths'] ?? null);
    }

    /**
     * The paths regex.json leaves out, vendor/ when it names none.
     *
     * @return list<string>
     */
    public function exclude(): array
    {
        return \array_key_exists('exclude', $this->config) ? self::stringList($this->config['exclude']) : ['vendor'];
    }

    /**
     * An initializationOptions version or locale, or null when it is unset
     * or cannot be read: what cannot be read is a warning, and the next
     * source is used.
     *
     * @param string $what what the value must be, for the warning
     */
    private function readOption(string $key, string $regexOption, string $what = 'a version'): string|int|null
    {
        $value = $this->options[$key] ?? null;
        if (null === $value) {
            return null;
        }

        if (\is_string($value) || \is_int($value)) {
            try {
                ParserOptions::fromArray([$regexOption => $value]);

                return $value;
            } catch (InvalidRegexOptionException) {
                // Reported below, like a value of the wrong type.
            }
        }

        self::log($this->transport, Transport::LOG_WARNING, \sprintf(
            'initializationOptions.%s %s is not %s: ignored.',
            $key,
            \is_string($value) || \is_int($value) ? '"'.$value.'"' : get_debug_type($value),
            $what,
        ));

        return null;
    }

    /**
     * The directory of the first workspace folder, else of rootUri; null
     * when neither is a local directory.
     *
     * @param array<array-key, mixed> $params
     */
    private static function rootDirectory(array $params): ?string
    {
        $folders = $params['workspaceFolders'] ?? null;
        $uri = \is_array($folders) && \is_array($folders[0] ?? null) ? ($folders[0]['uri'] ?? null) : null;
        $uri ??= $params['rootUri'] ?? null;

        $path = \is_string($uri) ? PatternDeclarations::pathOf($uri) : null;

        return null !== $path && is_dir($path) ? $path : null;
    }

    /**
     * regex.json at the root, empty when it cannot be read.
     *
     * @return LintConfig
     */
    private static function loadConfig(string $root, Transport $transport): array
    {
        $loaded = (new LintConfigLoader())->load($root);
        if (null !== $loaded->error) {
            $message = 'regex.json ignored: '.$loaded->error;
            self::log($transport, Transport::LOG_ERROR, $message);
            $transport->notification('window/showMessage', ['type' => Transport::LOG_ERROR, 'message' => $message]);

            return [];
        }

        // What the file holds that is ignored, the rest of it kept.
        foreach ($loaded->warnings as $warning) {
            self::log($transport, Transport::LOG_WARNING, 'regex.json: '.$warning);
        }

        return $loaded->config;
    }

    /**
     * A path of regex.json, or its list of paths, as a list.
     *
     * @param string|list<string>|null $value
     *
     * @return list<string>
     */
    private static function stringList(string|array|null $value): array
    {
        return \is_string($value) ? [$value] : $value ?? [];
    }

    private static function log(Transport $transport, int $type, string $message): void
    {
        $transport->notification('window/logMessage', ['type' => $type, 'message' => $message]);
    }
}
