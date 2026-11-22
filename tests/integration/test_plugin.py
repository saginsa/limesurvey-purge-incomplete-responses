#!/usr/bin/env python3
"""Integration scenarios for PurgeIncompleteResponses (run by run.sh; standard library only).

The containers come from docker-compose.yml: LimeSurvey is installed, the plugin is not.
Steps go in order and share state; the first failure stops the run.
"""
import datetime as dt
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import time
import urllib.parse
import urllib.request
import uuid

HERE = os.path.dirname(os.path.abspath(__file__))
COMPOSE = ["docker", "compose", "-f", os.path.join(HERE, "docker-compose.yml")]
BASE = "http://localhost:%s/index.php" % os.environ.get("PIR_PORT", "8099")
ZIP = os.environ["PIR_ZIP"]
ADMIN, PASSWORD = "admin", os.environ.get("PIR_ADMIN_PASSWORD", "Pir-test-2026")
DEMO = "/var/www/html/docs/demosurveys/ls7_Samplesurvey_en_de.lss"
NAME = "PurgeIncompleteResponses"

NOW = int(time.time())
H = 3600
passed = 0


# ---------------------------------------------------------------- helpers

def step(title):
    print("\n== " + title, flush=True)


def check(cond, what, detail=None):
    global passed
    if not cond:
        print("FAIL  " + what + ("" if detail is None else "\n      " + str(detail)))
        sys.exit(1)
    passed += 1
    print("ok    " + what)


def sh(args, stdin=None):
    r = subprocess.run(args, input=stdin, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError("%s\n%s%s" % (" ".join(args), r.stdout, r.stderr))
    return r.stdout


def sql(query):
    """Runs SQL in the test database, returns rows as lists of strings (NULL -> None)."""
    out = sh(COMPOSE + ["exec", "-T", "db", "mysql", "-ulimesurvey", "-plimesurvey", "-N", "-B", "limesurvey"],
             stdin=query)
    rows = []
    for line in out.splitlines():
        rows.append([None if v == "NULL" else v for v in line.split("\t")])
    return rows


def scalar(query):
    rows = sql(query)
    return rows[0][0] if rows else None


def console(*args):
    return sh(COMPOSE + ["exec", "-T", "-u", "www-data", "web", "php", "application/commands/console.php"] + list(args))


def web_sh(command):
    return sh(COMPOSE + ["exec", "-T", "-u", "www-data", "web", "sh", "-c", command])


def ts(offset_seconds):
    return dt.datetime.fromtimestamp(NOW + offset_seconds, dt.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
opener_noredir = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)


def http(path, data=None, headers=None, follow=True):
    """Returns (status, final url, body, response headers)."""
    req = urllib.request.Request(BASE + path if path.startswith("/") else path, data=data, headers=headers or {})
    try:
        r = (opener if follow else opener_noredir).open(req, timeout=120)
        return r.status, r.geturl(), r.read().decode("utf-8", "replace"), r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.geturl(), e.read().decode("utf-8", "replace"), e.headers


def csrf(html):
    m = re.search(r'name="YII_CSRF_TOKEN"[^>]*value="([^"]+)"', html) or \
        re.search(r'value="([^"]+)"[^>]*name="YII_CSRF_TOKEN"', html)
    if not m:
        raise RuntimeError("no CSRF token on the page")
    return m.group(1)


def post_form(path, fields, follow=True):
    return http(path, urllib.parse.urlencode(fields).encode(),
                {"Content-Type": "application/x-www-form-urlencoded"}, follow)


def post_multipart(path, fields, file_field, filename, content):
    boundary = uuid.uuid4().hex
    parts = []
    for k, v in fields.items():
        parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode())
    parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\n'
                  'Content-Type: application/zip\r\n\r\n' % (boundary, file_field, filename)).encode())
    parts.append(content + b"\r\n")
    parts.append(("--%s--\r\n" % boundary).encode())
    return http(path, b"".join(parts), {"Content-Type": "multipart/form-data; boundary=" + boundary})


def login():
    _, _, html, _ = http("/admin/authentication/sa/login")
    status, url, body, _ = post_form("/admin/authentication/sa/login", {
        "YII_CSRF_TOKEN": csrf(html), "authMethod": "Authdb", "user": ADMIN, "password": PASSWORD,
        "loginlang": "default", "action": "login", "width": "1600", "login_submit": "login"})
    return "authentication/sa/logout" in body


