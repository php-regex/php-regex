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

namespace PHPRegex\Symfony\Security;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Symfony\Exception\SecurityConfigException;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ExceptionInterface as DependencyInjectionException;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the access_control rules and the firewalls of the security
 * configuration. Inside an application it reads what the kernel processes,
 * as debug:config shows it: every file format the kernel loads, every YAML
 * shape, the parameters resolved. A file named on the command line is read
 * with symfony/yaml.
 *
 * The configuration carries no line numbers: each entry is located in the
 * security files by the value it is written with, and has no location (an
 * empty file, line 0) when no file writes it.
 *
 * @internal
 *
 * @phpstan-type AccessControlRule array{
 *     file: string,
 *     line: int,
 *     path: ?string,
 *     host: ?string,
 *     roles: array<int, string>,
 *     methods: array<int, string>,
 *     ips: array<int, string>,
 *     allowIf: ?string,
 *     requestMatcher: ?string,
 *     requiresChannel: ?string,
 *     port?: ?int,
 *     attributes?: array<string, string>,
 *     route?: ?string,
 *     fromEnvironment?: list<string>,
 * }
 * @phpstan-type FirewallRule array{
 *     name: string,
 *     file: string,
 *     line: int,
 *     pattern: ?string,
 *     requestMatcher: ?string,
 *     fromEnvironment?: bool,
 * }
 */
final class SecurityConfigExtractor
{
    private const ROOT = 'security';

    /**
     * The security configuration to analyze: the files named on the command
     * line when there are some, else the kernel's, else (without a kernel)
     * the YAML files found in the project. Null when there is none to read.
     *
     * @param list<string> $configPaths the files named on the command line
     *
     * @throws SecurityConfigException
     *
     * @return array{accessControl: list<AccessControlRule>, firewalls: list<FirewallRule>, skippedFiles: list<array{file: string, reason: string}>}|null
     */
    public function read(array $configPaths, ?KernelInterface $kernel, SecurityConfigLocator $locator, ?string $projectDir, ?string $environment): ?array
    {
        if ([] !== $configPaths) {
            return $this->readFiles($configPaths, $environment, null === $kernel ? null : self::bootedContainer($kernel), true);
        }

        if (null !== $kernel) {
            $config = $this->extractFromKernel($kernel, $locator);

            return null === $config ? null : $config + ['skippedFiles' => []];
        }

        $located = $locator->locate($projectDir, $environment);

        return [] === $located ? null : $this->readFiles($located, $environment, null, false);
    }

