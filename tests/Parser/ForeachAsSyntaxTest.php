<?php

declare(strict_types=1);

namespace SmartyAst\Tests\Parser;

use SmartyAst\Ast\BlockTagNode;
use SmartyAst\Ast\ExpressionNode;
use SmartyAst\Ast\TagNode;
use SmartyAst\Ast\VariableExpressionNode;
use SmartyAst\Parser\SmartyParser;
use PHPUnit\Framework\TestCase;

final class ForeachAsSyntaxTest extends TestCase
{
    public function testParsesAsItemForm(): void
    {
        $tag = $this->parseForeachOpenTag("{foreach \$items as \$row}ok{/foreach}");

        $names = $this->argumentNames($tag);
        self::assertSame(['from', 'item'], $names);

        $from = $this->argumentValue($tag, 'from');
        self::assertInstanceOf(VariableExpressionNode::class, $from);
        self::assertSame('items', $from->name);

        $item = $this->argumentValue($tag, 'item');
        self::assertInstanceOf(VariableExpressionNode::class, $item);
        self::assertSame('row', $item->name);
    }

    public function testParsesAsKeyValueForm(): void
    {
        $tag = $this->parseForeachOpenTag("{foreach \$items as \$k => \$v}ok{/foreach}");

        $names = $this->argumentNames($tag);
        self::assertSame(['from', 'item', 'key'], $names);

        self::assertSame('items', $this->argumentValue($tag, 'from')->name);
        self::assertSame('v', $this->argumentValue($tag, 'item')->name);
        self::assertSame('k', $this->argumentValue($tag, 'key')->name);
    }

    public function testLegacyNamedFormStillWorks(): void
    {
        $tag = $this->parseForeachOpenTag("{foreach from=\$items item='row' key='idx'}ok{/foreach}");

        $names = $this->argumentNames($tag);
        // Legacy form preserves source order: from, item, key.
        self::assertSame(['from', 'item', 'key'], $names);
    }

    public function testAsFormAcceptsArrayAccessSource(): void
    {
        $template = "{foreach \$rows['cols'] as \$col}ok{/foreach}";
        $result = (new SmartyParser())->parseString($template);

        $errors = array_filter($result->diagnostics, static fn ($d) => $d->severity->value === 'error');
        self::assertCount(0, $errors);
    }

    public function testAsFormAcceptsMethodCallSource(): void
    {
        $template = "{foreach \$obj->rows() as \$row}ok{/foreach}";
        $result = (new SmartyParser())->parseString($template);

        $errors = array_filter($result->diagnostics, static fn ($d) => $d->severity->value === 'error');
        self::assertCount(0, $errors);
    }

    public function testMissingItemAfterAsEmitsDiagnostic(): void
    {
        $template = "{foreach \$items as}ok{/foreach}";
        $result = (new SmartyParser())->parseString($template);

        $errors = array_values(array_filter(
            $result->diagnostics,
            static fn ($d) => $d->severity->value === 'error',
        ));

        self::assertNotEmpty($errors);
        self::assertSame('EXPR017', $errors[0]->code);
    }

    private function parseForeachOpenTag(string $template): TagNode
    {
        $result = (new SmartyParser())->parseString($template);

        $errors = array_values(array_filter(
            $result->diagnostics,
            static fn ($d) => $d->severity->value === 'error',
        ));
        self::assertCount(0, $errors, sprintf(
            "Expected clean parse for `%s`, got: %s",
            $template,
            json_encode(array_map(static fn ($d) => $d->toArray(), $errors), JSON_PRETTY_PRINT),
        ));

        $first = $result->ast->children[0] ?? null;
        self::assertInstanceOf(BlockTagNode::class, $first);

        return $first->openTag;
    }

    /** @return list<?string> */
    private function argumentNames(TagNode $tag): array
    {
        $names = [];
        foreach ($tag->arguments as $arg) {
            $names[] = $arg->name;
        }
        return $names;
    }

    private function argumentValue(TagNode $tag, string $name): ExpressionNode
    {
        foreach ($tag->arguments as $arg) {
            if ($arg->name === $name) {
                return $arg->value;
            }
        }
        $this->fail(sprintf('Tag {%s} has no argument named %s', $tag->name, $name));
    }
}
