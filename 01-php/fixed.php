<?php
/**
 * Corrected search results template.
 *
 * Requirements this satisfies:
 *   - ten posts per page
 *   - reads the `page` query parameter for pagination
 *   - filters by the `s` search parameter
 *   - replaces each literal [ad] marker with a div for the ad script to fill
 *
 * Target: PHP 8, inside a WordPress theme (e.g. search.php).
 *
 * Assumptions about the ad placeholders are documented in NOTES.md.
 *
 * @package BCM_Test
 */

if (!defined('ABSPATH') && !defined('BCM_TEST_HARNESS')) {
    exit; // Direct access guard; the test harness defines BCM_TEST_HARNESS.
}

require_once __DIR__ . '/helpers.php';

$page   = bcm_current_page();
$search = bcm_search_term();

$query = new WP_Query([
    'posts_per_page'         => BCM_POSTS_PER_PAGE,
    'paged'                  => $page,
    's'                      => $search,
    'post_status'            => 'publish',
    'ignore_sticky_posts'    => true,
    'no_found_rows'          => false, // We need max_num_pages, so keep the count.
    'update_post_meta_cache' => false,
    'update_post_term_cache' => false,
]);

$total_pages = max(1, (int) $query->max_num_pages);
?>

<h1>
    Page <?= esc_html((string) $page) ?> of <?= esc_html((string) $total_pages) ?>
    <?php if ($search !== '') : ?>
        : Search for <?= esc_html($search) ?>
    <?php endif; ?>
</h1>

<?php if (!$query->have_posts()) : ?>

    <p class="no-results">
        <?php if ($search !== '') : ?>
            Nothing found for &ldquo;<?= esc_html($search) ?>&rdquo;.
        <?php else : ?>
            No posts found.
        <?php endif; ?>
    </p>

<?php else : ?>

    <?php while ($query->have_posts()) : $query->the_post(); ?>

        <article>
            <h2><?= esc_html(get_the_title()) ?></h2>

            <?php
            // get_the_content() returns raw content; the_content filters add
            // shortcodes, embeds and wpautop. Apply them, then inject the ads.
            $content = apply_filters('the_content', get_the_content());
            $content = bcm_replace_ad_markers($content);

            // Deliberately unescaped: this is post HTML, already filtered by
            // the_content. Escaping here would print tags as literal text.
            echo $content;
            ?>
        </article>

    <?php endwhile; ?>

    <?php
    // Hand pagination back to WordPress rather than hand-rolling the links.
    echo paginate_links([
        'total'     => $total_pages,
        'current'   => $page,
        'format'    => '?page=%#%',
        'prev_text' => 'Previous',
        'next_text' => 'Next',
    ]);
    ?>

<?php endif; ?>

<?php
// Restore the global $post so anything rendered after this (sidebars, footer
// widgets) sees the real queried object again.
wp_reset_postdata();
