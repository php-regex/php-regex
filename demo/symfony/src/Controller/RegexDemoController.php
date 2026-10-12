<?php

declare(strict_types=1);

namespace App\Controller;

use PHPRegex\Toolkit\Regex;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A small tour of the PHPRegex bundle inside a real application.
 */
final class RegexDemoController
{
    public function __construct(
        private readonly Regex $regex,
    ) {
    }

    #[Route('/regex-demo', name: 'regex_demo')]
    public function index(): JsonResponse
    {
        $patterns = [
            'date' => '/\d{4}-\d{2}-\d{2}/',
            'slug' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'broken' => '/^(unclosed$/',
        ];

        $report = [];
        foreach ($patterns as $name => $pattern) {
            $result = $this->regex->validate($pattern);
            $report[$name] = [
                'pattern' => $pattern,
                'valid' => $result->isValid,
                'error' => $result->error,
            ];
        }

        return new JsonResponse($report);
    }

    /**
     * A route whose requirement is itself a regex: exactly the material the
     * regex:routes and regex:lint commands read through the bridge.
     */
    #[Route('/slug/{slug}', name: 'slug_demo', requirements: ['slug' => '[a-z0-9-]+'])]
    public function slug(string $slug): JsonResponse
    {
        return new JsonResponse(['slug' => $slug, 'requirement' => '[a-z0-9-]+']);
    }
}
