<?php

declare(strict_types=1);

namespace SmartyAst\Tests\Parser;

use SmartyAst\Parser\SmartyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VariableSyntaxMatrixTest extends TestCase
{
    private SmartyParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SmartyParser();
    }

    #[DataProvider('supportedNow')]
    public function testSupportedVariableAndExpressionSyntax(string $template): void
    {
        $result = $this->parser->parseString($template);
        $errors = array_filter($result->diagnostics, static fn ($d) => $d->severity->value === 'error');

        $this->assertCount(
            0,
            $errors,
            "Expected no parse errors for supported syntax:\n$template",
        );
    }

    public static function supportedNow(): array
    {
        return [
            ['{$foo}'],
            ['{$foo[4]}'],
            ['{$foo.bar}'],
            ['{$foo.$bar}'],
            ['{$foo->bar}'],
            ['{$foo->bar()}'],
            ['{$smarty.config.foo}'],
            ['{$foo[bar]}'],
            ['{assign var=foo value=\'baa\'}{$foo}'],
            ['{$foo.bar.baz}'],
            ['{$foo.$bar.$baz}'],
            ['{$foo[4].baz}'],
            ['{$foo[4].$baz}'],
            ['{$foo.bar.baz[4]}'],
            ['{$foo->bar($baz,2,$bar)}'],
            ['{$smarty.server.SERVER_NAME}'],
            ['{$x+$y}'],
            ['{assign var=foo value=$x+$y}'],
            ['{$foo[$x+3]}'],
            ['{$foo.a.b.c}'],
            ['{$foo.a.$b.c}'],
            ['{$foo.a.{$b+4}.c}'],
            ['{$foo.a.{$b.c}}'],
            ['{$foo[\'bar\']}'],
            ['{$foo[\'bar\'][1]}'],
            ['{$foo[$x+$x]}'],
            ['{$foo[$bar[1]]}'],
            ['{$foo[section_name]}'],
            ['{$object->method1($x)->method2($y)}'],
            ['{#foo#}'],
            ['{"foo"}'],
            ['{$foo={counter}+3}'],
            ['{$foo="this is message {counter}"}'],
            ['{assign var=foo value=[1,2,3]}'],
            ['{assign var=foo value=[\'y\'=>\'yellow\',\'b\'=>\'blue\']}'],
            ['{assign var=foo value=[1,[9,8],3]}'],
            ['{$foo=$bar+2}'],
            ['{$foo = strlen($bar)}'],
            ['{$foo = myfunct( ($x+$y)*3 )}'],
            ['{$foo.bar=1}'],
            ['{$foo.bar.baz=1}'],
            ['{$foo[]=1}'],
            ['{$foo_{$bar}}'],
            ['{$foo_{$x+$y}}'],
            ['{$foo_{$bar}_buh_{$blar}}'],
            ['{$foo_{$x}}'],
            ['{func var="test $foo test"}'],
            ['{func var="test $foo_bar test"}'],
            ['{func var="test `$foo[0]` test"}'],
            ['{func var="test `$foo[bar]` test"}'],
            ['{func var="test $foo.bar test"}'],
            ['{func var="test `$foo.bar` test"}'],
            ['{func var="test `$foo.bar` test"|escape}'],
            ['{func var="test {$foo|escape} test"}'],
            ['{func var="test {time()} test"}'],
            ['{func var="test {counter} test"}'],
            ['{func var="variable foo is {if !$foo}not {/if} defined"}'],
            ['{$item@first}'],
            ['{$item@last}'],
            ['{$item@index}'],
            ['{$item@iteration}'],
            ['{$item@total}'],
            ['{$item@show}'],
            ['{$item@key}'],
            ['{$arr|@count}'],
            ['{$arr|@count > 0}'],
            ['{$arr|@json_encode|escape:"html"}'],
        ];
    }

}
