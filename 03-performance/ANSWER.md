# Question 3: A slow article page, particularly on mobile

## Measure first

"Slow" usually means one of:

- **Slow to appear** (TTFB, FCP): server or cache.
- **Slow to look finished** (LCP): hero image or render-blocking resources.
- **Slow to respond** (INP, TBT): almost always third-party JS.

Start with field data (CrUX or RUM) at p75, split by mobile/desktop and
anonymous/signed-in. Lab runs on a dev laptop understate the problem. "Mostly on
mobile" points at CPU-bound script and bytes over slow networks rather than
server time.

Tools: PageSpeed Insights gives CrUX field data and a Lighthouse run for the same
URL. Lighthouse (mobile preset, median of 3 to 5 runs) and the DevTools
Performance panel with CPU throttling are for diagnosis, not for proving a fix.

## Three candidates

### 1. Image delivery

The hero is usually the LCP element, and a 2000px JPEG on a 390px screen wastes
most of the critical-path bytes.

Measure: what the LCP element actually is (from field data), image bytes per
article, delivered vs displayed dimensions, format mix. Also check the hero isn't
`loading="lazy"`; plugins often do that and it hurts LCP. If LCP isn't an image,
drop this one. Lighthouse audits: "Largest Contentful Paint element", "Properly
size images", "Serve images in next-gen formats", "Largest Contentful Paint
image was lazily loaded".

### 2. Third-party JavaScript

Ads, analytics, consent and embeds typically outweigh the site's own JS and are
the main cause of poor mobile INP.

Measure: main-thread blocking by origin and long tasks by script, traced on a
mid-range device (DevTools Performance panel, 4x CPU throttling). I want a
ranked list ("consent script: 400ms"), not "third parties are slow". Lighthouse's
"Reduce the impact of third-party code" and Total Blocking Time give a first cut.

Probably the biggest win, but those tags carry revenue, so it needs numbers and
commercial sign-off before code.

### 3. Edge caching for anonymous traffic

Most readers are logged out and can get identical HTML.

Measure: p75 TTFB by cache status (CrUX, or CDN logs) and article hit ratio. If hit ratio is 95%+
and TTFB is ~200ms, skip it. If a cookie on every visitor is bypassing the cache,
it's a quick win.

## Pick: image delivery

Biggest likely LCP improvement, reversible, can ship behind a flag, and needs no
commercial negotiation. It also removes a variable before tackling third-party
JS.

Change: proper `srcset`/`sizes`, AVIF/WebP with fallback, explicit
`width`/`height`, `fetchpriority="high"` on the hero, `loading="lazy"` only below
the fold.

### Verifying it

- At least a week of baseline; news traffic varies by day and story.
- Roll out to ~10% and compare against the rest over the same window. If traffic
  can't be split, use matched weekday before/after and treat it as weaker
  evidence.
- Primary metric: p75 mobile LCP, anonymous. Secondary: CLS, image bytes, FCP.

### Guardrails

Agreed thresholds that trigger rollback regardless of LCP:

- **Ads:** lazy-loading changes when slots enter the viewport, which affects
  viewability and revenue. Compare viewability and RPM between groups; make sure
  no ad slot inherits a lazy attribute from a blanket filter.
- **Analytics:** pageviews and events per session between groups. A drop means
  tracking broke, which invalidates every other number. Check this first.
- **Signed-in:** different template, no ads, paywall. Confirm caching or image
  variants can't leak signed-in responses to anonymous users (log in, load, log
  out, reload).
- **Errors:** JS error rate and 4xx/5xx on image URLs, which catches bad format
  fallbacks.

The change is behind a flag so rollback is a toggle.

## Open questions

1. What does field data say the LCP element is? If it's an ad slot, option 2
   goes first.
2. Is there a target, e.g. p75 LCP under 2.5s on mobile?
3. Was it fast before? If so, find the regression (likely one plugin or tag)
   rather than optimising around it.
