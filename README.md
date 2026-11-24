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
**Show available slots as a list**. The website shows the first month with available appointments, with time buttons
beneath each date heading. **More appointments** appends the next month with visible
times in chronological order. The calendar also loads additional months on demand. Booked,
past, and closed slots are omitted. Future slots whose booking window has not
opened retain the **Notify me** option. The editor's planning calendar remains
available; existing blocks use the calendar view by default.

Readers with access to a private WordPress post or page can browse its appointments.
Booking and opening notifications are available on published pages, including after
the visitor enters a required page password. When an older cached script displays
the form, the month links open the selected month on the same page.

### Encrypted booking storage

With a valid `RRZE_APPOINTMENT_ENCRYPTION_KEY` in `wp-config.php`, each new
reservation, booking and opening subscription is immediately stored as an
authenticated V2 record. An existing booking is adopted on its first business
change. Reading the calendar, opening an old guest link or viewing the
administration leaves its stored booking data untouched. Old links show a
preview and use a nonce-protected form to confirm the action.

Activation is site-local. The first encrypted write commits the record, its
index, the signed site policy and its scheduled work in one InnoDB transaction.
Existing plaintext records and their old cron arguments can remain until they
change, run or expire. **Appointments** and **Settings → RRZE Appointment** show
the current write policy to administrators (superadministrators on multisite).

Use Base64 for 32 or 64 cryptographically random bytes and back up the key
separately from the database. This version is a staging candidate. Before the
first production write, verify that all web, cron and CLI requests still using
2.3.3 have finished. An old request can already have accepted a slot that the
new booking process claims. Other sites can continue serving traffic during the
switch. See the [storage guide](includes/Storage/README.md) for format details,
read-only verification, scheduling and restore requirements.

### Optional SSO protection for appointment management

The **General** tab shows who can manage appointments and whether SSO is required.
Open **Access & SSO** on the relevant site's RRZE Appointment settings page to change either setting.
Access roles determine who can view or cancel bookings. Switching on SSO adds an identity check
for everyone on that site's appointment management screen. Each site's SSO setting starts off.

To enable protection, choose the responsible people and everyone who can view and cancel bookings.
Save these permissions, make sure the SSO integration is ready, and sign in with the SSO identity
that matches your own WordPress account. Then enable SSO for the site. Setup can activate an installed
and permitted RRZE-AC plugin on this site. Public bookings, emails, cron and encrypted-storage
migration keep their own access rules.

The management page and its REST list/cancellation routes require both the existing WordPress
permission and an active SSO session mapped to the same WordPress account. RRZE-AC and RRZE-SSO
must be active and configured; the RRZE-SSO helper requires its `force_sso` configuration and
initialized identity providers. Setup never changes that configuration or existing AC rules.
An unrecognized IdP, missing mapping or dependency failure denies access. Local accounts without
a matching SSO identity cannot access protected appointment data, including network administrators.

Responsible site administrators can change SSO protection with their matching SSO identity.
A multisite super administrator can also use their own matching SSO account to turn protection
on or off on behalf of a site, including one they do not belong to. Enabling protection requires
at least one currently eligible responsible person.
The super administrator receives booking access only when explicitly selected and eligible.
For a site that already required SSO before configuring explicit permissions, a site administrator
needs matching SSO to disable protection or change the legacy manager list. Deactivating AC later leaves
protection enabled and blocks management access until the integration is repaired or SSO is reset.

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

On a site using its original permissions, administrators and previously selected users can manage
appointments. In **Access & SSO**, select the people who should keep access, review the proposed
roles, and confirm the change. Eligible previous managers are offered for confirmation. After saving,
the list controls access for everyone, including administrators. Existing sites keep their current
permissions until someone explicitly saves this setup.

| Appointment role | View and cancel bookings | Assign appointment roles |
| --- | --- | --- |
| Responsible person | Yes | Yes |
| View and cancel | Yes | No |
| No access | No | No |

People selected for either role must belong to the current site and have `read` plus
`edit_others_posts` or `manage_options`. At least one eligible responsible person must remain.
Responsible people, including editors, can manage roles under **Appointments → Manage access**.
A multisite super administrator with a matching SSO identity can assign both roles on behalf of
the site and select another person as responsible. The acting super administrator can remain
outside the access list.

Membership, capabilities and, when required, the matching SSO session are checked on each request.
Removing or demoting someone takes effect immediately. Explicit restrictions also apply when SSO
is off. A damaged or empty policy blocks booking access. If the responsible people are unavailable,
a multisite super administrator can use the recovery controls without their own SSO session.
**Reassign responsibility** preserves ordinary grants and the SSO setting. If the policy is damaged,
the selected responsible people form a new policy. The separate **SSO emergency reset** turns SSO
off for this site and keeps its access list. Recovery gives the acting administrator no automatic
access to bookings. Changes require an authenticated POST with a WordPress nonce bound to the
site, action and observed revision.

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

Before a site configures user restrictions, administrators and its legacy appointment managers
can access the overview. Once restrictions are in place, only the people assigned an appointment
role can open it, with matching SSO when enabled. The administration REST endpoints enforce the same
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
