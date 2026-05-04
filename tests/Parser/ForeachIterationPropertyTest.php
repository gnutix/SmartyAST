<?php

declare(strict_types=1);

namespace SmartyAst\Tests\Parser;

use SmartyAst\Ast\ForeachIterationPropertyNode;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\VariableExpressionNode;
use SmartyAst\Parser\SmartyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ForeachIterationPropertyTest extends TestCase
{
    #[DataProvider('supportedProperties')]
    public function testParsesEachKnownProperty(string $property): void
    {
        $template = '{$item@' . $property . '}';
        $result = (new SmartyParser())->parseString($template);

        $errors = array_filter($result->diagnostics, static fn ($d) => $d->severity->value === 'error');
        self::assertCount(0, $errors, sprintf('Expected no errors for "%s"', $template));

        $print = $result->ast->children[0] ?? null;
        self::assertInstanceOf(PrintNode::class, $print);

        $expr = $print->expression;
        self::assertInstanceOf(ForeachIterationPropertyNode::class, $expr);
        self::assertSame($property, $expr->property);
        self::assertInstanceOf(VariableExpressionNode::class, $expr->target);
        self::assertSame('item', $expr->target->name);
    }

    public function testEmitsDiagnosticForUnknownProperty(): void
    {
        $result = (new SmartyParser())->parseString('{$item@bogus}');

        $errors = array_values(array_filter(
            $result->diagnostics,
            static fn ($d) => $d->severity->value === 'error',
        ));

        self::assertCount(1, $errors);
        self::assertSame('EXPR016', $errors[0]->code);
        self::assertStringContainsString('@bogus', $errors[0]->message);

        $print = $result->ast->children[0] ?? null;
        self::assertInstanceOf(PrintNode::class, $print);
        self::assertInstanceOf(ForeachIterationPropertyNode::class, $print->expression);
        self::assertSame('bogus', $print->expression->property);
    }

    public function testEmitsDiagnosticWhenPropertyMissing(): void
    {
        $result = (new SmartyParser())->parseString('{$item@}');

        $errors = array_values(array_filter(
            $result->diagnostics,
            static fn ($d) => $d->severity->value === 'error',
        ));

        self::assertNotEmpty($errors);
        self::assertSame('EXPR016', $errors[0]->code);
    }

    public function testWorksInsideForeachBlockArgument(): void
    {
        $template = "{foreach from=\$rows item='row'}{if \$row@last}last{/if}{/foreach}";
        $result = (new SmartyParser())->parseString($template);

        $errors = array_filter($result->diagnostics, static fn ($d) => $d->severity->value === 'error');
        self::assertCount(0, $errors, 'Expected clean parse for foreach with @last property.');
    }

    public function testCollectVariableNamesIncludesTarget(): void
    {
        $result = (new SmartyParser())->parseString('{$item@first}');
        $print = $result->ast->children[0] ?? null;
        self::assertInstanceOf(PrintNode::class, $print);

        $names = $print->expression->collectVariableNames();
        self::assertContains('item', $names);
    }

    /** @return iterable<string,array{0:string}> */
    public static function supportedProperties(): iterable
    {
        yield 'first' => ['first'];
        yield 'last' => ['last'];
        yield 'index' => ['index'];
        yield 'iteration' => ['iteration'];
        yield 'total' => ['total'];
        yield 'show' => ['show'];
        yield 'key' => ['key'];
    }
}
