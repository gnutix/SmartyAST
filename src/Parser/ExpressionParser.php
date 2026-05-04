<?php

declare(strict_types=1);

namespace SmartyAst\Parser;

use SmartyAst\Ast\ArrayAccessExpressionNode;
use SmartyAst\Ast\ArrayExpressionNode;
use SmartyAst\Ast\BinaryExpressionNode;
use SmartyAst\Ast\CallExpressionNode;
use SmartyAst\Ast\ErrorExpressionNode;
use SmartyAst\Ast\ExpressionNode;
use SmartyAst\Ast\IdentifierExpressionNode;
use SmartyAst\Ast\LiteralExpressionNode;
use SmartyAst\Ast\NamedArgumentExpressionNode;
use SmartyAst\Ast\ModifierChainExpressionNode;
use SmartyAst\Ast\ModifierNode;
use SmartyAst\Ast\PropertyFetchExpressionNode;
use SmartyAst\Ast\SourceSpan;
use SmartyAst\Ast\TernaryExpressionNode;
use SmartyAst\Ast\UnaryExpressionNode;
use SmartyAst\Ast\VariableExpressionNode;
use SmartyAst\Diagnostics\Diagnostic;
use SmartyAst\Diagnostics\Severity;

final class ExpressionParser
{
    private const IDENTIFIER_PRECEDENCE = [
        'or' => 1,
        'and' => 2,
        'eq' => 4,
        'ne' => 4,
        'neq' => 4,
        'lt' => 4,
        'lte' => 4,
        'le' => 4,
        'gt' => 4,
        'gte' => 4,
        'ge' => 4,
        'mod' => 6,
    ];

    private const OPERATOR_PRECEDENCE = [
        '=' => 0,
        '||' => 1,
        '&&' => 2,
        '==' => 4,
        '!=' => 4,
        '===' => 4,
        '!==' => 4,
        '>' => 4,
        '<' => 4,
        '>=' => 4,
        '<=' => 4,
        '??' => 4,
        '+' => 5,
        '-' => 5,
        '.' => 5,
        '<<' => 5,
        '>>' => 5,
        '*' => 6,
        '/' => 6,
        '%' => 6,
    ];

