# Includes architecture

The directory follows the `RRZE\Appointment` namespace. Subdirectories are
used for groups with a single, clear responsibility:

- `Controller/` handles incoming AJAX, REST, and public-link workflows.
- `Booking/` contains booking availability, persistence, and lifecycle state.
- `Mail/` renders and persists reusable email templates.
- `Notification/` schedules or sends event-driven notifications.
- `Presentation/` delivers frontend assets and public appointment pages.
- `Common/` contains reusable framework code that is not appointment-specific.

Classes remain at the root when they represent a core appointment concept or
when no meaningful group exists yet. Avoid catch-all directories such as
`Services/`, `Helpers/`, or `Utils/`; create a namespace only when its name
describes a cohesive responsibility shared by multiple classes.

When moving a public class, add a compatibility alias to the plugin autoloader
so existing integrations keep working during the transition.