def rpc(method, *params):
    body = json.dumps({"method": method, "params": list(params), "id": 1}).encode()
    # Own opener without cookies: RemoteControl would replace the admin's browser session
    req = urllib.request.Request(BASE + "/admin/remotecontrol", body, {"Content-Type": "application/json"})
    data = json.loads(urllib.request.urlopen(req, timeout=300).read().decode())
    if data.get("error"):
        raise RuntimeError("%s: %s" % (method, data["error"]))
    return data["result"]


def plugin_id():
    return int(scalar("SELECT id FROM lime_plugins WHERE name = '%s'" % NAME))


def report():
    value = scalar("SELECT value FROM lime_plugin_settings WHERE plugin_id = %d AND model IS NULL AND `key` = 'report'"
                   % plugin_id())
    return json.loads(value) if value else None


def configure_url():
    return "/admin/pluginmanager/sa/configure/id/%d" % plugin_id()


def save_settings(delete, common, default_hours, rules, max_per_run=50000):
    """Saves settings through the plugin's settings form, as a browser with JavaScript does."""
    _, _, html, _ = http(configure_url())
    fields = {
        "YII_CSRF_TOKEN": csrf(html),
        "deleteEnabled": "1" if delete else "0",
        "commonEnabled": "1" if common else "0",
        "defaultRule": str(default_hours),
        "maxPerRun": str(max_per_run),
        "surveyRules": json.dumps({str(k): v for k, v in rules.items()}),
        "redirect": BASE + configure_url() + "#settings",
    }
    status, _, _, headers = post_form(configure_url(), fields, follow=False)
    return status, headers.get("Location", "")


def own_rules():
    rows = sql("SELECT model_id, value FROM lime_plugin_settings WHERE plugin_id = %d AND model = 'Survey' "
               "AND `key` = 'rule'" % plugin_id())
    return {int(sid): json.loads(v) for sid, v in rows}


def set_checkpoints(sid, points):
    pid = plugin_id()
    sql("DELETE FROM lime_plugin_settings WHERE plugin_id = %d AND model = 'Survey' AND model_id = %d "
        "AND `key` = 'checkpoints';\n"
        "INSERT INTO lime_plugin_settings (plugin_id, model, model_id, `key`, value) "
        "VALUES (%d, 'Survey', %d, 'checkpoints', '%s');" % (pid, sid, pid, sid, json.dumps(points)))


def table(sid):
    return "lime_responses_%d" % sid


COLUMNS = {}


def insert_response(sid, age_hours, complete, extra=None):
    """Inserts a response last saved age_hours ago; returns its id."""
    stamp = ts(-int(age_hours * H))
    cols = {"startlanguage": "en", "startdate": stamp, "datestamp": stamp, "lastpage": 1,
            "submitdate": stamp if complete else None}
    cols.update(extra or {})
    if sid not in COLUMNS:
        COLUMNS[sid] = {r[0] for r in sql("SHOW COLUMNS FROM %s" % table(sid))}
    cols = {k: v for k, v in cols.items() if k in COLUMNS[sid]}
    names = ", ".join("`%s`" % c for c in cols)
    values = ", ".join("NULL" if v is None else "'%s'" % str(v).replace("'", "''") for v in cols.values())
    return int(scalar("INSERT INTO %s (%s) VALUES (%s); SELECT LAST_INSERT_ID();" % (table(sid), names, values)))


def add_saved(sid, srid, age_hours):
    sql("INSERT INTO lime_saved_control (sid, srid, identifier, access_code, email, ip, saved_thisstep, status, "
        "saved_date, refurl) VALUES (%d, %d, 'r%d', 'x', '', '127.0.0.1', '1', 'S', '%s', '')"
        % (sid, srid, srid, ts(-int(age_hours * H))))


def ids(sid, where="1=1"):
    return {int(r[0]) for r in sql("SELECT id FROM %s WHERE %s" % (table(sid), where))}


def complete_snapshot():
    snap = {}
    for sid in ALL:
        snap[sid] = sql("SELECT * FROM %s WHERE submitdate IS NOT NULL ORDER BY id" % table(sid))
    return snap


def saved_srids(sid):
    return {int(r[0]) for r in sql("SELECT srid FROM lime_saved_control WHERE sid = %d" % sid)}


# ---------------------------------------------------------------- scenarios

step("Admin login")
check(login(), "logged in to the admin interface")

step("Install the plugin from the release zip (Configuration > Plugins > Upload & install)")
_, _, html, _ = http("/admin/pluginmanager/sa/index")
with open(ZIP, "rb") as f:
    status, url, body, _ = post_multipart("/admin/pluginmanager/sa/upload", {"YII_CSRF_TOKEN": csrf(html)},
                                          "the_file", os.path.basename(ZIP), f.read())
