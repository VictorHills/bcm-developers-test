# Question 2: An editor cannot upload images, but a colleague can

One user works, one doesn't. The difference is in the user, the file, the
client, or (less likely) something server-side that only affects one of them.
The checks below aim to rule out whole categories quickly.

## Likely causes

1. **Missing `upload_files` capability.** "Editor" may not be the WordPress
   Editor role, or a plugin or theme code has removed the capability. The
   colleague may be an admin. Symptom: Media menu or "Add Media" is missing.
2. **File type or size rejected.** HEIC/WebP straight off a phone, SVG, or a file
   over `upload_max_filesize`, `post_max_size` or nginx `client_max_body_size`.
   Symptom: "file type is not permitted", HTTP 413, or the progress bar stalling
   at the same point.
3. **PHP memory exhausted generating thumbnails.** Upload succeeds, resize fails.
   Driven by pixel dimensions, not file size. Symptom: "post-processing of the
   image failed", 500 on `async-upload.php`, "Allowed memory size exhausted" in
   the PHP log.
4. **Client-side interference.** Ad blocker, corporate proxy, or a stale nonce
   from an expired session. Symptom: generic "An error occurred in the upload",
   403 on `async-upload.php`, works in incognito.
5. **Filesystem or quota.** Unwritable uploads dir, wrong ownership after a
   deploy, full disk, multisite quota. Usually breaks uploads for everyone, so
   less likely here.
6. **Security plugin or WAF** blocking by role, IP or user agent. Shows up in the
   plugin's log.

## Order of checks

1. **Get the exact error.** Screenshot plus the failing request from the Network
   tab. Often enough on its own.
2. **Swap files.** Editor uploads the colleague's file; colleague uploads the
   editor's.
    - Only the editor's file fails → file problem (2, 3).
    - Editor fails with any file → user or client (1, 4, 6).
3. **Incognito, other browser, other network.** Separates account (1, 6) from
   client (4).
4. **Check the actual capability**, `user_can($user, 'upload_files')`, not the
   role name. Role plugins make these diverge.
5. **For a file problem:** type, size, pixel dimensions against the PHP limits,
   `memory_limit` and allowed MIME types.
6. **Server and plugin logs** at the failure timestamp. Jump here first if the
   error was "post-processing failed".

## Fixes

- **Capability:** restore it, then find what removed it or it'll come back on
  the next deploy.
- **File type:** allow legitimate types via `upload_mimes` and confirm the server
  can generate thumbnails for them. Don't enable SVG site-wide without
  sanitisation. For phone photos, exporting JPEG at source is usually simpler.
- **Size:** raise the PHP and proxy limits together; raising one gives a
  different, more confusing failure.
- **Memory:** raise `WP_MEMORY_LIMIT` for admin and cap the dimensions WordPress
  processes. This one recurs as cameras improve, so fix it properly.
- **Client:** document the extension or proxy. If it's nonce expiry, look at
  session length.
- **Filesystem:** fix ownership, then fix the deploy that broke it.

Then: have the editor confirm on their own machine with their own file, write
down the cause, and check whether anyone else is quietly affected.

## Assumptions

Single-site install, and the editor could upload at some point. If it's a new
account that never worked, cause 1 goes to the top. If it stopped working, start
with what changed (deploy, plugin or core update, server change) and when.
