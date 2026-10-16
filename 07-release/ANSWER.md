# Question 7: Stale article after a theme release

## Assumptions

- Typical WordPress stack: browser → CDN → origin page cache → object cache
  (Redis/Memcached) → MySQL.
- Normal deploy, with a known-good previous version.
- "Some readers" means logged-out readers. Check 1 confirms this.

Readers seeing different versions fits several causes, and the fix for one can
make another worse, so I'd narrow it down before acting.

## First three checks

### 1. Response headers

Get a URL, time and description from a couple of reporters, then:

```bash
curl -sI 'https://site/the-article/' | grep -iE 'age|x-cache|cache-control|etag|last-modified|vary'
```

Look for high `Age`, `X-Cache: HIT`, an unexpectedly long `Cache-Control`, or a
`Vary` splitting the cache. Repeat from different locations (or `--resolve` to
different PoPs). Code and content bugs are usually uniform; variation between
readers points at a cache.

### 2. Origin, bypassing caches

```bash
curl -s 'https://origin.site/the-article/?cachebust=12345' | grep -o 'the corrected sentence'
```

- Origin correct, readers stale → caching.
- Origin stale → not (only) caching; go to 3.

### 3. Database

Does the post contain the correction? Check `post_modified` and revisions.

- DB correct, origin stale → **code**. The release changed how content is
  fetched or cached (new transient, changed cache key, different field).
- DB missing the fix → **content**. Edit not saved, saved as a revision, made on
  a duplicate or translation, or overwritten.

Cheapest first, and each step removes a branch.

## Caching vs code vs content

| | Caching | Code | Content |
| --- | --- | --- | --- |
| Origin with cache-bust | correct | stale | stale |
| Database | correct | correct | missing fix |
| Who sees it | varies | everyone | everyone |
| Over time | clears as TTLs expire | persists | persists |
| Rollback helps? | no | yes | no |

Two complications:

- A release can cause a caching problem (changed keys, new transient, changed
  `Vary`). Then roll back **and** purge; bad entries outlive the code.
- Cache layers expire independently. Purging the CDN but not the object cache
  looks fixed for a few minutes, then regresses.

## Rollback

- **Roll back** if the origin is stale with caches bypassed and the timing
  matches the release.
- **Roll back anyway** if impact is wide and there's no cause within an agreed
  window (~15 minutes). Restore known-good, then diagnose.
- **Don't roll back** for pure cache propagation. It purges nothing and adds
  another variable. Purge the affected URLs instead.

I'd push back on an immediate second release. If it's caching, a release does
nothing; if it's content, no release helps; and if the first release caused it, a
rushed second one can make it worse. A purge fixes the reader-facing problem in
seconds. I'd explain that and commit to a specific time for the next update.

## Verifying recovery

1. Origin renders the correction with caches bypassed.
2. Multiple edges: `Age` reset, `X-Cache` MISS then fresh HIT.
3. Logged out and logged in.
4. A fresh device on mobile data.
5. Two or three other recently corrected articles, in case it's systemic.
6. Keep watching for at least the longest TTL in the stack.
7. Original reporters confirm on their own devices.

Then document the cause and purge procedure.

## Update to the editor

> The corrected version is live at the source, and the stale copies some readers
> are seeing are coming from cache, which we're clearing now; I'll confirm once
> I've checked it from several locations.
>
> I'd hold the extra release until then, because if this is a cache issue a new
> deploy won't change what readers see and will make it harder to tell whether
> the fix worked.

(Assumes check 2 showed the origin correct. If not, the message would say we're
rolling back and give a recovery time.)
