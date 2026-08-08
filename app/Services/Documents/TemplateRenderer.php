<?php

namespace App\Services\Documents;

use App\Services\Documents\Exceptions\DocumentRenderException;

/**
 * THE TEMPLATE LANGUAGE
 *
 * A deliberately small language, not a general-purpose one:
 *
 *     {{ lead.name }}
 *     {% if deal.value > 10000 %} … {% else %} … {% endif %}
 *     {% for item in deal.items %} {{ item.product_name }} {% endfor %}
 *
 * The people writing these are salespeople and lawyers writing legal text.
 * Handing them a real template engine would mean arbitrary logic — and in some
 * engines arbitrary code — executing inside a contract body. A bounded subset
 * that covers the actual requirements (conditional clauses, product tables) is
 * the safer trade, and the limits are visible rather than implied.
 *
 * What that safety rests on:
 *
 *   - **No evaluation.** Conditions compare a resolved path against a literal.
 *     There is no expression parser, no function calls, no PHP.
 *   - **Escaped by default.** Every interpolated value is HTML-escaped, so a
 *     lead named `<script>` is text and not markup. `{{{ raw }}}` opts out and
 *     exists only for values the platform itself generates, like product
 *     tables.
 *   - **Bounded.** Body size, loop iterations and block nesting are all capped,
 *     so a pathological template cannot hang the worker rendering it.
 *
 * Unknown variables render as empty string. Never as the raw `{{token}}` — a
 * contract that goes out with `{{lead.name}}` printed in it is worse than one
 * with a blank, because the blank is obvious in review and the token looks
 * like a system fault to the counterparty.
 */
class TemplateRenderer
{
    private int $maxIterations;
    private int $maxNesting;

