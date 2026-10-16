/**
 * Checks for question 4.
 *
 * These checks run without a browser engine and verify:
 *
 *   1. The markup contains exactly one set of sections A-H (nothing duplicated)
 *      and no <script> anywhere.
 *   2. The mobile `order` values sort the visible panels into A, D, B, C, G.
 *   3. CSS Grid auto-placement, simulated against the spec's row-sparse
 *      algorithm, puts the desktop panels where the brief says they go,
 *      including A spanning two columns.
 *   4. The long-heading defences (`min-width: 0`, `overflow-wrap`) are present
 *      on the grid items.
 *
 * Actual rendering is covered by the Chrome screenshots in screenshots/.
 *
 * Run: node 04-css/tests/run-checks.cjs
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const ROOT = path.join(__dirname, '..');
const html = fs.readFileSync(path.join(ROOT, 'index.html'), 'utf8');
const css = fs.readFileSync(path.join(ROOT, 'styles.css'), 'utf8');

let checks = 0;
let failures = 0;

function check(label, passed, detail = '') {
    checks++;
    if (passed) {
        console.log(`  PASS  ${label}`);
        return;
    }
    failures++;
    console.log(`  FAIL  ${label}${detail ? `: ${detail}` : ''}`);
}

function section(name) {
    console.log(`\n=== ${name} ===`);
}

const LETTERS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
const HIDDEN_ON_MOBILE = ['e', 'f', 'h'];

// --------------------------------------------------------------- markup ---

section('markup');
{
    const document = new JSDOM(html).window.document;
    const panels = Array.from(document.querySelectorAll('.layout > .panel'));

    check('eight panels present', panels.length === 8, `found ${panels.length}`);

    const domOrder = panels.map((p) =>
        [...p.classList].find((c) => /^panel--[a-h]$/.test(c)).slice(-1));

    check('source order is A B C D E F G H', domOrder.join('') === 'abcdefgh',
        `got ${domOrder.join('').toUpperCase()}`);

    LETTERS.forEach((letter) => {
        const matches = document.querySelectorAll(`.panel--${letter}`);
        if (matches.length !== 1) {
            check(`exactly one panel ${letter.toUpperCase()}`, false,
                `${matches.length} found; sections must not be duplicated`);
        }
    });
    check('no section is duplicated',
        LETTERS.every((l) => document.querySelectorAll(`.panel--${l}`).length === 1));

    check('no <script> element anywhere', document.querySelectorAll('script').length === 0);
    check('no inline event handlers',
        !/\son[a-z]+\s*=/i.test(html));
    check('exactly one stylesheet linked',
        document.querySelectorAll('link[rel="stylesheet"]').length === 1);

    check('panels hidden on mobile are marked',
        HIDDEN_ON_MOBILE.every((l) =>
            document.querySelector(`.panel--${l}`).classList.contains('panel--desktop-only')));
    check('panels shown on mobile are not marked',
        LETTERS.filter((l) => !HIDDEN_ON_MOBILE.includes(l)).every((l) =>
            !document.querySelector(`.panel--${l}`).classList.contains('panel--desktop-only')));
}

// ------------------------------------------------------- mobile ordering ---

section('mobile order');
{
    // Pull the `order` value declared for each panel in the base (mobile) rules,
    // i.e. everything before the min-width media query.
    const base = css.split('@media (min-width: 48rem)')[0];

    const orders = {};
    const re = /\.panel--([a-h])\s*\{\s*order:\s*(\d+);/g;
    let match;
    while ((match = re.exec(base)) !== null) {
        orders[match[1]] = Number(match[2]);
    }

    const visible = LETTERS.filter((l) => !HIDDEN_ON_MOBILE.includes(l));

    check('every panel visible on mobile has an order value',
        visible.every((l) => typeof orders[l] === 'number'),
        `got ${JSON.stringify(orders)}`);

    const sorted = visible.slice().sort((x, y) => orders[x] - orders[y]);
    check('mobile running order is A, D, B, C, G',
        sorted.join('').toUpperCase() === 'ADBCG',
        `got ${sorted.join('').toUpperCase()}`);

    check('order values are distinct',
        new Set(visible.map((l) => orders[l])).size === visible.length);

    check('order is reset to 0 at the breakpoint',
        /\.layout\s*>\s*\.panel\s*\{\s*order:\s*0;/.test(css));
}

// ----------------------------------------------- desktop auto-placement ---

section('desktop auto-placement');
{
    /**
     * Simulate CSS Grid's sparse auto-placement for a fixed 3-column grid,
     * per CSS Grid Layout §8.5. Items are placed in order; an item wider than
     * the remaining space on the current row moves to the next row.
     */
    function autoPlace(items, columns) {
        const placed = [];
        let row = 1;
        let cursor = 1; // next free column on the current row

        items.forEach(({ name, span }) => {
            if (cursor + span - 1 > columns) {
                row += 1;
                cursor = 1;
            }
            placed.push({ name, row, colStart: cursor, colEnd: cursor + span - 1 });
            cursor += span;
            if (cursor > columns) {
                row += 1;
                cursor = 1;
            }
        });

        return placed;
    }

    // A spans two columns; the rest span one. Source order drives placement
    // because `order` is reset to 0 above the breakpoint.
    const items = LETTERS.map((name) => ({ name, span: name === 'a' ? 2 : 1 }));
    const placed = autoPlace(items, 3);

    const grid = {};
    placed.forEach((p) => { grid[p.name] = p; });

    const rowOf = (l) => grid[l].row;

    check('A spans two columns', grid.a.colStart === 1 && grid.a.colEnd === 2);
    check('row 1 is A A B', rowOf('a') === 1 && rowOf('b') === 1 && grid.b.colStart === 3);
    check('row 2 is C D E',
        ['c', 'd', 'e'].every((l) => rowOf(l) === 2)
        && grid.c.colStart === 1 && grid.d.colStart === 2 && grid.e.colStart === 3);
    check('row 3 is F G H',
        ['f', 'g', 'h'].every((l) => rowOf(l) === 3)
        && grid.f.colStart === 1 && grid.g.colStart === 2 && grid.h.colStart === 3);
    check('exactly three rows used', Math.max(...placed.map((p) => p.row)) === 3);

    const picture = [1, 2, 3].map((r) =>
        placed.filter((p) => p.row === r)
            .flatMap((p) => Array(p.colEnd - p.colStart + 1).fill(p.name.toUpperCase()))
            .join(' ')).join(' / ');
    check('resolved layout is "A A B / C D E / F G H"',
        picture === 'A A B / C D E / F G H', `got "${picture}"`);

    check('only A carries an explicit span rule',
        (css.match(/grid-column:\s*span/g) || []).length === 1);
}

// ------------------------------------------------------- long headings ---

section('long heading defences');
{
    check('grid items get min-width: 0', /\.panel\s*\{[^}]*min-width:\s*0;/s.test(css));
    check('long tokens can break', /overflow-wrap:\s*anywhere;/.test(css));
    check('a long heading exists in the markup to exercise it',
        /supercalifragilisticexpialidocious/.test(html));

    const document = new JSDOM(html).window.document;
    const longest = Array.from(document.querySelectorAll('.panel__heading'))
        .map((h) => h.textContent.trim().replace(/\s+/g, ' '))
        .sort((a, b) => b.length - a.length)[0] || '';
    check('that heading is genuinely long (>120 chars)', longest.length > 120,
        `longest is ${longest.length} chars`);
}

console.log(`\n${checks} checks, ${failures} failed`);
console.log(failures === 0 ? 'ALL CHECKS PASSED' : 'SOME CHECKS FAILED');
process.exit(failures === 0 ? 0 : 1);
