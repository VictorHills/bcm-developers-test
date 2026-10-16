/**
 * Question 6: paragraph duplication, separators, and an image toggle.
 *
 * Behaviour:
 *   1. Each click of the original button appends copies of the three original
 *      paragraphs, in a randomised order, after the last paragraph.
 *   2. An <hr> follows every pair of paragraphs, but only when another paragraph
 *      follows it. Applied on initial load and after every click.
 *   3. A second button, created here in JavaScript, toggles paragraphs that
 *      contain images. Paragraphs added while the toggle is "hidden" arrive
 *      hidden.
 *   4. Separator placement counts every paragraph, including hidden ones.
 *
 * Written as a UMD-ish module so the same source runs in the browser and under
 * jsdom in tests/run-checks.mjs: the test file drives the real functions
 * rather than a reimplementation of them.
 */

(function (root, factory) {
    'use strict';

    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.ParagraphWidget = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /** Marks the separators this script owns, so it never removes an author's <hr>. */
    const SEPARATOR_FLAG = 'data-generated-separator';

    /** Marks clones, so the pristine originals stay identifiable after any number of clicks. */
    const CLONE_FLAG = 'data-clone';

    /**
     * Fisher-Yates, on a copy. Unbiased: every permutation is equally likely,
     * unlike the `sort(() => Math.random() - 0.5)` idiom, whose distribution
     * depends on the engine's sort algorithm.
     *
     * @template T
     * @param {T[]} input
     * @returns {T[]}
     */
    function shuffle(input) {
        const items = input.slice();

        for (let i = items.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [items[i], items[j]] = [items[j], items[i]];
        }

        return items;
    }

    /**
     * @param {Element} paragraph
     * @returns {boolean}
     */
    function hasImage(paragraph) {
        return paragraph.querySelector('img') !== null;
    }

    /**
     * Wire the behaviour onto a container.
     *
     * @param {object} options
     * @param {Element} options.container Element holding the paragraphs.
     * @param {HTMLButtonElement} options.addButton The original button.
     * @param {Document} [options.document] Injectable for tests.
     * @returns {object} Handle exposing the internals the checks exercise.
     */
    function init({ container, addButton, document: doc }) {
        const ownerDocument = doc || container.ownerDocument;

        if (!container || !addButton) {
            throw new Error('ParagraphWidget.init needs a container and an addButton.');
        }

        /**
         * Pristine copies of the three paragraphs as they were at load, captured
         * before anything mutates the DOM. Every later batch clones from these,
         * so clones never compound (a clone of a clone of a clone).
         */
        const templates = Array.from(container.querySelectorAll('p'))
            .map((paragraph) => paragraph.cloneNode(true));

        /** Whether image-bearing paragraphs are currently hidden. */
        let imagesHidden = false;

        /** @returns {Element[]} Every paragraph, hidden ones included. */
        function allParagraphs() {
            return Array.from(container.querySelectorAll('p'));
        }

        /**
         * Rebuild separators from scratch.
         *
         * Removing and re-inserting is deliberate: after a batch is appended the
         * previously-correct positions may be wrong (a trailing paragraph that
         * had no separator now has one following it), and recomputing the whole
         * sequence is far easier to reason about than patching it. The cost is
         * trivial at this scale.
         *
         * An <hr> goes after paragraph i (1-based) when i is even AND another
         * paragraph follows. n=3 -> P P HR P; n=5 -> P P HR P P HR P;
         * n=6 -> P P HR P P HR P P (no trailing separator).
         */
        function rebuildSeparators() {
            container
                .querySelectorAll('hr[' + SEPARATOR_FLAG + ']')
                .forEach((hr) => hr.remove());

            // Counts hidden paragraphs too, as required: hiding images must not
            // shift where the separators land.
            const paragraphs = allParagraphs();

            paragraphs.forEach((paragraph, index) => {
                const position = index + 1;
                const isPairEnd = position % 2 === 0;
                const anotherFollows = position < paragraphs.length;

                if (!isPairEnd || !anotherFollows) {
                    return;
                }

                const hr = ownerDocument.createElement('hr');
                hr.setAttribute(SEPARATOR_FLAG, '');
                paragraph.after(hr);
            });
        }

        /** Apply the current toggle state to every image paragraph, new or old. */
        function applyImageVisibility() {
            allParagraphs()
                .filter(hasImage)
                .forEach((paragraph) => {
                    paragraph.hidden = imagesHidden;
                });
        }

        /** Append one randomised batch of the three originals after the last paragraph. */
        function addBatch() {
            const paragraphs = allParagraphs();
            const last = paragraphs[paragraphs.length - 1];
            const batch = shuffle(templates);

            // Build in a fragment, then insert once: one reflow instead of three.
            const fragment = ownerDocument.createDocumentFragment();

            batch.forEach((template) => {
                const clone = template.cloneNode(true);
                clone.setAttribute(CLONE_FLAG, '');
                fragment.appendChild(clone);
            });

            if (last) {
                last.after(fragment);
            } else {
                container.appendChild(fragment);
            }

            // Order matters: set visibility on the new paragraphs before
            // recomputing separators, so a newly added image paragraph is
            // already hidden the first time it is painted.
            applyImageVisibility();
            rebuildSeparators();
        }

        /** The second button, created in JavaScript as the brief requires. */
        const toggleButton = ownerDocument.createElement('button');
        toggleButton.type = 'button';
        toggleButton.id = 'toggle-images';
        toggleButton.textContent = 'Hide image paragraphs';
        toggleButton.setAttribute('aria-pressed', 'false');

        function setImagesHidden(nextHidden) {
            imagesHidden = nextHidden;
            toggleButton.textContent = imagesHidden
                ? 'Show image paragraphs'
                : 'Hide image paragraphs';
            toggleButton.setAttribute('aria-pressed', String(imagesHidden));
            applyImageVisibility();

            // Separators are NOT rebuilt here: placement counts hidden
            // paragraphs, so toggling cannot change it. Rebuilding would be a
            // no-op, and asserting that is one of the checks.
        }

        toggleButton.addEventListener('click', () => setImagesHidden(!imagesHidden));
        addButton.after(toggleButton);
        addButton.addEventListener('click', addBatch);

        // Initial load: separators apply before any click.
        rebuildSeparators();
        applyImageVisibility();

        return {
            addBatch,
            toggleImages: () => setImagesHidden(!imagesHidden),
            setImagesHidden,
            rebuildSeparators,
            allParagraphs,
            get imagesHidden() {
                return imagesHidden;
            },
            templates,
            toggleButton,
        };
    }

    return { init, shuffle, hasImage, SEPARATOR_FLAG, CLONE_FLAG };
}));

// Browser bootstrap. Skipped under Node, where there is no document.
if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.querySelector('article');
        const addButton = document.getElementById('add-paragraphs');

        if (container && addButton) {
            window.widget = (window.ParagraphWidget).init({ container, addButton });
        }
    });
}
