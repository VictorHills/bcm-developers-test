# Question 8: Initiative and judgment

## What I'd ask or observe first

I'd watch one editor publish a real article end to end before asking anything.
Described processes leave out the steps people no longer notice.

What I'd note:

- **Tools, order, and what moves between them.** Titles, links and images likely
  need different formats per tool (shortened headline, UTM-tagged URL, cropped
  image).
- **Where time goes.** Often the switching (finding tabs, waiting for loads)
  rather than the copying.
- **What the mistakes are.** Typos, stale links copied before a slug change,
  wrong image, skipped steps. Each needs a different fix.
- **Mechanical vs judgement steps.** Pasting a URL is mechanical; shortening a
  headline is a decision. Only automate the first kind.
- **Volume.** Five articles a day vs fifty.

Then ask: "What would you rather spend this time on?" and "When it goes wrong,
how do you find out?"

## Smallest useful improvement

A "publish pack" metabox on the post edit screen, showing each value the
downstream tools need with a copy button:

- canonical URL with tracking parameters
- headline, plus a version trimmed to the tightest character limit, with count
- social/hero image URL at the right size, and alt text
- excerpt

No integrations, credentials or sync. It only reads the post and formats it.
About a day's work. It removes retyping, and the URL is generated at copy time so
it can't be stale.

### Lower-code alternative

A bookmarklet that reads the article's fields from the page or REST API and shows
the same copyable values. About thirty lines of JS, no deploy. Plus a short
written checklist, which would help more than either if the problem turns out to
be skipped steps rather than typos.

### Why the panel

The obvious alternative is pushing to each tool automatically. On a one-day
budget I'd avoid it: credentials for every tool, error handling for every
failure mode, ongoing maintenance as APIs change, and it removes the editor's
check before things go out.

The panel removes the mechanical work and keeps the judgement. If it breaks,
editors are back to today's process.

I'd ship the bookmarklet in the first hour to test my understanding before
building the panel.

### What stays with editors

- final headline wording (the short version is a suggestion)
- image choice and cropping
- whether to post to a given tool
- when to publish
- ignoring the panel entirely

The panel never writes anywhere.

## Testing with one editor

Pick whoever does this most often.

- Baseline first: time two or three publishes and note errors.
- A week of real work, not a demo.
- Observe the second session, not the first.
- Log every edit to suggested text. If they rewrite every short headline, the
  trimming logic is wrong.
- Ask what they'd remove, not what they'd add.

## Measuring success

Main signal: still used after two weeks without prompting.

Supporting:

1. Post-publish errors (wrong links, mismatched images, stale titles). This was
   the actual complaint, so it has to move.
2. Time from "article ready" to "distributed everywhere".
3. Whether other editors start using it on their own.

Counter-metric: any new kind of mistake it makes easier.

If errors haven't moved and nobody uses it after two weeks, remove it.

## A similar improvement I made

Not editorial tooling, but the same problem: a repeated manual step people kept
getting wrong. On a team PHP project, everyone normalised formatting by hand
before pushing, and it was regularly missed, so reviews stalled on style instead
of logic. I added a shared git pre-push hook running Laravel Pint, so the
standard no longer depended on memory. The first version was slow, so I
optimised it. Formatting stopped coming up in reviews, saving a few hours of
review time. Lesson: an automatic check only works if it is fast enough that
people don't mind it running.
