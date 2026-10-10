# Editorial workflow

Pages and posts share the history/review screen linked from their editor. Admins publish; editors prepare drafts and request review from an assigned administrator. Changes to an already-published record made by an editor are stored as a full proposal, leaving the live publication unchanged. Approval applies the exact proposal selected by the review, after checking that the live content still matches the review fingerprint. Changes made after the request require a new review. Review requests, decisions and comments retain their authors.

## Full versions

`editorial_versions` contains full attributes, media ids with collection/order and taxonomy ids. It is separate from `content_revisions`, the append-only hash ledger used by snapshots. Saving captures the previous persisted state before mutation, including the first programmatic write after an upgrade, and captures the resulting state. Admin controllers and media/taxonomy attachment helpers also capture association changes. Restoration creates another version and a draft; it never silently republishes historical content. Restoring a live publication requires an administrator. Missing deleted associations are skipped; retained versions keep media referenced for deletion protection.

The history screen compares leaf fields, shows proposal authors and provides an explicit restore confirmation. Restore and per-field translation review honor active editing leases and content fingerprints. Review approval also respects editing leases. Opening history itself does not reserve the record.

## Recovery drafts

The editor debounces structured form snapshots into browser storage and a user-scoped server draft retained for 14 days. It excludes CSRF, lease tokens, return URL and publication status. Draft snapshots never update the public content record. The recovery prompt chooses the latest retained local/server draft and explains conflicts with newer saved content. Recovering reconstructs repeaters and checkbox/multiple-select selections, hydrates media previews, and leaves explicit saving to the user. A successful save clears its recovery draft while preserving edits entered during that save. Network failures retain the browser copy and retry when connectivity returns. Expired server drafts are pruned by `cms:expire-content`.

## Scheduling and preview links

Publication requires `status = published`, `published_at <= now`, and either no `expires_at` or an expiry in the future. Both `published()` and `isPublished()` apply these checks, so expiry is effective even when the scheduler has not run. `cms:expire-content`, scheduled every minute, records the expired publication as a draft and prunes expired recovery data. Use the admin calendar to inspect publication and expiry dates by month.

Shared preview links have a random bearer token stored only as a SHA-256 hash, expiry of 1–168 hours and explicit revocation. A selected history/proposal version can be shared; otherwise the author's latest proposal is selected before falling back to the persisted content. Rendering a proposal hydrates its fields and associations in memory. Public responses need no admin session, forbid caching/indexing/framing and form submission, suppress the admin bar and analytics, and send no-referrer. Links are excluded from snapshots.

## Translation review

The history screen shows the previous acknowledged source value and the current source value for each changed translatable schema path. The previous value comes from full source versions; old installations without a matching version explicitly show that the previous value is unavailable. Removed base fields are represented by a deterministic removal hash, so deleted repeater paths also require review. Each field can be acknowledged independently against its current base hash; a concurrently changed source returns a conflict. Approving all stale paths updates the source fingerprint. Normal translation saves retain the existing stale-field validation.

## Verification

`EditorialWorkflowTest` covers both entities, version restoration, first-write baselines, recovery isolation/retention/conflicts, role separation over real MCP REST/RPC bearer requests, exact proposal approval and preview, repeater removal, expiry and granular translation review. `tests/JavaScript/editor-recovery.test.mjs` covers retained-draft selection and repeater reconstruction; the existing editor safety tests cover lease loss and edits made during async saving.
