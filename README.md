# BCM Developer Test

Victor Chinonso Ugwu

## Setup

Requires PHP 8+ and Node 22.22+ or 24.8+ (html-validate and jsdom need it;
`.nvmrc` pins 24, so `nvm use` works).

```bash
npm install          # html-validate and jsdom (dev only)
./run-all-checks.sh  # runs every check, exits non-zero on failure
```

140 assertions across questions 1, 4, 5, 6 and 9. Individually:

```bash
php  01-php/tests/run-checks.php             # Q1: 25 assertions
php  01-php/tests/verify-original-faults.php # Q1: reproduces each original fault
node 04-css/tests/run-checks.cjs             # Q4: 23 assertions
npx  html-validate 05-html/index.html        # Q5: clean
node 06-js/tests/run-checks.cjs              # Q6: 48 assertions
php  09-newsletter/tests/run-checks.php      # Q9: 44 assertions
```

The pages in `04-css/`, `05-html/` and `06-js/` open directly in a browser. No
build step.

## Layout

| Question | Folder | Deliverable |
| --- | --- | --- |
| 1 · PHP | `01-php/` | `fixed.php`, `helpers.php`, `NOTES.md`, checks |
| 2 · Uploads | `02-uploads/` | `ANSWER.md` |
| 3 · Performance | `03-performance/` | `ANSWER.md` |
| 4 · CSS | `04-css/` | `index.html`, `styles.css`, `NOTES.md`, checks, `screenshots/` |
| 5 · HTML | `05-html/` | `index.html`, `NOTES.md`, `validation-output.txt` |
| 6 · JavaScript | `06-js/` | `index.html`, `app.js`, `NOTES.md`, checks |
| 7 · Troubleshooting | `07-release/` | `ANSWER.md` |
| 8 · Initiative | `08-initiative/` | `ANSWER.md` |
| 9 · APIs | `09-newsletter/` | `subscribe.php`, `NOTES.md`, checks |
| 10 · Security | `10-security/` | `ANSWER.md` |

## Use of AI

I used Claude (AI) for three things:

- **Tests:** generated the test harnesses under each `tests/` folder and
  `run-all-checks.sh`.
- **Docs:** generated the documentation (`NOTES.md` files and this README).
- **Comments:** added comments to parts of the code.

I verified its output by running it. Everything is backed by executable checks
rather than prose claims:

- **Q1:** `tests/verify-original-faults.php` runs each faulty construct and
  prints what PHP actually does. The fixed template has 25 assertions across the
  four scenarios in the brief.
- **Q4, Q6, Q9:** 115 assertions. Q6 drives the real `app.js` through jsdom
  click events. The Q9 mock reproduces the exact failure in the brief: the
  provider writes the record, then the request times out.
- **Q5:** before/after validator output in `validation-output.txt` (html-validate:
  16 errors, then clean; W3C Nu: fatal error, then clean).

Running the checks caught two real problems:

1. Helper functions declared inside `fixed.php` fataled with
   `Cannot redeclare bcm_current_page()` when the template was included twice.
   Moved to `helpers.php` behind `function_exists` guards.
2. A Q1 assertion expected an HTML search term to come out escaped. It was
   stripped instead, because `sanitize_text_field` removes tags before
   `esc_html` runs. The code was right; I fixed the test.
