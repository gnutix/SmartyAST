<?php

declare(strict_types=1);

namespace SmartyAst\Tests\Parser;

use SmartyAst\Ast\BlockTagNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\TagNode;
use SmartyAst\Parser\SmartyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuiltinTagsTest extends TestCase
{
    #[DataProvider('singleTagTemplates')]
    public function testParsesBuiltinSingleTags(string $tagName, string $template): void
    {
        $result = (new SmartyParser())->parseString($template);

        $this->assertNoErrorDiagnostics($result->diagnostics);
        self::assertTrue($this->hasTag($result->ast->children, $tagName), sprintf('Expected tag {%s} in AST', $tagName));
    }

    #[DataProvider('blockTagTemplates')]
    public function testParsesBuiltinBlockTags(string $tagName, string $template): void
    {
        $result = (new SmartyParser())->parseString($template);

        $this->assertNoErrorDiagnostics($result->diagnostics);
        self::assertTrue($this->hasBlockTag($result->ast->children, $tagName), sprintf('Expected block tag {%s} in AST', $tagName));
    }

    #[DataProvider('shorthandTagTemplates')]
    public function testParsesBuiltinsShorthandTags(string $tagName, string $template): void
    {
        $result = (new SmartyParser())->parseString($template);

        $this->assertNoErrorDiagnostics($result->diagnostics);

        $tag = $this->findFirstTag($result->ast->children, $tagName);
        self::assertNotNull($tag, sprintf('Expected tag {%s} in AST', $tagName));
        self::assertTrue($tag->isShorthand, sprintf('Expected tag {%s} to be marked shorthand', $tagName));
    }

    #[DataProvider('elseVariantTemplates')]
    public function testParsesElseVariants(string $blockTagName, string $elseBranchName, string $template): void
    {
        $result = (new SmartyParser())->parseString($template);

        $this->assertNoErrorDiagnostics($result->diagnostics);

        $block = $this->findFirstBlockTag($result->ast->children, $blockTagName);
        self::assertNotNull($block, sprintf('Expected block tag {%s} in AST', $blockTagName));

        $branchNames = array_map(static fn ($branch) => strtolower($branch->name), $block->elseBranches);
        self::assertContains(strtolower($elseBranchName), $branchNames);
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function singleTagTemplates(): iterable
    {
        yield 'append' => ['append', "{append var='names' value='John'}"];
        yield 'assign' => ['assign', "{assign var='title' value='Hello'}"];
        yield 'break' => ['break', '{break}'];
        yield 'call' => ['call', "{call name='renderRow' id=1}"];
        yield 'config_load' => ['config_load', "{config_load file='main.conf' section='web'}"];
        yield 'continue' => ['continue', '{continue}'];
        yield 'debug' => ['debug', '{debug}'];
        yield 'eval' => ['eval', "{eval var='Hello'}"];
        yield 'extends' => ['extends', "{extends file='layout.tpl'}"];
        yield 'foreachsection' => ['foreachsection', "{foreachsection name='idx' loop=3}"];
        yield 'include' => ['include', "{include file='header.tpl'}"];
        yield 'inheritance' => ['inheritance', '{inheritance}'];
        yield 'ldelim' => ['ldelim', '{ldelim}'];
        yield 'rdelim' => ['rdelim', '{rdelim}'];
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function blockTagTemplates(): iterable
    {
        yield 'if' => ['if', "{if \$ok}ok{/if}"];
        yield 'for' => ['for', "{for start=0 to=3 step=1}ok{/for}"];
        yield 'foreach' => ['foreach', "{foreach from=\$items item='item'}ok{/foreach}"];
        yield 'foreach_as_v' => ['foreach', "{foreach \$items as \$v}ok{/foreach}"];
        yield 'foreach_as_kv' => ['foreach', "{foreach \$items as \$k => \$v}ok{/foreach}"];
        yield 'while' => ['while', "{while \$keep}ok{/while}"];
        yield 'section' => ['section', "{section name='idx' loop=\$items}ok{/section}"];
        yield 'capture' => ['capture', "{capture name='cap'}ok{/capture}"];
        yield 'block' => ['block', "{block name='content'}ok{/block}"];
        yield 'function' => ['function', "{function name='row'}ok{/function}"];
        yield 'setfilter' => ['setfilter', "{setfilter name='escape'}ok{/setfilter}"];
        yield 'strip' => ['strip', "{strip}  ok {/strip}"];
        yield 'literal' => ['literal', "{literal}{if \$x}{/if}{/literal}"];
        yield 'nocache' => ['nocache', "{nocache}ok{/nocache}"];
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function shorthandTagTemplates(): iterable
    {
        yield 'append shorthand' => ['append', "{append 'names' 'John'}"];
        yield 'assign shorthand' => ['assign', "{assign 'title' 'Hello'}"];
        yield 'call shorthand' => ['call', "{call renderRow 1}"];
        yield 'config_load shorthand' => ['config_load', "{config_load 'main.conf'}"];
        yield 'eval shorthand' => ['eval', "{eval 'Hello'}"];
        yield 'extends shorthand' => ['extends', "{extends 'layout.tpl'}"];
        yield 'if shorthand' => ['if', "{if \$ok}ok{/if}"];
        yield 'include shorthand' => ['include', "{include 'header.tpl'}"];
        yield 'while shorthand' => ['while', "{while \$keep}ok{/while}"];
    }

    /** @return iterable<string,array{0:string,1:string,2:string}> */
    public static function elseVariantTemplates(): iterable
    {
        yield 'if else' => ['if', 'else', "{if \$ok}yes{else}no{/if}"];
        yield 'if elseif' => ['if', 'elseif', "{if \$ok}yes{elseif \$alt}alt{else}no{/if}"];
        yield 'foreach foreachelse' => ['foreach', 'foreachelse', "{foreach from=\$items item='item'}yes{foreachelse}no{/foreach}"];
        yield 'for forelse' => ['for', 'forelse', "{for start=0 to=1}yes{forelse}no{/for}"];
        yield 'section sectionelse' => ['section', 'sectionelse', "{section name='idx' loop=\$items}yes{sectionelse}no{/section}"];
    }

    /** @param list<Node> $nodes */
    private function hasTag(array $nodes, string $tagName): bool
    {
        return $this->findFirstTag($nodes, $tagName) !== null;
    }

    /** @param list<Node> $nodes */
    private function hasBlockTag(array $nodes, string $tagName): bool
    {
        return $this->findFirstBlockTag($nodes, $tagName) !== null;
    }

    /** @param list<Node> $nodes */
    private function findFirstTag(array $nodes, string $tagName): ?TagNode
    {
        foreach ($nodes as $node) {
            if ($node instanceof TagNode && strtolower($node->name) === strtolower($tagName)) {
                return $node;
            }
            if ($node instanceof BlockTagNode) {
                if (strtolower($node->openTag->name) === strtolower($tagName)) {
                    return $node->openTag;
                }
                $childMatch = $this->findFirstTag($node->children, $tagName);
                if ($childMatch !== null) {
                    return $childMatch;
                }
                foreach ($node->elseBranches as $branch) {
                    $branchMatch = $this->findFirstTag($branch->children, $tagName);
                    if ($branchMatch !== null) {
                        return $branchMatch;
                    }
                }
            }
        }

        return null;
    }

    /** @param list<Node> $nodes */
    private function findFirstBlockTag(array $nodes, string $tagName): ?BlockTagNode
    {
        foreach ($nodes as $node) {
            if ($node instanceof BlockTagNode && strtolower($node->openTag->name) === strtolower($tagName)) {
                return $node;
            }
            if ($node instanceof BlockTagNode) {
                $childMatch = $this->findFirstBlockTag($node->children, $tagName);
                if ($childMatch !== null) {
                    return $childMatch;
                }
                foreach ($node->elseBranches as $branch) {
                    $branchMatch = $this->findFirstBlockTag($branch->children, $tagName);
                    if ($branchMatch !== null) {
                        return $branchMatch;
                    }
                }
            }
        }

        return null;
    }

    /** @param list<\SmartyAst\Diagnostics\Diagnostic> $diagnostics */
    private function assertNoErrorDiagnostics(array $diagnostics): void
    {
        $errors = array_values(array_filter($diagnostics, static fn ($diagnostic) => $diagnostic->severity->value === 'error'));
        self::assertCount(0, $errors, $errors === [] ? '' : json_encode(array_map(static fn ($d) => $d->toArray(), $errors), JSON_PRETTY_PRINT));
    }
}
