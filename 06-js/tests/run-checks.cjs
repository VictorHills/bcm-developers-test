/**
 * Checks for question 6.
 *
 * Loads the real index.html and the real app.js under jsdom and drives them,
 * rather than reimplementing the behaviour in the test. Covers:
 *   - separators on initial load
 *   - repeated clicks (1, 2, and 10 in a row)
 *   - that each batch is a true permutation of the three originals
 *   - that the shuffle actually varies
 *   - toggling before adding, and after adding
 *   - that hidden paragraphs still count for separator placement
 *
 * Run: node 06-js/tests/run-checks.cjs
 * Exits non-zero on any failure.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const ROOT = path.join(__dirname, '..');
const ParagraphWidget = require(path.join(ROOT, 'app.js'));

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

/** Fresh DOM + widget per scenario, so no test leaks into the next. */
function setup() {
    const html = fs.readFileSync(path.join(ROOT, 'index.html'), 'utf8');
    const dom = new JSDOM(html, { url: 'http://localhost/' });
    const { document } = dom.window;

    const container = document.querySelector('article');
    const addButton = document.getElementById('add-paragraphs');
    const widget = ParagraphWidget.init({ container, addButton, document });

    return { dom, document, container, addButton, widget };
}

/** The p/hr sequence inside the container, as a compact string like "P P HR P". */
function sequence(container) {
    return Array.from(container.children)
        .filter((el) => el.tagName === 'P' || el.tagName === 'HR')
        .map((el) => el.tagName)
        .join(' ');
}

/** What the sequence should be for n paragraphs: HR after every even position that is not last. */
function expectedSequence(n) {
    const parts = [];

    for (let i = 1; i <= n; i++) {
        parts.push('P');

        if (i % 2 === 0 && i < n) {
            parts.push('HR');
        }
    }

    return parts.join(' ');
}

/** Normalised text of a paragraph, for comparing a clone to its original. */
function signature(paragraph) {
    return paragraph.textContent.replace(/\s+/g, ' ').trim().slice(0, 40);
}

// ---------------------------------------------------------------------------

section('initial load');
{
    const { container, document } = setup();
    const paragraphs = container.querySelectorAll('p');

    check('starts with the three original paragraphs', paragraphs.length === 3);
    check('separators applied before any click', sequence(container) === expectedSequence(3),
        `got "${sequence(container)}"`);
    check('exactly one separator at n=3', container.querySelectorAll('hr').length === 1);
    check('no separator after the final paragraph',
        !sequence(container).endsWith('HR'));
    check('second button was created by the script',
        document.getElementById('toggle-images') !== null);
    check('second button sits next to the original',
        document.getElementById('add-paragraphs').nextElementSibling.id === 'toggle-images');
    check('second button is type=button',
        document.getElementById('toggle-images').type === 'button');
}

section('repeated clicks');
{
    const { container, addButton } = setup();

    addButton.click();
    check('one click yields six paragraphs', container.querySelectorAll('p').length === 6);
    check('sequence correct at n=6', sequence(container) === expectedSequence(6),
        `got "${sequence(container)}"`);
    check('two separators at n=6', container.querySelectorAll('hr').length === 2);

    addButton.click();
    check('two clicks yield nine paragraphs', container.querySelectorAll('p').length === 9);
    check('sequence correct at n=9', sequence(container) === expectedSequence(9),
        `got "${sequence(container)}"`);
    check('four separators at n=9', container.querySelectorAll('hr').length === 4);

    // Ten more clicks: invariants must hold all the way up.
    let allGood = true;
    let badAt = null;

    for (let i = 0; i < 10; i++) {
        addButton.click();
        const n = container.querySelectorAll('p').length;

        if (sequence(container) !== expectedSequence(n) || sequence(container).endsWith('HR')) {
            allGood = false;
            badAt = n;
            break;
        }
    }

    check('invariants hold across twelve clicks total', allGood,
        badAt ? `first divergence at n=${badAt}` : '');
    check('ends at 39 paragraphs (3 + 12 batches of 3)',
        container.querySelectorAll('p').length === 39);
    check('separators never trail the last paragraph',
        !sequence(container).endsWith('HR'));
    check('no two separators are adjacent',
        !sequence(container).includes('HR HR'));
}

section('batch contents');
{
    const { container, addButton, widget } = setup();

    const originals = widget.templates.map(signature).sort();

    addButton.click();
    const afterFirst = Array.from(container.querySelectorAll('p'));
    const appended = afterFirst.slice(3).map(signature).sort();

    check('appended batch is a permutation of the three originals',
        JSON.stringify(appended) === JSON.stringify(originals),
        `got ${JSON.stringify(appended)}`);
    check('originals are left in place and unreordered',
        JSON.stringify(afterFirst.slice(0, 3).map(signature))
        === JSON.stringify(widget.templates.map(signature)));
    check('clones are marked as clones',
        afterFirst.slice(3).every((p) => p.hasAttribute('data-clone')));
    check('originals are not marked as clones',
        afterFirst.slice(0, 3).every((p) => !p.hasAttribute('data-clone')));

    // Clones must come from the pristine templates, never from a previous clone,
    // otherwise data-clone attributes would nest and content could drift.
    addButton.click();
    const second = Array.from(container.querySelectorAll('p')).slice(6).map(signature).sort();
    check('second batch is also a clean permutation',
        JSON.stringify(second) === JSON.stringify(originals));
}

