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

namespace PHPRegex\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Without php_regex.ide, the links of regex:lint open the editor
 * FrameworkBundle links its own pages to: debug.file_link_format, which
 * holds framework.ide, itself the SYMFONY_IDE environment variable unless
 * set. The parameter only exists once FrameworkBundle is loaded, which an
 * extension cannot see: a compiler pass can.
 *
 * @internal
 */
final class EditorLinkPass implements CompilerPassInterface
{
    private const COMMAND = 'php_regex.command.lint';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::COMMAND) || !$container->hasParameter('debug.file_link_format')) {
            return;
        }

        $command = $container->getDefinition(self::COMMAND);
        if (null === ($command->getArguments()['$editorUrl'] ?? null)) {
            $command->setArgument('$editorUrl', '%debug.file_link_format%');
        }
    }
}