    /** @var list<ExpressionToken> */
    private array $tokens = [];
    private int $index = 0;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];
    private string $phpVersion = '8.1';

    public function __construct(
        private readonly ExpressionLexer $lexer = new ExpressionLexer(),
    ) {
    }

    public function parse(string $source, SourceSpan $containerSpan, string $phpVersion = '8.1'): ExpressionParseResult
    {
        $this->tokens = $this->lexer->tokenize($source, $containerSpan->start->offset, $containerSpan->start->line, $containerSpan->start->column);
        $this->index = 0;
        $this->diagnostics = [];
        $this->phpVersion = $phpVersion;

        $expression = $this->parseExpression(0);
        if ($this->current()->type !== 'eof') {
            $this->diagnostics[] = new Diagnostic('EXPR001', 'Unexpected token in expression.', Severity::Error, $this->current()->span, true);
        }

        return new ExpressionParseResult($expression, $this->diagnostics);
    }

    /**
     * Parse foreach tag arguments, supporting both the legacy named form
     *   from=$arr item=$v key=$k
     * and the Smarty 3+ shorthand
     *   $arr as $v
     *   $arr as $k => $v
     *
     * The shorthand is normalised into synthetic from/item/key arguments so
     * downstream walkers see the same AST shape regardless of source form.
     *
     * @return array{0:list<array{name:?string,value:ExpressionNode,span:SourceSpan}>,1:list<Diagnostic>}
     */
    public function parseForeachArguments(string $source, SourceSpan $containerSpan, string $phpVersion = '8.1'): array
    {
        $this->tokens = $this->lexer->tokenize($source, $containerSpan->start->offset, $containerSpan->start->line, $containerSpan->start->column);
        $this->index = 0;
        $this->diagnostics = [];
        $this->phpVersion = $phpVersion;

        if (!$this->hasTopLevelAsKeyword()) {
            return $this->parseArgumentsFromTokens();
        }

        $args = [];
        $sourceStart = $this->current()->span->start;
        $sourceExpr = $this->parseExpression(0);
        $args[] = [
            'name' => 'from',
            'value' => $sourceExpr,
            'span' => new SourceSpan($sourceStart, $sourceExpr->span->end),
        ];

        // Consume `as`
        $this->consume();

        if ($this->current()->type !== 'variable') {
            $this->diagnostics[] = new Diagnostic(
                'EXPR017',
                'Expected variable after `as` in foreach.',
                Severity::Error,
                $this->current()->span,
                true,
            );
            return [$args, $this->diagnostics];
        }

        $firstVar = $this->parseExpression(0);

        if ($this->current()->type === 'operator' && $this->current()->value === '=>') {
            $this->consume();
            if ($this->current()->type !== 'variable') {
                $this->diagnostics[] = new Diagnostic(
                    'EXPR017',
                    'Expected variable after `=>` in foreach.',
                    Severity::Error,
                    $this->current()->span,
                    true,
                );
                $args[] = [
                    'name' => 'key',
                    'value' => $firstVar,
                    'span' => $firstVar->span,
                ];
                return [$args, $this->diagnostics];
            }
            $valueVar = $this->parseExpression(0);
            $args[] = [
                'name' => 'item',
                'value' => $valueVar,
                'span' => $valueVar->span,
            ];
            $args[] = [
                'name' => 'key',
                'value' => $firstVar,
                'span' => $firstVar->span,
            ];
        } else {
            $args[] = [
                'name' => 'item',
                'value' => $firstVar,
                'span' => $firstVar->span,
            ];
        }

        // Allow trailing named arguments (e.g. name='loop').
        $trailing = $this->collectRemainingArguments();
        foreach ($trailing as $arg) {
            $args[] = $arg;
        }

        return [$args, $this->diagnostics];
    }

    private function hasTopLevelAsKeyword(): bool
    {
        $depth = 0;
        for ($i = $this->index; $i < count($this->tokens); $i++) {
            $tok = $this->tokens[$i];
            if ($tok->type === 'eof') {
                return false;
            }
            if (in_array($tok->value, ['(', '[', '{'], true)) {
                $depth++;
                continue;
            }
            if (in_array($tok->value, [')', ']', '}'], true)) {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth === 0 && $tok->type === 'identifier' && strtolower($tok->value) === 'as') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:list<array{name:?string,value:ExpressionNode,span:SourceSpan}>,1:list<Diagnostic>}
     */
    public function parseArguments(string $source, SourceSpan $containerSpan, string $phpVersion = '8.1'): array
    {
        $this->tokens = $this->lexer->tokenize($source, $containerSpan->start->offset, $containerSpan->start->line, $containerSpan->start->column);
        $this->index = 0;
        $this->diagnostics = [];
        $this->phpVersion = $phpVersion;

        return $this->parseArgumentsFromTokens();
    }

    /**
     * @return array{0:list<array{name:?string,value:ExpressionNode,span:SourceSpan}>,1:list<Diagnostic>}
     */
    private function parseArgumentsFromTokens(): array
    {
        $args = $this->collectRemainingArguments();
        return [$args, $this->diagnostics];
    }

    /**
     * @return list<array{name:?string,value:ExpressionNode,span:SourceSpan}>
     */
    private function collectRemainingArguments(): array
    {
        $args = [];

        while ($this->current()->type !== 'eof') {
            while ($this->current()->type === 'punct' && $this->current()->value === ',') {
                $this->consume();
            }

            if ($this->current()->type === 'eof') {
                break;
            }

            $name = null;
            $start = $this->current()->span->start;
            $current = $this->current();
            $next = $this->tokens[$this->index + 1] ?? null;
            if (($current->type === 'identifier' || $current->type === 'variable') && $next?->value === '=') {
                $name = ltrim($current->value, '$');
                $this->consume(); // name
                $this->consume(); // =
            }

            if ($this->current()->type === 'eof') {
                break;
            }

            $expression = $this->parseExpression(0);
            $args[] = [
                'name' => $name,
                'value' => $expression,
                'span' => new SourceSpan($start, $expression->span->end),
            ];

            if ($this->current()->value === ',') {
                $this->consume();
            }
        }

        return $args;
    }

    private function parseExpression(int $minPrecedence): ExpressionNode
    {
        $left = $this->parsePrefix();
        $left = $this->parsePostfix($left);

        while (true) {
            $token = $this->current();
            if ($token->type === 'identifier' && strtolower($token->value) === 'is') {
                $isResult = $this->parseIsExpression($left);
                if ($isResult !== null) {
                    $left = $isResult;
                    continue;
                }
            }

            if ($token->type === 'identifier' && strtolower($token->value) === 'matches') {
                $left = $this->parseMatchesExpression($left);
                continue;
            }

            if ($token->type === 'operator' && $token->value === '?') {
                $this->consume();
                // Support both full ternary (a ? b : c) and shorthand elvis (a ?: c).
                if ($this->current()->value === ':') {
                    $ifTrue = $left;
                } else {
                    $ifTrue = $this->parseExpression(0);
                    if ($this->current()->value !== ':') {
                        $this->diagnostics[] = new Diagnostic('EXPR002', 'Expected : in ternary expression.', Severity::Error, $token->span, true);
                        return $left;
                    }
                }
                $this->consume();
                $ifFalse = $this->parseExpression(0);
                $left = new TernaryExpressionNode(
                    new SourceSpan($left->span->start, $ifFalse->span->end),
                    $left,
                    $ifTrue,
                    $ifFalse,
                );
                continue;
            }

            $precedence = $this->precedence($token);
            if ($precedence < $minPrecedence) {
                break;
            }

            $operator = $token->value;
            $operator = $this->normalizeBinaryOperator($token);
            $this->consume();
            $right = $this->parseExpression($operator === '=' ? $precedence : $precedence + 1);
            $left = new BinaryExpressionNode(
                new SourceSpan($left->span->start, $right->span->end),
                $operator,
                $left,
                $right,
            );
        }

        return $left;
    }

    private function parsePrefix(): ExpressionNode
    {
        $token = $this->current();
        if ($token->type === 'operator' && in_array($token->value, ['!', '-', '+', '...'], true)) {
            $operator = $this->consume();
            $right = $this->parseExpression(100);

            return new UnaryExpressionNode(new SourceSpan($operator->span->start, $right->span->end), $operator->value, $right);
        }

        if ($token->type === 'identifier' && strtolower($token->value) === 'not') {
            $operator = $this->consume();
            $right = $this->parseExpression(100);

            return new UnaryExpressionNode(new SourceSpan($operator->span->start, $right->span->end), 'not', $right);
        }

        if ($token->type === 'variable') {
            $var = $this->consume();
            return $this->parseVariableLikeExpression($var);
        }

        if ($token->type === 'number') {
            $num = $this->consume();
            $value = str_contains($num->value, '.') ? (float)$num->value : (int)$num->value;
            return new LiteralExpressionNode($num->span, 'number', $value);
        }

        if ($token->type === 'string') {
            $str = $this->consume();
            return $this->parseQuotedString($str);
        }

        if ($token->type === 'identifier') {
            $id = $this->consume();
            $name = strtolower($id->value);
            if ($name === 'true' || $name === 'false') {
                return new LiteralExpressionNode($id->span, 'bool', $name === 'true');
            }
            if ($name === 'null') {
                return new LiteralExpressionNode($id->span, 'null', null);
            }
            if ($name === 'array' && $this->current()->value === '(') {
                return $this->parseArrayLike($id->span->start);
            }

            return new IdentifierExpressionNode($id->span, $id->value);
        }

        if ($token->value === '(') {
            $open = $this->consume();
            $expr = $this->parseExpression(0);
            if ($this->current()->value === ')') {
                $close = $this->consume();
                return new UnaryExpressionNode(new SourceSpan($open->span->start, $close->span->end), 'group', $expr);
            }

            $this->diagnostics[] = new Diagnostic('EXPR009', 'Expected ) to close grouped expression.', Severity::Error, $open->span, true);
            return $expr;
        }

        if ($token->value === '{') {
            $open = $this->consume();
            $expr = $this->parseExpression(0);
            if ($this->current()->value === '}') {
                $close = $this->consume();
                return new UnaryExpressionNode(new SourceSpan($open->span->start, $close->span->end), 'group', $expr);
            }
            $this->diagnostics[] = new Diagnostic('EXPR007', 'Expected } to close grouped expression.', Severity::Error, $open->span, true);
            return $expr;
        }

        if ($token->value === '[') {
            return $this->parseBracketArray($token->span->start);
        }

        $this->diagnostics[] = new Diagnostic('EXPR001', 'Unexpected token in expression.', Severity::Error, $token->span, true);
        $bad = $this->consume();
        return new ErrorExpressionNode($bad->span, 'Unexpected token');
    }

    private function parsePostfix(ExpressionNode $left): ExpressionNode
    {
        while (true) {
            $token = $this->current();
            if ($token->value === '(') {
                $open = $this->consume();
                $args = [];
                while ($this->current()->type !== 'eof' && $this->current()->value !== ')') {
                    $argStart = $this->current()->span->start;
                    if ($this->current()->value === '...') {
                        $spreadTok = $this->consume();
                        $operand = $this->parseExpression(0);
                        $args[] = new UnaryExpressionNode(new SourceSpan($spreadTok->span->start, $operand->span->end), '...', $operand);
                    } elseif (
                        version_compare($this->phpVersion, '8.0', '>=')
                        && $this->current()->type === 'identifier'
                        && ($this->tokens[$this->index + 1] ?? null)?->value === ':'
                    ) {
                        $nameTok = $this->consume();
                        $colon = $this->consume();
                        $value = $this->parseExpression(0);
                        $args[] = new NamedArgumentExpressionNode(
                            new SourceSpan($nameTok->span->start, $value->span->end),
                            $nameTok->value,
                            $value,
                        );
                    } else {
                        $args[] = $this->parseExpression(0);
                    }
                    if ($this->current()->value === ',') {
                        $this->consume();
                    }
                }
                $close = $this->current()->value === ')' ? $this->consume() : $open;
                $left = new CallExpressionNode(new SourceSpan($left->span->start, $close->span->end), $left, $args);
                continue;
            }

            if ($token->value === '[') {
                $open = $this->consume();
                if ($this->current()->value === ']') {
                    $index = new LiteralExpressionNode($open->span, 'null', null);
                } else {
                    $index = $this->parseExpression(0);
                }
                if ($this->current()->value === ']') {
                    $close = $this->consume();
                } else {
                    $close = $open;
                    $this->diagnostics[] = new Diagnostic('EXPR010', 'Expected ] after array index access.', Severity::Error, $open->span, true);
                }
                $left = new ArrayAccessExpressionNode(new SourceSpan($left->span->start, $close->span->end), $left, $index);
                continue;
            }

            if ($token->value === '.' || $token->value === '->') {
                $operator = $token->value;
                $this->consume();

                if ($operator === '.' && $this->current()->value === '{') {
                    $open = $this->consume();
                    $index = $this->parseExpression(0);
                    if ($this->current()->value === '}') {
                        $close = $this->consume();
                    } else {
                        $close = $open;
                        $this->diagnostics[] = new Diagnostic('EXPR008', 'Expected } after dynamic dot index.', Severity::Error, $open->span, true);
                    }
                    $left = new ArrayAccessExpressionNode(new SourceSpan($left->span->start, $close->span->end), $left, $index);
                    continue;
                }

                $propertyToken = $this->consume();
                if (!in_array($propertyToken->type, ['identifier', 'variable'], true)) {
                    $this->diagnostics[] = new Diagnostic('EXPR004', 'Expected property name after access operator.', Severity::Error, $propertyToken->span, true);
                    return $left;
                }
                $left = new PropertyFetchExpressionNode(
                    new SourceSpan($left->span->start, $propertyToken->span->end),
                    $left,
                    ltrim($propertyToken->value, '$'),
                    $operator,
                );
                continue;
            }

            if ($token->value === '::') {
                $this->consume();
                $memberToken = $this->consume();
                if (!in_array($memberToken->type, ['identifier', 'variable'], true)) {
                    $this->diagnostics[] = new Diagnostic('EXPR004', 'Expected property name after access operator.', Severity::Error, $memberToken->span, true);
                    return $left;
                }
                $left = new PropertyFetchExpressionNode(
                    new SourceSpan($left->span->start, $memberToken->span->end),
                    $left,
                    ltrim($memberToken->value, '$'),
                    '::',
                );
                continue;
            }

            if ($token->value === '|') {
                $left = $this->parseModifiers($left);
                continue;
            }

            break;
        }

        return $left;
    }

    private function parseModifiers(ExpressionNode $base): ExpressionNode
    {
        $modifiers = [];
        $end = $base->span->end;

        while ($this->current()->value === '|') {
            $pipe = $this->consume();
            $name = $this->consume();
            if ($name->type !== 'identifier') {
                $this->diagnostics[] = new Diagnostic('EXPR005', 'Expected modifier name after |.', Severity::Error, $name->span, true);
                break;
            }

            $arguments = [];
            while ($this->current()->value === ':') {
                $this->consume();
                $arguments[] = $this->parseExpression(80);
            }

            $modifiers[] = new ModifierNode(new SourceSpan($pipe->span->start, ($arguments !== [] ? end($arguments)->span : $name->span)->end), $name->value, $arguments);
            $end = $modifiers[array_key_last($modifiers)]->span->end;
        }

        return new ModifierChainExpressionNode(new SourceSpan($base->span->start, $end), $base, $modifiers);
    }

    private function parseArrayLike(\SmartyAst\Ast\Position $start): ExpressionNode
    {
        $open = $this->consume();
        if ($open->value !== '(') {
            return new ErrorExpressionNode($open->span, 'Expected ( after array');
        }

        $items = [];
        while ($this->current()->type !== 'eof' && $this->current()->value !== ')') {
            $items[] = $this->parseExpression(0);
            if ($this->current()->value === ',') {
                $this->consume();
            }
        }

        if ($this->current()->value === ')') {
            $close = $this->consume();
        } else {
            $close = $open;
            $this->diagnostics[] = new Diagnostic('EXPR011', 'Expected ) to close array(...) expression.', Severity::Error, $open->span, true);
        }

        return new ArrayExpressionNode(new SourceSpan($start, $close->span->end), $items);
    }

    private function parseBracketArray(\SmartyAst\Ast\Position $start): ExpressionNode
    {
        $open = $this->consume();
        if ($open->value !== '[') {
            return new ErrorExpressionNode($open->span, 'Expected [ for array');
        }

        $items = [];
        while ($this->current()->type !== 'eof' && $this->current()->value !== ']') {
            $keyOrValue = $this->parseExpression(0);

            if ($this->current()->value === '=>') {
                $arrow = $this->consume();
                $value = $this->parseExpression(0);
                $items[] = new BinaryExpressionNode(
                    new SourceSpan($keyOrValue->span->start, $value->span->end),
                    $arrow->value,
                    $keyOrValue,
                    $value,
                );
            } else {
                $items[] = $keyOrValue;
            }

            if ($this->current()->value === ',') {
                $this->consume();
            }
        }

        if ($this->current()->value === ']') {
            $close = $this->consume();
        } else {
            $close = $open;
            $this->diagnostics[] = new Diagnostic('EXPR012', 'Expected ] to close array literal.', Severity::Error, $open->span, true);
        }
        return new ArrayExpressionNode(new SourceSpan($start, $close->span->end), $items);
    }

    private function precedence(ExpressionToken $token): int
    {
        if ($token->type === 'identifier') {
            return self::IDENTIFIER_PRECEDENCE[strtolower($token->value)] ?? -1;
        }

        if ($token->type !== 'operator') {
            return -1;
        }

        return self::OPERATOR_PRECEDENCE[$token->value] ?? -1;
    }

    private function current(): ExpressionToken
    {
        return $this->tokens[$this->index] ?? $this->tokens[array_key_last($this->tokens)];
    }

    private function consume(): ExpressionToken
    {
        $token = $this->current();
        $this->index++;
        return $token;
    }

    private function parseVariableLikeExpression(ExpressionToken $variableToken): ExpressionNode
    {
        $baseName = substr($variableToken->value, 1);
        $parts = [
            new LiteralExpressionNode($variableToken->span, 'string', $baseName),
        ];
        $hasDynamicParts = false;
        $endOffset = $variableToken->span->end->offset;

        while (true) {
            $current = $this->current();

            if ($current->value === '{' && $current->span->start->offset === $endOffset) {
                $hasDynamicParts = true;
                $open = $this->consume();
                $embedded = $this->parseExpression(0);
                $parts[] = $embedded;

                if ($this->current()->value === '}') {
                    $endOffset = $this->consume()->span->end->offset;
                } else {
                    $this->diagnostics[] = new Diagnostic('EXPR006', 'Expected } in variable interpolation.', Severity::Error, $open->span, true);
                    $endOffset = $embedded->span->end->offset;
                }
                continue;
            }

            if ($current->type === 'identifier' && $current->span->start->offset === $endOffset) {
                $hasDynamicParts = true;
                $this->consume();
                $parts[] = new LiteralExpressionNode($current->span, 'string', $current->value);
                $endOffset = $current->span->end->offset;
                continue;
            }

            break;
        }

        if (!$hasDynamicParts) {
            return new VariableExpressionNode($variableToken->span, $baseName);
        }

        $result = array_shift($parts);
        foreach ($parts as $part) {
            $result = new BinaryExpressionNode(
                new SourceSpan($variableToken->span->start, $part->span->end),
                '.',
                $result,
                $part,
            );
        }

        // Represent dynamic variable names as an expression that computes the variable name.
        return $result;
    }

    private function parseQuotedString(ExpressionToken $token): ExpressionNode
    {
        $raw = $token->value;
        if (strlen($raw) < 2) {
            return new LiteralExpressionNode($token->span, 'string', $raw);
        }

        $quote = $raw[0];
        $body = substr($raw, 1, -1);

        // Keep single-quoted strings as plain literals.
        if ($quote === "'") {
            return new LiteralExpressionNode($token->span, 'string', stripslashes($body));
        }

        return $this->parseInterpolatedDoubleQuotedString($body, $token);
    }

    private function parseInterpolatedDoubleQuotedString(string $body, ExpressionToken $token): ExpressionNode
    {
        $parts = [];
        $literalBuffer = '';
        $length = strlen($body);
        $i = 0;

        while ($i < $length) {
            $char = $body[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $literalBuffer .= $body[$i + 1];
                $i += 2;
                continue;
            }

            // Backtick interpolation: `...`
            if ($char === '`') {
                $end = strpos($body, '`', $i + 1);
                if ($end === false) {
                    $literalBuffer .= '`';
                    $i++;
                    continue;
                }

                $this->flushLiteralPart($parts, $literalBuffer, $token->span);
                $embedded = substr($body, $i + 1, $end - $i - 1);
                $expr = $this->parseEmbeddedExpression($embedded, $token->span);
                if ($expr !== null) {
                    $parts[] = $expr;
                } else {
                    $parts[] = new LiteralExpressionNode($token->span, 'string', '`' . $embedded . '`');
                }
                $i = $end + 1;
                continue;
            }

            // Smarty inline block: {...}
            if ($char === '{') {
                $end = strpos($body, '}', $i + 1);
                if ($end === false) {
                    $literalBuffer .= '{';
                    $i++;
                    continue;
                }

                $this->flushLiteralPart($parts, $literalBuffer, $token->span);
                $inner = substr($body, $i + 1, $end - $i - 1);
                $expr = $this->parseEmbeddedSmartyChunk($inner, $token->span);
                if ($expr !== null) {
                    $parts[] = $expr;
                } else {
                    $parts[] = new LiteralExpressionNode($token->span, 'string', '{' . $inner . '}');
                }
                $i = $end + 1;
                continue;
            }

            // Simple variable interpolation: $foo, $foo_bar
            if ($char === '$' && $i + 1 < $length && preg_match('/[A-Za-z_]/', $body[$i + 1]) === 1) {
                $this->flushLiteralPart($parts, $literalBuffer, $token->span);
                $j = $i + 1;
                while ($j < $length && preg_match('/[A-Za-z0-9_]/', $body[$j]) === 1) {
                    $j++;
                }

                $name = substr($body, $i + 1, $j - $i - 1);
                $parts[] = new VariableExpressionNode($token->span, $name);
                $i = $j;
                continue;
            }

            $literalBuffer .= $char;
            $i++;
        }

        $this->flushLiteralPart($parts, $literalBuffer, $token->span);
        if ($parts === []) {
            return new LiteralExpressionNode($token->span, 'string', '');
        }

        $result = array_shift($parts);
        foreach ($parts as $part) {
            $result = new BinaryExpressionNode(
                new SourceSpan($result->span->start, $part->span->end),
                '.',
                $result,
                $part,
            );
        }

        return $result;
    }

    /**
     * @param list<ExpressionNode> $parts
     */
    private function flushLiteralPart(array &$parts, string &$literalBuffer, SourceSpan $span): void
    {
        if ($literalBuffer === '') {
            return;
        }

        $parts[] = new LiteralExpressionNode($span, 'string', $literalBuffer);
        $literalBuffer = '';
    }

    private function parseEmbeddedSmartyChunk(string $inner, SourceSpan $containerSpan): ?ExpressionNode
    {
        $trimmed = trim($inner);
        if ($trimmed === '' || str_starts_with($trimmed, '/')) {
            return null;
        }

        if (preg_match('/^(if|elseif|while)\s+(.+)$/i', $trimmed, $match) === 1) {
            return $this->parseEmbeddedExpression($match[2], $containerSpan);
        }

        return $this->parseEmbeddedExpression($trimmed, $containerSpan);
    }

    private function parseEmbeddedExpression(string $source, SourceSpan $containerSpan): ?ExpressionNode
    {
        $probe = new self($this->lexer);
        $result = $probe->parse($source, $containerSpan);

        if ($result->expression instanceof ErrorExpressionNode) {
            return null;
        }
        if ($result->diagnostics !== []) {
            return null;
        }

        return $result->expression;
    }

    private function normalizeBinaryOperator(ExpressionToken $token): string
    {
        if ($token->type !== 'identifier') {
            return $token->value;
        }

        return match (strtolower($token->value)) {
            'eq' => '==',
            'ne', 'neq' => '!=',
            'gt' => '>',
            'lt' => '<',
            'gte', 'ge' => '>=',
            'lte', 'le' => '<=',
            'mod' => '%',
            'and' => '&&',
            'or' => '||',
            default => $token->value,
        };
    }

    private function parseIsExpression(ExpressionNode $left): ?ExpressionNode
    {
        $isToken = $this->consume();
        $negated = false;
        if ($this->current()->type === 'identifier' && strtolower($this->current()->value) === 'not') {
            $negated = true;
            $this->consume();
        }

        $kind = $this->current();
        if ($kind->type !== 'identifier') {
            $this->diagnostics[] = new Diagnostic('EXPR013', 'Expected predicate after "is".', Severity::Error, $isToken->span, true);
            return $left;
        }

        $predicate = strtolower($kind->value);
        if ($predicate === 'in') {
            $inToken = $this->consume();
            $right = $this->parseExpression(5);
            $callee = new IdentifierExpressionNode($inToken->span, 'in_array');
            $call = new CallExpressionNode(
                new SourceSpan($left->span->start, $right->span->end),
                $callee,
                [$left, $right],
            );
            if ($negated) {
                return new UnaryExpressionNode(new SourceSpan($left->span->start, $right->span->end), 'not', $call);
            }
            return $call;
        }

        if ($predicate === 'div') {
            $divToken = $this->consume();
            if (!($this->current()->type === 'identifier' && strtolower($this->current()->value) === 'by')) {
                $this->diagnostics[] = new Diagnostic('EXPR014', 'Expected "by" after "is div".', Severity::Error, $divToken->span, true);
                return $left;
            }
            $byToken = $this->consume();
            $divisor = $this->parseExpression(5);
            $modExpr = new BinaryExpressionNode(
                new SourceSpan($left->span->start, $divisor->span->end),
                '%',
                $left,
                $divisor,
            );
            $zero = new LiteralExpressionNode($byToken->span, 'number', 0);
            return new BinaryExpressionNode(
                new SourceSpan($left->span->start, $divisor->span->end),
                $negated ? '!=' : '==',
                $modExpr,
                $zero,
            );
        }

        if ($predicate === 'even' || $predicate === 'odd') {
            $predToken = $this->consume();
            $base = $left;
            $end = $predToken->span->end;

            if ($this->current()->type === 'identifier' && strtolower($this->current()->value) === 'by') {
                $this->consume();
                $byExpr = $this->parseExpression(5);
                $base = new BinaryExpressionNode(
                    new SourceSpan($left->span->start, $byExpr->span->end),
                    '/',
                    $left,
                    $byExpr,
                );
                $end = $byExpr->span->end;
            }

            $two = new LiteralExpressionNode($predToken->span, 'number', 2);
            $zero = new LiteralExpressionNode($predToken->span, 'number', 0);
            $modExpr = new BinaryExpressionNode(
                new SourceSpan($left->span->start, $end),
                '%',
                $base,
                $two,
            );

            $isOdd = $predicate === 'odd';
            $useNotEqual = ($isOdd !== $negated);
            return new BinaryExpressionNode(
                new SourceSpan($left->span->start, $end),
                $useNotEqual ? '!=' : '==',
                $modExpr,
                $zero,
            );
        }

        $this->diagnostics[] = new Diagnostic('EXPR015', sprintf('Unsupported "is" predicate: %s', $kind->value), Severity::Error, $kind->span, true);
        return $left;
    }

    private function parseMatchesExpression(ExpressionNode $left): ExpressionNode
    {
        $matchesToken = $this->consume();
        $pattern = $this->parseExpression(5);
        $callee = new IdentifierExpressionNode($matchesToken->span, 'preg_match');

        return new CallExpressionNode(
            new SourceSpan($left->span->start, $pattern->span->end),
            $callee,
            [$pattern, $left],
        );
    }
}
