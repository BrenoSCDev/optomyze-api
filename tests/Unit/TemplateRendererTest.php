<?php

use App\Services\Documents\Exceptions\DocumentRenderException;
use App\Services\Documents\TemplateRenderer;

/**
 * The template language.
 *
 * These are the tests that matter most in the whole module: this code decides
 * what a legally binding document says. A silently wrong conditional puts the
 * wrong clause in a contract, and a rendering failure that leaks a raw token
 * makes the CRM look broken to the counterparty.
 */

// The renderer reads its limits from config, so it needs the app booted.
// Bound here rather than in Pest.php so the rest of tests/Unit stays plain
// PHPUnit, which is how this project has it.
uses(Tests\TestCase::class);

beforeEach(function () {
    $this->renderer = new TemplateRenderer();

    $this->context = [
        'lead'    => ['name' => 'Maria Silva', 'email' => 'maria@example.test'],
        'deal'    => [
            'value' => 15000,
            'total' => 15000.5,
            'items' => [
                ['product_name' => 'Consultoria', 'quantity' => 2, 'total_price' => 5000],
                ['product_name' => 'Suporte',     'quantity' => 1, 'total_price' => 10000],
            ],
        ],
        'empty'   => [],
        'country' => 'Brazil',
        'flag'    => true,
    ];
});

/* ---------------- interpolation ---------------- */

test('it interpolates a dotted path', function () {
    expect($this->renderer->render('Olá {{ lead.name }}', $this->context))
        ->toBe('Olá Maria Silva');
});

test('it escapes interpolated values', function () {
    $rendered = $this->renderer->render('{{ lead.name }}', [
        'lead' => ['name' => '<script>alert(1)</script>'],
    ]);

    expect($rendered)->not->toContain('<script>')
        ->and($rendered)->toContain('&lt;script&gt;');
});

test('triple braces opt out of escaping for platform-generated markup', function () {
    expect($this->renderer->render('{{{ block }}}', ['block' => '<b>x</b>']))
        ->toBe('<b>x</b>');
});

test('an unknown variable renders empty, never the raw token', function () {
    $rendered = $this->renderer->render('[{{ nope.missing }}]', $this->context);

    expect($rendered)->toBe('[]')
        ->and($rendered)->not->toContain('{{');
});

/* ---------------- conditionals ---------------- */

test('each comparison operator behaves', function (string $expression, string $expected) {
    expect($this->renderer->render("{% if {$expression} %}YES{% else %}NO{% endif %}", $this->context))
        ->toBe($expected);
})->with([
    ['deal.value > 10000',      'YES'],
    ['deal.value > 20000',      'NO'],
    ['deal.value < 20000',      'YES'],
    ['deal.value >= 15000',     'YES'],
    ['deal.value <= 14999',     'NO'],
    ['deal.value == 15000',     'YES'],
    ['deal.value != 15000',     'NO'],
    ["country == 'Brazil'",     'YES'],
    ["country == 'Portugal'",   'NO'],
    ['flag',                    'YES'],
    ['empty',                   'NO'],
]);

test('an if without an else renders nothing when false', function () {
    expect($this->renderer->render('A{% if deal.value > 99999 %}B{% endif %}C', $this->context))
        ->toBe('AC');
});

test('an untaken branch is never rendered', function () {
    // The false branch references a variable that does not exist and contains
    // a loop over a missing collection. If it were evaluated, this would still
    // pass — so the real assertion is that nothing from it appears.
    $rendered = $this->renderer->render(
        '{% if deal.value > 99999 %}{% for x in nothing.here %}LEAK{% endfor %}{% else %}OK{% endif %}',
        $this->context
    );

    expect($rendered)->toBe('OK');
});

test('string comparison is case-insensitive', function () {
    expect($this->renderer->render("{% if country == 'BRAZIL' %}YES{% else %}NO{% endif %}", $this->context))
        ->toBe('YES');
});

/* ---------------- loops ---------------- */

test('it loops over a collection with loop metadata', function () {
    $rendered = $this->renderer->render(
        '{% for item in deal.items %}{{loop.index}}:{{item.product_name}};{% endfor %}',
        $this->context
    );

    expect($rendered)->toBe('1:Consultoria;2:Suporte;');
});

test('an empty collection renders nothing and does not leak the block', function () {
    expect($this->renderer->render('[{% for i in empty %}X{% endfor %}]', $this->context))
        ->toBe('[]');
});

test('a missing collection renders nothing', function () {
    expect($this->renderer->render('[{% for i in nope.missing %}X{% endfor %}]', $this->context))
        ->toBe('[]');
});

test('conditionals nest inside loops', function () {
    $rendered = $this->renderer->render(
        '{% for item in deal.items %}{% if item.total_price > 6000 %}BIG{% else %}small{% endif %}{% endfor %}',
        $this->context
    );

    expect($rendered)->toBe('smallBIG');
});

test('a loop is capped rather than allowed to run away', function () {
    config()->set('documents.rendering.max_loop_iterations', 3);

    $renderer = new TemplateRenderer();
    $rendered = $renderer->render('{% for n in numbers %}x{% endfor %}', [
        'numbers' => range(1, 100),
    ]);

    expect($rendered)->toBe('xxx');
});

/* ---------------- safety ---------------- */

test('php tags in a template body are inert text', function () {
    $rendered = $this->renderer->render('<?php echo "pwned"; ?>{{ lead.name }}', $this->context);

    // Nothing was evaluated: the output is the input verbatim with only the
    // token substituted. The PHP survives as literal characters, which is
    // what "inert" means here.
    expect($rendered)->toBe('<?php echo "pwned"; ?>Maria Silva');
});

test('a body over the size cap is refused', function () {
    config()->set('documents.rendering.max_body_bytes', 100);

    $renderer = new TemplateRenderer();

    expect(fn () => $renderer->render(str_repeat('a', 200), []))
        ->toThrow(DocumentRenderException::class);
});

/* ---------------- syntax errors ---------------- */

test('an unclosed block is reported with its line', function () {
    expect(fn () => $this->renderer->render("linha1\n{% if flag %}oops", $this->context))
        ->toThrow(DocumentRenderException::class, 'não fechado');
});

test('a closer without an opener is reported', function () {
    expect(fn () => $this->renderer->render('{% endif %}', $this->context))
        ->toThrow(DocumentRenderException::class, 'sem bloco correspondente');
});

test('mismatched block types are reported', function () {
    expect(fn () => $this->renderer->render('{% if flag %}x{% endfor %}', $this->context))
        ->toThrow(DocumentRenderException::class);
});

test('an unknown tag is refused rather than ignored', function () {
    expect(fn () => $this->renderer->render('{% while true %}x{% endwhile %}', $this->context))
        ->toThrow(DocumentRenderException::class, 'Tag desconhecida');
});

test('malformed for syntax is reported helpfully', function () {
    expect(fn () => $this->renderer->render('{% for deal.items %}x{% endfor %}', $this->context))
        ->toThrow(DocumentRenderException::class, 'Sintaxe inválida');
});

/* ---------------- introspection ---------------- */

test('it reports which variables a body references', function () {
    $referenced = $this->renderer->referencedVariables(
        '{{lead.name}} {% if deal.value > 1 %}{{deal.total}}{% endif %}{% for i in deal.items %}{{i.x}}{% endfor %}'
    );

    expect($referenced)->toContain('lead.name')
        ->and($referenced)->toContain('deal.value')
        ->and($referenced)->toContain('deal.total')
        ->and($referenced)->toContain('deal.items');
});