check("uploadConfirm" in url and NAME in body, "zip accepted, confirmation page shows the plugin", url)
status, url, body, _ = post_form("/admin/pluginmanager/sa/installUploadedPlugin",
                                 {"YII_CSRF_TOKEN": csrf(body), "isUpdate": "false"})
row = sql("SELECT id, active, load_error FROM lime_plugins WHERE name = '%s'" % NAME)
check(row and row[0][1] == "0" and row[0][2] in (None, "0"), "plugin installed, no load error", row)
check(web_sh("ls /var/www/html/upload/plugins/%s" % NAME).split() ==
      sorted(["CHANGELOG.md", "LICENSE.txt", "PurgeIncompleteResponses.php", "README.md", "assets", "config.xml",
              "locale"]), "only runtime files are installed")
_, _, html, _ = http("/admin/pluginmanager/sa/index")
post_form("/admin/pluginmanager/sa/activate", {"YII_CSRF_TOKEN": csrf(html), "pluginId": str(plugin_id())})
check(scalar("SELECT active FROM lime_plugins WHERE name = '%s'" % NAME) == "1", "plugin activated")

step("Settings page renders")
status, _, body, _ = http(configure_url())
check(status == 200 and 'id="pir-rules"' in body and ">Delete responses</label>" in body,
      "settings page has the rules widget (English)")
check("Cron has never run the plugin" in body or "cron" in body.lower(), "page mentions cron")
check(not re.search(r"(PHP (Warning|Notice|Error)|CException|Stack trace)", body), "no PHP errors on the page")

step("Surveys through RemoteControl")
sql("DELETE FROM lime_settings_global WHERE stg_name = 'RPCInterface';\n"
    "INSERT INTO lime_settings_global (stg_name, stg_value) VALUES ('RPCInterface', 'json');")
key = rpc("get_session_key", ADMIN, PASSWORD)
demo = web_sh("base64 -w0 %s" % DEMO).strip()


def make_survey(title, props):
    sid = int(rpc("import_survey", key, demo, "lss", title))
    if props:
        res = rpc("set_survey_properties", key, sid, props)
        check(all(res.get(k) is True for k in props), "%s: properties %s" % (title, props), res)
    res = rpc("activate_survey", key, sid)
    check(isinstance(res, dict) and res.get("status") == "OK", "%s: activated (%d)" % (title, sid), res)
    return sid


S_DATE = make_survey("by date", {"datestamp": "Y", "anonymized": "N"})
S_NODATE = make_survey("no date stamp", {"datestamp": "N", "anonymized": "N"})
S_ANON = make_survey("anonymized", {"datestamp": "Y", "anonymized": "Y"})
S_KEEP = make_survey("keep forever", {"datestamp": "Y", "anonymized": "N"})
S_OWN = make_survey("own 1 hour", {"datestamp": "Y", "anonymized": "N"})
ALL = [S_DATE, S_NODATE, S_ANON, S_KEEP, S_OWN]
BY_CHECKPOINTS = [S_NODATE, S_ANON]

step("Responses")
# Per survey: inserted first ("old" ids) — complete, two incomplete, a draft saved long ago, a draft saved
# recently; then ("young" ids) — complete, incomplete. Default period 48 h deletes the 3 old incomplete ones.
data = {}
for sid in ALL:
    d = {}
    d["c_old"] = insert_response(sid, 200, True)
    d["i_old1"] = insert_response(sid, 100, False)
    d["i_old2"] = insert_response(sid, 60, False)
    d["i_saved_old"] = insert_response(sid, 100, False)
    add_saved(sid, d["i_saved_old"], 100)
    # Draft resumed recently: datestamp is recent; in surveys without date stamp only saved_date tells it
    d["i_saved_young"] = insert_response(sid, 0.2 if sid not in BY_CHECKPOINTS else 100, False)
    add_saved(sid, d["i_saved_young"], 0.2)
    d["boundary"] = d["i_saved_young"]
    d["c_young"] = insert_response(sid, 0.1, True)
    d["i_young"] = insert_response(sid, 0.2, False)
    data[sid] = d
data[S_OWN]["i_2h"] = insert_response(S_OWN, 2, False)  # older than 1 hour, younger than 48

# Uploaded files: one of an old incomplete response, one of a complete response
qid = int(scalar("SELECT qid FROM lime_questions WHERE sid = %d AND type = '|' LIMIT 1" % S_DATE))
filedir = "/var/www/html/upload/surveys/%d/files" % S_DATE
web_sh("mkdir -p %s && echo x > %s/fu_incomplete && echo x > %s/fu_complete" % (filedir, filedir, filedir))
for rid, name in ((data[S_DATE]["i_old1"], "fu_incomplete"), (data[S_DATE]["c_old"], "fu_complete")):
    info = json.dumps([{"title": "", "comment": "", "size": "0.1", "name": "a.txt", "filename": name, "ext": "txt"}])
    sql("UPDATE %s SET `Q%d` = '%s' WHERE id = %d" % (table(S_DATE), qid, info, rid))
