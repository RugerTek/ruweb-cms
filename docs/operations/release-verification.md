# Release verification

This guide owns the evidence required to turn a validated candidate into a release. Host deployment,
updates and rollback use [update-recovery.md](update-recovery.md); do not treat a release gate as a
production deployment. The [acceptance matrix](../plans/agent-first-v1.md#11-matriz-de-aceptación-de-10)
is the checklist, and the phase results record what was actually exercised and any owner-approved exceptions.

## Contract boundary

Freeze the candidate's application source, migrations, configuration registry, lockfiles and emitted build
before final verification. Record the exact Git revision and SHA-256 maps for any uncommitted candidate
changes. A branch name or declared VERSION alone does not identify the tested behavior.

The invariant contracts are:

- Both profiles use the same editorial data, schemas, public rendering, roles and credential scopes;
  profile-specific human surfaces follow [admin-profiles.md](../concepts/admin-profiles.md).
- Public queries enforce publication, future dates, expiry and public locales; title/description,
  canonical, alternates and sitemap follow [seo.md](../content/seo.md).
- Content media uses IDs; local paths/IDs remain portable. Forms retain code-owned validation and the
  complete anti-spam layer. Flexible HTML uses the shared sanitizer/registered style vocabulary.
- Published edits use proposals and explicit review/publication. Historical restore modes have
  distinct publication effects. New mutation receipts bind actor, operation key and identical input;
  legacy omitted revisions retain the stated limitation in [agent-access.md](agent-access.md).
- Custom Code requires its dedicated permission and exact activation; public destination rules and
  operational disable are independent of the administration profile.
- Snapshots carry authored content/history, invalidate installation authority, deactivate snippets and
  preserve the destination profile. They do not provide an atomic database/files rollback.

Use the linked contract documents for exact fields, limits and envelopes. Freeze does not imply arbitrary
future client/protocol compatibility or certify every deployment environment.

## Evidence and release decision

Run the accumulated deterministic suite and conventions against the candidate. Exercise both profiles
in fresh guarded MySQL copies, with separate storage and disabled outbound effects. Record runtime and
browser versions, build mode, source/build fingerprints, scenario results, mail boundary, sanitized client
results and cleanup. A response with HTTP 200 still requires inspecting RPC errors and `result.isError`.

The real client flow must cover creation, translation, media, preview/review/publication, submissions,
history, Custom Code scope/exclusions/deactivation and access/snapshot recovery. Pair those results with
anonymous desktop/mobile browser checks. Earlier evidence can explain a compatibility contract; it must
not silently stand in for an accumulated scenario required on the final candidate.

Compare public SEO semantics and resources against the reproducible 0.47.0 baseline. Label criteria adopted for a validation separately from previously agreed owner budgets; record their
justification and keep raw measurements available. Laboratory timings on loopback are diagnostic, not
production field Core Web Vitals; record both their distribution and the resource changes that explain
it. The [phase 11 laboratory](../plans/phase-11-seo-performance.md) records that boundary.

Any deferred client, minimum-runtime check, external provider or untested performance target must remain visible
in the release decision. Label each acceptance-matrix row passed, failed, pending or owner-excepted with
an evidence link and scope. Do not infer an exception from elapsed time or from another passing row.

Only after the acceptance decision, change VERSION and CHANGELOG together. If the release commit changes
only metadata/docs, verify that the frozen application/build fingerprints still match; if behavior or
assets change, rerun the affected flows and update the evidence. Record the final commit and release
artifact hashes. Publishing/deployment also requires the authorized host procedure and verified recovery
set. Retain only sanitized evidence in the repo; destroy disposable schemas, keys, private previews,
password-bearing logs, mail fixtures, snapshots and copied environments after verification.
