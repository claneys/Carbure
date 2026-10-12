<?php

/**
 * NativeTypesRule.php
 *
 * PHPStan rule of Carbure: every parameter, return value and property is typed in the
 * code itself (native type, checked by PHP at runtime), not only in its PHPDoc.
 * Loaded by phpstan.neon.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node>
 */
final class NativeTypesRule implements Rule
{
    /**
     * Nodes checked: every node (functions, methods, closures and properties are filtered).
     *
     * @return string
     */
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * Errors of a node: a parameter, a return value or a property without native type.
     *
     * @param Node  $node  The node
     * @param Scope $scope The scope of the node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof Node\Stmt\Property) {
            if ($node->type !== null) {
                return [];
            }
            $names = implode(', ', array_map(fn($p) => '$' . $p->name->toString(), $node->props));
            return [RuleErrorBuilder::message("Property $names has no native type.")
                ->identifier('carbure.nativeType')->line($node->getStartLine())->build()];
        }

        if (!$node instanceof FunctionLike || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Expr\Closure) {
            return [];
        }

        $name = $node instanceof ClassMethod ? $node->name->toString() . '()'
            : ($node instanceof Node\Stmt\Function_ ? $node->name->toString() . '()' : 'function');
        $errors = [];
        foreach ($node->getParams() as $param) {
            if ($param->type === null && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $errors[] = RuleErrorBuilder::message("Parameter \${$param->var->name} of $name has no native type.")
                    ->identifier('carbure.nativeType')->line($param->getStartLine())->build();
            }
        }
        // A constructor has no return type
        $constructor = $node instanceof ClassMethod && strtolower($node->name->toString()) === '__construct';
        if ($node->getReturnType() === null && !$constructor) {
            $errors[] = RuleErrorBuilder::message("$name has no native return type.")
                ->identifier('carbure.nativeType')->line($node->getStartLine())->build();
        }
        return $errors;
    }
}
