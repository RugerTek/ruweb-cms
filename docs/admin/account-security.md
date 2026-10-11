# Account security

The profile information tab changes names and usernames immediately. A different email requires the current
password and a matching email confirmation. The address remains unchanged until the new mailbox follows an
expiring, signed, single-use verification link. Only the token digest is stored. Confirmation logs out the
account and revokes its sessions and MCP keys. Password recovery, password changes and
administrator credential changes cancel pending email changes too. Users changing their own credentials
from user management are directed to this profile flow.

Password recovery always returns the same public response for known, unknown and recently requested
addresses, with a per-IP request limit. A successful reset revokes all named and legacy MCP keys, rotates the remember token and invalidates all sessions.
The CLI password reset does the same. Profile password changes retain the initiating session, regenerate
its id and revoke the other credentials. Administrator changes to another user's password, email or role
revoke all of that account's credentials and sessions.

## Two-factor authentication

Profile > Security enrolls a standard RFC 6238 authenticator (SHA-1, six digits, 30-second time steps).
Enrollment requires the current password. The setup key can be entered manually into an authenticator;
activation requires its current code. Secrets and hashed recovery-code lists are encrypted using APP_KEY.
Confirmed enrollment displays eight recovery codes once. Each recovery code is usable once. Code
verification accepts one adjacent step in either direction and atomically rejects reuse of an already
accepted counter. Enabling/disabling protection revokes other sessions and existing integrations.
Disabling and replacing recovery codes require both the current password and a second-factor proof.

A successful password login for an enrolled account creates a five-minute pending challenge; it does not
create an authenticated admin session. The challenge is throttled and bound to the account's credential
version. Revoked sessions cannot re-enter the admin. The second-factor challenge is limited by account
and IP.

## Sessions and API keys

Profile > Security lists the current and recent authenticated admin sessions, their IP, browser and last
activity, with individual revocation and a sign-out-other-sessions action. Revocation requires the current
password and is owner-scoped. The session ledger stores SHA-256 session identifiers, never raw session
cookies. Revoking a session rotates the remember token to prevent re-entry through remembered login.
MCP keys are individually revoked from the MCP screen.

The ledger works with database, file, Redis and cookie session drivers. Credential versions invalidate
sessions independently of their backing store. Database session rows are deleted as an extra measure
when credentials are revoked. Legacy sessions without a version are accepted only for accounts still
at the initial version; remembered login cannot bypass a changed version or enrolled second factor.

## Snapshots

Snapshots exclude keys and sessions, and scrub pending-email, remembered-login and
second-factor data from user rows. Restoration clears destination credentials before replacing users and
assigns a fresh unpredictable credential version, invalidating sessions in every session driver. Accounts
must enroll their authenticator again after restore; encrypted secrets cannot safely travel between
installations with different APP_KEY values. Password hashes retain the existing snapshot behavior.

Keep production clocks synchronized and configure transactional email delivery. Deploy the account
security migration before exposing these routes. No third-party service receives authenticator secrets.

Human first connection, named-key rotation and CLI recovery are documented in
[connect and recover agent access](../operations/agent-access.md). Admin responses containing one-time keys,
2FA setup or recovery codes are private and must not be stored by caches.