    /**
     * The security configuration the kernel processes, read the way
     * debug:config reads it. Null when the kernel has none.
     *
     * @throws SecurityConfigException
     *
     * @return array{accessControl: list<AccessControlRule>, firewalls: list<FirewallRule>}|null
     */
    public function extractFromKernel(KernelInterface $kernel, SecurityConfigLocator $locator = new SecurityConfigLocator()): ?array
    {
        if (!$kernel instanceof Kernel) {
            throw new SecurityConfigException(\sprintf('The security configuration of a %s cannot be read: name its files with --config.', get_debug_type($kernel)));
        }

        $container = self::uncompiledContainer($kernel);
        if (!$container->hasExtension(self::ROOT)) {
            return null;
        }

        $configs = $container->getExtensionConfig(self::ROOT);
        $extension = $container->getExtension(self::ROOT);
        $configuration = $extension instanceof ConfigurationExtensionInterface ? $extension->getConfiguration($configs, $container) : null;
        if ([] === $configs || null === $configuration) {
            return null;
        }

        // An environment value is resolved at run time only, in the
        // environment the application runs in: the bag gives it a
        // placeholder, and it is left unknown, never read from the
        // environment the analysis runs in.
        $parameters = new EnvPlaceholderParameterBag($container->getParameterBag()->all());
        $placeholders = new ContainerBuilder($parameters);

        // MergeExtensionConfigurationPass resolves a parameter before the
        // bundle reads it, and has the bundle's nodes take an environment
        // value through a placeholder prefix it gives BaseNode, a boolean
        // node as well as a path. Each value holding a parameter or an
        // environment value is read so, through a placeholder of that
        // prefix, and comes out as written: its entry is found in the files
        // by it, and it is resolved then.
        //
        // No public API does this: the pass runs the load() of every
        // extension and gives no processed configuration back, and
        // ValidateEnvPlaceholdersPass gives its own only through an @internal
        // getter, resolved, without the text each entry is found by. The
        // prefix is set and cleared through BaseNode, @internal too, here
        // alone, for this one processing (checked on Symfony 6.4.0, 7.4.0
        // and 8.1.11).
        $prefix = $parameters->getEnvPlaceholderUniquePrefix();
        $written = [];
        array_walk_recursive($configs, static function (&$value, int|string $key) use ($parameters, $prefix, &$written): void {
            try {
                if (!\is_string($value) || $value === $resolved = $parameters->resolveValue($value)) {
                    return;
                }
            } catch (DependencyInjectionException) {
                // Left as written: resolving it again says why it cannot be.
                return;
            }

            // The bundle splits a string of roles or methods once the pass
            // has resolved it: it is given resolved, and its entry is found
            // by what else it writes.
            if (\is_string($resolved) && \in_array($key, ['roles', 'role', 'methods', 'method'], true)) {
                $value = $resolved;

                return;
            }

            $placeholder = $prefix.'_written_'.\count($written);
            $written[$placeholder] = $value;
            $value = $placeholder;
        });

        BaseNode::setPlaceholderUniquePrefix($prefix);

        try {
            $processed = (new Processor())->processConfiguration($configuration, $configs);
        } catch (ConfigurationException $e) {
            throw new SecurityConfigException('The security configuration is invalid: '.$e->getMessage(), previous: $e);
        } finally {
            BaseNode::resetPlaceholders();
        }

        // A node may join placeholders, as a list of firewall patterns is
        // joined into one alternation: each is written back where it stands.
        array_walk_recursive($processed, static function (&$value) use ($written): void {
            if (\is_string($value)) {
                $value = strtr($value, $written);
            }
        });

        $resolve = static function (string $value) use ($parameters, $placeholders): array {
            try {
                $resolved = $parameters->unescapeValue($parameters->resolveValue($value));
            } catch (DependencyInjectionException $e) {
                throw new SecurityConfigException(\sprintf('The security configuration value "%s" cannot be resolved: %s', $value, $e->getMessage()), previous: $e);
            }

            return self::written($placeholders, self::text($resolved) ?? throw self::notAString($value));
        };

        return $this->build([$processed], $locator->locate($kernel->getProjectDir(), $kernel->getEnvironment()), $resolve);
    }