check(set(web_sh("ls %s" % filedir).split()) >= {"fu_incomplete", "fu_complete"}, "test files in place", web_sh("ls -la %s" % filedir))

before_complete = complete_snapshot()
total_before = {sid: len(ids(sid)) for sid in ALL}

step("Defaults: nothing is counted or deleted")
out = console("plugin", "--target=" + NAME, "--function=dryrun")
r = report()
check(r and r["dryRun"] is True and r["total"] == 0 and not r["surveys"],
      "dry run with default settings counts 0", out)

step("Settings form: default 48 h on, own periods, deletion off")
status, location = save_settings(False, True, 48, {S_KEEP: 0, S_OWN: 1})
check(status == 302 and location.endswith("#settings"), "save redirects back to the Settings tab (no resubmit)",
      (status, location))
check(own_rules() == {S_KEEP: 0, S_OWN: 1}, "own periods stored", own_rules())

step("First run: surveys without date stamp start counting later")
out = console("plugin", "cron", "--interval=5")
r = report()
for sid in BY_CHECKPOINTS:
    row = r["surveys"][str(sid)]
    check(row["count"] == 0 and row["mode"] == "checkpoints" and row["note"]["type"] == "starts",
          "survey %d: checkpoints mode, starts one period later" % sid, row)
check(all(len(ids(sid)) == total_before[sid] for sid in ALL), "cron in count-only mode deleted nothing")
cron_info = scalar("SELECT value FROM lime_plugin_settings WHERE plugin_id = %d AND model IS NULL "
                   "AND `key` = 'cronInfo'" % plugin_id())
check(json.loads(cron_info)["interval"] == 5, "cron interval remembered")

# Pretend the plugin has been recording checkpoints for 100 hours: the "old" ids are older than 48 h
for sid in BY_CHECKPOINTS:
    set_checkpoints(sid, [[NOW - 100 * H, data[sid]["boundary"]], [NOW - 1, max(ids(sid))]])

EXPECTED = {
    S_DATE: {"i_old1", "i_old2", "i_saved_old"},
    S_NODATE: {"i_old1", "i_old2", "i_saved_old"},
    S_ANON: {"i_old1", "i_old2", "i_saved_old"},
    S_KEEP: set(),
    S_OWN: {"i_old1", "i_old2", "i_saved_old", "i_2h"},
}
EXPECTED_IDS = {sid: {data[sid][k] for k in keys} for sid, keys in EXPECTED.items()}

step("Count-only run")
out = console("plugin", "--target=" + NAME, "--function=dryrun")
r = report()
counts = {int(sid): row["count"] for sid, row in r["surveys"].items()}
check(counts == {sid: len(v) for sid, v in EXPECTED_IDS.items() if sid != S_KEEP},
      "counts per survey (keep-forever survey skipped)", (counts, out))
check(all(len(ids(sid)) == total_before[sid] for sid in ALL), "dry run deleted nothing")

step("Preview endpoint (the review window) agrees with the run")
cfg = {"commonEnabled": 1, "defaultRule": 48, "deleteEnabled": 1, "maxPerRun": 50000,
       "rules": {str(S_KEEP): 0, str(S_OWN): 1}}
status, _, text, _ = http("/plugins/direct?plugin=%s&function=preview&config=%s"
                          % (NAME, urllib.parse.quote(json.dumps(cfg))))
p = json.loads(text)
check(status == 200 and p["totalTo"] == sum(counts.values()) and p["totalFrom"] == p["totalTo"],
      "preview totals = dry run total (%d)" % sum(counts.values()), text[:500])
check([c["what"] for c in p["changes"]] == ["Delete responses"], "only the switch is reported as changed",
      p["changes"])
cfg2 = dict(cfg, commonEnabled=0)
_, _, text, _ = http("/plugins/direct?plugin=%s&function=preview&config=%s"
                     % (NAME, urllib.parse.quote(json.dumps(cfg2))))
p2 = json.loads(text)
check(p2["totalTo"] == len(EXPECTED_IDS[S_OWN]), "default period off: only surveys with own period count",
      text[:500])
anon_jar = urllib.request.build_opener()
try:
    anon_jar.open(BASE + "/plugins/direct?plugin=%s&function=preview&config={}" % NAME, timeout=30)
    anon_status = 200
