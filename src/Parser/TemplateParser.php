<?php

declare(strict_types=1);

namespace SmartyAst\Parser;

use SmartyAst\Ast\BlockTagNode;
use SmartyAst\Ast\CommentNode;
use SmartyAst\Ast\DocumentNode;
use SmartyAst\Ast\ElseBranchNode;
use SmartyAst\Ast\ExpressionNode;
use SmartyAst\Ast\Node;
use SmartyAst\Ast\PrintNode;
use SmartyAst\Ast\SourceSpan;
use SmartyAst\Ast\TagArgumentNode;
use SmartyAst\Ast\TagNode;
use SmartyAst\Ast\TextNode;
use SmartyAst\Comments\CommentParseContext;
use SmartyAst\Diagnostics\Diagnostic;
use SmartyAst\Diagnostics\Severity;
use SmartyAst\Lexer\TemplateToken;
use SmartyAst\ParseOptions;

final class TemplateParser
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /**
     * @param list<TemplateToken> $tokens
     * @return array{0:DocumentNode,1:list<Diagnostic>}
     */
    public function parse(array $tokens, ParseOptions $options): array
    {
        $this->diagnostics = [];
        $exprParser = new ExpressionParser();
        $rootChildren = [];
        $stack = [];

        foreach ($tokens as $token) {
            if ($token->type === 'eof') {
                break;
            }

            if ($this->inLiteralMode($stack) && !$this->isLiteralClose($token)) {
                $this->appendNode($stack, $rootChildren, new TextNode($token->span, $token->raw));
                continue;
            }

            switch ($token->type) {
                case 'text':
                    $this->appendNode($stack, $rootChildren, new TextNode($token->span, $token->content));
                    break;

                case 'comment':
                    $comment = new CommentNode($token->span, $token->content, []);
                    $ctx = new CommentParseContext($options);
                    foreach ($options->commentParsers as $plugin) {
                        $pluginResult = $plugin->parse($comment, $ctx);
                        $comment->annotations = array_merge($comment->annotations, $pluginResult->annotations);
                        $this->diagnostics = array_merge($this->diagnostics, $pluginResult->diagnostics);
                    }
                    $this->appendNode($stack, $rootChildren, $comment);
                    break;

                case 'print':
                    $expr = $exprParser->parse($token->content, $token->span, $options->phpVersion);
                    $this->diagnostics = array_merge($this->diagnostics, $expr->diagnostics);
                    $this->appendNode($stack, $rootChildren, new PrintNode($token->span, $expr->expression, $token->trimLeft, $token->trimRight));
                    break;

                case 'tag':
                    $tag = $this->parseTagNode($token, $exprParser, $options);
                    $name = strtolower($tag->name);

                    if ($this->isElseTag($name)) {
                        $this->handleElse($stack, $tag, $name, $exprParser, $options);
                        break;
                    }

                    if ($this->isBlockTag($name)) {
                        $stack[] = [
                            'open' => $tag,
                            'children' => [],
                            'branches' => [],
                            'active' => 'main',
                            'branch_index' => null,
                        ];
                        break;
                    }

                    $this->appendNode($stack, $rootChildren, $tag);
                    break;

                case 'close_tag':
                    $this->handleCloseTag($stack, $rootChildren, $token);
                    break;
            }
        }

        while (($frame = array_pop($stack)) !== null) {
            /** @var TagNode $open */
            $open = $frame['open'];
            $span = new SourceSpan($open->span->start, $this->nodeEnd($frame['children'], $open->span));
            $this->diagnostics[] = new Diagnostic('PARSE001', sprintf('Unclosed block tag {%s}.', $open->name), Severity::Error, $open->span, true);
            $rootChildren[] = new BlockTagNode($span, $open, $frame['children'], $frame['branches'], null);
        }

        $span = $tokens !== [] ? new SourceSpan($tokens[0]->span->start, end($tokens)->span->end) : new SourceSpan(
            new \SmartyAst\Ast\Position(0, 1, 1),
            new \SmartyAst\Ast\Position(0, 1, 1),
        );

        return [new DocumentNode($span, $rootChildren), $this->diagnostics];
    }

    private function parseTagNode(TemplateToken $token, ExpressionParser $exprParser, ParseOptions $options): TagNode
    {
        $content = trim($token->content);
        if ($content === '') {
            return new TagNode($token->span, '', [], false, $token->raw, $token->trimLeft, $token->trimRight);
        }

        if (!preg_match('/^(?P<name>[A-Za-z_][A-Za-z0-9_:\\-]*)(?:\s+(?P<rest>.*))?$/s', $content, $match)) {
            $this->diagnostics[] = new Diagnostic('PARSE002', 'Invalid tag syntax.', Severity::Error, $token->span, true);
            return new TagNode($token->span, $content, [], false, $token->raw, $token->trimLeft, $token->trimRight);
        }

        $name = $match['name'];
        $rest = trim($match['rest'] ?? '');
        $arguments = [];
        $isShorthand = false;

        if ($rest !== '') {
            if (strtolower($name) === 'foreach') {
                [$rawArgs, $argDiagnostics] = $exprParser->parseForeachArguments($rest, $token->span, $options->phpVersion);
            } else {
                [$rawArgs, $argDiagnostics] = $exprParser->parseArguments($rest, $token->span, $options->phpVersion);
            }
            $this->diagnostics = array_merge($this->diagnostics, $argDiagnostics);

            foreach ($rawArgs as $arg) {
                $arguments[] = new TagArgumentNode($arg['span'], $arg['name'], $arg['value']);
                if ($arg['name'] === null) {
                    $isShorthand = true;
                }
            }
        }

        return new TagNode($token->span, $name, $arguments, $isShorthand, $token->raw, $token->trimLeft, $token->trimRight);
    }

    /** @param list<array{open:TagNode,children:list<Node>,branches:list<ElseBranchNode>,active:string,branch_index:?int}> $stack */
    private function handleElse(array &$stack, TagNode $tag, string $name, ExpressionParser $exprParser, ParseOptions $options): void
    {
        if ($stack === []) {
            $this->diagnostics[] = new Diagnostic('PARSE003', sprintf('Unexpected {%s} without open block.', $name), Severity::Error, $tag->span, true);
            return;
        }

        $topIndex = array_key_last($stack);
        $frame = &$stack[$topIndex];
        $openName = strtolower($frame['open']->name);

        if (!$this->allowsElse($openName, $name)) {
            $this->diagnostics[] = new Diagnostic('PARSE004', sprintf('Tag {%s} not valid inside {%s}.', $name, $openName), Severity::Error, $tag->span, true);
            return;
        }

        $condition = null;
        if (in_array($name, ['elseif'], true)) {
            $arg = $tag->arguments[0] ?? null;
            $condition = $arg?->value;
        }

        $frame['branches'][] = new ElseBranchNode($tag->span, $name, $condition, []);
        $frame['active'] = 'branch';
        $frame['branch_index'] = array_key_last($frame['branches']);
    }

    /** @param list<array{open:TagNode,children:list<Node>,branches:list<ElseBranchNode>,active:string,branch_index:?int}> $stack
     *  @param list<Node> $rootChildren
     */
    private function handleCloseTag(array &$stack, array &$rootChildren, TemplateToken $token): void
    {
        $closeName = strtolower(trim(explode(' ', $token->content)[0] ?? ''));
        if ($closeName === '') {
            $this->diagnostics[] = new Diagnostic('PARSE005', 'Empty closing tag.', Severity::Error, $token->span, true);
            return;
        }

        if ($stack === []) {
            $this->diagnostics[] = new Diagnostic('PARSE006', sprintf('Unexpected closing tag {/%s}.', $closeName), Severity::Error, $token->span, true);
            return;
        }

        $matchIndex = null;
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if (strtolower($stack[$i]['open']->name) === $closeName) {
                $matchIndex = $i;
                break;
            }
        }

        if ($matchIndex === null) {
            $this->diagnostics[] = new Diagnostic('PARSE007', sprintf('No matching opening tag for {/%s}.', $closeName), Severity::Error, $token->span, true);
            return;
        }

        while (count($stack) - 1 > $matchIndex) {
            $unclosed = array_pop($stack);
            $this->diagnostics[] = new Diagnostic('PARSE008', sprintf('Auto-closing unclosed block {%s}.', $unclosed['open']->name), Severity::Warning, $unclosed['open']->span, true);
            $span = new SourceSpan($unclosed['open']->span->start, $this->nodeEnd($unclosed['children'], $unclosed['open']->span));
            $this->appendNode($stack, $rootChildren, new BlockTagNode($span, $unclosed['open'], $unclosed['children'], $unclosed['branches'], null));
        }

        $frame = array_pop($stack);
        $span = new SourceSpan($frame['open']->span->start, $token->span->end);
        $block = new BlockTagNode($span, $frame['open'], $frame['children'], $frame['branches'], $token->span, $token->trimLeft, $token->trimRight);
        $this->appendNode($stack, $rootChildren, $block);
    }

    /** @param list<array{open:TagNode,children:list<Node>,branches:list<ElseBranchNode>,active:string,branch_index:?int}> $stack
     *  @param list<Node> $rootChildren
     */
    private function appendNode(array &$stack, array &$rootChildren, Node $node): void
    {
        if ($stack === []) {
            $rootChildren[] = $node;
            return;
        }

        $topIndex = array_key_last($stack);
        if ($stack[$topIndex]['active'] === 'branch' && $stack[$topIndex]['branch_index'] !== null) {
            $branchIndex = $stack[$topIndex]['branch_index'];
            $stack[$topIndex]['branches'][$branchIndex]->children[] = $node;
            return;
        }

        $stack[$topIndex]['children'][] = $node;
    }

    /** @param list<array{open:TagNode,children:list<Node>,branches:list<ElseBranchNode>,active:string,branch_index:?int}> $stack */
    private function inLiteralMode(array $stack): bool
    {
        if ($stack === []) {
            return false;
        }

        $top = $stack[array_key_last($stack)];
        return in_array(strtolower($top['open']->name), ['literal', 'php'], true);
    }

    private function isLiteralClose(TemplateToken $token): bool
    {
        return $token->type === 'close_tag' && in_array(strtolower(trim($token->content)), ['literal', 'php'], true);
    }

    private function isBlockTag(string $name): bool
    {
        return in_array($name, [
            'if', 'foreach', 'for', 'while', 'section', 'capture', 'setfilter', 'block', 'function', 'strip', 'literal', 'nocache', 'php',
        ], true);
    }

    private function isElseTag(string $name): bool
    {
        return in_array($name, ['else', 'elseif', 'foreachelse', 'forelse', 'sectionelse'], true);
    }

    private function allowsElse(string $openName, string $elseName): bool
    {
        $mapping = [
            'if' => ['else', 'elseif'],
            'foreach' => ['foreachelse', 'else'],
            'for' => ['forelse', 'else'],
            'section' => ['sectionelse', 'else'],
            'while' => ['else'],
        ];

        return in_array($elseName, $mapping[$openName] ?? [], true);
    }

    /** @param list<Node> $children */
    private function nodeEnd(array $children, SourceSpan $fallback): \SmartyAst\Ast\Position
    {
        if ($children === []) {
            return $fallback->end;
        }

        return $children[array_key_last($children)]->span->end;
    }
}
