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
 * Runs the packages once, end to end, on the PHP that runs the script: parse, validate, ReDoS, optimize, explain, generate,
 * transpile, the lint of a directory through the CLI, and, given a PHPStan
 * binary, the PHPStan rule on a file.
 *
 *     php tests/Tools/php81_smoke.php [--phpstan=path/to/phpstan]
 *
 * The classes are loaded from the sources, as each package's own autoloader
 * would, with no Composer install of the repository. Exits 1 on the first
 * check that fails.
 */

namespace PHPRegex\Tests\Tools;

use PHPRegex\Cli\ApplicationFactory;
use PHPRegex\Cli\Output;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\NodeFinder;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

$root = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'PHPRegex\\')) {
        $file = $root.'/src/'.str_replace('\\', '/', substr($class, strlen('PHPRegex\\'))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$phpstan = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--phpstan=')) {
        $phpstan = (string) realpath(substr($argument, strlen('--phpstan=')));
    }
}

$checks = 0;
$check = static function (string $name, bool $passed, string $detail = '') use (&$checks): void {
    $checks++;
    echo ($passed ? 'ok   ' : 'FAIL ').$name.('' === $detail ? '' : ': '.$detail).\PHP_EOL;
    if (!$passed) {
        exit(1);
    }
};

echo 'PHP '.\PHP_VERSION.', PCRE2 '.\PCRE_VERSION.\PHP_EOL;

$regex = Regex::create();

$ast = $regex->parse('/(?<year>\d{4})-(?<month>\d{2})/');
$groups = NodeFinder::find($ast, static fn (NodeInterface $node): bool => $node instanceof GroupNode && null !== $node->name);
$check('parse', 2 === count($groups) && '/' === $ast->delimiter);

$check('validate a valid pattern', $regex->validate('/^[a-z]+$/i')->isValid);
$invalid = $regex->validate('/(abc/');
$check('validate an invalid pattern', !$invalid->isValid, (string) $invalid->error);
$check('validate the n modifier for the running PHP', $regex->validate('/a/n')->isValid === (\PHP_VERSION_ID >= 80200));

$redos = $regex->redos('/(a+)+$/');
$check('redos', RedosSeverity::Critical === $redos->severity || RedosSeverity::High === $redos->severity, $redos->severity->value);
$check('redos of a safe pattern', RedosSeverity::Safe === $regex->redos('/^abc$/')->severity);

$optimized = $regex->optimize('/[0-9]+/', new OptimizerOptions());
$check('optimize', '/\d+/' === $optimized->optimized, $optimized->optimized);

$explained = $regex->explain('/^a+$/');
$check('explain', '' !== $explained);

$sample = $regex->generate('/^[a-c]{3}\d$/');
$check('generate', 1 === preg_match('/^[a-c]{3}\d$/', $sample), $sample);

$check('transpile', '' !== $regex->transpile('/^a+$/i', 'javascript')->pattern);

$directory = sys_get_temp_dir().'/php-regex-smoke-'.getmypid();
@mkdir($directory);
file_put_contents($directory.'/broken.php', "<?php\n\npreg_match('/(abc/', \$subject);\n");
file_put_contents($directory.'/fine.php', "<?php\n\npreg_match('/^abc$/', \$subject);\n");
$report = $directory.'/report.json';

ob_start();
$code = ApplicationFactory::create(new Output(false, false, '#', '-'))->run(['regex', 'lint', $directory, '--format=json', '--output='.$report, '--no-splash']);
ob_end_clean();
$document = json_decode((string) @file_get_contents($report), true);
$found = is_array($document) && str_contains((string) json_encode($document), 'broken.php') && !str_contains((string) json_encode($document), 'fine.php');
$check('lint of a directory', 0 !== $code && $found, 'exit '.$code);

if (null !== $phpstan) {
    file_put_contents($directory.'/autoload.php', '<?php spl_autoload_register(static function (string $class): void { if (str_starts_with($class, "PHPRegex\\\\")) { $file = '.var_export($root.'/src/', true).'.str_replace("\\\\", "/", substr($class, 9)).".php"; if (is_file($file)) { require $file; } } });'.\PHP_EOL);
    file_put_contents($directory.'/phpstan.neon', "includes:\n    - ".$root."/src/PHPStan/extension.neon\n    - ".$root."/src/PHPStan/rules.neon\nparameters:\n    level: 0\n    tmpDir: ".$directory."/cache\n");
    file_put_contents($directory.'/redos.php', "<?php\n\npreg_match('/^(a+)+$/', \$subject);\n");
    // Run from the temporary directory: PHPStan loads the autoloader of the
    // project it starts in, and the rule must load from the sources alone.
    $command = 'cd '.escapeshellarg($directory).' && XDEBUG_MODE=off '.escapeshellarg(\PHP_BINARY).' -d memory_limit=-1 '.escapeshellarg($phpstan).' analyse --no-progress --error-format=json -a autoload.php -c phpstan.neon redos.php fine.php';
    $output = trim((string) shell_exec($command.' 2>/dev/null'));
    $lines = explode("\n", $output);
    $result = json_decode((string) end($lines), true);
    $messages = is_array($result) ? (string) json_encode($result['files'] ?? []) : '';
    $check('phpstan rule', str_contains($messages, 'redos.php') && !str_contains($messages, 'fine.php') && str_contains($messages, '"regex.redos"'), '' === $messages ? $output : '');
}

$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
foreach ($files as $file) {
    if ($file instanceof \SplFileInfo) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
}
@rmdir($directory);

echo $checks.' checks passed.'.\PHP_EOL;