    public function __construct()
    {
        $this->maxIterations = (int) config('documents.rendering.max_loop_iterations', 500);
        $this->maxNesting    = (int) config('documents.rendering.max_nested_blocks', 8);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws DocumentRenderException
     */
    public function render(string $template, array $context): string
    {
        $maxBytes = (int) config('documents.rendering.max_body_bytes', 1048576);

        if (strlen($template) > $maxBytes) {
            throw new DocumentRenderException(
                'O modelo excede o tamanho máximo de ' . round($maxBytes / 1024) . ' KB.'
            );
        }

        $tokens = $this->tokenize($template);
        $index  = 0;

        return $this->renderTokens($tokens, $index, $context, 0);
    }

    /**
     * Which variable paths a template references — used to warn an author that
     * a template needs a Deal before they attach it to a lead-only document.
     *
     * @return string[]
     */
    public function referencedVariables(string $template): array
    {
        preg_match_all('/\{\{\{?\s*([a-zA-Z0-9_.]+)\s*\}?\}\}/', $template, $interpolations);
        preg_match_all('/\{%\s*(?:if|for\s+\w+\s+in)\s+([a-zA-Z0-9_.]+)/', $template, $blocks);

        return array_values(array_unique(array_merge(
            $interpolations[1] ?? [],
            $blocks[1] ?? []
        )));
    }

    /* ============================================================
     | Tokenizing
     ============================================================ */

    /**
     * Split into literal text and `{% … %}` control tags. Interpolation is
     * handled inside text runs; only control flow needs structure.
     *
     * @return array<int, array{type: string, raw: string, line: int}>
     */
    private function tokenize(string $template): array
    {
        $parts  = preg_split('/(\{%.*?%\})/s', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        $line   = 1;

        foreach ($parts ?: [] as $part) {
            $tokens[] = [
                'type' => str_starts_with($part, '{%') ? 'tag' : 'text',
                'raw'  => $part,
                'line' => $line,
            ];

            $line += substr_count($part, "\n");
        }

        return $tokens;
    }

    /* ============================================================
     | Rendering
     ============================================================ */

    /**
     * Render a flat run of tokens. Control blocks are sliced out by their
     * handlers before recursing, so this never has to look for an end tag.
     *
     * @param array<int, array{type: string, raw: string, line: int}> $tokens
     */
    private function renderTokens(array $tokens, int &$index, array $context, int $depth): string
    {
        if ($depth > $this->maxNesting) {
            throw new DocumentRenderException(
                "Blocos aninhados demais (máximo {$this->maxNesting})."
            );
        }

        $output = '';

        while ($index < count($tokens)) {
            $token = $tokens[$index];

            if ($token['type'] === 'text') {
                $output .= $this->interpolate($token['raw'], $context);
                $index++;
                continue;
            }

            $tag = $this->parseTag($token['raw'], $token['line']);
            $index++;

            $output .= match ($tag['keyword']) {
                'if'  => $this->renderIf($tag, $tokens, $index, $context, $depth),
                'for' => $this->renderFor($tag, $tokens, $index, $context, $depth),

                // Reaching a closer here means it had no opener — the block
                // handlers consume their own.
                'endif', 'endfor', 'else' => throw DocumentRenderException::syntax(
                    "{% {$tag['keyword']} %} sem bloco correspondente",
                    $token['line']
                ),

                default => throw DocumentRenderException::syntax(
                    "Tag desconhecida '{$tag['keyword']}'",
                    $token['line']
                ),
            };
        }

        return $output;
    }

    /**
     * Only the taken branch is rendered.
     *
     * The block's extent is found by scanning rather than by rendering to a
     * stop tag, so an untaken branch is never executed — a false branch
     * containing a thousand-row loop costs nothing, and a syntax error inside
     * a branch that never runs cannot fail the whole document.
     */
    private function renderIf(array $tag, array $tokens, int &$index, array $context, int $depth): string
    {
        $block = $this->findBlockEnd($tokens, $index, 'endif', $tag['line']);

        $branch = $this->evaluateCondition($tag['expression'], $context, $tag['line'])
            ? array_slice($tokens, $index, ($block['else'] ?? $block['end']) - $index)
            : ($block['else'] !== null
                ? array_slice($tokens, $block['else'] + 1, $block['end'] - $block['else'] - 1)
                : []);

        $index = $block['end'] + 1;

        if ($branch === []) {
            return '';
        }

        $cursor = 0;

        return $this->renderTokens($branch, $cursor, $context, $depth + 1);
    }

    private function renderFor(array $tag, array $tokens, int &$index, array $context, int $depth): string
    {
        if (!preg_match('/^(\w+)\s+in\s+([a-zA-Z0-9_.]+)$/', trim($tag['expression']), $matches)) {
            throw DocumentRenderException::syntax(
                'Sintaxe inválida em {% for %} — use {% for item in deal.items %}',
                $tag['line']
            );
        }

        [, $itemName, $path] = $matches;

        $block = $this->findBlockEnd($tokens, $index, 'endfor', $tag['line']);
        $body  = array_slice($tokens, $index, $block['end'] - $index);

        $index = $block['end'] + 1;

        $collection = $this->resolvePath($path, $context);
        $items      = is_array($collection) ? array_values($collection) : [];

        // Capped rather than refused: a product table with more rows than the
        // limit should still produce a contract, and the cap is high enough
        // that hitting it means something is wrong upstream.
        if (count($items) > $this->maxIterations) {
            $items = array_slice($items, 0, $this->maxIterations);
        }

        $output = '';
        $total  = count($items);

        foreach ($items as $position => $item) {
            $scoped             = $context;
            $scoped[$itemName]  = $item;
            $scoped['loop']     = [
                'index'  => $position + 1,
                'index0' => $position,
                'first'  => $position === 0,
                'last'   => $position === $total - 1,
                'count'  => $total,
            ];

            $cursor  = 0;
            $output .= $this->renderTokens($body, $cursor, $scoped, $depth + 1);
        }

        return $output;
    }

    /**
     * Find where a block closes, tracking nesting so an inner `{% if %}`
     * inside a `{% for %}` does not steal the outer block's `{% endfor %}`.
     *
     * @return array{else: ?int, end: int}
     */
    private function findBlockEnd(array $tokens, int $from, string $endKeyword, int $openedAtLine): array
    {
        $depth = 0;
        $else  = null;

        for ($i = $from; $i < count($tokens); $i++) {
            if ($tokens[$i]['type'] !== 'tag') {
                continue;
            }

            $keyword = $this->parseTag($tokens[$i]['raw'], $tokens[$i]['line'])['keyword'];

            if (in_array($keyword, ['if', 'for'], true)) {
                $depth++;
                continue;
            }

            if (in_array($keyword, ['endif', 'endfor'], true)) {
                if ($depth === 0) {
                    if ($keyword !== $endKeyword) {
                        throw DocumentRenderException::syntax(
                            "Esperava {% {$endKeyword} %} mas encontrou {% {$keyword} %}",
                            $tokens[$i]['line']
                        );
                    }

                    return ['else' => $else, 'end' => $i];
                }

                $depth--;
                continue;
            }

            if ($keyword === 'else' && $depth === 0 && $endKeyword === 'endif') {
                $else = $i;
            }
        }

        throw DocumentRenderException::syntax(
            "Bloco aberto e não fechado — faltou {% {$endKeyword} %}",
            $openedAtLine
        );
    }

    /**
     * @return array{keyword: string, expression: string, line: int}
     */
    private function parseTag(string $raw, int $line): array
    {
        $inner = trim(substr($raw, 2, -2));

        if ($inner === '') {
            throw DocumentRenderException::syntax('Tag vazia', $line);
        }

        $parts   = preg_split('/\s+/', $inner, 2);
        $keyword = strtolower($parts[0]);

        return [
            'keyword'    => $keyword,
            'expression' => $parts[1] ?? '',
            'line'       => $line,
        ];
    }

    /* ============================================================
     | Interpolation
     ============================================================ */

    private function interpolate(string $text, array $context): string
    {
        // Triple braces first, so `{{{ x }}}` is not consumed as `{{ x }}`.
        $text = preg_replace_callback(
            '/\{\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}\}/',
            fn (array $m) => $this->stringify($this->resolvePath($m[1], $context)),
            $text
        );

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            fn (array $m) => e($this->stringify($this->resolvePath($m[1], $context))),
            $text
        );
    }

    private function stringify(mixed $value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        return (string) $value;
    }

    /* ============================================================
     | Paths and conditions
     ============================================================ */

    /**
     * Dot-path lookup into the frozen context. No method calls, no lazy
     * loading — the resolver built the whole context up front precisely so
     * that rendering cannot reach back into the database.
     */
    private function resolvePath(string $path, array $context): mixed
    {
        $value = $context;

        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }

            return null;
        }

