# Question 5: HTML

`broken.html` is the original, `index.html` the fix, `test.jpeg` a local
300×200 image, `validation-output.txt` the captured validator run.

```bash
npx html-validate 05-html/broken.html   # 16 errors
npx html-validate 05-html/index.html    # clean
```

Config: `.htmlvalidate.json` (`recommended` plus `missing-doctype`,
`attribute-allowed-values`, `no-unknown-elements`).

## A. Validity errors

Line numbers refer to `broken.html`.

| # | Error | Effect |
| --- | --- | --- |
| 1 | No `<!DOCTYPE html>` (1:1) | Quirks mode: old box model, inconsistent rendering across browsers. |
| 2 | `</html>` before `<body>` (1:2) | Body ends up a sibling of `<html>`. Browsers reparent it, so the DOM doesn't match the source. |
| 3 | No closing `</html>` | Follows from 2. |
| 4 | `<img>` without `alt` (19:10) | Required in HTML5. |
| 5 | `with=300` (19:28) | Typo for `width`; ignored. |
| 6 | `height=auto` (19:44) | Must be an integer; ignored. With 5, no space is reserved for the image. |
| 7 | `<code><b>...</code></b>` (20:40, 21:34) | Overlapping tags; engines recover differently. Leaves elements open at the `</p>` (21:45). |
| 8 | `<html>` without `lang` (1:2) | Required by the ruleset; also an accessibility fix. |
| 9 | No `<meta charset>` | Missed by html-validate; a Nu rule, though Nu halts on `broken.html` before reaching it. Risk of wrong encoding. |
| 10 | `<abbr name="lobortis">` | `name` isn't allowed on `abbr`; should be `title`. Missed by html-validate; a Nu rule, though Nu halts on `broken.html` before reaching it. |

### Valid but tidied

- `</a >`: whitespace in an end tag is allowed.
- Unquoted attributes (`href=https://www.bigcabal.com`, `src=test.jpeg`): valid
  since the values have no spaces, quotes, `=`, `<`, `>` or backticks.
  html-validate's `attr-quotes` is a style rule. Quoted anyway, since a future
  query string would break them.

### W3C Nu

Run on 2026-10-16; output is in `validation-output.txt`. `index.html` returns
no messages. `broken.html` stops with a fatal error at the stray `<body>` (line 2),
so Nu reports only 3 errors before giving up. Command:

```bash
curl -H "Content-Type: text/html; charset=utf-8" \
     --data-binary @05-html/index.html \
     "https://validator.w3.org/nu/?out=gnu"
```

## B. Accessibility and style

| Change | Why |
| --- | --- |
| `lang="en"` | Screen readers pick the right pronunciation. |
| Descriptive `alt` | `alt=""` validates but tells the reader nothing. |
| `<abbr title="lobortis">` | Valid and announced by assistive tech. |
| `<button type="button">` | Default is `submit`, which bites if the markup ends up in a form. |
| `width="300" height="200"` | Reserves space, avoids CLS. Size confirmed with Pillow. |
| `<meta name="viewport">` | Otherwise mobile renders at ~980px and scales down. |
| Quoted attributes, indentation | Style. |

## C. HTML-only SEO improvements

1. **Descriptive `<title>`.** `Example` was the worst issue. It's the main
   relevance signal and the clickable line in results.
2. **`<meta name="description">`.** Controls the snippet. Not a ranking factor
   but affects click-through.
3. **`<link rel="canonical">`.** Consolidates UTM, trailing-slash and AMP
   variants into one indexed URL.

Also markup-only: `<main>`/`<article>`/`<header>` landmarks (help screen readers
too), and Open Graph tags for shared links.

## D. Note for question 6

`index.html` keeps exactly three `<p>` elements, the third containing the
`<img>`. Q6 depends on this. `06-js/index.html` is this file plus a `<script>`.
