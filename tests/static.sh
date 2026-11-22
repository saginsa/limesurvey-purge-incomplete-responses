#!/usr/bin/env bash
# Static checks: PHP syntax, config.xml, translations, forbidden words / secrets.
# Needs: php, xgettext/msgfmt/msgcmp (gettext), optionally node and phpcs.
set -euo pipefail
cd "$(dirname "$0")/.."
fail=0
ok()  { printf 'ok    %s\n' "$1"; }
bad() { printf 'FAIL  %s\n' "$1"; fail=1; }

# 1. PHP syntax
if php -l PurgeIncompleteResponses.php >/dev/null; then ok "php -l"; else bad "php -l"; fi

# 2. JS syntax (if node is available)
if command -v node >/dev/null; then
    if node --check assets/settings.js; then ok "node --check settings.js"; else bad "node --check settings.js"; fi
fi

# 3. config.xml: well-formed, required fields, version matches CHANGELOG
if php -r '$x = simplexml_load_file("config.xml"); if (!$x) exit(1);
    foreach (["name","type","version","author","license","description"] as $f)
        if (trim((string)$x->metadata->$f) === "") { fwrite(STDERR, "missing $f\n"); exit(1); }
    if ((string)$x->metadata->name !== "PurgeIncompleteResponses") exit(1);
    if (count($x->compatibility->version) === 0) { fwrite(STDERR, "missing compatibility\n"); exit(1); }'; then
    ok "config.xml"; else bad "config.xml"; fi
version=$(php -r 'echo simplexml_load_file("config.xml")->metadata->version;')
if grep -q "^## $version" CHANGELOG.md; then ok "CHANGELOG has $version"; else bad "CHANGELOG has no section for $version"; fi

# 4. Translations: .po compiles, every t('...') string is translated
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
xgettext --language=PHP --keyword=t --from-code=UTF-8 --no-location -o "$tmp/strings.pot" PurgeIncompleteResponses.php
for po in locale/*/*.po; do
    lang=$(basename "$(dirname "$po")")
    if msgfmt --check -o "$tmp/$lang.mo" "$po"; then ok "msgfmt $lang"; else bad "msgfmt $lang"; fi
    if msgcmp --use-untranslated "$po" "$tmp/strings.pot" 2>"$tmp/cmp.txt"; then
        ok "all strings present in $lang"
    else bad "strings missing in $lang:"; sed 's/^/      /' "$tmp/cmp.txt" | head -20; fi
    untranslated=$(msgattrib --untranslated --no-obsolete "$po" | grep -c '^msgid ' || true)
    # msgattrib prints the header entry too
    if [ "${untranslated:-0}" -le 1 ]; then ok "no untranslated in $lang"; else bad "$((untranslated-1)) untranslated in $lang"; fi
    if cmp -s "$tmp/$lang.mo" "${po%.po}.mo" || msgunfmt "${po%.po}.mo" | msgcmp - "$po" >/dev/null 2>&1; then
        ok "$lang.mo is up to date"; else bad "$lang.mo is out of date (run msgfmt)"; fi
done

# 5. No company names, internal hosts or secrets in tracked files
files=$(git ls-files 2>/dev/null || find . -type f -not -path './.git/*' -not -path './dist/*')
# FORBIDDEN_WORDS="word1|word2" — e.g. company or host names that must not get into the public code
if [ -n "${FORBIDDEN_WORDS:-}" ]; then
    if grep -I -i -n -E "$FORBIDDEN_WORDS" $files; then bad "forbidden words"; else ok "no forbidden words"; fi
fi
if grep -I -n -E '(BEGIN [A-Z ]*PRIVATE KEY|gh[pousr]_[A-Za-z0-9]{30,}|AKIA[0-9A-Z]{16}|password *= *["'\''][^"'\'' ]+)' $files \
    | grep -v '^tests/' ; then bad "possible secrets"; else ok "no secrets"; fi
if grep -I -n -E '\b10\.[0-9]+\.[0-9]+\.[0-9]+\b|\b192\.168\.[0-9]+\.[0-9]+' $files | grep -v '^tests/'; then bad "private IPs"; else ok "no private IPs"; fi

# 6. Coding standard of LimeSurvey core (optional locally, always in CI)
if command -v phpcs >/dev/null; then
    if phpcs --standard=tests/phpcs.xml; then ok "phpcs (LimeSurvey PSR-12)"; else bad "phpcs (LimeSurvey PSR-12)"; fi
else
    echo "skip  phpcs (not installed)"
fi

# 7. Spelling, with the LimeSurvey core settings (optional locally, always in CI)
if command -v codespell >/dev/null; then
    if codespell --builtin clear,rare --check-filenames PurgeIncompleteResponses.php assets README.md CHANGELOG.md config.xml tests; then
        ok "codespell"; else bad "codespell"; fi
else
    echo "skip  codespell (not installed)"
fi

exit $fail
