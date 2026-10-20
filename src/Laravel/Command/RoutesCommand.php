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

namespace PHPRegex\Laravel\Command;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use PHPRegex\Laravel\Routing\RouteAction;
use PHPRegex\Laravel\Routing\RouteConstraintNormalizer;
use PHPRegex\Linter\Console\ConsoleWriter;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Toolkit\Regex;

/**
 * Lists the Laravel routes that have a regex constraint, and validates the
 * constraints.
 *
 * @internal
 */
final class RoutesCommand extends Command
{
    use ReadsStringInput;

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
    protected $description = 'List the routes that have a regex constraint, and validate the constraints';

    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = <<<'HELP'
        Lists the routes that have a regex constraint. --show-constraints prints
        each constraint; --validate compiles each one as the router matches it,
        anchored and under the s, D and u flags, marks it valid or invalid, and
        exits with 1 when one is invalid.
        HELP;

    public function __construct(
        private readonly Router $router,
        private readonly Regex $regex,
        private readonly RouteConstraintNormalizer $normalizer = new RouteConstraintNormalizer(),
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $showConstraints = (bool) $this->option('show-constraints');
        $validate = (bool) $this->option('validate');
        $format = strtolower($this->stringOption('format'));

        $routes = $this->router->getRoutes();
        $routeData = [];
        $issues = [];

        foreach ($routes->getRoutes() as $route) {
            $routeName = $route->getName() ?? $route->uri();
            $wheres = $route->wheres;
            $methods = array_values(array_filter($route->methods(), \is_string(...)));

            $routeInfo = [
                'name' => $routeName,
                'uri' => $route->uri(),
                'methods' => $methods,
                'action' => RouteAction::name($route) ?? 'Unknown',
                'constraints' => [],
            ];

            foreach ($wheres as $parameter => $pattern) {
                if (!\is_string($pattern) || '' === $pattern) {
                    continue;
                }

                // A constraint has a verdict only once it is validated.
                $constraintInfo = [
                    'parameter' => $parameter,
                    'pattern' => $pattern,
                    'valid' => $validate ? true : null,
                    'error' => null,
                ];

                if ($validate) {
                    $normalized = $this->normalizer->normalize($route, (string) $parameter, $pattern);
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
            ConsoleWriter::document($this->output, JsonDocument::encode([
                'routes' => $routeData,
                'issues' => $issues,
                'summary' => [
                    'total_routes' => \count($routeData),
                    'routes_with_constraints' => \count(array_filter($routeData, static fn (array $r): bool => !empty($r['constraints']))),
                    'total_issues' => \count($issues),
                ],
            ]));

            return \count($issues) > 0 ? self::FAILURE : self::SUCCESS;
        }

        // Console output
        $this->line('<fg=cyan;options=bold>PHPRegex</> <fg=yellow>'.Regex::VERSION.'</> - Route Analysis');
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
                    $status = match ($constraint['valid']) {
                        true => '<fg=green>✓</> ',
                        false => '<fg=red>✗</> ',
                        null => '',
                    };
                    $this->line("    {$status}<fg=cyan>{$constraint['parameter']}</> = <fg=white>{$constraint['pattern']}</>");

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
}
