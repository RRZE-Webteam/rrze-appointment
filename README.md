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
- Site Editor templates and template parts are not supported


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