    /**
     * A YAML security file: its root "security" key, then the one under
     * "when@<environment>" (under every "when@" block when no environment is
     * given). A "%parameter%" is read from the file's "parameters", then
     * from the container given.
     *
     * @throws SecurityConfigException
     *
     * @return array{accessControl: list<AccessControlRule>, firewalls: list<FirewallRule>}
     */
    public function extract(string $path, ?string $environment = null, ?ContainerInterface $container = null): array
    {
        if (!class_exists(Yaml::class)) {
            throw new SecurityConfigException('Reading a YAML security file needs symfony/yaml: composer require symfony/yaml');
        }

        try {
            $data = Yaml::parseFile($path, Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $e) {
            throw new SecurityConfigException(\sprintf('The security file "%s" cannot be read: %s', $path, $e->getMessage()), previous: $e);
        }

        $data = \is_array($data) ? $data : [];
        $configs = \is_array($data[self::ROOT] ?? null) ? [$data[self::ROOT]] : [];
        foreach ($data as $key => $block) {
            if (\is_string($key) && str_starts_with($key, 'when@')
                && (null === $environment || 'when@'.$environment === $key)
                && \is_array($block) && \is_array($block[self::ROOT] ?? null)) {
                $configs[] = $block[self::ROOT];
            }
        }

        // The parameters a value may use: a scalar. A list or a tagged value
        // (a service closure, a tagged iterator) is never a path.
        $declared = \is_array($data['parameters'] ?? null) ? $data['parameters'] : [];
        // An environment value is left unknown, as in the kernel.
        $parameters = new EnvPlaceholderParameterBag(array_filter($declared, \is_scalar(...)));
        $placeholders = new ContainerBuilder($parameters);
        $resolve = static function (string $value) use ($parameters, $placeholders, $container, $path): array {
            while (true) {
                try {
                    return self::written($placeholders, self::text($parameters->unescapeValue($parameters->resolveValue($value))) ?? throw self::notAString($value));
                } catch (ParameterNotFoundException $e) {
                    $name = $e->getKey();
                    $found = null !== $container && $container->hasParameter($name) ? $container->getParameter($name) : null;
                    if (!\is_scalar($found)) {
                        throw new SecurityConfigException(\sprintf('The parameter "%s" used in "%s" is defined neither in the file nor in the application.', $name, $path), previous: $e);
                    }

                    $parameters->set($name, $found);
                }
            }
        };

        return $this->build($configs, [$path], $resolve);
    }

    /**
     * @param list<string> $paths
     *
     * @throws SecurityConfigException
     *
     * @return array{accessControl: list<AccessControlRule>, firewalls: list<FirewallRule>, skippedFiles: list<array{file: string, reason: string}>}
     */
    private function readFiles(array $paths, ?string $environment, ?ContainerInterface $container, bool $named): array
    {
        $accessControl = [];
        $firewalls = [];
        $skippedFiles = [];

        foreach ($paths as $path) {
            if (!is_file($path)) {
                $skippedFiles[] = ['file' => $path, 'reason' => 'File not found.'];

                continue;
            }

            if (!AccessRuleSummary::isYamlFile($path)) {
                if ($named) {
                    throw new SecurityConfigException(\sprintf('Only YAML files can be read with --config, not "%s": without --config, the command reads the security configuration of the application, whatever its format.', $path));
                }

                $skippedFiles[] = ['file' => $path, 'reason' => 'Only YAML files are read without a kernel.'];

                continue;
            }

            $config = $this->extract($path, $environment, $container);
            $accessControl = [...$accessControl, ...$config['accessControl']];
            $firewalls = [...$firewalls, ...$config['firewalls']];
        }

        if ($named && \count($skippedFiles) === \count($paths)) {
            throw new SecurityConfigException('None of the provided --config files could be found.');
        }

        return ['accessControl' => $accessControl, 'firewalls' => $firewalls, 'skippedFiles' => $skippedFiles];
    }

    /**
     * @param list<array<array-key, mixed>>               $configs the security configurations, in the order they apply
     * @param list<string>                                $files   the files to locate each entry in
     * @param \Closure(string): array{0: string, 1: bool} $resolve resolves the parameters of a value, and says whether it reads the environment
     *
     * @return array{accessControl: list<AccessControlRule>, firewalls: list<FirewallRule>}
     */
    private function build(array $configs, array $files, \Closure $resolve): array
    {
        $sources = self::sources($files);
        $taken = [];

        $accessControl = [];
        $firewalls = [];
        foreach ($configs as $config) {
            $rules = $config['access_control'] ?? null;
            foreach (\is_array($rules) ? $rules : [] as $rule) {
                if (!\is_array($rule)) {
                    continue;
                }

                // The request attributes, by name, as the bundle's map
                // holds them: a scalar value each.
                $attributes = [];
                foreach (\is_array($rule['attributes'] ?? null) ? $rule['attributes'] : [] as $name => $value) {
                    $value = self::text($value);
                    if (null !== $value) {
                        $attributes[(string) $name] = $value;
                    }
                }

                $accessControl[] = $this->accessRule([
                    'path' => self::text($rule['path'] ?? null),
                    'host' => self::text($rule['host'] ?? null),
                    'roles' => \is_string($rule['roles'] ?? null) ? $rule['roles'] : self::listOf($rule['roles'] ?? null, false),
                    'methods' => \is_string($rule['methods'] ?? null) ? $rule['methods'] : self::listOf($rule['methods'] ?? null, false),
                    'ips' => self::listOf($rule['ips'] ?? null, false),
                    'allowIf' => self::text($rule['allow_if'] ?? null),
                    'requestMatcher' => self::text($rule['request_matcher'] ?? null),
                    'requiresChannel' => self::text($rule['requires_channel'] ?? null),
                    'port' => self::text($rule['port'] ?? null),
                    'attributes' => $attributes,
                    'route' => self::text($rule['route'] ?? null),
                ], \count($accessControl) + 1, $sources, $taken, $resolve);
            }

            // A later configuration adds to a firewall an earlier one declared.
            $declared = $config['firewalls'] ?? null;
            foreach (\is_array($declared) ? $declared : [] as $name => $firewall) {
                $firewalls[$name] = array_replace($firewalls[$name] ?? [], \is_array($firewall) ? $firewall : []);
            }
        }

        $firewallRules = [];
        foreach ($firewalls as $name => $firewall) {
            // As the security bundle reads it: a list of patterns is one
            // alternation.
            $pattern = $firewall['pattern'] ?? null;
            $single = self::text($pattern);
            $firewallRules[] = $this->firewallRule((string) $name, [
                'patterns' => \is_array($pattern) ? self::listOf($pattern, false) : (null === $single ? [] : [$single]),
                'alternation' => \is_array($pattern),
                'requestMatcher' => self::text($firewall['request_matcher'] ?? null),
            ], $sources, $taken, $resolve);
        }

        return ['accessControl' => $accessControl, 'firewalls' => $firewallRules];
    }

    /**
     * @param array{path: ?string, host: ?string, roles: string|list<string>, methods: string|list<string>, ips: list<string>, allowIf: ?string, requestMatcher: ?string, requiresChannel: ?string, port: ?string, attributes: array<string, string>, route: ?string} $rule
     * @param list<array{file: string, lines: array<int, string>}>                                                                                                                                                                                                    $sources
     * @param array<int, array<int, bool>>                                                                                                                                                                                                                            $taken
     * @param \Closure(string): array{0: string, 1: bool}                                                                                                                                                                                                             $resolve
     *
     * @throws SecurityConfigException
     *
     * @return AccessControlRule
     */
    private function accessRule(array $rule, int $index, array $sources, array &$taken, \Closure $resolve): array
    {
        $matchers = [
            ...self::valueMatchers($rule['path'], 'path'),
            ...self::valueMatchers($rule['host'], 'host'),
            ...self::valueMatchers($rule['route'], 'route'),
            ...self::valueMatchers($rule['requestMatcher'], 'request_matcher'),
            ...self::valueMatchers($rule['allowIf'], 'allow_if'),
        ];
        if ([] === $matchers) {
            // A rule with none of these, "{ roles: ROLE_USER }", is found by
            // what else it writes.
            $matchers = self::entryMatchers([
                'roles' => self::listOf($rule['roles'], true),
                'ips' => $rule['ips'],
                'methods' => self::listOf($rule['methods'], true),
                'requires_channel' => null === $rule['requiresChannel'] ? [] : [$rule['requiresChannel']],
            ]);
        }

        [$file, $line] = self::locate($sources, $taken, $matchers);

        // The fields holding a value read from the environment, in order.
        $fromEnvironment = [];
        $read = static function (string $value, string $field) use ($resolve, &$fromEnvironment): string {
            [$text, $environment] = $resolve($value);
            if ($environment && !\in_array($field, $fromEnvironment, true)) {
                $fromEnvironment[] = $field;
            }

            return $text;
        };

        $path = null === $rule['path'] ? null : $read($rule['path'], 'path');
        $host = null === $rule['host'] ? null : $read($rule['host'], 'host');
        $roles = self::resolvedList($rule['roles'], $read, 'roles');
        $methods = self::resolvedList($rule['methods'], $read, 'methods');
        $port = null === $rule['port'] ? null : $read($rule['port'], 'port');

        // SecurityExtension builds the port matcher, and upper-cases the
        // methods, when the container is compiled: from the environment, a
        // port is no integer and the methods lose the value, and the
        // application does not boot (Symfony 6.4, 7.4 and 8.1).
        $where = \sprintf('access_control rule %d (%s)', $index, '' === $file ? 'security configuration' : $file.':'.$line);
        if (\in_array('port', $fromEnvironment, true)) {
            throw new SecurityConfigException(\sprintf('The security configuration is invalid: the port of %s, "%s", is read from the environment, which the security bundle refuses: it builds the port matcher when the container is compiled, from an integer only.', $where, $port));
        }
        if (\in_array('methods', $fromEnvironment, true)) {
            throw new SecurityConfigException(\sprintf('The security configuration is invalid: the methods of %s, "%s", are read from the environment, which the security bundle refuses: it upper-cases them when the container is compiled, which loses the environment value.', $where, implode(', ', $methods)));
        }

        return [
            'file' => $file,
            'line' => $line,
            'path' => $path,
            'host' => $host,
            'roles' => $roles,
            'methods' => $methods,
            'ips' => array_map(static fn (string $ip): string => $read($ip, 'ips'), $rule['ips']),
            // SecurityExtension takes an allow_if when it is truthy, and a
            // request_matcher and a channel as written.
            'allowIf' => '' === $rule['allowIf'] || '0' === $rule['allowIf'] ? null : $rule['allowIf'],
            'requestMatcher' => $rule['requestMatcher'],
            'requiresChannel' => $rule['requiresChannel'],
            'port' => null === $port ? null : self::port($port),
            'attributes' => array_map(static fn (string $value): string => $read($value, 'attributes'), $rule['attributes']),
            'route' => null === $rule['route'] ? null : $read($rule['route'], 'route'),
            'fromEnvironment' => $fromEnvironment,
        ];
    }

    /**
     * @param array{patterns: list<string>, alternation: bool, requestMatcher: ?string} $firewall
     * @param list<array{file: string, lines: array<int, string>}>                      $sources
     * @param array<int, array<int, bool>>                                              $taken
     * @param \Closure(string): array{0: string, 1: bool}                               $resolve
     *
     * @return FirewallRule
     */
    private function firewallRule(string $name, array $firewall, array $sources, array &$taken, \Closure $resolve): array
    {
        $patterns = $firewall['patterns'];

        [$file, $line] = self::locate($sources, $taken, [
            ...self::valueMatchers($patterns[0] ?? null, 'pattern'),
            self::keyMatcher($name),
        ]);

        $fromEnvironment = false;
        $read = static function (string $value) use ($resolve, &$fromEnvironment): string {
            [$text, $environment] = $resolve($value);
            $fromEnvironment = $fromEnvironment || $environment;

            return $text;
        };

        $pattern = null;
        if ($firewall['alternation']) {
            $pattern = \sprintf('(?:%s)', implode('|', array_map($read, $patterns)));
        } elseif ([] !== $patterns) {
            $pattern = $read($patterns[0]);
        }

        return [
            'name' => $name,
            'file' => $file,
            'line' => $line,
            'pattern' => $pattern,
            'requestMatcher' => $firewall['requestMatcher'],
            'fromEnvironment' => $fromEnvironment,
        ];
    }

    /**
     * The roles or methods of a rule: a string resolved first, then split
     * on its commas, as the security bundle splits it once
     * MergeExtensionConfigurationPass has resolved it; each item of a list
     * resolved alone.
     *
     * @param string|list<string>              $written
     * @param \Closure(string, string): string $read
     *
     * @return list<string>
     */
    private static function resolvedList(string|array $written, \Closure $read, string $field): array
    {
        if (\is_string($written)) {
            return self::listOf($read($written, $field), true);
        }

        return array_map(static fn (string $item): string => $read($item, $field), $written);
    }

    /**
     * A value resolved, one read from the environment written as the
     * configuration writes it, "%env(APP_PORT)%", and said to be so.
     *
     * @return array{0: string, 1: bool}
     */
    private static function written(ContainerBuilder $container, string $resolved): array
    {
        $environment = [];
        $written = $container->resolveEnvPlaceholders($resolved, '%%env(%s)%%', $environment);

        return [\is_string($written) ? $written : $resolved, [] !== $environment];
    }

    /**
     * A copy of the kernel, its bundles registered anew, builds the
     * container without compiling it, as debug:config does: the extension
     * configurations are then the ones the files declare, and the running
     * kernel is left untouched.
     */
    private static function uncompiledContainer(Kernel $kernel): ContainerBuilder
    {
        $copy = clone $kernel;
        $build = \Closure::bind(function (): ContainerBuilder {
            $this->initializeBundles();

            return $this->buildContainer();
        }, $copy, Kernel::class) ?? throw new SecurityConfigException('The kernel cannot build its container.');

        return $build();
    }

    /**
     * The parameters of the application a file named with --config may use.
     */
    private static function bootedContainer(KernelInterface $kernel): ?ContainerInterface
    {
        try {
            return $kernel->getContainer();
        } catch (\LogicException) {
            // A boundary: a kernel that is not booted refuses to give its
            // container, and the file then has its own parameters only.
            return null;
        }
    }

    /**
     * The lines of each file, comments blanked out; none for a file that
     * cannot be read.
     *
     * @param list<string> $files
     *
     * @return list<array{file: string, lines: array<int, string>}>
     */
    private static function sources(array $files): array
    {
        $sources = [];
        foreach ($files as $file) {
            $sources[] = [
                'file' => $file,
                'lines' => array_map(
                    static fn (string $line): string => 1 === LibraryPcre::match('~^\s*(?:#|//)~', $line) ? '' : $line,
                    @file($file, \FILE_IGNORE_NEW_LINES) ?: [],
                ),
            ];
        }

        return $sources;
    }

    /**
     * The first line a matcher accepts that no earlier entry took; when every
     * such line is taken (an alias repeats an entry), the first one taken.
     *
     * @param list<array{file: string, lines: array<int, string>}> $sources
     * @param array<int, array<int, bool>>                         $taken    the lines taken, by source and line
     * @param list<\Closure(string): bool>                         $matchers in the order to try them
     *
     * @return array{0: string, 1: int}
     */
    private static function locate(array $sources, array &$taken, array $matchers): array
    {
        foreach ([true, false] as $free) {
            foreach ($matchers as $matches) {
                foreach ($sources as $at => $source) {
                    foreach ($source['lines'] as $index => $line) {
                        if (($free && ($taken[$at][$index] ?? false)) || !$matches($line)) {
                            continue;
                        }

                        $taken[$at][$index] = true;

                        return [$source['file'], $index + 1];
                    }
                }
            }
        }

        return ['', 0];
    }

    /**
     * Lines that write a value, first under its key, then anywhere: as
     * written, or with its backslashes doubled as a double-quoted string
     * spells them.
     *
     * @return list<\Closure(string): bool>
     */
    private static function valueMatchers(?string $value, string $key): array
    {
        if (null === $value || '' === $value) {
            return [];
        }

        $writes = static fn (string $line): bool => str_contains($line, $value) || str_contains($line, str_replace('\\', '\\\\', $value));

        // The key as YAML (path:), a PHP array ('path' =>) or a config
        // builder (->path() write it, and not as the end of a longer name:
        // admin_path: writes no path. The key is one of the bundle's own.
        $keyed = '~(?<!\w)'.$key.'\s*(?::|=>|\()~';

        return [
            static fn (string $line): bool => $writes($line) && 1 === LibraryPcre::match($keyed, str_replace(['"', "'"], '', $line)),
            $writes,
        ];
    }

    /**
     * Lines that write these values under their keys, first those that
     * open an entry ("- roles:", "{ roles:", "['roles' =>",
     * "accessControl()->roles("), so that the roles line of an earlier
     * block entry is not taken for a rule that writes the same role, then
     * any.
     *
     * @param array<string, list<string>> $values the values written, by key
     *
     * @return list<\Closure(string): bool>
     */
    private static function entryMatchers(array $values): array
    {
        $keyed = [];
        foreach ($values as $key => $written) {
            foreach ($written as $value) {
                $keyed = [...$keyed, ...\array_slice(self::valueMatchers($value, $key), 0, 1)];
            }
        }

        $opening = [];
        foreach ($keyed as $writes) {
            $opening[] = static fn (string $line): bool => 1 === LibraryPcre::match('~^\s*[-[{]|accessControl\(~', $line) && $writes($line);
        }

        return [...$opening, ...$keyed];
    }

    /**
     * A line that opens the entry of this name: a YAML key, quoted or not,
     * or a PHP string.
     *
     * @return \Closure(string): bool
     */
    private static function keyMatcher(string $name): \Closure
    {
        return static fn (string $line): bool => str_starts_with(str_replace(['"', "'"], '', ltrim($line, " \t")), $name.':')
            || str_contains($line, "'".$name."'");
    }

    /**
     * A scalar value of the configuration as a string, null for anything else.
     */
    private static function text(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }

        return \is_int($value) || \is_float($value) ? (string) $value : null;
    }

    /**
     * The port a rule's request matcher compares the request's with: the
     * security bundle takes an integer only.
     *
     * @throws SecurityConfigException
     */
    private static function port(string $value): int
    {
        if (!Ascii::isDigit($value)) {
            throw new SecurityConfigException(\sprintf('The access_control port "%s" is not a port number.', $value));
        }

        return (int) $value;
    }

    private static function notAString(string $value): SecurityConfigException
    {
        return new SecurityConfigException(\sprintf('The security configuration value "%s" does not resolve to a string.', $value));
    }

    /**
     * A list of roles, methods, addresses or patterns, its items as
     * written. A string of roles or methods is split on its commas and
     * the spaces around them, as the security bundle splits it, and any
     * other string is one item.
     *
     * @return list<string>
     */
    private static function listOf(mixed $value, bool $splitOnCommas): array
    {
        if (\is_string($value)) {
            return $splitOnCommas ? (preg_split('/\s*,\s*/', $value) ?: []) : [$value];
        }

        $items = [];
        foreach (\is_array($value) ? $value : [] as $item) {
            $item = self::text($item);
            if (null !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
