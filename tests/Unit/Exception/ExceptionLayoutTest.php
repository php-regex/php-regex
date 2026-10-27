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

namespace PHPRegex\Tests\Unit\Exception;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\PatternContextTrait;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Tests\Support\LibrarySource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every exception of the library is found one way: in the Exception
 * namespace of its package, named after what it is, and caught by
 * ExceptionInterface whichever package threw it.
 */
final class ExceptionLayoutTest extends TestCase
{
    /**
     * @param class-string<\Throwable> $class
     */
    #[Test]
    #[DataProvider('provideExceptions')]
    public function test_an_exception_lives_in_its_package_exception_namespace(string $class, string $package): void
    {
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));

        $this->assertSame('PHPRegex\\'.$package.'\\Exception', $namespace, $class.' is an exception: it belongs under src/'.$package.'/Exception/.');
        $this->assertStringEndsWith('Exception', $class);
        $this->assertTrue(is_subclass_of($class, ExceptionInterface::class), $class.' must implement ExceptionInterface.');
    }

    #[Test]
    public function test_every_class_named_as_an_exception_is_one(): void
    {
        $notThrowable = [];
        foreach (self::declaredClasses() as $class => $package) {
            if (str_ends_with($class, 'Exception') && !is_subclass_of($class, \Throwable::class)) {
                $notThrowable[] = $class;
            }
        }

        $this->assertSame([], $notThrowable);
        $this->assertArrayHasKey(SemanticErrorException::class, self::declaredClasses(), 'The scan found no exception.');
    }

    /**
     * The semantic error is built as the other exceptions that show the
     * pattern are: through the shared context, with its hint beside it.
     */
    #[Test]
    public function test_semantic_error_builds_its_context_like_its_siblings(): void
    {
        $this->assertContains(PatternContextTrait::class, (new \ReflectionClass(SemanticErrorException::class))->getTraitNames());

        $shifted = SemanticErrorException::withContext('Bad.', ErrorCode::GroupUnclosed, 5, '/abcdefgh/', null, 2);
        $atTwo = new SemanticErrorException('Bad.', ErrorCode::GroupUnclosed, 2, '/abcdefgh/');
        $hinted = new SemanticErrorException('Bad.', ErrorCode::GroupUnclosed, 2, '/abcdefgh/', hint: 'Close the group.');

        $this->assertSame(5, $shifted->position);
        $this->assertSame($atTwo->snippet, $shifted->snippet);
        $this->assertNull($shifted->hint);
        $this->assertSame('Close the group.', $hinted->hint);
        $this->assertSame($atTwo->snippet, $hinted->snippet);
        $this->assertSame('Bad.', $hinted->getMessage());
    }

    /**
     * @return iterable<string, array{class: class-string<\Throwable>, package: string}>
     */
    public static function provideExceptions(): iterable
    {
        foreach (self::declaredClasses() as $class => $package) {
            if (is_subclass_of($class, \Throwable::class)) {
                yield $class => ['class' => $class, 'package' => $package];
            }
        }
    }

    /**
     * The classes src/ declares that extend another one or are named as an
     * exception, each with its package: the ones an exception can be.
     *
     * @return array<class-string, string>
     */
    private static function declaredClasses(): array
    {
        $classes = [];
        foreach (array_keys(LibrarySource::files()) as $path) {
            if (1 !== preg_match('~^src/(\w+)/(.+)\.php$~', $path, $parts)) {
                continue;
            }
            $code = (string) file_get_contents(\dirname(__DIR__, 3).'/'.$path);
            if (1 !== preg_match('~^(?:final |abstract |readonly )*class (\w+)(?: extends ([\w\\\\]+))?~m', $code, $declaration)) {
                continue;
            }
            if (!isset($declaration[2]) && !str_ends_with($declaration[1], 'Exception')) {
                continue;
            }
            if (isset($declaration[2]) && 1 !== preg_match('~(?:Exception|Error|Failure)$~', $declaration[2]) && !str_ends_with($declaration[1], 'Exception')) {
                continue;
            }

            $class = 'PHPRegex\\'.$parts[1].'\\'.str_replace('/', '\\', $parts[2]);
            self::assertTrue(class_exists($class), $path.' declares no class '.$class.'.');
            $classes[$class] = $parts[1];
        }

        return $classes;
    }
}
