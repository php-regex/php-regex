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

/*
 * A JSON run of the application whose command PHP stops with a fatal error,
 * set up the way the binary sets up its own: its shutdown function prints
 * what Application::reportFatalError() returns.
 *
 *   php json_fatal_run.php exhaust   the command exhausts the memory left
 *   php json_fatal_run.php fork      a forked child of the command exhausts
 *                                    it; the parent prints {"ok": true}
 */

require dirname(__DIR__, 3).'/vendor/autoload.php';

use PHPRegex\Cli\Application;
use PHPRegex\Cli\Command\JsonCommandInterface;
use PHPRegex\Cli\GlobalOptionsParser;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Parser\Internal\JsonDocument;

register_shutdown_function(static function (): void {
    $code = Application::reportFatalError();
    if (null !== $code) {
        exit($code);
    }
});

$command = new class implements JsonCommandInterface {
    public function getName(): string
    {
        return 'fatal';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'fatal';
    }

    public function run(Input $input, Output $output): int
    {
        if ('fork' !== ($input->args[0] ?? null)) {
            self::exhaustMemory();
        }

        $pid = pcntl_fork();
        if (0 === $pid) {
            self::exhaustMemory();
        }

        pcntl_waitpid($pid, $status);
        $output->writeDocument(JsonDocument::encode(['ok' => true]));

        return self::SUCCESS;
    }

    /**
     * Small allocations, each in a chain of its own, until none fits: the
     * error comes with almost no memory left, as a real exhaustion does.
     */
    private static function exhaustMemory(): never
    {
        ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1024 * 1024));

        $chain = null;
        while (true) {
            $chain = [$chain, str_repeat('x', 200)];
        }
    }
};

$application = new Application(new GlobalOptionsParser(), new Output(false, false), $command);
$application->register($command);

exit($application->run(['regex', 'fatal', $argv[1] ?? 'exhaust', '--json']));
