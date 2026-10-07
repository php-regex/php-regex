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

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Psalm\Internal\PregCallAnalyzer;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

/**
 * The Psalm plugin: types $matches where preg_match() returned 1 and after
 * preg_match_all(), from the pattern, and reports the patterns the targeted
 * PHP and PCRE2 refuse as InvalidRegexPattern.
 *
 * Patterns are judged for the PHP version Psalm analyses for, 8.2 at least,
 * with the PCRE2 it bundles, unless the plugin's options say otherwise:
 *
 *     <pluginClass class="PHPRegex\Psalm\Plugin">
 *         <phpVersion>8.4</phpVersion>
 *         <pcreVersion>10.44</pcreVersion>
 *     </pluginClass>
 *
 * <phpVersion> takes a version ("8.4"), a PHP_VERSION_ID (80400) or
 * "runtime", the PHP running Psalm with the PCRE2 it links; <pcreVersion>
 * a PCRE2 release.
 */
final class Plugin implements PluginEntryPointInterface
{
    /**
     * @throws InvalidRegexOptionException when <phpVersion> or <pcreVersion> cannot be read; Psalm stops on it before reading any file
     */
    public function __invoke(RegistrationInterface $registration, ?\SimpleXMLElement $config = null): void
    {
        PregCallAnalyzer::configure(self::option($config, 'phpVersion'), self::option($config, 'pcreVersion'));
        $registration->registerHooksFromClass(PregCallAnalyzer::class);
    }

    private static function option(?\SimpleXMLElement $config, string $name): ?string
    {
        $element = $config?->{$name};

        // A missing element reads as an empty list of elements.
        return $element instanceof \SimpleXMLElement && 0 < $element->count() ? trim((string) $element) : null;
    }
}
