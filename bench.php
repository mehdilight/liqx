<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\Template;

$env = Environment::create();
$env->registerFilter('money', fn($v) => number_format((float)$v, 2) . ' MAD');
$env->registerFilter('t', fn($v) => (string)$v);
$env->registerFilter('default', fn($v, $d) => $v ?? $d);

// Compiled path: Template renders lower the AST to native PHP closures,
// cached as .php files so OPcache serves them from shared memory.
$cacheDir = sys_get_temp_dir() . '/liqx-bench-cache';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);

$envCompiled = Environment::create();
$envCompiled->registerFilter('money', fn($v) => number_format((float)$v, 2) . ' MAD');
$envCompiled->registerFilter('t', fn($v) => (string)$v);
$envCompiled->registerFilter('default', fn($v, $d) => $v ?? $d);
$envCompiled->setCompiledTemplateDir($cacheDir);

foreach (glob($cacheDir . '/*.php') ?: [] as $f) unlink($f);

// 1. Template with template literals inside loops
$templateLoopSource = <<<'LIQX'
<div class="products">
  {items.map((item, i) => (
    <div key={item.id} class={`product-card product-card--${i % 2 === 0 ? 'even' : 'odd'}`}>
      <span class="index">{`Item #${i + 1} (${item.title})`}</span>
      <span class="price">{`${item.price | money} for ${item.qty} ${'items' | t}`}</span>
    </div>
  ))}
</div>
LIQX;

// 2. Template with styles & scripts
$templateVerbatimSource = <<<'LIQX'
<section class="banner">
  <h2>{title}</h2>
  <style>
    .banner {
      background-color: {theme.bgColor};
      padding: {theme.padding}px;
      color: {theme.textColor};
    }
  </style>
</section>
LIQX;

// 3. Complex full section template
$templateComplexSource = <<<'LIQX'
---
const { settings, product } = section;
const inStock = product.available && product.variants.length > 0;
const discount = product.compare_at_price > product.price;
---
<div class={`product-page ${settings.layout}`}>
  <h1>{product.title}</h1>
  {discount && (
    <span class="badge">{`Save ${(product.compare_at_price - product.price) | money}`}</span>
  )}
  <div class="variants">
    {product.variants.map((v, idx) => (
      <div key={v.id} class={`variant-item ${idx === 0 ? 'selected' : ''}`}>
        <span>{`${v.title} - ${v.price | money}`}</span>
        <button type="button" aria-label={`Select ${v.title} variant`}>Choose</button>
      </div>
    ))}
  </div>
</div>
LIQX;

// Data fixtures
$items = [];
for ($i = 1; $i <= 50; $i++) {
    $items[] = ['id' => $i, 'title' => "Product $i", 'price' => 100 + $i * 5, 'qty' => $i % 4 + 1];
}

$complexData = [
    'section' => [
        'settings' => ['layout' => 'two-column'],
        'product' => [
            'title' => 'Radiant Cheek & Lip Tint',
            'available' => true,
            'price' => 180,
            'compare_at_price' => 250,
            'variants' => array_map(fn($n) => ['id' => $n, 'title' => "Shade #$n", 'price' => 180], range(1, 10)),
        ],
    ],
];

$verbatimData = [
    'title' => 'Sale Banner',
    'theme' => ['bgColor' => '#ff0055', 'padding' => 24, 'textColor' => '#ffffff'],
];

// Warmup
$tLoop = Template::parse($templateLoopSource, $env);
$tVerbatim = Template::parse($templateVerbatimSource, $env);
$tComplex = Template::parse($templateComplexSource, $env);

for ($i = 0; $i < 50; $i++) {
    $tLoop->render(['items' => $items]);
    $tVerbatim->render($verbatimData);
    $tComplex->render($complexData);
}

// Compiled (first render also compiles+writes the .php cache files).
$cLoop = Template::parse($templateLoopSource, $envCompiled);
$cVerbatim = Template::parse($templateVerbatimSource, $envCompiled);
$cComplex = Template::parse($templateComplexSource, $envCompiled);

for ($i = 0; $i < 50; $i++) {
    $cLoop->render(['items' => $items]);
    $cVerbatim->render($verbatimData);
    $cComplex->render($complexData);
}

function measure(string $name, callable $fn, int $iterations): void {
    $startTime = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    $endTime = hrtime(true);

    $durationMs = ($endTime - $startTime) / 1_000_000;
    $opsPerSec = ($iterations / ($durationMs / 1000));
    printf("%-35s: %8.2f ms (%8.0f ops/s)\n", $name, $durationMs, $opsPerSec);
}

echo "=== BENCHMARK (Iterations: 1000) ===\n";
measure("1. Parse Loop Template", fn() => Template::parse($templateLoopSource, $env), 1000);
measure("2. Render Loop Template (50 items)", fn() => $tLoop->render(['items' => $items]), 1000);
measure("3. Parse Verbatim Template", fn() => Template::parse($templateVerbatimSource, $env), 1000);
measure("4. Render Verbatim Template", fn() => $tVerbatim->render($verbatimData), 1000);
measure("5. Parse Complex Section", fn() => Template::parse($templateComplexSource, $env), 1000);
measure("6. Render Complex Section", fn() => $tComplex->render($complexData), 1000);
echo "--- compiled path ---\n";
measure("7. Compiled Render Loop (50 items)", fn() => $cLoop->render(['items' => $items]), 1000);
measure("8. Compiled Render Verbatim", fn() => $cVerbatim->render($verbatimData), 1000);
measure("9. Compiled Render Complex", fn() => $cComplex->render($complexData), 1000);
echo "--- full request (end-to-end) ---\n";
measure("10. Interpreter parse+render Loop", fn() => Template::parse($templateLoopSource, $env)->render(['items' => $items]), 1000);
measure("11. Compiled warm-cache render Loop", fn() => Template::parse($templateLoopSource, $envCompiled)->render(['items' => $items]), 1000);
echo "====================================\n";