section('shuffle randomises');
{
    // Drive the real shuffle many times; all six permutations of three items
    // should appear. Probability of a miss across 300 draws is negligible.
    const seen = new Set();

    for (let i = 0; i < 300; i++) {
        seen.add(ParagraphWidget.shuffle(['a', 'b', 'c']).join(''));
    }

    check('all six permutations observed over 300 draws', seen.size === 6,
        `saw ${seen.size}: ${[...seen].join(', ')}`);
    check('shuffle preserves length and membership',
        ParagraphWidget.shuffle(['a', 'b', 'c']).sort().join('') === 'abc');

    // The input array must not be mutated: templates are reused every click.
    const input = ['a', 'b', 'c'];
    ParagraphWidget.shuffle(input);
    check('shuffle does not mutate its input', input.join('') === 'abc');
}

section('toggle before adding');
{
    const { container, addButton, document } = setup();
    const toggle = document.getElementById('toggle-images');

    const imageParagraphs = () =>
        Array.from(container.querySelectorAll('p')).filter((p) => p.querySelector('img'));

    check('one image paragraph at load', imageParagraphs().length === 1);
    check('visible initially', imageParagraphs().every((p) => p.hidden === false));

    toggle.click();
    check('toggle hides the image paragraph', imageParagraphs().every((p) => p.hidden === true));
    check('button label flips', toggle.textContent === 'Show image paragraphs');
    check('aria-pressed reflects state', toggle.getAttribute('aria-pressed') === 'true');

    const sequenceWhileHidden = sequence(container);

    addButton.click();
    check('newly added image paragraphs arrive hidden',
        imageParagraphs().every((p) => p.hidden === true),
        `${imageParagraphs().filter((p) => !p.hidden).length} visible`);
    check('two image paragraphs now exist', imageParagraphs().length === 2);
    check('non-image paragraphs stay visible',
        Array.from(container.querySelectorAll('p'))
            .filter((p) => !p.querySelector('img'))
            .every((p) => p.hidden === false));

    toggle.click();
    check('toggling back reveals all image paragraphs',
        imageParagraphs().every((p) => p.hidden === false));
    check('label flips back', toggle.textContent === 'Hide image paragraphs');

    // Sanity: hiding did not change the structure.
    check('sequence unchanged by hiding at the same paragraph count',
        sequenceWhileHidden === expectedSequence(3));
}

section('toggle after adding');
{
    const { container, addButton, document } = setup();
    const toggle = document.getElementById('toggle-images');

    addButton.click();
    addButton.click();

    const imageParagraphs = () =>
        Array.from(container.querySelectorAll('p')).filter((p) => p.querySelector('img'));

    check('three image paragraphs after two clicks', imageParagraphs().length === 3);

    toggle.click();
    check('toggle hides every image paragraph including clones',
        imageParagraphs().every((p) => p.hidden === true));

    addButton.click();
    check('still hidden after a further click',
        imageParagraphs().every((p) => p.hidden === true));
    check('four image paragraphs now', imageParagraphs().length === 4);

    toggle.click();
    toggle.click();
    check('double toggle leaves them hidden',
        imageParagraphs().every((p) => p.hidden === true));
}

section('hidden paragraphs still count for separators');
{
    const { container, addButton, document } = setup();
    const toggle = document.getElementById('toggle-images');

    addButton.click();
    const before = sequence(container);
    const hrCountBefore = container.querySelectorAll('hr').length;

    toggle.click();
    const after = sequence(container);

    check('sequence identical with images hidden', before === after,
        `before "${before}" / after "${after}"`);
    check('separator count unchanged by hiding',
        container.querySelectorAll('hr').length === hrCountBefore);
    check('hidden paragraphs are still in the DOM',
        container.querySelectorAll('p').length === 6);

    // And after adding more while hidden, placement still counts the hidden ones.
    addButton.click();
    const n = container.querySelectorAll('p').length;
    check('placement still correct with hidden paragraphs present',
        sequence(container) === expectedSequence(n),
        `n=${n}, got "${sequence(container)}"`);
    check('some paragraphs genuinely are hidden',
        Array.from(container.querySelectorAll('p')).some((p) => p.hidden === true));
}

section('separators are owned, not stolen');
{
    const { container, addButton, document } = setup();

    // An <hr> the author put in the markup must survive a rebuild.
    const authorHr = document.createElement('hr');
    authorHr.id = 'author-hr';
    container.appendChild(authorHr);

    addButton.click();

    check('an author-supplied <hr> is not removed',
        document.getElementById('author-hr') !== null);
    check('generated separators are flagged',
        Array.from(container.querySelectorAll('hr'))
            .filter((hr) => hr.id !== 'author-hr')
            .every((hr) => hr.hasAttribute('data-generated-separator')));
}

console.log(`\n${checks} checks, ${failures} failed`);
console.log(failures === 0 ? 'ALL CHECKS PASSED' : 'SOME CHECKS FAILED');
process.exit(failures === 0 ? 0 : 1);
