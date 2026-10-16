<?php
/**
 * Helpers for the search template.
 *
 * These live outside the template on purpose. A WordPress template file is
 * included once per request in normal use, but it can legitimately be included
 * more than once (a secondary loop, a partial rendered twice, a test harness),
 * and function declarations at template scope fatal on the second include.
 * function_exists guards also keep a child theme free to override any of them.
 *
 * @package BCM_Test
 */

if (!defined('BCM_POSTS_PER_PAGE')) {
    define('BCM_POSTS_PER_PAGE', 10);
}

if (!function_exists('bcm_current_page')) {
    /**
     * Read the requested page number from the `page` query parameter.
     *
     * Note the deliberate asymmetry: the *URL* parameter is `page` (as the brief
     * specifies) but the *WP_Query* argument must be `paged`. `page` as a query
     * arg only paginates the inner content of a single static front page, which
     * is why the original returned the same ten posts for every page.
     *
     * @return int Page number, never below 1.
     */
    function bcm_current_page(): int
    {
        $raw = $_GET['page'] ?? 1;

        // (int) on a non-numeric string yields 0, so clamp to a floor of 1.
        return max(1, (int) $raw);
    }
}

if (!function_exists('bcm_search_term')) {
    /**
     * Read and sanitise the `s` search parameter.
     *
     * @return string Empty string when absent, which WP_Query treats as "no search".
     */
    function bcm_search_term(): string
    {
        return sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
    }
}

if (!function_exists('bcm_replace_ad_markers')) {
    /**
     * Swap each literal [ad] marker for an empty div that the ad script fills in.
     *
     * Runs AFTER the_content filters so that wpautop/shortcodes behave exactly as
     * they do everywhere else on the site. The alternation absorbs the paragraph
     * wpautop wraps around a marker that sits on its own line; without it the
     * output would be <p><div class="ad"></div></p>, which is invalid nesting.
     *
     * Each marker is numbered by its position within the post, so a post with
     * three markers yields ad-1, ad-2, ad-3. The counter resets per post.
     *
     * @param string $content Post content, already filtered.
     * @return string
     */
    function bcm_replace_ad_markers(string $content): string
    {
        $slot = 0;

        return preg_replace_callback(
            '#<p>\s*\[ad\]\s*</p>|\[ad\]#i',
            static function (array $matches) use (&$slot): string {
                $slot++;

                // Left empty on purpose: a JavaScript handler swaps in the live ad.
                return sprintf('<div class="ad ad-%d"></div>', $slot);
            },
            $content
        );
    }
}
