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
            return self::propertyErrors($node);
        }
        if (!$node instanceof FunctionLike || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Expr\Closure) {
            return [];
        }
        return self::functionErrors($node);
    }

    /**
     * Error of a property without native type.
     *
     * @param Node\Stmt\Property $node The property
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function propertyErrors(Node\Stmt\Property $node): array
    {
        if ($node->type !== null) {
            return [];
        }
        $names = implode(', ', array_map(fn($p) => '$' . $p->name->toString(), $node->props));
        return [self::error("Property $names has no native type.", $node->getStartLine())];
    }

    /**
     * Errors of a function or method: parameters and return value without native type.
     *
     * @param FunctionLike $node The function or method
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private static function functionErrors(FunctionLike $node): array
    {
        $name = $node instanceof ClassMethod || $node instanceof Node\Stmt\Function_ ? $node->name->toString() . '()' : 'function';
        $errors = [];
        foreach ($node->getParams() as $param) {
            if ($param->type === null && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $errors[] = self::error("Parameter \${$param->var->name} of $name has no native type.", $param->getStartLine());
            }
        }
        // A constructor has no return type
        $constructor = $node instanceof ClassMethod && strtolower($node->name->toString()) === '__construct';
        if ($node->getReturnType() === null && !$constructor) {
            $errors[] = self::error("$name has no native return type.", $node->getStartLine());
        }
        return $errors;
    }

    /**
     * Error of this rule.
     *
     * @param string $message The message
     * @param int    $line    The line of the code
     * @return \PHPStan\Rules\IdentifierRuleError
     */
    private static function error(string $message, int $line): \PHPStan\Rules\IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier('carbure.nativeType')->line($line)->build();
    }
}