        return $value;
    }

    /**
     * `path`, or `path OP literal`. Nothing else is a condition.
     */
    private function evaluateCondition(string $expression, array $context, int $line): bool
    {
        $expression = trim($expression);

        if ($expression === '') {
            throw DocumentRenderException::syntax('Condição vazia em {% if %}', $line);
        }

        if (!preg_match('/^([a-zA-Z0-9_.]+)\s*(==|!=|>=|<=|>|<)\s*(.+)$/', $expression, $matches)) {
            // Bare path — truthiness.
            if (!preg_match('/^[a-zA-Z0-9_.]+$/', $expression)) {
                throw DocumentRenderException::syntax(
                    "Condição inválida '{$expression}' — use {% if deal.value > 10000 %}",
                    $line
                );
            }

            return $this->isTruthy($this->resolvePath($expression, $context));
        }

        [$_, $path, $operator, $rawLiteral] = $matches;

        $left  = $this->resolvePath($path, $context);
        $right = $this->parseLiteral(trim($rawLiteral));

        return $this->compare($left, $operator, $right);
    }

    private function parseLiteral(string $raw): mixed
    {
        $lower = strtolower($raw);

        if ($lower === 'true')  return true;
        if ($lower === 'false') return false;
        if ($lower === 'null')  return null;

        if (preg_match('/^([\'"])(.*)\1$/s', $raw, $matches)) {
            return $matches[2];
        }

        if (is_numeric($raw)) {
            return $raw + 0;
        }

        // An unquoted word compares as a string; authors forget quotes and
        // meaning it literally is friendlier than failing the whole render.
        return $raw;
    }

    private function compare(mixed $left, string $operator, mixed $right): bool
    {
        // Numeric when both sides are numbers, so "10000" > 9999 behaves the
        // way an author writing a money threshold expects.
        if (is_numeric($left) && is_numeric($right)) {
            $left  = $left + 0;
            $right = $right + 0;
        } elseif (is_string($left) && is_string($right)) {
            $left  = mb_strtolower($left);
            $right = mb_strtolower($right);
        }

        return match ($operator) {
            '=='    => $left == $right,
            '!='    => $left != $right,
            '>'     => $left > $right,
            '<'     => $left < $right,
            '>='    => $left >= $right,
            '<='    => $left <= $right,
            default => false,
        };
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return (bool) $value;
    }
}
