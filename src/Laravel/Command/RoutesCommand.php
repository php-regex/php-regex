<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Laravel\Command;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PhpRegex\Toolkit\Regex;

/**
 * Analyze Laravel route patterns for conflicts and issues.
 */
final class RoutesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'regex:routes
        {--show-constraints : Show route constraints}
        {--validate : Validate all route constraints}
        {--format=console : Output format (console, json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze Laravel route patterns for conflicts and issues';

    public function __construct(private readonly Router $router, private readonly Regex $regex)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $showConstraints = (bool) $this->option('show-constraints');
        $validate = (bool) $this->option('validate');
        $format = strtolower((string) $this->option('format'));

        $routes = $this->router->getRoutes();
        $routeData = [];
        $issues = [];

        /** @var Route $route */
        foreach ($routes as $route) {
            $routeName = $route->getName() ?? $route->uri();
            $wheres = $route->wheres;
            $methods = $route->methods();

            $routeInfo = [
                'name' => $routeName,
                'uri' => $route->uri(),
                'methods' => $methods,
                'action' => $this->resolveAction($route),
                'constraints' => [],
            ];

            foreach ($wheres as $parameter => $pattern) {
                if (!\is_string($pattern) || '' === $pattern) {
                    continue;
                }

                $constraintInfo = [
                    'parameter' => $parameter,
                    'pattern' => $pattern,
                    'valid' => true,
                    'error' => null,
                ];

                if ($validate) {
                    $normalized = $this->normalizePattern($pattern);
                    $validation = $this->regex->validate($normalized);

                    if (!$validation->isValid) {
                        $constraintInfo['valid'] = false;
                        $constraintInfo['error'] = $validation->error;
                        $issues[] = [
                            'route' => $routeName,
                            'parameter' => $parameter,
                            'pattern' => $pattern,
                            'error' => $validation->error,
                        ];
                    }
                }

                $routeInfo['constraints'][] = $constraintInfo;
            }

            $routeData[] = $routeInfo;
        }

        if ('json' === $format) {
            $this->output->writeln((string) json_encode([
                'routes' => $routeData,
                'issues' => $issues,
                'summary' => [
                    'total_routes' => \count($routeData),
                    'routes_with_constraints' => \count(array_filter($routeData, static fn (array $r): bool => !empty($r['constraints']))),
                    'total_issues' => \count($issues),
                ],
            ], \JSON_PRETTY_PRINT));

            return \count($issues) > 0 ? self::FAILURE : self::SUCCESS;
        }

        // Console output
        $this->line('<fg=cyan;options=bold>RegexParser</> <fg=yellow>'.Regex::VERSION.'</> - Route Analysis');
        $this->newLine();

        $routesWithConstraints = array_filter($routeData, static fn (array $r): bool => !empty($r['constraints']));

        if (empty($routesWithConstraints)) {
            $this->info('No routes with regex constraints found.');

            return self::SUCCESS;
        }

        $this->line('<fg=white;options=bold>Routes with Constraints:</> '.\count($routesWithConstraints));
        $this->newLine();

        foreach ($routesWithConstraints as $routeInfo) {
            $methods = implode('|', $routeInfo['methods']);
            $this->line("  <fg=yellow>{$methods}</> <fg=white>{$routeInfo['uri']}</>");

            if (null !== $routeInfo['name'] && $routeInfo['name'] !== $routeInfo['uri']) {
                $this->line("    <fg=gray>Name: {$routeInfo['name']}</>");
            }

            if ($showConstraints) {
                foreach ($routeInfo['constraints'] as $constraint) {
                    $status = $constraint['valid'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
                    $this->line("    {$status} <fg=cyan>{$constraint['parameter']}</> = <fg=white>{$constraint['pattern']}</>");

                    if (null !== $constraint['error']) {
                        $this->line("      <fg=red>{$constraint['error']}</>");
                    }
                }
            }

            $this->newLine();
        }

        if (!empty($issues)) {
            $this->newLine();
            $this->error(\count($issues).' invalid constraint(s) found.');

            return self::FAILURE;
        }

        if ($validate) {
            $this->info('All route constraints are valid.');
        }

        return self::SUCCESS;
    }

    private function resolveAction(Route $route): string
    {
        $action = $route->getAction();

        if (isset($action['controller']) && \is_string($action['controller'])) {
            return $action['controller'];
        }

        if (isset($action['uses'])) {
            if (\is_string($action['uses'])) {
                return $action['uses'];
            }
            if ($action['uses'] instanceof \Closure) {
                return 'Closure';
            }
        }

        return 'Unknown';
    }

    private function normalizePattern(string $pattern): string
    {
        if ($this->hasDelimiters($pattern)) {
            return $pattern;
        }

        return '/'.addcslashes($pattern, '/').'/';
    }

    private function hasDelimiters(string $pattern): bool
    {
        if (\strlen($pattern) < 2) {
            return false;
        }

        $firstChar = $pattern[0];
        $validDelimiters = ['/', '#', '~', '!', '@', '%', '`'];

        if (!\in_array($firstChar, $validDelimiters, true)) {
            return false;
        }

        $lastDelimiterPos = strrpos($pattern, $firstChar);

        return false !== $lastDelimiterPos && $lastDelimiterPos > 0;
    }
}
