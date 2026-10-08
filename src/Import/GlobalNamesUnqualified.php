<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Import;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;

/**
 * Writes a global function's or constant's fully qualified name
 * (`\env`, `\PHP_EOL`), as `NameResolver` leaves it, plainly again:
 * with no namespace in between, both spellings name the same thing,
 * and the plain one is how the code was written. Class names keep
 * their leading `\`, which a `!php` value needs to be read anywhere.
 *
 * @internal
 */
final class GlobalNamesUnqualified extends NodeVisitorAbstract
{
    /**
     * @param Node $node the node being left
     * @return Node|null the node with its name made plain, or `null` to keep it
     */
    public function leaveNode(Node $node): ?Node
    {
        if (($node instanceof Expr\FuncCall || $node instanceof Expr\ConstFetch)
            && $node->name instanceof Name\FullyQualified
            && !str_contains($node->name->toString(), "\\")) {
            $node->name = new Name($node->name->toString(), $node->name->getAttributes());

            return $node;
        }

        return null;
    }
}
