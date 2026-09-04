# Includes architecture

The directory follows the `RRZE\Appointment` namespace. Subdirectories are
used for groups with a single, clear responsibility:

- `Controller/` handles incoming AJAX, REST, and public-link workflows.
- `Admin/` contains focused WordPress administration pages and request handling.
- `Booking/` contains booking availability, persistence, and lifecycle state.
- `Configuration/` validates and exposes persisted plugin configuration.
- `Mail/` renders and persists reusable email templates.
- `Notification/` schedules or sends event-driven notifications.
- `Presentation/` delivers frontend assets and public appointment pages.

Classes remain at the root when they represent a core appointment concept or
when no meaningful group exists yet. Avoid catch-all directories such as
`Services/`, `Helpers/`, or `Utils/`; create a namespace only when its name
describes a cohesive responsibility shared by multiple classes.

Compatibility aliases are reserved for active classes that change namespace;
unused legacy subsystems should be removed instead of carried indefinitely.
