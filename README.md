# OpenID Connect Username Sanitizer

Sanitises [OpenID Connect](https://www.drupal.org/project/openid_connect) SSO username-candidate
claims (`preferred_username` and `name`) before they're used to generate a Drupal username, so a
character an identity provider sends that Drupal's own username validation rejects can't silently
block account creation.

## The problem

`openid_connect`'s `OpenIDConnect::createUser()` builds the Drupal username from the SSO claims
`preferred_username` or `name`, in preference to the account's email address. Neither
`openid_connect` nor its `openid_connect_windows_aad` sub-module sanitises that value before
Drupal validates it.

When the claim contains a character outside Drupal's allowed username set - or is simply too long
- account creation fails outright, and `openid_connect` logs only a generic error with no
indication of the actual value that was tried:

```
Failed to create user account for user@example.com: The username contains an illegal character.
```

A real-world example: an identity provider that prefixes organisation-type accounts' display
names, e.g. `"[Acme Ltd] Jane Doe"`, will produce this error for every such account - the square
brackets are outside Drupal's allowed username character set - with no record anywhere of what the
actual claim value was.

This is a known, previously-reported issue:

- [OpenID connect doesn't validate usernames (#3294141)](https://www.drupal.org/project/openid_connect/issues/3294141) -
  the same symptom: an identity-provider-supplied name containing an illegal character breaks
  account creation. A patch was submitted stripping invalid characters using the same validation
  logic this module uses. Closed as a duplicate of #3252021.
- [OpenId Connect don't check username when exceeded (#3252021)](https://www.drupal.org/project/openid_connect/issues/3252021) -
  the issue #3294141 was merged into, covering both the illegal-character case and the case where
  the claim exceeds Drupal's 60-character username limit. Since `openid_connect` `3.0.0-alpha7`,
  `createUser()` validates the username/email and throws an exception with a logged error if
  either is invalid, rather than attempting to create an invalid account - which is the error
  shown above.
- [No way to alter userinfo before getting 'name' property (#3021049)](https://www.drupal.org/project/openid_connect_windows_aad/issues/3021049) -
  covers the same class of problem specifically for `openid_connect_windows_aad`'s
  `displayName`/`name` claim handling, and confirms `hook_openid_connect_userinfo_alter()` (the
  hook this module uses) as the intended place for a site to intercept and fix a claim like this
  before it's used.

This module addresses both linked problems - illegal characters and excessive length - in one
place, via that hook.

## Requirements

None declared. This module deliberately has **no dependency on `openid_connect`**: its hook
implementation is simply never called on a site that doesn't have `openid_connect` (or
`openid_connect_windows_aad`) enabled, so it's safe to install alongside any other module set. It
does nothing at all unless `openid_connect` is also installed and an SSO login actually returns a
`preferred_username` or `name` claim.

## Installation

Install like any other module, either via the UI or Drush:

```bash
drush pm:enable openid_connect_username_sanitizer -y
```

Installing the module creates the `openid_connect_username_sanitizer.settings` configuration
object with sanitisation enabled by default. No further setup is required.

## Configuration

Settings form: `/admin/config/system/openid-connect-username-sanitizer` (also linked from
Administration » Configuration » System, and from this module's row on the Extend page).
Permission: `administer site configuration`.

A single checkbox controls whether sanitisation runs at all. Disable it to fall back to
`openid_connect`'s original, unmodified behaviour - for example, if you'd rather usernames simply
fail to be created than be silently altered, and prefer to fix the identity provider's claim
format at the source instead.

## How it works

`hook_openid_connect_userinfo_alter()`:

1. For each of `$userinfo['preferred_username']` and `$userinfo['name']`, if set:
   - Strips any character Drupal's `UserNameConstraintValidator` would reject. Both of that
     validator's regular expressions are applied, one Unicode character at a time, exactly as
     core itself tests them - see the code comment on `_openid_connect_username_sanitizer_sanitize_username()`
     for why the precise patterns (including a deliberately-missing `/u` modifier on one of them)
     matter, and must be kept in sync with core after a major Drupal upgrade.
   - Collapses repeated spaces and trims - a username can't start or end with a space, or contain
     two in a row.
   - Truncates to `UserInterface::USERNAME_MAX_LENGTH - 5` characters (55), leaving headroom for
     `openid_connect`'s own `_1`, `_2`, ... suffix on a duplicate username.
   - If nothing usable survives, unsets the key entirely, so `openid_connect` falls through to its
     next candidate (the other claim, then its own safe hash-based fallback) rather than trying to
     use an empty username.
2. If a claim was changed, logs a **debug**-level message to the `openid_connect_username_sanitizer`
   logger channel, recording the field name and both the original and sanitised values. This is
   rate-limited via Drupal's core flood control service (per SSO client, so a busy but
   well-behaved client isn't silenced by a different, misbehaving one on the same site) to prevent
   log flooding from a persistently misconfigured identity provider.

`hook_module_implements_alter()` moves this module's `hook_openid_connect_userinfo_alter()`
implementation to run last, so any other module's own implementation of the same hook sees the
identity provider's raw, unaltered claim values first; this module only ever sanitises whatever is
left once every other hook has had its turn.

Because the debug log records the raw claim value before and after sanitisation, restrict access
to your site's logs (the `access site reports` permission) as you would for any other log that may
contain personal data from your identity provider.

## Tests

A PHPUnit Kernel test (`tests/src/Kernel/UsernameSanitizerTest.php`) checks the sanitiser's output
against Drupal's real username field validation (not just this module's own copy of the rules),
covers claims with no usable characters at all, confirms the hook leaves already-valid claims
untouched, and confirms the hook-ordering and disabled-config behaviour described above.
