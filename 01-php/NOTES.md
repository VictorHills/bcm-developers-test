# Question 1: PHP

Three fatal errors and ten other defects. As supplied, the template dies on line
2 with `Call to undefined function WP_Query()`.

| File | Purpose |
| --- | --- |
| `broken.php` | Original, verbatim. Not loaded. |
| `fixed.php` | Corrected template. |
| `helpers.php` | Template helpers, `function_exists` guarded. |
| `tests/run-checks.php` | The four required scenarios, 25 assertions. |
| `tests/wp-stubs.php` | Minimal WordPress fakes. |
| `tests/verify-original-faults.php` | Reproduces each fault below. |
| `tests/evidence-*.txt` | Captured output. |

```bash
cd 01-php
php -l fixed.php && php -l helpers.php
php tests/verify-original-faults.php
php tests/run-checks.php
```

## Faults

### Fatal

1. **`WP_Query([...])` called as a function.** It's a class. Fix: `new WP_Query`.
2. **`preg_replace_callback` has no subject.** PHP 8 throws
   `ArgumentCountError` (PHP 7 only warned). Fix: pass `$content`.
3. **`$m` is undefined in the callback.** The closure takes no parameters, and
   the pattern has no capture group for `$m[1]` anyway. Fix: accept `$matches`,
   number slots with a counter (see below).

### Silently wrong

4. **`'page'` instead of `'paged'`.** `page` only applies to static front pages,
   so `?page=2` changes the heading but returns page 1's posts. Fix: read the
   `page` URL parameter, pass it as `paged`.
5. **`#[ad]#` is a character class.** It matches any single `a` or `d`:

   ```
   preg_replace('#[ad]#','X','A dad had a bad day. [ad]')  =>  A XXX hXX X bXX XXy. [XX]
   preg_replace('#\[ad\]#','X','A dad had a bad day. [ad]') =>  A dad had a bad day. X
   ```

6. **The callback echoes instead of returning.** The div prints before the
   `<h2>`, outside the article, and the marker becomes an empty string.
7. **`$pages` is never assigned.** Heading reads "Page 1 of  :". Fix:
   `max(1, $query->max_num_pages)` so an empty result reads "Page 1 of 1".
8. **`esc_html(the_title())`.** `the_title()` echoes and returns null, so the
   title prints unescaped and in the wrong place. Fix: `get_the_title()`.
9. **`get_the_content()` skips content filters.** No shortcodes, embeds or
   `wpautop`. Fix: `apply_filters('the_content', get_the_content())`.

### Markup and hygiene

10. **`$_GET['s']` unguarded and unsanitised.** Undefined-key warning on every
    non-search page. Fix: `sanitize_text_field(wp_unslash($_GET['s'] ?? ''))`
    in, `esc_html` out.
11. **Unclosed `<h1>`.**
12. **No `have_posts()` guard or `wp_reset_postdata()`.** Empty results render a
    bare heading; the global `$post` leaks into whatever renders after.
13. **`$page` not cast or clamped.** `?page=abc` gives 0, `?page=-5` a negative
    offset. Fix: `max(1, (int) $raw)`.

## Ad markers

The brief says replace each literal `[ad]`, but the original code reads `$m[1]`,
which implies numbered markers. I followed the brief:

- Markers are literal `[ad]`, case-insensitive. Each div gets its position in
  the post (`ad-1`, `ad-2`, ...), resetting per post.
- If editors actually write `[ad-3]`, the pattern becomes `#\[ad-(\d+)\]#i` and
  the callback uses `$matches[1]`. Worth confirming against real content.

Replacement runs after `the_content` filters, so shortcodes behave normally. But
`wpautop` turns a marker on its own line into `<p>[ad]</p>`, and a naive swap
puts a `<div>` inside a `<p>`. The pattern absorbs the wrapper:

```php
'#<p>\s*\[ad\]\s*</p>|\[ad\]#i'
```

The div is left empty because the brief says JS fills it. In production `.ad`
should get a `min-height` to avoid layout shift.

## Checks

Each scenario runs in its own process. Output in `tests/evidence-checks.txt`.

| Scenario | Asserts |
| --- | --- |
| missing params | page 1; no search clause; ten articles; clean heading |
| page 2 | "Page 2 of 3"; second window of posts; page 1 posts absent; `abc` and `-5` clamp to 1 |
| no results | empty-state message; term escaped; "Page 1 of 1"; `<script>` term inert |
| multiple `[ad]` | all three replaced and numbered; no `<div>` in `<p>`; plain `a`/`d` untouched; counter resets per post |

The fixture includes "A dad had a bad day." so a regression to `#[ad]#` fails
loudly.

The stubs are fakes, not WordPress: simplified `wpautop`, substring search. They
cover the template logic but not real `WP_Query` behaviour (e.g. whether
`'s' => ''` equals omitting `s`). I'd rerun the same scenarios on a real install.

Helpers originally lived in `fixed.php` and fataled with
`Cannot redeclare bcm_current_page()` when the template was included twice. They
now live in `helpers.php` behind `function_exists`, which also lets a child theme
override them.
