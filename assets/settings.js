/* PurgeIncompleteResponses — settings page: "Surveys with their own period" and the check before saving.
 * Texts come translated from the plugin (window.PurgeIncompleteResponsesData.i18n). */
(function () {
    'use strict';

    var data = window.PurgeIncompleteResponsesData || {};
    var T = data.i18n || {};

    // Locale of the admin interface for numbers and times (LimeSurvey codes like "de-informal" are trimmed)
    var locale = (function () {
        var lang = String(data.lang || document.documentElement.lang || '').replace(/-informal$/, '');
        try {
            return lang && Intl.NumberFormat.supportedLocalesOf([lang]).length ? lang : undefined;
        } catch (e) {
            return undefined;
        }
    })();

    /** Fills %1$s, %2$s… in a translated text */
    function fmt(text) {
        var args = arguments;
        return String(text || '').replace(/%(\d)\$s/g, function (m, i) {
            return args[i] !== undefined ? args[i] : m;
        });
    }
    function num(n) { return Number(n).toLocaleString(locale); }
    // Time in the browser's time zone; skew — difference between server and browser clocks
    function hm(ts, skew) {
        return new Date((ts - skew) * 1000).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
    }
    function inMinutes(ts, now) {
        var m = Math.max(0, Math.ceil((ts - now) / 60));
        return m === 0 ? T.lessThanMinute : fmt(T.minutes, m);
    }
    function nextRunText(sch) {
        if (!sch || sch.late || !sch.next) {
            return T.cronMissing;
        }
        var skew = sch.now - Math.floor(Date.now() / 1000);
        return fmt(T.startsAt, hm(sch.next, skew), inMinutes(sch.next, sch.now));
    }
    function renderScheduleLine() {
        var el = document.getElementById('pir-schedule');
        if (!el || !el.dataset.last || el.dataset.late === '1') {
            return;
        }
        var now = parseInt(el.dataset.now, 10), skew = now - Math.floor(Date.now() / 1000);
        var last = parseInt(el.dataset.last, 10), next = parseInt(el.dataset.next, 10);
        el.textContent = fmt(T.schedule, el.dataset.interval, hm(last, skew), hm(next, skew), inMinutes(next, now));
    }
    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function start() {
        renderScheduleLine();
        // Back on the page after "Save": open the "Settings" tab
        if (window.location.hash === '#settings') {
            var tab = document.getElementById('settings-tab');
            if (tab) {
                tab.click();
            }
        }
        var root = document.getElementById('pir-rules');
        var input = document.getElementById('pir-rules-input');
        if (!data.rows || !root || !input) {
            return;
        }
        var form = root.closest('form');
        var periodLabel = {};
        data.periods.forEach(function (p) { periodLabel[p.value] = p.label; });

        // sid -> {sid, title, initial (hours, or null for a new row), hours, removed, active, incomplete, last}
        var rows = {};
        var order = [];
        data.rows.forEach(function (r) {
            rows[r.sid] = {
                sid: r.sid, title: r.title, initial: r.hours, hours: r.hours, removed: false,
                active: r.active, incomplete: r.incomplete, last: r.last
            };
            order.push(r.sid);
        });

        function radio(name) {
            var r = form && form.querySelector('input[name="' + name + '"]:checked');
            return r ? r.value === '1' : undefined;
        }
        function commonOn() { return radio('commonEnabled') === true; }
        function commonText() {
            var sel = form && form.elements.defaultRule;
            return sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text : '';
        }
        function periodSelect(value, cls) {
            return '<select class="form-select form-select-sm ' + cls + '">' + data.periods.map(function (p) {
                return '<option value="' + p.value + '"' + (p.value === value ? ' selected' : '') + '>' + esc(p.label) + '</option>';
            }).join('') + '</select>';
        }
        function currentRules() {
            var out = {};
            order.forEach(function (sid) {
                if (!rows[sid].removed) {
                    out[sid] = rows[sid].hours;
                }
            });
            return out;
        }

        function render() {
            var html = '<table class="table table-sm align-middle pir-table mb-2"><thead><tr>'
                + '<th>' + esc(T.colSurvey) + '</th><th class="pir-col-period">' + esc(T.colPeriod) + '</th>'
                + '<th class="text-end">' + esc(T.colIncomplete) + '</th><th>' + esc(T.colLastRun) + '</th><th></th></tr></thead><tbody>';
            if (!order.length) {
                html += '<tr><td colspan="5" class="text-muted">' + esc(T.none) + '</td></tr>';
            }
            order.forEach(function (sid) {
                var r = rows[sid];
                var cls = r.removed ? 'pir-removed' : (r.initial === null ? 'table-success' : (r.hours !== r.initial ? 'table-warning' : ''));
                var url = data.surveyUrl.replace('__SID__', sid);
                html += '<tr class="' + cls + '" data-sid="' + sid + '">'
                    + '<td><a href="' + esc(url) + '" target="_blank">' + sid + '</a> — ' + esc(r.title)
                    + (r.active ? '' : ' <span class="badge bg-secondary">' + esc(T.inactive) + '</span>')
                    + (r.initial === null ? ' <span class="badge bg-success">' + esc(T['new']) + '</span>' : '')
                    + (r.removed ? ' <span class="badge bg-secondary">' + esc(T.toDefault) + '</span>' : '') + '</td>'
                    + '<td>' + (r.removed ? esc(periodLabel[r.hours]) : periodSelect(r.hours, 'pir-row-period')) + '</td>'
                    + '<td class="text-end">' + (r.incomplete === null ? '—' : num(r.incomplete)) + '</td>'
                    + '<td class="small text-muted">' + esc(r.last || '') + '</td>'
                    + '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary pir-row-toggle">'
                    + esc(r.removed ? T.restore : T.remove) + '</button></td></tr>';
            });
            html += '</tbody></table>';

            html += '<div class="pir-add d-flex flex-wrap gap-2 align-items-center mb-2">'
                + '<select class="form-select pir-add-survey"><option value="">' + esc(T.addSurvey) + '</option>'
                + data.surveys.filter(function (s) { return !rows[s.sid]; }).map(function (s) {
                    return '<option value="' + s.sid + '">' + s.sid + ' — ' + esc(s.title) + '</option>';
                }).join('') + '</select>'
                + periodSelect(commonOn() ? 0 : 48, 'pir-add-period')
                + '<button type="button" class="btn btn-outline-primary pir-add-btn">' + esc(T.add) + '</button></div>';

            html += '<div class="form-text pir-rest">'
                + esc(commonOn() ? fmt(T.restDefault, commonText()) : T.restOff) + ' ' + esc(T.alsoInSurvey) + '</div>';

            root.innerHTML = html;
            input.value = JSON.stringify(currentRules());

            var add = root.querySelector('.pir-add-survey');
            if (window.jQuery && window.jQuery.fn.select2) {
                window.jQuery(add).select2({ width: '32em', placeholder: T.addSurvey, allowClear: false });
            }
        }

        root.addEventListener('change', function (e) {
            var tr = e.target.closest('tr[data-sid]');
            if (tr && e.target.classList.contains('pir-row-period')) {
                rows[tr.dataset.sid].hours = parseInt(e.target.value, 10);
                render();
            }
        });
        root.addEventListener('click', function (e) {
            var sid;
            if (e.target.classList.contains('pir-row-toggle')) {
                sid = e.target.closest('tr').dataset.sid;
                if (rows[sid].initial === null) { // a new row is simply dropped
                    delete rows[sid];
                    order = order.filter(function (x) { return String(x) !== String(sid); });
                } else {
                    rows[sid].removed = !rows[sid].removed;
                }
                render();
            } else if (e.target.classList.contains('pir-add-btn')) {
                sid = parseInt(root.querySelector('.pir-add-survey').value, 10);
                if (!sid) {
                    return;
                }
                var s = data.surveys.filter(function (x) { return x.sid === sid; })[0];
                rows[sid] = {
                    sid: sid, title: s ? s.title : '', initial: null,
                    hours: parseInt(root.querySelector('.pir-add-period').value, 10),
                    removed: false, active: true, incomplete: null, last: ''
                };
                order.push(sid);
                order.sort(function (a, b) { return a - b; });
                render();
            }
        });

        // The default period field is shown only while "Apply to all surveys" is on
        function syncCommon() {
            var wrap = form && form.querySelector('[data-name="defaultRule"]');
            if (wrap) {
                wrap.style.display = commonOn() ? '' : 'none';
            }
            render();
        }
        if (form) {
            form.addEventListener('change', function (e) {
                if (e.target.name === 'commonEnabled' || e.target.name === 'defaultRule') {
                    syncCommon();
                }
            });
            if (window.jQuery) { // select2 fires change through jQuery
                window.jQuery(form).on('change', 'select[name="defaultRule"]', syncCommon);
            }
        }
        syncCommon();

        // ---------------------------------------------------------------- check before saving
        if (!form) {
            return;
        }
        var confirmed = false;
        form.addEventListener('submit', function (e) {
            if (confirmed) {
                return;
            }
            e.preventDefault();
            preview(e.submitter || null);
        });

        function submitFor(submitter) {
            confirmed = true;
            // "Save and close" posts its own "redirect" (the plugin list): our hidden one must not override it
            var own = document.getElementById('pir-redirect');
            if (own) {
                own.disabled = !!(submitter && submitter.name === 'redirect');
            }
            if (form.requestSubmit) {
                form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            } else {
                if (submitter && submitter.name) {
                    var h = document.createElement('input');
                    h.type = 'hidden';
                    h.name = submitter.name;
                    h.value = submitter.value;
                    form.appendChild(h);
                }
                form.submit();
            }
        }

        function modal() {
            var el = document.createElement('div');
            el.className = 'pir-modal';
            el.innerHTML = '<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">'
                + '<div class="modal-header"><h5 class="modal-title">' + esc(T.checkTitle) + '</h5>'
                + '<button type="button" class="btn-close pir-cancel" aria-label="' + esc(T.close) + '"></button></div>'
                + '<div class="modal-body"><p class="text-muted">' + esc(T.counting) + '</p></div>'
                + '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary pir-cancel">' + esc(T.cancel) + '</button>'
                + '<button type="button" class="btn btn-primary pir-ok" disabled>' + esc(T.save) + '</button></div></div></div>';
            document.body.appendChild(el);
            function close() {
                el.remove();
                document.removeEventListener('keydown', onKey);
            }
            function onKey(e) {
                if (e.key === 'Escape') {
                    close();
                }
            }
            document.addEventListener('keydown', onKey);
            el.addEventListener('click', function (e) {
                if (e.target === el || e.target.classList.contains('pir-cancel')) {
                    close();
                }
            });
            return { body: el.querySelector('.modal-body'), ok: el.querySelector('.pir-ok'), close: close };
        }

        function preview(submitter) {
            var m = modal();
            m.ok.addEventListener('click', function () {
                m.close();
                submitFor(submitter);
            });
            var cfg = {
                commonEnabled: radio('commonEnabled'),
                defaultRule: parseInt(form.elements.defaultRule.value, 10),
                deleteEnabled: radio('deleteEnabled'),
                maxPerRun: parseInt(form.elements.maxPerRun.value, 10),
                rules: currentRules()
            };
            var url = new URL(data.previewUrl, window.location.href);
            url.searchParams.set('config', JSON.stringify(cfg));
            fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) {
                    return r.json().then(function (j) {
                        if (!r.ok || j.error) {
                            throw new Error(j.error || ('HTTP ' + r.status));
                        }
                        return j;
                    });
                })
                .then(function (res) {
                    m.body.innerHTML = renderPreview(res);
                    m.ok.disabled = false;
                    m.ok.focus();
                })
                .catch(function (err) {
                    m.body.innerHTML = '<div class="alert alert-warning mb-0">' + esc(fmt(T.checkFailed, err.message)) + '</div>';
                    m.ok.textContent = T.saveUnchecked;
                    m.ok.disabled = false;
                });
        }

        function renderPreview(res) {
            if (!res.changes.length) {
                return '<p class="mb-0">' + esc(T.noChanges) + '</p>';
            }
            var html = '<h6>' + esc(T.whatChanges) + '</h6><ul>' + res.changes.map(function (c) {
                return '<li><b>' + esc(c.what) + '</b>: ' + esc(c.from) + ' → ' + esc(c.to) + '</li>';
            }).join('') + '</ul>';

            var what = '<b>' + esc(fmt(T.responsesSurveys, num(res.totalTo), num(res.surveysTo))) + '</b>';
            var before = res.totalFrom !== res.totalTo && res.totalFrom > 0 ? ' ' + esc(fmt(T.withoutChanges, num(res.totalFrom))) : '';
            if (res.dryRun) {
                html += '<div class="alert alert-info">' + fmt(esc(T.dryRunNext), what) + before + '</div>';
            } else if (res.totalTo > 0) {
                html += '<div class="alert alert-danger">' + fmt(esc(T.deleteNext), what)
                    + (res.totalTo > res.maxPerRun ? ' ' + esc(fmt(T.perRunLimit, num(res.maxPerRun))) : '')
                    + before + '<br>' + esc(nextRunText(res.schedule)) + '</div>';
            } else {
                html += '<div class="alert alert-secondary">' + esc(T.nothingNext) + before + '</div>';
            }

            // rows "0 → 0" without a note are folded into one line at the end
            var idle = res.impact.filter(function (r) { return !r.countFrom && !r.countTo && !r.note; });
            var shown = res.impact.filter(function (r) { return r.countFrom || r.countTo || r.note; });
            if (res.impact.length) {
                var limit = 50;
                // only "Delete responses" changes: periods and numbers are the same, no "without changes" column
                var same = res.impact.every(function (r) { return r.from === r.to && r.countFrom === r.countTo; });
                var cols = same ? 3 : 4;
                html += '<h6 class="mt-3">' + esc(same ? T.runsTable : T.changedTable) + '</h6>'
                    + '<table class="table table-sm align-middle"><thead><tr><th>' + esc(T.colSurvey) + '</th><th>' + esc(T.colPeriod) + '</th>'
                    + (same ? '' : '<th class="text-end">' + esc(T.colWithout) + '</th>')
                    + '<th class="text-end">' + esc(same ? T.colResponses : T.colAfter) + '</th></tr></thead><tbody>';
                shown.slice(0, limit).forEach(function (r) {
                    var cls = same ? '' : (r.countTo > r.countFrom ? 'table-danger' : (r.countTo < r.countFrom ? 'table-success' : ''));
                    html += '<tr class="' + cls + '"><td>' + r.sid + ' — ' + esc(r.title)
                        + (r.note ? '<div class="small text-muted">' + esc(r.note) + '</div>' : '') + '</td>'
                        + '<td class="text-nowrap">' + esc(r.from === r.to ? r.to : r.from + ' → ' + r.to) + '</td>'
                        + (same ? '' : '<td class="text-end">' + num(r.countFrom) + '</td>')
                        + '<td class="text-end">' + num(r.countTo) + '</td></tr>';
                });
                if (shown.length > limit) {
                    html += '<tr><td colspan="' + cols + '" class="text-muted">' + esc(fmt(T.moreSurveys, shown.length - limit)) + '</td></tr>';
                }
                if (idle.length) {
                    html += '<tr><td colspan="' + cols + '" class="text-muted">' + esc(fmt(T.idleSurveys, idle.length,
                        idle.slice(0, 15).map(function (r) { return r.sid; }).join(', ') + (idle.length > 15 ? '…' : ''))) + '</td></tr>';
                }
                var sumFrom = 0, sumTo = 0;
                res.impact.forEach(function (r) { sumFrom += r.countFrom; sumTo += r.countTo; });
                html += '</tbody><tfoot><tr><th colspan="2">' + esc(T.totalShown) + '</th>'
                    + (same ? '' : '<th class="text-end">' + num(sumFrom) + '</th>')
                    + '<th class="text-end">' + num(sumTo) + '</th></tr></tfoot></table>';
            }
            return html;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
