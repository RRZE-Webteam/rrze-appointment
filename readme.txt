=== RRZE Appointment ===
Contributors: rrze-webteam
Tags: appointments, calendar, booking
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.3.43
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Plugin URI: https://github.com/RRZE-Webteam/rrze-appointment
Author: RRZE Webteam
Author URI: https://www.rrze.fau.de
Text Domain: rrze-appointment

== Description ==

RRZE Appointment is a WordPress plugin for handling appointments. 
It provides a Gutenberg block for integrating appointments into posts and pages.

The plugin works with other RRZE plugins but can also be used standalone.

== Installation ==

1. Install the plugin via WordPress or upload it to /wp-content/plugins/
2. Activate the plugin
3. Use the "RRZE Appointment" block in the block editor

== Features ==

* Quick and easy creation of consultation/office hours
* One-click booking for users
* Reminder email sent 1 day before the appointment
* Integration of person data from FAUdir
* Flexible customization options
* Consistent with the FAU corporate design
* Templates for customizing email notifications
* Ready-to-use default templates included
* Adapted to the layouts of the "FAU Einrichtungen" and "FAU Elemental" themes
* Supports the color schemes of FAU faculties and central administration
* User-friendly booking management
* Can be inserted as a Gutenberg block in posts and pages for flexible placement

== Dependencies ==

Requires the following plugins to function:

* RRZE Access Control: https://gitlab.rrze.fau.de/rrze-webteam/rrze-ac
* RRZE SSO: https://github.com/RRZE-Webteam/rrze-sso
* RRZE FAUdir: https://github.com/RRZE-Webteam/rrze-faudir

With these plugins, integration with SSO and FAUdir is enabled. 
Users booking appointments log in via SSO using their IdM credentials. 
Person data can be imported automatically from FAUdir. 
For example, office hours or consultation appointments are generated if defined in FAUdir.

== Screenshots ==

1. Gutenberg block for appointments
2. Booking interface in the block editor
3. Example frontend output

== Changelog ==

= 0.3.13 =
* Initial release

== License ==

GPL-2.0-or-later
https://www.gnu.org/licenses/gpl-2.0.html

== Credits ==

Developed and maintained by the RRZE Webteam, Friedrich-Alexander-Universität Erlangen-Nürnberg (FAU)  
https://github.com/RRZE-Webteam/rrze-appointment