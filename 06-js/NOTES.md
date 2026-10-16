# Question 6: JavaScript

`index.html` is Q5's corrected file plus a `<script>` and a button `id`.

```bash
node 06-js/tests/run-checks.cjs   # 48 assertions
```

Or open `06-js/index.html` in a browser.

## Implementation

**1. Append a shuffled copy of the three originals on each click.**
The originals are cloned into `templates` at init, before the DOM changes, so
later batches never clone clones. Shuffle is Fisher-Yates on a copy;
`sort(() => Math.random() - 0.5)` is biased. Each batch goes in via a
`DocumentFragment`, one insert.

**2. `<hr>` after every second paragraph, if another follows.**

| Paragraphs | Result |
| --- | --- |
| 3 | `P P HR P` |
| 5 | `P P HR P P HR P` |
| 6 | `P P HR P P HR P P` |

Separators are fully rebuilt on each change. Appending can invalidate earlier
positions (the old last paragraph now needs one), and recomputing is simpler than
patching. Generated `<hr>`s carry `data-generated-separator`; author-written ones
are left alone. Runs on load and after every click.

**3. Second button, JS only.**
Created with `type="button"`, inserted after the first, with `aria-pressed` and
its label kept in sync with state.

**4. Hidden image paragraphs.**
State lives in one variable, `imagesHidden`, and `applyImageVisibility()` runs
after every insert, so new paragraphs inherit it. Visibility is applied before
separators are rebuilt to avoid a visible flash.

Hiding uses the `hidden` property, so the paragraphs stay in the DOM and still
count for separator placement, as the brief requires. Toggling doesn't touch
separators; a check asserts the sequence is identical before and after.

Side effect: with images hidden, two `<hr>`s can appear adjacent. That's what the
spec implies. Counting only visible paragraphs would make separators move on
every toggle, which seems worse, but I'd raise it.

## Checks

The test loads the real `index.html` and `app.js` in jsdom and uses real
`click()` events. Output in `tests/evidence-checks.txt`.

| Group | Covers |
| --- | --- |
| initial load | 3 paragraphs; `P P HR P`; second button present, `type=button` |
| repeated clicks | 6/2 and 9/4 paragraphs/separators, then ten more clicks checked at each step up to 39 paragraphs; no trailing or adjacent separators |
| batch contents | each batch is a permutation of the originals; originals untouched; clones flagged; no clone-of-clone |
| shuffle | all six permutations over 300 draws; input not mutated |
| toggle before adding | new image paragraphs arrive hidden; others unaffected; label and `aria-pressed` track state |
| toggle after adding | all image paragraphs including clones hide; stable on repeat |
| hidden still counts | separators unchanged by toggle; placement correct when adding while hidden |
| separators are owned | author `<hr>` survives; generated ones flagged |

Repeated clicks and toggling before/after adding are groups 2, 5 and 6.

jsdom has no layout, so `hidden === true` doesn't prove invisibility on screen,
and a one-frame flash can't be detected. That needs a browser (e.g. Playwright).

## Structure

`app.js` uses a UMD-style wrapper so it loads via `<script>` and `require`. The
browser bootstrap is guarded by `typeof document !== 'undefined'`. No runtime
dependencies; jsdom is test-only.
