<?php
/**
 * Executes fixed.php against the stub WordPress and asserts the four scenarios
 * the brief asks for:
 *
 *   1. missing parameters      (no ?page, no ?s)
 *   2. page 2                  (?page=2)
 *   3. no search results       (?s=zzzznothing)
 *   4. multiple [ad] markers   (one post carries three)
 *
 * Run:  php tests/run-checks.php
 * Exits non-zero if any assertion fails, so it can gate a commit.
 */

require __DIR__ . '/wp-stubs.php';

$failures = 0;
$checks   = 0;

/**
 * Render fixed.php with a given $_GET and capture the output.
 *
 * @param array<string, string> $get
 */
function render(array $get): string
{
    $_GET = $get;

    // fixed.php declares functions, so it can only be included once per process.
    // Re-including is avoided by rendering in a subprocess-free way: we include
    // once and re-run only the template body. Simpler and honest: run each
    // scenario in its own process via the CLI below.
    ob_start();
    require __DIR__ . '/../fixed.php';

    return (string) ob_get_clean();
}

function check(string $label, bool $passed, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;

    if ($passed) {
        echo "  PASS  {$label}\n";

        return;
    }

    $failures++;
    echo "  FAIL  {$label}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
}

$scenario = $argv[1] ?? 'all';

if ($scenario === 'all') {
    // Each scenario needs a clean process because fixed.php declares functions
    // and constants at file scope. Re-exec this script once per scenario.
    $scenarios = ['missing', 'page2', 'noresults', 'ads'];
    $exit      = 0;

    foreach ($scenarios as $name) {
        echo "\n=== {$name} ===\n";
        passthru(sprintf('%s %s %s', PHP_BINARY, escapeshellarg(__FILE__), $name), $code);
        $exit = $exit ?: $code;
    }

    echo "\n" . ($exit === 0 ? "ALL CHECKS PASSED\n" : "SOME CHECKS FAILED\n");
    exit($exit);
}

switch ($scenario) {
    case 'missing':
        // No page, no s. Expect page 1 of 3, no PHP warnings, no search clause.
        $html = render([]);

        check('defaults to page 1', str_contains($html, 'Page 1 of 3'));
        check('omits the search clause when s is absent', !str_contains($html, 'Search for'));
        check('renders ten articles', substr_count($html, '<article>') === 10);
        check('no undefined-variable artefacts', !str_contains($html, 'Page 1 of  '));
        break;

    case 'page2':
        // ?page=2 must advance the window, not repeat page 1.
        $html = render(['page' => '2']);

        check('reports page 2', str_contains($html, 'Page 2 of 3'));
        check('shows the second window of posts', str_contains($html, 'Post 11:'));
        check('does not repeat page 1', !str_contains($html, 'Post 1:'));
        check('renders ten articles', substr_count($html, '<article>') === 10);
        check('marks page 2 current in pagination', str_contains($html, '<span class="current">2</span>'));

        // Non-numeric and out-of-range input must not fatal or go below 1.
        $junk = render(['page' => 'not-a-number']);
        check('non-numeric page clamps to 1', str_contains($junk, 'Page 1 of 3'));

        $negative = render(['page' => '-5']);
        check('negative page clamps to 1', str_contains($negative, 'Page 1 of 3'));
        break;

    case 'noresults':
        $html = render(['s' => 'zzzznothing']);

        check('shows the empty-state message', str_contains($html, 'Nothing found for'));
        check('echoes the escaped term', str_contains($html, 'zzzznothing'));
        check('renders no articles', substr_count($html, '<article>') === 0);
        check('still reports a sane page count', str_contains($html, 'Page 1 of 1'));

        // Escaping check: a term containing HTML must come back inert.
        // Two layers run here. sanitize_text_field strips the tags on the way in,
        // so esc_html never sees them; the surviving text is the inner "alert(1)".
        // Both layers are kept: sanitise on input, escape on output.
        $xss = render(['s' => '<script>alert(1)</script>']);
        check('no executable script survives', !str_contains($xss, '<script>'));
        check('tags are stripped on input, inner text survives', str_contains($xss, 'alert(1)'));

        // With sanitising bypassed, esc_html alone must still neutralise the tags.
        $raw = esc_html('<script>alert(1)</script>');
        check('esc_html alone neutralises tags', str_contains($raw, '&lt;script&gt;'));
        break;

    case 'ads':
        // Post 2 carries three markers: one standalone, one inline, one trailing.
        $html = render([]);

        check('replaces all three markers', substr_count($html, 'class="ad ad-') === 3);
        check('numbers them sequentially', str_contains($html, 'ad-1')
            && str_contains($html, 'ad-2')
            && str_contains($html, 'ad-3'));
        check('leaves no literal markers behind', !str_contains($html, '[ad]'));
        check('does not nest a div inside a p', !preg_match('#<p>\s*<div class="ad#', $html));
        check('leaves ordinary letters a and d alone', str_contains($html, 'A dad had a bad day.'));

        // The counter must restart per post, so the widget post also starts at 1.
        $widget = render(['s' => 'widget']);
        check('ad counter resets per post', substr_count($widget, 'class="ad ad-1"') === 1);
        check('search result still renders', str_contains($widget, 'Widget roundup'));
        break;

    default:
        fwrite(STDERR, "Unknown scenario: {$scenario}\n");
        exit(2);
}

echo "  ({$checks} checks, {$failures} failed)\n";
exit($failures === 0 ? 0 : 1);
