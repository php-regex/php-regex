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

namespace PHPRegex\Tests\Integration\Bridge\Symfony;

use PHPRegex\Symfony\Validator\Constraints\ValidRegex;
use PHPRegex\Tests\Support\FrameworkKernel;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * The ValidRegex messages are translation keys of the validators domain,
 * as every Symfony constraint's: the bundle ships their English catalogue,
 * and an application translates them as it translates the others.
 */
final class ValidRegexTranslationKernelTest extends TestCase
{
    use TemporaryProject;

    private const CATALOGUE = __DIR__.'/../../../../src/Symfony/Resources/translations/validators.en.xlf';

    #[Test]
    public function test_the_bundle_ships_the_english_catalogue_of_its_messages(): void
    {
        $sources = array_keys((new XliffFileLoader())->load(self::CATALOGUE, 'en', 'validators')->all('validators'));
        $constraint = new ValidRegex();

        $this->assertSame([$constraint->message, $constraint->redosMessage], $sources);
    }

    #[Test]
    public function test_the_application_translator_holds_the_bundle_catalogue(): void
    {
        $kernel = $this->kernel([]);
        $kernel->boot();

        try {
            $translator = $kernel->getContainer()->get('translator');
            $this->assertInstanceOf(TranslatorBagInterface::class, $translator);
            $catalogue = $translator->getCatalogue('en');
        } finally {
            $kernel->shutdown();
        }

        $constraint = new ValidRegex();
        $this->assertTrue($catalogue->defines($constraint->message, 'validators'));
        $this->assertTrue($catalogue->defines($constraint->redosMessage, 'validators'));
    }

    #[Test]
    public function test_an_application_translates_the_messages_in_its_own_catalogue(): void
    {
        $kernel = $this->kernel([
            'translations/validators.fr.yaml' => "'This value is not a valid regular expression: {{ error }}': 'Cette valeur n''est pas une expression régulière valide : {{ error }}'\n",
        ]);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            $translator = $container->get('translator');
            $this->assertInstanceOf(LocaleAwareInterface::class, $translator);
            $translator->setLocale('fr');
            $testContainer = $container->get('test.service_container');
            $this->assertInstanceOf(ContainerInterface::class, $testContainer);
            $validator = $testContainer->get('validator');
            $this->assertInstanceOf(ValidatorInterface::class, $validator);
            $violations = $validator->validate('/abc)/', new ValidRegex());
        } finally {
            $kernel->shutdown();
        }

        $this->assertCount(1, $violations);
        $this->assertStringStartsWith('Cette valeur n\'est pas une expression régulière valide : ', (string) $violations[0]?->getMessage());
    }

    /**
     * @param array<string, string> $files
     */
    private function kernel(array $files): FrameworkKernel
    {
        return new FrameworkKernel($this->makeProject($files), $this->makeProject(), [], [
            'default_locale' => 'en',
            'test' => true,
            'translator' => ['default_path' => '%kernel.project_dir%/translations', 'fallbacks' => ['en']],
        ]);
    }
}
