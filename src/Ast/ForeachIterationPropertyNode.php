<?php

declare(strict_types=1);

namespace SmartyAst\Ast;

final class ForeachIterationPropertyNode extends ExpressionNode
{
    /** @var list<string> */
    public const array PROPERTIES = ['first', 'last', 'index', 'iteration', 'total', 'show', 'key'];

    public function __construct(
        SourceSpan $span,
        public ExpressionNode $target,
        public string $property,
    ) {
        parent::__construct('ForeachIterationPropertyExpression', $span);
    }

    public function childExpressions(): array
    {
        return [$this->target];
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'span' => SpanArray::from($this->span),
            'target' => $this->target->toArray(),
            'property' => $this->property,
        ];
    }
}
