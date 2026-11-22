# Changelog

## 1.1.1 — 2026-11-22

Security fix.

- A survey's own period can be changed in the survey settings only by users who may delete that survey's
  responses. Before, the permission to edit survey settings was enough, so such a user could make the plugin
  delete responses they were not allowed to delete. Without the permission the period is shown read-only.
- The review window (preview of the changes) requires the permission to change settings, not only to read them.
- Tests: static checks (PHP, translations, LimeSurvey coding standard, spelling) and Docker integration tests.

## 1.1.0 — 2026-11-22

First public release.

- Default retention period for all active surveys (on/off) and per-survey periods; "Keep forever" excludes a survey.
- "Surveys with their own period" table on the plugin page: add, change, remove.
- Review window before saving: what changes and how many responses the next run would count or delete.
- "Delete responses" switch, off by default (count only).
- Cron schedule on the page (last/next run), warning when cron is missing or stopped.
- Report of the last run, per survey; stored as data and shown in the viewer's language.
- Complete responses are never deleted: `submitdate IS NULL` is checked in every `DELETE`.
- After "Save" the page is reloaded with a GET (no "resend form" prompt on refresh).
- English and Russian interface.
