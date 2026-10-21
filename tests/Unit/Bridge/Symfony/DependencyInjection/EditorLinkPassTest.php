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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\DependencyInjection;

use PHPRegex\Symfony\DependencyInjection\EditorLinkPass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * php_regex.ide wins; without it the lint links to FrameworkBundle's
 * debug.file_link_format, when there is one.
 */
final class EditorLinkPassTest extends TestCase
{
    #[Test]
    public function test_without_php_regex_ide_the_lint_links_to_the_framework_editor(): void
    {
        $container = $this->container(null, true);

        (new EditorLinkPass())->process($container);

        $this->assertSame('%debug.file_link_format%', $container->getDefinition('php_regex.command.lint')->getArgument('$editorUrl'));
    }

    #[Test]
    public function test_php_regex_ide_wins_over_the_framework_editor(): void
    {
        $container = $this->container('vscode', true);

        (new EditorLinkPass())->process($container);

        $this->assertSame('%php_regex.editor_format%', $container->getDefinition('php_regex.command.lint')->getArgument('$editorUrl'));
    }

    #[Test]
    public function test_without_frameworkbundle_nothing_changes(): void
    {
        $container = $this->container(null, false);

        (new EditorLinkPass())->process($container);

        $this->assertSame('%php_regex.editor_format%', $container->getDefinition('php_regex.command.lint')->getArgument('$editorUrl'));
    }

    #[Test]
    public function test_without_the_lint_command_nothing_changes(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('debug.file_link_format', 'phpstorm');

        (new EditorLinkPass())->process($container);

        $this->assertFalse($container->hasDefinition('php_regex.command.lint'));
    }

    private function container(?string $ide, bool $framework): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('php_regex.editor_format', $ide);
        if ($framework) {
            $container->setParameter('debug.file_link_format', 'phpstorm');
        }
        $container->setDefinition('php_regex.command.lint', (new Definition())->setArgument('$editorUrl', '%php_regex.editor_format%'));

        return $container;
    }
}
