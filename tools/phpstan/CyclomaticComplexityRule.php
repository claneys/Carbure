<?php

/**
 * CyclomaticComplexityRule.php
 *
 * PHPStan rule of Carbure: the cyclomatic complexity of a function or method (1 + one
 * per decision: if, elseif, loop, case, catch, ternary, && / ||, match arm) stays under
 * a maximum, so that the code stays readable and testable. Loaded by phpstan.neon.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FunctionLike>
 */
final class CyclomaticComplexityRule implements Rule
{
    /** Nodes adding a path through the code */
    private const DECISIONS = [
        Node\Stmt\If_::class, Node\Stmt\ElseIf_::class, Node\Stmt\For_::class, Node\Stmt\Foreach_::class,
        Node\Stmt\While_::class, Node\Stmt\Do_::class, Node\Stmt\Catch_::class, Node\Expr\Ternary::class,
        Node\Expr\BinaryOp\BooleanAnd::class, Node\Expr\BinaryOp\BooleanOr::class,
        Node\Expr\BinaryOp\LogicalAnd::class, Node\Expr\BinaryOp\LogicalOr::class,
        Node\Expr\BinaryOp\LogicalXor::class,
    ];

    /**
     * @param int $maxComplexity Highest complexity allowed (phpstan.neon)
     */
    public function __construct(private int $maxComplexity)
    {
    }

    /**
     * Nodes checked: functions, methods and closures with a body.
     *
     * @return string
     */
    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * Error when the complexity of the function is above the maximum.
     *
     * @param FunctionLike $node  The function
     * @param Scope        $scope The scope of the function
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // Closures and arrow functions count in the function that holds them
        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node->getStmts() === null) {
            return [];
        }

        $complexity = self::complexity($node);
        if ($complexity <= $this->maxComplexity) {
            return [];
        }

        $name = $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_
            ? $node->name->toString() . '()' : 'function';
        $class = $scope->getClassReflection()?->getName();
        return [RuleErrorBuilder::message(sprintf('%s%s has a cyclomatic complexity of %d (maximum %d).',
            $class ? "$class::" : '', $name, $complexity, $this->maxComplexity))
            ->identifier('carbure.cyclomaticComplexity')->line($node->getStartLine())->build()];
    }

    /**
     * Cyclomatic complexity of a function: 1 + its decisions.
     *
     * @param FunctionLike $node The function
     * @return int
     */
    public static function complexity(FunctionLike $node): int
    {
        $complexity = 1;
        foreach ((new NodeFinder())->find($node->getStmts() ?? [], fn(Node $n): bool => true) as $child) {
            if (in_array($child::class, self::DECISIONS, true)
                || ($child instanceof Node\Stmt\Case_ && $child->cond !== null)
                || ($child instanceof Node\MatchArm && $child->conds !== null)) {
                $complexity++;
            }
        }
        return $complexity;
    }
}
