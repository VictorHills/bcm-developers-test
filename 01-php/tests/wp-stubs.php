<?php
/**
 * Minimal WordPress stubs so fixed.php can be executed outside a WP install.
 *
 * These are deliberately simple fakes, just enough to exercise the template's
 * logic (pagination maths, search filtering, ad replacement, the empty-result
 * branch). They are NOT a WordPress emulator and are only used by run-checks.php.
 */

define('BCM_TEST_HARNESS', true);

/** In-memory post fixtures. */
final class BCM_Fixtures
{
    /** @return array<int, array{title: string, content: string}> */
    public static function posts(): array
    {
        $posts = [];

        // 23 posts: enough for three pages at ten per page.
        for ($i = 1; $i <= 23; $i++) {
            $content = "Body of post {$i}. A dad had a bad day.";

            // Post 2 carries three ad markers, one of them on its own line so
            // that the wpautop paragraph-wrapping path gets exercised.
            if ($i === 2) {
                $content = "Opening paragraph about dads and ads.\n\n[ad]\n\n"
                    . "Middle paragraph. [ad] Inline marker here.\n\n"
                    . "Closing paragraph.\n\n[ad]";
            }

            $posts[] = [
                'title' => "Post {$i}: a standard title",
                'content' => $content,
            ];
        }

        // One post guaranteed to match the search term "widget".
        $posts[] = [
            'title' => 'Widget roundup',
            'content' => "All about the widget.\n\n[ad]\n\nMore widget talk.",
        ];

        return $posts;
    }
}

/** Stand-in for WP_Query covering only what the template touches. */
class WP_Query
{
    public int $max_num_pages = 0;
    public int $found_posts = 0;

    /** @var array<int, array{title: string, content: string}> */
    private array $matched = [];

    /** @var array<int, array{title: string, content: string}> */
    private array $page_posts = [];

    private int $cursor = -1;

    /** @var array{title: string, content: string}|null */
    public static ?array $current = null;

    /** @param array<string, mixed> $args */
    public function __construct(array $args)
    {
        $per_page = (int)($args['posts_per_page'] ?? 10);
        $paged = max(1, (int)($args['paged'] ?? 1));
        $search = (string)($args['s'] ?? '');

        $this->matched = BCM_Fixtures::posts();

        if ($search !== '') {
            $this->matched = array_values(array_filter(
                $this->matched,
                static fn(array $p): bool => stripos($p['title'], $search) !== false
                    || stripos($p['content'], $search) !== false
            ));
        }

        $this->found_posts = count($this->matched);
        $this->max_num_pages = (int)ceil($this->found_posts / max(1, $per_page));
        $this->page_posts = array_slice($this->matched, ($paged - 1) * $per_page, $per_page);
    }

    public function have_posts(): bool
    {
        return ($this->cursor + 1) < count($this->page_posts);
    }

    public function the_post(): void
    {
        $this->cursor++;
        self::$current = $this->page_posts[$this->cursor];
    }
}

function get_the_title(): string
{
    return WP_Query::$current['title'] ?? '';
}

function get_the_content(): string
{
    return WP_Query::$current['content'] ?? '';
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function sanitize_text_field(string $text): string
{
    return trim(strip_tags($text));
}

function wp_unslash(string $text): string
{
    return stripslashes($text);
}

function wp_reset_postdata(): void
{
    WP_Query::$current = null;
}

/**
 * Fake apply_filters that only knows 'the_content', and only applies a
 * simplified wpautop: enough to reproduce the <p>[ad]</p> wrapping that the
 * real wpautop produces for a marker on its own line.
 */
function apply_filters(string $hook, string $value): string
{
    if ($hook !== 'the_content') {
        return $value;
    }

    $chunks = preg_split('/\n\s*\n/', trim($value)) ?: [];

    return implode("\n", array_map(
        static fn(string $chunk): string => '<p>' . trim($chunk) . '</p>',
        $chunks
    ));
}

/** @param array<string, mixed> $args */
function paginate_links(array $args): string
{
    $total = (int)($args['total'] ?? 1);
    $current = (int)($args['current'] ?? 1);
    $out = [];

    for ($i = 1; $i <= $total; $i++) {
        $out[] = $i === $current
            ? "<span class=\"current\">{$i}</span>"
            : "<a href=\"?page={$i}\">{$i}</a>";
    }

    return '<nav class="pagination">' . implode(' ', $out) . '</nav>';
}
