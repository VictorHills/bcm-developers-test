# Question 10: Security and code review

## The claim

> "It is safe because the button is visible only to editors."

Hiding a button controls what the UI offers, not what the server accepts. The
endpoint is a URL anyone can call with curl, Postman or the browser console:

```bash
curl -X POST https://site/wp-json/bcm/v1/article-status \
     -H 'Content-Type: application/json' \
     -b 'wordpress_logged_in_...=<any logged-in session>' \
     -d '{"id": 1234, "status": "publish"}'
```

Without server-side checks, any logged-in user (subscriber, contributor,
compromised account) can publish article 1234.

Even with a role check, three more problems:

- **CSRF.** A logged-in editor visiting a malicious page can be made to send this
  request; the cookie goes along automatically. A role check passes it. Only a
  nonce stops it.
- **Role isn't per-article permission.** `edit_posts` means "can edit posts in
  general". It should be `current_user_can('edit_post', $id)` for this specific
  post (private posts, others' drafts, workflow restrictions).
- **Unvalidated status.** Passing `status` straight to `wp_update_post()` lets
  someone who can edit but not publish set `publish`, or `trash`.

Hiding the button is fine UX, but it isn't access control.

## Required server-side checks

Cheapest first, so nothing hits the database until the request is authenticated
and has a valid nonce.

1. **Authentication.** Reject anonymous requests.
2. **Nonce.** `_wpnonce` / `X-WP-Nonce`, scoped to the action and article, e.g.
   `update_article_status_{$id}`.
3. **Input.** `absint()` the id; check status against an allowlist.
4. **Existence and type.** Post exists and is the expected post type.
5. **Object permission.** `current_user_can('edit_post', $id)`.
6. **Transition permission.** `publish_post` for `publish`, `delete_post` for
   `trash`.
7. **Apply and check.** `wp_update_post(..., true)`, handle `WP_Error`.
8. **Audit.** Who, which post, from/to status, when.

Add rate limiting near 1–2 if low-trust roles can reach the endpoint.

```
handle_status_change(request):
    user = current_user()
    if not user:
        return 401

    if not verify_nonce(request.nonce, "update_article_status_" + request.id):
        return 403

    id = absint(request.id)
    if id == 0:
        return 400

    ALLOWED = ["draft", "pending", "publish"]
    if request.status not in ALLOWED:
        return 400

    post = get_post(id)
    if not post or post.type != "post":
        return 404

    if not current_user_can("edit_post", id):
        return 403

    if request.status == "publish" and not current_user_can("publish_post", id):
        return 403

    result = wp_update_post({id: id, post_status: request.status}, true)
    if is_error(result):
        log_error(result); return 500

    audit_log(user.id, id, from: post.status, to: request.status)
    return 200
```

Distinct 404/403 responses reveal which ids exist. Fine between editors; if
low-trust roles can call this, return 404 for both.

In WordPress REST terms: checks 1–2 and 5–6 go in `permission_callback` (never
`__return_true`), checks 3–4 in the `args` schema with
`validate_callback`/`sanitize_callback`.

## Tests that must be rejected

**1. Low-privilege user, someone else's article.** Log in as a Contributor, get
a valid nonce, POST `{id: <editor's article>, status: "publish"}`. Expect 403 and
no DB change. Valid session and nonce, so only the capability check stops it.
This directly disproves the claim.

**2. CSRF against an editor.** Editor session, POST with no nonce, a nonce for a
different action, and an expired nonce. Expect 403 each time, no change.

**3. (If time) Status allowlist.** As an editor, POST `trash`, `private`,
`future` and a junk value. Expect 400, no change.

## Raising it

- In the PR, as comments on the specific lines.
- Attach a curl request against staging that publishes from a Subscriber
  account, with the response. That settles it faster than argument.
- Focus on the request, not the person: "this request gets through" rather than
  "you forgot permission checks". It's a common mistake.
- Be clear about what blocks release:

| Must fix before release | Can follow |
| --- | --- |
| Nonce verification (2) | Audit logging (8) |
| `edit_post` on the article (5) | Rate limiting |
| Publish/delete capability (6) | 404/403 collapsing |
| Status allowlist (3) | Moving validation into REST `args` |
| Tests 1 and 2 passing in CI | |

The blocking items are about fifteen lines. I'd offer to pair on them.

Also check whether the same pattern is already live elsewhere. If so, treat it as
an incident and review the audit logs.
