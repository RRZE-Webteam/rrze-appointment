# Email template

The responsive HTML email shell is compiled with Maizzle at build time. WordPress does not load Maizzle or Node.js at runtime.

- Run `npm run dev:email` for Maizzle's local preview.
- Run `npm run build:email` to compile `mail/emails/layout.vue` to `build/email/layout.html` and verify all runtime markers.
- Keep the `___RRZE_EMAIL_*___` markers intact; `MailTemplate::wrap()` replaces them with escaped WordPress values at send time.

The generated file is committed so packaged plugins can send mail without development dependencies.
