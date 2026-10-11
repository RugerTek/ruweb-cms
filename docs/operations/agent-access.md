# Connect and recover agent access

Both administration profiles keep login, Users, Profile > Security and MCP access. Installation creates the
first administrator with `cms:install`; there is no public administrator signup. The operator can create
another account and issue its first credential without an agent already connected.

## First connection

1. Sign in to the configured admin login path (`login` by default). An enrolled account must complete its
   authenticator challenge or use an unused recovery code.
2. Create the operating account in Users and assign its role. Account roles and credential scopes are
   independent of `full`/`essential`; the profile controls the human interface only.
3. Sign in as the operating account and open MCP. Issue a named key with the smallest suitable scope and
   an expiration. Copy the key immediately: the plaintext is displayed once and cannot be recovered.
   Enroll 2FA **before** issuing integrations: enabling/disabling it revokes existing credentials.
4. Configure the client's HTTP MCP server with the displayed `/mcp/rpc` URL and bearer credential.
   Start a fresh client session and call `cms_capabilities`, then `cms_schema` before writing content.
5. Read back the changed record. A draft-only integration can prepare editorial work; publication needs
   an authorized administrator and an appropriate credential. Custom Code additionally requires the
   account's specific grant and the separate `custom-code` scope.

Named keys are independent. To rotate one integration, issue a new named key, verify its connection, then
revoke its predecessor from MCP. Creating a named key does not rotate other keys. An expired key cannot be
reactivated; issue a replacement. Losing a key's plaintext also requires a replacement. Reconnect the client
after changing its credential; a cached catalog is not proof that its next operation is authorized.

## API-key authentication only

API keys are the sole MCP and REST credentials. OAuth authorization, token exchange and discovery endpoints
have been removed. Existing OAuth tokens no longer authenticate; create a named key in MCP and replace the
client's bearer credential. No OAuth client ID, secret, callback or environment configuration is needed.
Admin login, users, password recovery and two-factor authentication remain available.

## Client compatibility and limits

Use [the phase 9 results](../plans/phase-9-results.md) for exact installed-client versions, evidence and the
current gate status. Phase 9 was accepted with the owner’s explicit deferral of the real Claude test;
Claude remains pending and uncertified. Codex API-key operation is verified in both profiles. Historical OAuth evidence describes earlier releases and does not apply to this API-key-only release.
API-key configuration examples below do not certify an untested client release. Rüweb offers stateless JSON HTTP calls, not SSE subscriptions or general MCP background-task APIs.
It negotiates the supported `2024-11-05` protocol revision and announces only tools. `serverInfo.version`
comes from `VERSION`; it is independent of the protocol revision. `ping` returns `{}` for every valid scope.

For Codex, use a named HTTP MCP server and `bearer_token_env_var`; for a controlled unattended integration,
use the client's per-server/tool approval settings and an explicit tool allowlist. Store credentials outside
versioned project files. For Claude Code, a named HTTP MCP server accepts an Authorization header; use a
private configuration file, or the client's supported secure credential mechanism. Do not place secrets in
shell arguments, shared logs or committed JSON. Client prompts never grant server permissions.

A transport 401 means missing, expired or revoked credentials. Scope and account-role denials return HTTP 200
with RPC `-32003` and `error.data.reason`; they are refusals, not server failures. A controller-level failure can return
`result.isError: true` with a diagnostic in its text content. Inspect these fields, not just HTTP 200.
Conflict errors use RPC `-32009` (REST 409); validation errors identify the input to correct. This release
preserves legacy error envelopes rather than silently changing their meaning.

Legacy page/post/menu/media/settings mutations still accept omitted `expected_revision` for compatibility.
Without it, serialization prevents concurrent transaction corruption **but does not protect against stale
replacement input**. Read the current revision, merge the intended change and send it. New editorial,
submission and Custom Code mutations require the revision and durable `operation_key` as their contracts
specify. When a response disappears after a commit, retry the identical arguments and key; do not invent a
new key. The receipt recovers the committed result. A different payload under the same key conflicts.
Preview issuance is the documented exception: retrying can issue another link; revoke unwanted links.

List pages are bounded to 100 rows, editorial strings to 8192 bytes/characters according to their tool,
with explicit truncation diagnostics and range reads. Do not send a clipped read as replacement content.
Binary upload uses a short-lived, actor/credential-bound ticket and multipart transfer; it is synchronous,
not a background MCP task. Notification delivery is queued: `submissions_get` exposes queued/retrying/
sending/failed/sent/skipped state and attempts; poll that record, repair authorized transport configuration
when required, then request an idempotent retry. No tool offers arbitrary SQL or server execution.

## Lost access

With an authenticated session, use Profile > Security to revoke integrations or other sessions. For a lost
password, use the login's password-recovery link and the mailbox verification procedure. A successful reset
invalidates every session, named/legacy key.

If email delivery is unavailable, an authorized operator with access to the installation can run:

```sh
php artisan cms:user:reset-password account@example.test
```

The command asks for the new password without echoing it and performs the same revocation. Sign in with
the new password and complete 2FA using the authenticator or an unused recovery code, then issue fresh
connections from the panel. Password recovery does **not** disable the authenticator; keep recovery codes
outside the CMS before losing the device. There is no public 2FA bypass or self-elevation endpoint. Loss
of both the authenticator and every recovery code requires the installation operator's documented security
incident procedure; do not edit encrypted secrets or restore a snapshot merely to bypass 2FA.

For exact session and snapshot invalidation guarantees, see [account security](../admin/account-security.md).