except urllib.error.HTTPError as e:
    anon_status = e.code
check(anon_status == 403, "preview without login is refused", anon_status)

step("Deletion on: cron deletes exactly the old incomplete responses")
status, _ = save_settings(True, True, 48, {S_KEEP: 0, S_OWN: 1})
check(status == 302, "settings saved")
# Saving drops checkpoints of surveys that are no longer cleaned only; ours must survive
for sid in BY_CHECKPOINTS:
    check(scalar("SELECT COUNT(*) FROM lime_plugin_settings WHERE plugin_id = %d AND model = 'Survey' "
                 "AND model_id = %d AND `key` = 'checkpoints'" % (plugin_id(), sid)) == "1",
          "checkpoints of survey %d kept on save" % sid)
out = console("plugin", "cron", "--interval=5")
r = report()
check(r["dryRun"] is False and r["total"] == sum(len(v) for v in EXPECTED_IDS.values()),
      "report: %d deleted" % r["total"], out)
for sid in ALL:
    gone = {i for i in range(1, max(data[sid].values()) + 1)} - ids(sid)
    expected_gone = EXPECTED_IDS[sid]
    check(ids(sid) & expected_gone == set() and len(ids(sid)) == total_before[sid] - len(expected_gone),
          "survey %d: deleted %s" % (sid, ", ".join(sorted(EXPECTED[sid])) or "nothing"), (ids(sid), expected_gone))
check(complete_snapshot() == before_complete, "complete responses untouched (ids and dates)")
for sid in ALL:
    check(saved_srids(sid) == {data[sid]["i_saved_young"]} | ({data[sid]["i_saved_old"]} if sid == S_KEEP else set()),
          "survey %d: saved_control rows of deleted drafts removed, recent draft kept" % sid, saved_srids(sid))
check({f for f in web_sh("ls %s" % filedir).split() if f.startswith("fu_")} == {"fu_complete"},
      "uploaded file of the deleted response removed, of the complete one kept")

step("Repeated run deletes nothing more")
console("plugin", "cron", "--interval=5")
check(report()["total"] == 0, "second run: 0")

step("Per-run limit")
extra = [insert_response(S_DATE, 100, False) for _ in range(5)]
save_settings(True, True, 48, {S_KEEP: 0, S_OWN: 1}, max_per_run=2)
console("plugin", "cron", "--interval=5")
r = report()
check(r["total"] == 2 and r["limitReached"] is True and len(ids(S_DATE) & set(extra)) == 3,
      "limit 2: two deleted, limit reported", r)
console("plugin", "cron", "--interval=5")
console("plugin", "cron", "--interval=5")
check(ids(S_DATE) & set(extra) == set(), "the rest deleted by the next runs")

step("Responses table recreated: stale checkpoints are not used")
young = insert_response(S_NODATE, 0.1, False)
set_checkpoints(S_NODATE, [[NOW - 100 * H, young + 1000]])
console("plugin", "cron", "--interval=5")
row = report()["surveys"][str(S_NODATE)]
check(young in ids(S_NODATE) and row["count"] == 0 and row["note"]["type"] == "starts",
      "checkpoint above the highest id is discarded, nothing deleted", row)

step("Turning a survey's period off keeps its responses")
old = insert_response(S_OWN, 100, False)
save_settings(True, False, 48, {S_KEEP: 0, S_OWN: 0})
console("plugin", "cron", "--interval=5")
check(old in ids(S_OWN) and report()["total"] == 0, "everything off: nothing deleted")
check(scalar("SELECT COUNT(*) FROM lime_plugin_settings WHERE plugin_id = %d AND `key` = 'checkpoints'"
             % plugin_id()) == "0", "checkpoints of surveys no longer cleaned are dropped")

step("Interface language follows the admin's language")
sql("UPDATE lime_users SET lang = 'ru' WHERE users_name = '%s'" % ADMIN)
jar.clear()
check(login(), "logged in again")
status, _, body, _ = http(configure_url())
check(status == 200 and ">Удалять ответы</label>" in body and ">Delete responses</label>" not in body,
      "settings page in Russian",
      [m.group(0) for m in re.finditer(r".{0,80}(Удалять ответы|Delete responses).{0,80}", body)][:4])
_, _, text, _ = http("/plugins/direct?plugin=%s&function=preview&config=%s"
                     % (NAME, urllib.parse.quote(json.dumps({"deleteEnabled": 0}))))
check("Удалять ответы" in text, "review window texts in Russian", text[:300])

print("\nAll %d checks passed." % passed)
