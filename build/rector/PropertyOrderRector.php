<?php

declare(strict_types=1);

namespace PHPRegex\Build\Rector;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use Rector\Rector\AbstractRector;
use Rector\RuleDoc\CodeSample\CodeSample;
use Rector\RuleDoc\RuleDefinition;

/**
 * Declares the properties of each class in the order the original does,
 * read from the map build/property-order.php writes (TWIN_PROPERTY_ORDER
 * names it). The properties the map does not name, the static ones the
 * twin adds, keep their place.
 */
final class PropertyOrderRector extends AbstractRector
{
    /**
     * @var array<string, list<string>>|null
     */
    private ?array $order = null;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Declare the properties in the order of the original class', [
            new CodeSample("public \$b;\npublic \$a;", "public \$a;\npublic \$b;"),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $class = null === $node->namespacedName ? null : $node->namespacedName->toString();
        $order = null === $class ? null : ($this->order()[$class] ?? null);
        if (null === $order) {
            return null;
        }

        $slots = [];
        $properties = [];
        foreach ($node->stmts as $index => $stmt) {
            if ($stmt instanceof Property && 1 === \count($stmt->props) && \in_array($stmt->props[0]->name->toString(), $order, true)) {
                $slots[] = $index;
                $properties[] = $stmt;
            }
        }

        $sorted = $properties;
        usort($sorted, static fn (Property $a, Property $b): int => array_search($a->props[0]->name->toString(), $order, true) <=> array_search($b->props[0]->name->toString(), $order, true));
        if ($sorted === $properties) {
            return null;
        }

        foreach ($slots as $position => $index) {
            $node->stmts[$index] = $sorted[$position];
        }

        return $node;
    }

    /**
     * @return array<string, list<string>>
     */
    private function order(): array
    {
        if (null === $this->order) {
            $path = getenv('TWIN_PROPERTY_ORDER');
            if (!\is_string($path) || !is_file($path)) {
                throw new \RuntimeException('TWIN_PROPERTY_ORDER names no file: run build/property-order.php first.');
            }

            $this->order = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        }

        return $this->order;
    }
}
