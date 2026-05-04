<?php

declare(strict_types=1);

namespace SmartyAst\Parser;

use SmartyAst\Ast\Position;
use SmartyAst\Ast\SourceSpan;

final class ExpressionLexer
{
    /** @return list<ExpressionToken> */
    public function tokenize(string $source, int $baseOffset, int $line, int $column): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($source);

        while ($offset < $length) {
            $ch = $source[$offset];

            if (ctype_space($ch)) {
                [$line, $column] = $this->advance($ch, $line, $column);
                $offset++;
                continue;
            }

            $startOffset = $offset;
            $startLine = $line;
            $startColumn = $column;

            $threeChar = $offset + 2 < $length ? $source[$offset] . $source[$offset + 1] . $source[$offset + 2] : '';
            $threeCharOps = ['===', '!==', '...'];
            if (in_array($threeChar, $threeCharOps, true)) {
                $offset += 3;
                $column += 3;
                $tokens[] = new ExpressionToken('operator', $threeChar, $this->span($baseOffset + $startOffset, $startLine, $startColumn, $baseOffset + $offset, $line, $column));
                continue;
            }

            if ($ch === '$') {
                $offset++;
                $column++;
                while ($offset < $length && preg_match('/[A-Za-z0-9_]/', $source[$offset]) === 1) {
                    $offset++;
                    $column++;
                }
                $value = substr($source, $startOffset, $offset - $startOffset);
                $tokens[] = new ExpressionToken('variable', $value, $this->span($baseOffset + $startOffset, $startLine, $startColumn, $baseOffset + $offset, $line, $column));
                continue;
            }

            if ($ch === '"' || $ch === "'" || $ch === '`') {
                $quote = $ch;
                $offset++;
                $column++;
                while ($offset < $length) {
                    $c = $source[$offset];
                    if ($c === '\\') {
                        $chunk = substr($source, $offset, min(2, $length - $offset));
                        [$line, $column] = $this->advance($chunk, $line, $column);
                        $offset += strlen($chunk);
                        continue;
                    }
                    if ($c === $quote) {
                        $offset++;
                        $column++;
                        break;
                    }
                    [$line, $column] = $this->advance($c, $line, $column);
                    $offset++;
                }
                $value = substr($source, $startOffset, $offset - $startOffset);
                $tokens[] = new ExpressionToken('string', $value, $this->span($baseOffset + $startOffset, $startLine, $startColumn, $baseOffset + $offset, $line, $column));
                continue;
            }

            if (ctype_digit($ch)) {
                $offset++;
                $column++;
                while ($offset < $length && preg_match('/[0-9.]/', $source[$offset]) === 1) {
                    $offset++;
                    $column++;
                }
                $value = substr($source, $startOffset, $offset - $startOffset);
                $tokens[] = new ExpressionToken('number', $value, $this->span($baseOffset + $startOffset, $startLine, $startColumn, $baseOffset + $offset, $line, $column));
                continue;
            }

            if (preg_match('/[A-Za-z_]/', $ch) === 1) {
                $offset++;
                $column++;
                while ($offset < $length && preg_match('/[A-Za-z0-9_]/', $source[$offset]) === 1) {
                    $offset++;
                    $column++;
                }
                $value = substr($source, $startOffset, $offset - $startOffset);
                $tokens[] = new ExpressionToken('identifier', $value, $this->span($baseOffset + $startOffset, $startLine, $startColumn, $baseOffset + $offset, $line, $column));
                continue;
            }

            $twoChar = $offset + 1 < $length ? $source[$offset] . $source[$offset + 1] : '';
            $twoCharOps = ['==', '!=', '>=', '<=', '&&', '||', '->', '=>', '??', '::', '>>', '<<'];
            if (in_array($twoChar, $twoCharOps, true)) {
                $offset += 2;
                $column += 2;
                $tokens[] = new ExpressionToken('operator', $twoChar, $this->span($baseOffset + $startOffset, $startLine, $startColumn, $baseOffset + $offset, $line, $column));
                continue;
            }

            $singleOps = ['+', '-', '*', '/', '%', '(', ')', '[', ']', '{', '}', '.', ',', '?', ':', '|', '=', '!', '>', '<', '@'];
            if (in_array($ch, $singleOps, true)) {
                $offset++;
                $column++;
                $type = in_array($ch, ['(', ')', '[', ']', '{', '}', ',', ':'], true) ? 'punct' : 'operator';
                $tokens[] = new ExpressionToken($type, $ch, $this->span($baseOffset + $startOffset, $startLine, $startColumn, $baseOffset + $offset, $line, $column));
                continue;
            }

            $offset++;
            $column++;
        }

        $pos = new Position($baseOffset + $offset, $line, $column);
        $tokens[] = new ExpressionToken('eof', '', new SourceSpan($pos, $pos));

        return $tokens;
    }

    private function span(int $startOffset, int $startLine, int $startColumn, int $endOffset, int $endLine, int $endColumn): SourceSpan
    {
        return new SourceSpan(
            new Position($startOffset, $startLine, $startColumn),
            new Position($endOffset, $endLine, $endColumn),
        );
    }

    /** @return array{0:int,1:int} */
    private function advance(string $raw, int $line, int $column): array
    {
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            if ($raw[$i] === "\n") {
                $line++;
                $column = 1;
            } else {
                $column++;
            }
        }

        return [$line, $column];
    }
}
