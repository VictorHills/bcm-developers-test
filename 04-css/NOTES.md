# Question 4: CSS

```bash
node 04-css/tests/run-checks.cjs   # 23 assertions
npx html-validate 04-css/index.html
```

One HTML file, one stylesheet, no JS, no duplicated sections. The checks assert
all four.

## Approach

Single three-column grid, mobile-first. Base rules are the mobile layout; one
`min-width: 48rem` query adds desktop.

- **Desktop:** `order` resets to 0, auto-placement walks the DOM (A to H) and A
  spans two columns. That alone yields `A A B / C D E / F G H`. The test
  simulates auto-placement and asserts this.
- **Mobile:** five `order` values: A=1, D=2, B=3, C=4, G=5.
- **E, F, H** use `display: none`, so they're also gone from the tab order and
  accessibility tree. `visibility: hidden` or an off-screen class would leave
  focusable links nobody can see.

Breakpoint is 48rem (768px), the phone/tablet gap. Using `rem` means users with a
larger base font get the single column at a wider viewport. `--breakpoint`
documents it, but `@media` can't read custom properties, so the query repeats the
value.

## Source order and accessibility

`order` and grid placement change visual order only. Tab order and screen-reader
order follow the DOM, so the DOM can match one layout, not both.

I used A B C D E F G H:

- **Desktop:** DOM and visual order match.
- **Mobile:** DOM is A B C G, visual is A D B C G. Focus goes A, B, C, then back
  up to D. That's a WCAG 2.4.3 (Focus Order) and 1.3.2 (Meaningful Sequence)
  issue, and real here because each panel has a link.

Matching mobile instead (A D B C G E F H) would make desktop diverge across eight
panels instead of four, so this is the smaller problem. A to H is also the
natural content order.

What I'd actually propose: if D really matters more than B and C on mobile, put
the DOM in mobile order and place desktop explicitly:

```css
@media (min-width: 48rem) {
    .layout {
        grid-template-areas:
            "a a b"
            "c d e"
            "f g h";
    }
    .panel--a { grid-area: a; }   /* ...and so on */
}
```

I didn't, because the brief doesn't say what the panels mean and I didn't want to
invent a priority.

Also:

- Each panel is `<section aria-labelledby>` so landmarks are announced by name.
- `:focus-visible` has a 3px outline, which makes the mobile focus jump easy to
  see.

## Long heading

Panel C's heading ends in `supercalifragilisticexpialidocious-00000000`. Grid
items default to `min-width: auto`, so an unbreakable word widens its track and
can cause horizontal scroll.

```css
.panel            { min-width: 0; }
.panel__heading   { overflow-wrap: anywhere; }
```

`anywhere`, not `break-word`: `break-word` still reports the full word as
min-content width, so the track stays wide.

## Screenshots

`screenshots/desktop.png`, `mobile.png` and `narrow-320.png`, taken in Chrome.
They confirm in a real engine what the checks verify by simulation: desktop
`A A B / C D E / F G H`, mobile A D B C G with E, F, H hidden, and the long
heading wrapping without overflow down to 320px.
