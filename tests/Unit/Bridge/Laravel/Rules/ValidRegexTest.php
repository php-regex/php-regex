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

namespace PHPRegex\Tests\Unit\Bridge\Laravel\Rules;

use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;
use PHPRegex\Laravel\Rules\ValidRegex;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Outside an application, the rule judges with a default Regex service.
 */
final class ValidRegexTest extends TestCase
{
    #[Test]
    public function test_the_rule_runs_without_an_application(): void
    {
        // The offset PCRE2 gives depends on its release: the message is the
        // library's own for the running engine.
        $error = (string) Regex::create()->validate('/abc)/')->error;
        $this->assertStringStartsWith('Unmatched closing parenthesis at position ', $error);

        $this->assertSame(['The :attribute field must be a valid regular expression: '.$error], $this->failuresWith('/abc)/'));
        $this->assertSame([], $this->failuresWith('/a+/'));
    }

    /**
     * @return list<string>
     */
    private function failuresWith(string $value): array
    {
        $previous = Facade::getFacadeApplication();
        Facade::setFacadeApplication(null);

        $failures = [];

        try {
            $translator = new Translator(new ArrayLoader(), 'en');
            (new ValidRegex())->validate('pattern', $value, static function (string $message) use (&$failures, $translator): PotentiallyTranslatedString {
                $failures[] = $message;

                return new PotentiallyTranslatedString($message, $translator);
            });
        } finally {
            Facade::setFacadeApplication($previous);
        }

        return $failures;
    }
}
