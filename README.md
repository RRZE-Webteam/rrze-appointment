[![Version](https://img.shields.io/github/package-json/v/rrze-webteam/rrze-appointment/main?label=Version)](https://github.com/RRZE-Webteam/rrze-appointment)
[![Release Version](https://img.shields.io/github/v/release/rrze-webteam/rrze-appointment?label=Release+Version)](https://github.com/RRZE-Webteam/rrze-appointment/releases/)
[![GitHub License](https://img.shields.io/github/license/rrze-webteam/rrze-appointment)](https://github.com/RRZE-Webteam/rrze-appointment)
[![GitHub issues](https://img.shields.io/github/issues/rrze-webteam/rrze-appointment)](https://github.com/RRZE-Webteam/rrze-appointment/issues)

# RRZE Appointment

WordPress plugin for handling appointments.

## Contributors

* RRZE-Webteam, http://www.rrze.fau.de 

## Documentation

See documenation at https://www.wp.rrze.fau.de

## Feedback

* https://github.com/RRZE-Webteam/rrze-appointment/issues
* webmaster@rrze.fau.de


## Description

RRZE Appointment lets you manage appointments and office hours directly in WordPress. Features include one-click booking, email reminders, early-slot notifications, login via SSO, and automatic user data import from FAUdir. Customizable templates and Gutenberg block integration make setup quick and flexible.



## Functionality

- Provides a Gutenberg block related to appointments
- Handles appointment data within WordPress


## Features
- Quick and easy creation of consultation/office hours
- One-click booking for users
- Reminder email sent 1 day before the appointment
- Configurable automatic deletion of completed booking data
- Notifications when an earlier slot becomes available
- Integration of person data from FAUdir
- User-friendly booking management
- Flexible customization options
- Consistent with the FAU corporate design
- Templates for customizing email notifications
- Includes ready-to-use templates
- Adapted to the layouts of the "FAU Einrichtungen" and "FAU Elemental" themes
- Supports FAU faculty color schemes
- Can be inserted as a Gutenberg block in posts and pages for flexible placement
- Optional list view groups available times under date headings without a calendar
- Site Editor templates and template parts are not supported

### List view

In the appointment block's Inspector, open **Appointment display** and enable
**Show available slots as a list**. The website then shows all available dates
in chronological order, with time buttons beneath each date heading. Booked,
past, and closed slots are omitted. Future slots whose booking window has not
opened retain the **Notify me** option. The editor's planning calendar remains
available; existing blocks use the calendar view by default.

### Encrypted booking storage

Back up the database and preserve the encryption key separately before rollout.
Configure `RRZE_APPOINTMENT_ENCRYPTION_KEY` in `wp-config.php` with Base64 for
32 or 64 random bytes (for example, generated with `openssl rand -base64 64`).
Migration starts automatically on the next request and resumes automatically
after interruption. No customer action is required. **Settings → RRZE Appointment**
shows the status. If the key is already configured, updating to this version
enables automatic migration on the next request.

Multisite additionally schedules a network worker that visits up to ten sites per
cron run, including sites without visitors. Progress and errors appear in network
administration. Cron must run reliably on the coordinator site (normally the main
site); visitor-triggered WP-Cron alone provides no completion deadline.

The migration encrypts booking and notification data, preserves existing guest
links, and replaces sensitive cron arguments with opaque references. Public
blocks, mail templates and ordinary settings remain editable. Errors stop access
instead of treating existing data as empty. See the [storage operating guide](includes/Storage/README.md)
for deployment, interrupted migrations, multisite, backups and restore requirements.
Calendar attachments are prepared entirely in memory. Personal responses use
private/no-store headers, and unexpected dependency errors never expose their
original messages or exception chains. Auxiliary slot locks use keyed references
and expired locks are removed in bounded batches.

### Optional SSO protection for appointment management

The general settings contain an independent **SSO protection for appointment management** section.
It is disabled by default, separately for every site. Setup can activate an installed and permitted
RRZE-AC plugin on the current site, then requires a fresh request and a matching SSO login before
protection can be enabled. Public bookings, emails, cron and encrypted-storage migration remain independent.

The management page and its REST list/cancellation routes require both the existing WordPress
permission and an active SSO session mapped to the same WordPress account. RRZE-AC and RRZE-SSO
must be active and configured; the RRZE-SSO helper requires its `force_sso` configuration and
initialized identity providers. Setup never changes that configuration or existing AC rules.
An unrecognized IdP, missing mapping or dependency failure denies access. Local accounts without
a matching SSO identity cannot access protected appointment data, including network administrators.

Before explicit user restrictions are configured, site administrators need matching SSO to disable protection or change legacy appointment managers. After setup, only responsible administrators may change SSO protection; responsible editors may manage grants.
Network administrators have a separate, nonce-protected emergency reset in the same settings section.
It explicitly removes the additional requirement on that site; it is not a data-access bypass.
Deactivating AC later leaves protection enabled and denies management access until repaired or reset.

The non-autoloaded site option is `rrze_appointment_backend_sso_required`. Ordinary settings saves
do not modify it. Activation honors WordPress capabilities, plugin visibility (`all_plugins`),
network-only restrictions, WordPress dependency checks and the optional
`rrze_appointment_can_activate_access_control` filter (boolean, current blog ID).
Networks with a separate plugin allowlist should enforce it through that filter if they do not
already expose it through capabilities or plugin visibility. No other sites are scanned or activated.

REST failures use `rrze_appointment_sso_required` (401),
`rrze_appointment_sso_identity_mismatch` (403), and `rrze_appointment_sso_unavailable` (503).
They never redirect; the management UI clears displayed data on a failed request and offers an
explicit login when appropriate. Already displayed information cannot be recalled when an SSO
session expires: access is checked on every subsequent request. No SSO attributes or authorization
results are stored by this integration. Settings, management, login and error responses are private
and non-cacheable. Server configuration and other plugins remain trusted administrative components.

### Explicit appointment permissions and responsible people

Each site can deliberately set up **user restrictions** in the general settings. Existing installations
retain their administrator access and legacy manager list until this confirmation; no site is converted
by an update or a network scan. The preview makes the acting administrator responsible and offers
eligible previous managers for confirmation. Other administrators must be explicitly selected.
A new SSO activation requires this setup first; an already enabled SSO requirement remains unchanged.

After setup, only listed responsible people and ordinary authorized users may view and cancel
appointments. Administrators and network administrators have no implicit data access. Eligible users
must currently belong to the site and have `read` plus `edit_others_posts` or `manage_options`.
Roles, membership and the matching SSO session (when required) are rechecked on every request.
Deleting or demoting users removes their effective access immediately. Local accounts without a
matching SSO identity cannot use an SSO-protected management screen even when listed.

Responsible people use **Appointments → Manage access**, including editors without `manage_options`,
to grant view/cancellation access or appoint other responsible people. At least one eligible owner
must remain after each change. Restrictions persist independently of SSO and never revert to
administrator access because a list becomes empty or invalid. There is no regular "allow all admins"
action after setup. Other administrators see read-only responsibility information in settings.

Only a responsible administrator with matching SSO may normally enable/disable SSO. A network
administrator can explicitly **reassign responsibility** without SSO if owners are unavailable; this
preserves ordinary grants and the SSO requirement. If a damaged policy cannot be decoded, recovery
creates a valid policy from the explicitly selected owners without trusting damaged grants.
The separate **SSO emergency reset** removes only SSO, leaving explicit user restrictions intact.
Neither recovery action automatically adds its actor to the list. All writes require authenticated
POSTs and site/action/revision-bound WordPress nonces.

The non-autoloaded, per-site option `rrze_appointment_access_policy` stores a version, the restricted
state, owners, managers and a revision. It is separate from ordinary settings and booking encryption.
The old `appointment_manager_user_ids` field is ignored and preserved after setup. Security writes
share a bounded database advisory lock, refresh option caches, reject stale forms (409), and preserve
the previous policy on failure. Policy writes use compare-and-swap; failures deny access (503).
No SSO claims or authentication results are persisted. Server code and database administrators remain
trusted infrastructure; these application permissions do not isolate data from someone controlling PHP.

Users without a base grant do not see the Appointments menu. Direct links display a private, data-free
explanation. Users who only need to renew SSO still see the menu and an explicit sign-in action.
Responsible names are shown only to authenticated site members, never in denied REST responses.
The open application clears its list and cancellation dialog on access failures and rejects late responses.
The existing REST list/cancellation URLs and their SSO status codes remain unchanged.

Validation covers setup/legacy compatibility, ordinary and responsible editors, excluded admins,
SSO failures, nonce manipulation, last-owner protection, stale forms, role/member loss, network recovery,
site isolation, and late frontend responses. Public booking, mail, cron and encrypted migration retain
independent authorization paths. Build without a version bump using
`./node_modules/.bin/wp-scripts build --webpack-copy-php` followed by `npm run build:email`.

### Email addresses for bookings without SSO

Guest bookings and booking-opening notifications validate the submitted email
address on the server before reserving a slot, storing a subscription, or
sending mail. Surrounding ASCII spaces are removed; malformed addresses and
control characters are rejected. Domains are lowercased and internationalized
domains are converted to ASCII (Punycode) using nontransitional UTS #46. For
example, `Max+Termin@MÜLLER.DE` becomes `Max+Termin@xn--mller-kva.de`. The local
part before `@` retains its spelling, case, dots, and plus tags. Unicode local
parts are not supported. Address and domain-label length limits are checked
after conversion; the resulting address must not exceed 254 bytes.

Automatic IDN conversion requires PHP's `intl` extension (`idn_to_ascii`). If
unavailable, ASCII addresses (including Punycode domains) still work, and users
entering a Unicode domain are asked to use its Punycode form. The normalization
applies to new guest submissions; existing stored addresses are not migrated.

### Abuse protection for guest requests

Guest bookings and booking-opening notifications share per-site quotas:

- **5 requests per email address in 15 minutes**, across both forms and all source IPs.
- **300 requests per source IP in 15 minutes** as a generous backstop for shared
  university networks. Native IPv6 addresses share a quota within their `/64`;
  IPv4-mapped IPv6 addresses share the corresponding IPv4 quota.

Each window starts with its first counted request. Validation runs first;
quota checks run before a reservation, subscription, or mail is created.
The source quota counts valid attempts even if the recipient quota then rejects
them. Rejected attempts do not extend the window. The response uses HTTP 429,
a `Retry-After` header, and a translated message displayed in the existing form.
Authenticated SSO bookings do not consume guest quotas.

Email case is ignored for quota counting only; delivery still preserves the
local part. Plus tags and dots are not stripped. Counters store keyed hashes
derived from the WordPress nonce salt, expiry timestamps, and counts, without
raw email or IP addresses. Database counters use conditional writes to handle
concurrent requests and are not autoloaded or dependent on an evictable cache.
Expired counters stop restricting requests immediately and are removed by
WordPress's daily `delete_expired_transients` cron event while the plugin is active;
physical deletion depends on WP-Cron running. A storage failure temporarily
blocks new guest requests instead of allowing uncounted mail.

Site operators can adjust the quotas with a filter, for example in an MU plugin:

```php
add_filter('rrze_appointment_guest_request_limits', static function (array $limits): array {
    $limits['email'] = 5;
    $limits['ip'] = 300; // Set to 0 to disable only the source-IP quota.
    $limits['window'] = 15 * MINUTE_IN_SECONDS;
    return $limits;
});
```

Limits must be positive integers; only `ip` accepts `0`. The plugin uses
`REMOTE_ADDR` and ignores client-supplied forwarding headers. Behind a trusted
reverse proxy, configure the web server to supply the verified client address;
otherwise all visitors may share the proxy's quota. Increase or disable the IP
quota for sites where the shared source address would restrict legitimate demand.

### Appointment administration

The **Appointments** overview uses WordPress DataViews with search, sorting,
date and host filters, column selection, and pagination. Current bookings can
be cancelled from a confirmation dialog, with an optional reason when enabled
in the settings.

Only administrators and users explicitly authorized in the plugin settings
can access the overview. The administration REST endpoints enforce the same
site-specific permissions and require a valid REST nonce. They do not need
to be enabled as public endpoints.

When **Booking data retention** is greater than zero, the **Appointments**
overview offers **Current appointments** and **Past appointments** views.
Current appointments include ongoing appointments. After their end time,
appointments appear in the past view, newest first, until their retention
period expires. Past appointments cannot be cancelled from the overview.

In sensitive mode, the overview shows the appointment date, time, and title.
Host and booker details are hidden, including in REST responses. Bookings
confirmed in sensitive mode retain this protection after the mode is disabled;
their appointment titles remain visible.

With a retention period of zero, the past view is unavailable. The daily
cleanup continues to remove expired booking data from the database.


## Dependencies

Required for full functionality; plugin also works standalone with reduced features.

- [RRZE Access-Control](https://gitlab.rrze.fau.de/rrze-webteam/rrze-ac)
- [RRZE SSO](https://github.com/RRZE-Webteam/rrze-sso)
- [RRZE FAUdir](https://github.com/RRZE-Webteam/rrze-faudir)

With these plugins, integration with SSO and FAUdir is enabled.
Users booking appointments log in via SSO using their IdM credentials.
Person data can be conveniently imported into the calendar when the FAUdir WordPress plugin is active.
For example, office hours or consultation appointments are automatically generated in the calendar if they are defined in FAUdir as "office hours" or "consultation hours".


### Public booking endpoint and REST restrictions

When RRZE Settings controls REST access, visit an Appointment site once so the
endpoint is discovered. In Network Admin → RRZE Settings → REST API → Registered
public endpoints, approve **RRZE Appointment: booking login**. This grants access
only to `POST /rrze/v2/appointment/booker`. Registration alone grants no access;
with the current RRZE Settings registry, a namespace allowlist does not replace
this explicit approval. Keep the editor-only `/persons` endpoint restricted.

For older RRZE Settings versions without the endpoint registry, allow only
`/rrze/v2/appointment/booker` in the REST route allowlist.

The identity endpoint and its legacy AJAX equivalent accept only same-origin
POST requests (scheme, host, and port must match the site's configured home URL).
They return only the current SSO visitor's name/email or a login URL and use
`private, no-store` cache controls. SSO login itself uses a normal page redirect.
A valid SSO session is still required when submitting an SSO-enabled booking.

After deployment, test a logged-out browser: select a slot, complete SSO login,
verify the restored identity, and submit the booking. If the REST endpoint is
blocked or SSO fails, the page displays an error instead of an empty booking form.
