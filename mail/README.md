# Email template

The responsive HTML email shell is compiled with Maizzle at build time. WordPress does not load Maizzle or Node.js at runtime.

- Run `npm run dev:email` for Maizzle's local preview.
- Run `npm run build:email` to compile `mail/emails/layout.vue` to `build/email/layout.html` and verify all runtime markers.
- Keep the `___RRZE_EMAIL_*___` markers intact; `MailTemplate::wrap()` replaces them with escaped WordPress values at send time.

The generated file is committed so packaged plugins can send mail without development dependencies.

## Semantic status colors

`Settings::sendMail()` accepts a status after its attachments argument. The colors use Material palette values with a light surface and a high-contrast text color:

- `success`: confirmed bookings and reminders (green)
- `warning`: requests that still require confirmation (amber)
- `danger`: cancelled bookings (red)
- `neutral`: other appointment updates (brand blue)

Always keep the visible status label in the template so the message state is not communicated by color alone.

## Outlook compatibility

Maizzle still compiles the layout and provides its fixed-width Outlook container. The inner layout uses presentation tables: put spacing and background colors on `td` elements, not on `div` elements. The canvas table owns the full width and its cell owns the padding, avoiding horizontal overflow in browser-based clients.

`MailTemplate::detailsTable()` and `actionButton()` produce HTML at PHP runtime, after Maizzle has run. Keep these components compatible with email clients independently. Their outer spacing belongs on presentation cells, not table margins. The button's Outlook-only rules move its padding from the link to its cell; modern clients retain padding inside the clickable link. These runtime-only selectors must remain in the CSS purge safelist in `maizzle.config.ts`.

The logo uses explicit proportional dimensions capped at 200 × 64 pixels. The layout uses Arial/Helvetica with a generic sans-serif fallback. Dark-mode clients may adjust the paired foreground and background colors; do not force only selected surfaces back to white.

The shared layout applies to existing templates as well. Saved custom HTML (including the editable copy of the defaults) is preserved: to use the updated details table and button markup, reinsert the bundled HTML default or adapt the custom HTML. No stored content is migrated automatically.

After `npm run build:email`, run the mail template tests and inspect full rendered messages at narrow and wide widths. Browser previews and structural tests cannot replace a final rendering check in classic Outlook for Windows.
