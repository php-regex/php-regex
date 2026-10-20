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

namespace PHPRegex\Psalm;

use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Hir\ClassSetProvider;
use Psalm\Config;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

/**
 * The Psalm plugin: types $matches where preg_match() returned 1 and after
 * preg_match_all(), the subject where preg_match() returned 1, the callback
 * of preg_replace_callback() and the pieces of preg_split(), from the
 * pattern and the flags, and reports the patterns the targeted PHP and PCRE2
 * refuse as InvalidRegexPattern. A call a "@regex-target" marker covers is
 * judged for the versions it keeps of the series of Psalm's PHP; a marker
 * that cannot be read, or keeps none, is reported as InvalidRegexTarget.
 *
 * Patterns are judged for the PHP version Psalm analyses for, with the
 * PCRE2 it bundles, unless the plugin's options say otherwise; a PHP below
 * 7.3, which ships PCRE1, is judged as regex lint judges it:
 *
 *     <pluginClass class="PHPRegex\Psalm\Plugin">
 *         <phpVersion>8.4</phpVersion>
 *         <pcreVersion>10.44</pcreVersion>
 *         <locale>tr_TR.UTF-8</locale>
 *     </pluginClass>
 *
 * <phpVersion> takes a version ("8.4"), a PHP_VERSION_ID (80400) or
 * "runtime", the PHP running Psalm with the PCRE2 it links; <pcreVersion>
 * a PCRE2 release; <locale> the LC_CTYPE locale the patterns run under,
 * the one of the PHP running Psalm by default.
 */
final class Plugin implements PluginEntryPointInterface
{
    /**
     * @throws InvalidRegexOptionException when <phpVersion>, <pcreVersion> or <locale> cannot be read; Psalm stops on it before reading any
     *                                     file
     * @throws UnsupportedTargetException  when <phpVersion> names a PHP that ships PCRE1, as regex lint refuses it
     */
    public function __invoke(RegistrationInterface $registration, ?\SimpleXMLElement $config = null): void
    {
        CallTarget::configure(self::option($config, 'phpVersion'), self::option($config, 'pcreVersion'), self::option($config, 'locale'));
        // What the engine answers about Unicode classes, kept in Psalm's
        // cache directory, which --clear-cache empties; none under --no-cache.
        $cacheDirectory = Config::getInstance()->getCacheDirectory();
        ClassSetProvider::persistIn(null === $cacheDirectory ? null : $cacheDirectory.\DIRECTORY_SEPARATOR.'php-regex');
        foreach ([PregCallAnalyzer::class, CallbackParameters::class, TargetMarkerAnalyzer::class] as $hooks) {
            // Psalm registers a class only once it is loaded.
            class_exists($hooks);
            $registration->registerHooksFromClass($hooks);
        }
    }

    private static function option(?\SimpleXMLElement $config, string $name): ?string
    {
        $element = $config?->{$name};

        // A missing element reads as an empty list of elements.
        return $element instanceof \SimpleXMLElement && 0 < $element->count() ? trim((string) $element, " \t\n\r\0\x0B") : null;
    }
}
