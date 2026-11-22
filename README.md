# PurgeIncompleteResponses — LimeSurvey plugin

Deletes **incomplete** (never submitted) survey responses — including "Resume later" drafts — once they are
older than a retention period. A default period applies to all active surveys; any survey can have its own
period or be kept forever. Runs from cron. Before you save the settings, it shows what will change and how many
responses that affects.

*Русское описание — [ниже](#по-русски).*

## Why

Every time someone opens a survey and leaves without submitting, LimeSurvey keeps an incomplete response.
On busy surveys these pile up into hundreds of thousands of rows that nobody reads: they bloat the responses
tables, exports and statistics. LimeSurvey has no built-in retention for them — only manual bulk delete.

## Features

- **Default retention period** for all active surveys (1 hour … 365 days), on/off.
- **Per-survey periods** that always override the default one; "Keep forever" excludes a survey.
  Set them on the plugin page or in the survey's settings → "Plugins".
- **Count-only mode by default**: nothing is deleted until you switch "Delete responses" on.
- **Review before saving**: what changes, how many responses the next run would delete, per survey.
- **Schedule on the page**: last and next cron run; a warning if cron is not set up or stopped.
- **Report of the last run** with per-survey numbers.
- **Translated interface**: English and Russian; the admin's interface language is used.

## Safety

- **Complete responses are never deleted.** Both the selection and every `DELETE` have `submitdate IS NULL`,
  so a response submitted while the plugin runs stays.
- **Per-run limit** (50 000 by default): a wrong setting cannot wipe millions of rows in one go.
- **One run at a time** (a database lock on MySQL/MariaDB and PostgreSQL).
- Deletion is the same as the bulk delete on the Responses screen: the response, its timings and uploaded files,
  plus the "Resume later" record (`saved_control`), which the core leaves behind.
- Deleted responses cannot be restored. Make sure nobody needs a survey's incomplete responses before cleaning it
  (e.g. ratings passed in the survey link are stored even if the respondent never submits).

## How the age of a response is counted

- **Survey with "Date stamp" on and not anonymized** — from the last saved page (`datestamp`).
  A respondent who comes back makes the response "younger".
- **No date stamp, or anonymized survey** (`datestamp` is always 1980-01-01 there) — from the start of the response,
  using checkpoints the plugin records on every run ("at time T the highest response id was N").
  Such a survey starts being cleaned one period after it gets a period. A "Resume later" draft stays
  while its save date is younger than the period.
- Times are UTC.

## Requirements

- LimeSurvey **7.0 or later** (tested on 7.5.1).
- Database: tested on **MySQL 8**. Queries use the Yii query builder; PostgreSQL and MSSQL are expected to work but
  have not been tested. On MSSQL there is no lock against parallel runs.
- Access to the server's crontab.

## Installation

1. Download `PurgeIncompleteResponses-<version>.zip` from [Releases](../../releases)
   and upload it in **Configuration → Plugins → Upload & install**.
   Or copy the folder to `upload/plugins/PurgeIncompleteResponses` (owned by the web server user).
2. Activate the plugin.
3. Add a cron job (as the web server user), e.g. `/etc/cron.d/limesurvey-purge-incomplete`:

   ```
   */5 * * * * www-data cd /path/to/limesurvey && php application/commands/console.php plugin cron --interval=5 2>&1 | logger -t limesurvey-purge
   ```

   The plugin remembers `--interval` to show the next run and to warn when cron stops.
4. Open the plugin settings, choose periods, check the numbers in the review window, save.
   "Delete responses" stays **Off** (count only) until you switch it on.

## Manual runs

```
php application/commands/console.php plugin --target=PurgeIncompleteResponses --function=dryrun   # count only
php application/commands/console.php plugin --target=PurgeIncompleteResponses --function=run      # delete now
```

`run` deletes regardless of the "Delete responses" switch.

## Disk space

InnoDB does not shrink table files after `DELETE`: the freed space is reused for new responses, so after the
first big cleanup the table simply stops growing. To return the space to the disk once, your DBA can rebuild the
table, e.g. `OPTIMIZE TABLE lime_responses_123456;` (needs free disk space about the size of the table). The plugin
deliberately does not do this — it is a database maintenance task. On a test copy, a table with 41 % incomplete
responses shrank from 204 MB to 120 MB after cleaning and rebuilding.

## Tests

- `tests/static.sh` — PHP syntax, `config.xml`, translations (every string is translated, `.mo` is up to date),
  no secrets or private addresses; PSR-12 if `phpcs` is installed.
- `tests/integration/run.sh` — LimeSurvey 7.5.1 and MySQL 8 in Docker (needs Docker and Python 3). Installs the
  plugin from the release zip through "Upload & install", creates surveys of every kind (date stamp, no date stamp,
  anonymized, own period, keep forever, file upload question), fills them with complete and incomplete responses
  and drafts, and checks: complete responses are never touched, only old incomplete ones are deleted with their
  drafts and files, the review window agrees with the run, the per-run limit, checkpoints, and the interface language.
  `KEEP=1` leaves the containers running at http://localhost:8099/index.php/admin.

Both run on GitHub Actions for every push.

## Translations

Strings are in `PurgeIncompleteResponses.php` (`$this->t(...)`); translations live in
`locale/<lang>/<lang>.po` and are compiled to `<lang>.mo` (e.g. `msgfmt -o locale/de/de.mo locale/de/de.po`).
Pull requests with new languages are welcome — use `locale/ru/ru.po` as a template.

## License

[GPL-3.0-or-later](LICENSE.txt).

---

## По-русски

Плагин для LimeSurvey 7+: удаляет **незавершённые** ответы (в том числе черновики «Сохранить и продолжить позже»)
старше срока хранения. Есть общий срок для всех активных опросов, у любого опроса может быть свой срок
или «Хранить бессрочно». Запускается по cron. Перед сохранением настроек показывает, что изменится и сколько
ответов удалит ближайший запуск.

- **Завершённые ответы не удаляются никогда**: условие `submitdate IS NULL` стоит и в отборе, и в каждом `DELETE`.
- По умолчанию — **только подсчёт**; удаление включается переключателем «Удалять ответы».
- **Предел за запуск** (50 000) защищает от ошибки в настройках.
- Интерфейс — на языке админки (английский, русский).

Установка — как выше: загрузить zip в «Конфигурация → Плагины», включить, добавить задание cron
(`*/5 * * * * … plugin cron --interval=5`), задать сроки и проверить числа в окне перед сохранением.
Возраст ответа: в опросах с отметкой даты — от последней сохранённой страницы; в анонимных и без отметки даты —
от начала ответа (по засечкам плагина, первое удаление — через срок после назначения).
