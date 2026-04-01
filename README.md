# RRZE Appointment

[![Version](https://img.shields.io/github/package-json/v/rrze-webteam/rrze-appointment/main?label=Version)](https://github.com/RRZE-Webteam/rrze-appointment)
[![Release Version](https://img.shields.io/github/v/release/rrze-webteam/rrze-appointment?label=Release+Version)](https://github.com/RRZE-Webteam/rrze-appointment/releases/)
[![GitHub License](https://img.shields.io/github/license/rrze-webteam/rrze-appointment)](https://github.com/RRZE-Webteam/rrze-appointment)
[![GitHub issues](https://img.shields.io/github/issues/rrze-webteam/rrze-appointment)](https://github.com/RRZE-Webteam/rrze-appointment/issues)

---

## Overview

**RRZE Appointment** 

WordPress plugin for handling appointments.

---

## Description

RRZE Appointment lets you manage appointments and office hours directly in WordPress. Features include one-click booking, email reminders, early-slot notifications, login via SS=, and automatic user data import from FAUdir. Customizable templates and Gutenberg block integration make setup quick and flexible.

---

## Functionality

- Provides a Gutenberg block related to appointments
- Handles appointment data within WordPress

---

## Features
- Quick and easy creation of consultation/office hours
- One-click booking for users
- Reminder email sent 1 day before the appointment
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

---

## Dependencies

Required for full functionality; plugin also works standalone with reduced features.

- [RRZE Access-Control](https://gitlab.rrze.fau.de/rrze-webteam/rrze-ac)
- [RRZE SSO](https://github.com/RRZE-Webteam/rrze-sso)
- [RRZE FAUdir](https://github.com/RRZE-Webteam/rrze-faudir)

With these plugins, integration with SSO and FAUdir is enabled.
Users booking appointments log in via SSO using their IdM credentials.
Person data can be conveniently imported into the calendar when the FAUdir WordPress plugin is active.
For example, office hours or consultation appointments are automatically generated in the calendar if they are defined in FAUdir as "office hours" or "consultation hours".

---

## License

Licensed under the [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html).

---

## Credits

Developed and maintained by the  
**RRZE Webteam, Friedrich-Alexander-Universität Erlangen-Nürnberg (FAU)**  
👉 [https://github.com/RRZE-Webteam/rrze-appointment](https://github.com/RRZE-Webteam/rrze-appointment)

