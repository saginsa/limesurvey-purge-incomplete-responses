<?php

/**
 * PurgeIncompleteResponses — deletes incomplete (never submitted) survey responses, including
 * "Resume later" drafts, once they are older than the retention period set for the survey.
 *
 * Retention period is stored in hours; 0 means "keep forever".
 * - Default period: 'commonEnabled' (on/off) + 'defaultRule' (hours). Applies to active surveys
 *   without their own period.
 * - Survey's own period: plugin setting 'rule' of model Survey. No record — the default period applies.
 *
 * Runs from the system cron through the LimeSurvey console:
 *   php application/commands/console.php plugin cron --interval=5
 * Manually:
 *   php application/commands/console.php plugin --target=PurgeIncompleteResponses --function=dryrun
 *   php application/commands/console.php plugin --target=PurgeIncompleteResponses --function=run
 * "dryrun" only counts; "run" deletes regardless of the "Delete responses" switch.
 *
 * Before saving, the settings page asks the plugin what will change (read only, needs settings/read):
 *   GET index.php/plugins/direct?plugin=PurgeIncompleteResponses&function=preview&config=<json>
 *
 * How the age of a response is determined:
 * - survey with date stamp and not anonymized — by `datestamp` (time of the last saved page);
 * - no date stamp, or anonymized (datestamp is always 1980-01-01 there) — by checkpoints: every run
 *   remembers the current maximum response id and the time. Responses with id not greater than a
 *   checkpoint older than the retention period are deleted, i.e. the age is counted from the start of
 *   the response. A "Resume later" draft is kept while its saved_date is younger than the period.
 *   Until the first checkpoint matures, such surveys are not cleaned. Checkpoints are dropped when a
 *   survey stops being cleaned.
 *
 * Deletion mirrors the bulk delete of the Responses screen: responses, timings and uploaded files
 * (if the survey has a file upload question), plus saved_control rows, which the core leaves behind.
 * Every DELETE has "submitdate IS NULL", so a response submitted during the run is never deleted.
 * beforeDataEntryDelete is not dispatched, so AuditLog does not get one full-response record per
 * deleted draft; the result of the last run is shown on the plugin settings page.
 */
class PurgeIncompleteResponses extends PluginBase
{
    protected $storage = 'DbStorage';
    protected static $name = 'PurgeIncompleteResponses';
    protected static $description = 'Deletes incomplete survey responses older than a retention period (via cron).';

    /** @inheritdoc this plugin has no public methods */
    public $allowedPublicMethods = array();

    /** Selectable periods, in hours */
    private const PRESETS = array(1, 3, 6, 12, 24, 48, 72, 120, 168, 336, 720, 1440, 2160, 4320, 8760);
    /** Default period until another one is chosen */
    private const DEFAULT_HOURS = 48;
    /** Responses deleted per query */
    private const BATCH = 1000;
    /** Checkpoints per retention period: boundary precision is 1/48 of the period (1 hour for 2 days) */
    private const CHECKPOINTS_PER_AGE = 48;
    private const LOCK_NAME = 'limesurvey_PurgeIncompleteResponses';
    /** Advisory lock key for PostgreSQL (crc32 of LOCK_NAME) */
    private const PG_LOCK_KEY = 1853184171;

    /** Labels and help are set in getPluginSettings(), translated to the admin's language */
    protected $settings = array(
        'modeHeader' => array('type' => 'info', 'content' => ''),
        'deleteEnabled' => array('type' => 'boolean', 'default' => 0),
        'commonHeader' => array('type' => 'info', 'content' => ''),
        'commonEnabled' => array('type' => 'boolean', 'default' => 0),
        'defaultRule' => array(
            'type' => 'select',
            'selectOptions' => array('minimumResultsForSearch' => -1),
            'options' => array(),
            'default' => self::DEFAULT_HOURS,
        ),
        'surveyRules' => array('type' => 'info', 'content' => ''),
        'advancedHeader' => array('type' => 'info', 'content' => ''),
        'maxPerRun' => array('type' => 'int', 'htmlOptions' => array('min' => 1), 'default' => 50000),
        'lastReport' => array('type' => 'info', 'content' => ''),
    );

    public function init()
    {
        $this->subscribe('cron');
        $this->subscribe('direct');
        $this->subscribe('newDirectRequest');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
    }

    public function cron()
    {
        // Remember when and how often cron runs: the settings page shows the next run and warns if cron stopped
        $interval = (int) $this->getEvent()->get('interval');
        $this->set('cronInfo', array('time' => time(), 'interval' => $interval > 0 ? $interval : 60));
        $this->purge(!$this->isDeleteEnabled());
    }

    /** Console: --function=run|dryrun */
    public function direct()
    {
        $event = $this->getEvent();
        if ($event->get('target') !== static::$name) {
            return;
        }
        switch ($event->get('function')) {
            case 'run':
                $this->purge(false);
                break;
            case 'dryrun':
                $this->purge(true);
                break;
            default:
                $this->say(sprintf($this->t('Usage: %s'), '--target=' . static::$name . ' --function=dryrun|run'));
        }
    }

    /** Web: preview of changes before the settings are saved */
    public function newDirectRequest()
    {
        $event = $this->getEvent();
        if ($event->get('target') !== static::$name || $event->get('function') !== 'preview') {
            return;
        }
        // plugins/direct does not go through the admin controller: take the admin language from the session
        if (!empty(App()->session['adminlang'])) {
            App()->setLanguage(App()->session['adminlang']);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        // The review window is part of saving the settings: same permission as saving
        if (!Permission::model()->hasGlobalPermission('settings', 'update')) {
            http_response_code(403);
            echo json_encode(array('error' => $this->t('No permission to change settings')), JSON_UNESCAPED_UNICODE);
            App()->end();
        }
        $config = json_decode((string) App()->request->getParam('config'), true);
        try {
            $result = $this->preview(is_array($config) ? $config : array());
        } catch (Exception $e) {
            http_response_code(500);
            $result = array('error' => $e->getMessage());
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        App()->end();
    }

    /** @inheritdoc */
    public function getPluginSettings($getValues = true)
    {
        $settings = parent::getPluginSettings($getValues);
        $settings['deleteEnabled']['label'] = $this->t('Delete responses');
        $settings['deleteEnabled']['help'] = $this->t('On — cron runs permanently delete incomplete responses. Off — they only count what would be deleted (see "Last run" below).');
        $settings['commonHeader']['content'] = '<h2 class="h5 mt-3 mb-1">' . $this->t('Default retention period', 'html') . '</h2>';
        $settings['commonEnabled']['label'] = $this->t('Apply to all surveys');
        $settings['commonEnabled']['help'] = $this->t('On — incomplete responses are deleted in all active surveys except surveys with their own period. Off — only in surveys with their own period.');
        $settings['defaultRule']['label'] = $this->t('Default period');
        $settings['defaultRule']['help'] = $this->t('Incomplete responses older than this period are deleted.');
        $settings['defaultRule']['options'] = $this->periodOptions($this->getDefaultHours());
        $settings['advancedHeader']['content'] = '<h2 class="h5 mt-3 mb-0">' . $this->t('Advanced', 'html') . '</h2>';
        $settings['maxPerRun']['label'] = $this->t('Maximum responses per run');
        $settings['maxPerRun']['help'] = $this->t('The rest is deleted by the next runs.');
        $settings['lastReport']['label'] = $this->t('Last run');
        if (!$getValues) {
            return $settings;
        }
        $settings['commonEnabled']['current'] = $this->isCommonEnabled() ? '1' : '0';
        $settings['deleteEnabled']['current'] = $this->isDeleteEnabled() ? '1' : '0';
        $settings['defaultRule']['current'] = (string) $this->getDefaultHours();
        $report = $this->get('report');
        $settings['modeHeader']['content'] = '<h2 class="h5 mb-1">' . $this->t('Deletion', 'html') . '</h2>' . $this->renderSchedule();
        $settings['surveyRules']['content'] = $this->renderRulesWidget($report);
        $settings['lastReport']['content'] = $this->renderReport($report);
        return $settings;
    }

    /** @inheritdoc */
    public function saveSettings($settings)
    {
        $before = $this->currentConfig();
        $this->dropIdleCheckpoints($before);
        $rules = isset($settings['surveyRules']) ? json_decode((string) $settings['surveyRules'], true) : null;
        unset($settings['lastReport'], $settings['surveyRules'], $settings['modeHeader'], $settings['commonHeader'], $settings['advancedHeader']);

        $settings['commonEnabled'] = empty($settings['commonEnabled']) ? 0 : 1;
        $settings['deleteEnabled'] = empty($settings['deleteEnabled']) ? 0 : 1;
        $hours = (int) ($settings['defaultRule'] ?? 0);
        $settings['defaultRule'] = $hours > 0 ? $hours : self::DEFAULT_HOURS;
        $settings['maxPerRun'] = max(1, (int) ($settings['maxPerRun'] ?? 0));
        parent::saveSettings($settings);

        // Without the field (page rendered without JavaScript) survey periods are left as they are
        if (is_array($rules)) {
            $new = $this->normalizeRules($rules);
            foreach ($before['own'] as $sid => $old) {
                if (!array_key_exists($sid, $new)) {
                    $this->setSurveyRule($sid, null);
                }
            }
            foreach ($new as $sid => $value) {
                if (!array_key_exists($sid, $before['own']) || $before['own'][$sid] !== $value) {
                    $this->setSurveyRule($sid, $value);
                }
            }
        }
        $this->dropIdleCheckpoints();
    }

    /** Survey settings → "Plugins" */
    public function beforeSurveySettings()
    {
        $event = $this->getEvent();
        $sid = (int) $event->get('survey');
        $survey = Survey::model()->findByPk($sid);
        if (!$survey) {
            return;
        }
        $rule = $this->getSurveyRule($sid);
        $help = $this->isByDate($survey)
            ? $this->t('The age of a response is counted from its last saved page.')
            : $this->t('No date stamp or anonymized survey: the age is counted from the start of the response; the first deletion happens one period after the period is set.');
        if (!$survey->isActive) {
            $help .= ' ' . $this->t('The survey is not active — the period takes effect after activation.');
        }
        $help .= ' ' . $this->t('All surveys with their own period are listed on the plugin settings page (Configuration → Plugins).');
        $label = $this->t('Retention period for incomplete responses');
        if (!$this->canChangeSurveyRule($sid)) {
            // The period deletes responses: changing it needs the right to delete them, not only to edit settings
            $current = $rule === null ? $this->commonOptionLabel() : $this->ruleLabel($rule);
            $setting = array(
                'type' => 'info',
                'content' => '<div class="mb-3"><b>' . CHtml::encode($label) . ':</b> ' . CHtml::encode($current)
                    . '<div class="form-text">' . $this->t('Changing it requires the permission to delete responses of this survey.', 'html')
                    . '</div></div>',
            );
        } else {
            $setting = array(
                'type' => 'select',
                'label' => $label,
                'help' => $help,
                'selectOptions' => array('minimumResultsForSearch' => -1),
                'options' => array('default' => $this->commonOptionLabel()) + $this->ruleOptions($rule),
                'current' => $rule === null ? 'default' : (string) $rule,
            );
        }
        $event->set("surveysettings.{$this->id}", array(
            'name' => get_class($this),
            'settings' => array('rule' => $setting),
        ));
    }

    /** The period leads to deleting responses: it takes the right to delete them */
    private function canChangeSurveyRule($sid)
    {
        return Permission::model()->hasSurveyPermission((int) $sid, 'responses', 'delete');
    }

    public function newSurveySettings()
    {
        $event = $this->getEvent();
        $settings = $event->get('settings');
        if (is_array($settings) && array_key_exists('rule', $settings)) {
            $sid = (int) $event->get('survey');
            if (!$this->canChangeSurveyRule($sid)) {
                // The core calls this with only the "survey settings: update" permission checked
                $this->log(sprintf('Survey %d: period not changed, no permission to delete responses', $sid), CLogger::LEVEL_WARNING);
                return;
            }
            $this->dropIdleCheckpoints();
            $this->setSurveyRule($sid, $settings['rule']);
            $this->dropIdleCheckpoints();
        }
    }

    // ------------------------------------------------------------------ purge

    /**
     * One pass over all active surveys whose period is not "keep forever".
     * The report stores data, not text: it is rendered later in the viewer's language.
     * @param bool $dryRun only count
     */
    private function purge($dryRun)
    {
        Yii::import('application.helpers.common_helper', true);
        if (PHP_SAPI === 'cli') {
            // The console has no user: write the cron log in the site's default language
            $default = SettingGlobal::model()->findByPk('defaultlang');
            if ($default && $default->stg_value) {
                App()->setLanguage($default->stg_value);
            }
        }
        if (!$this->lock()) {
            $this->say($this->t('The previous run has not finished yet, skipping.'));
            return;
        }
        try {
            $now = time();
            $config = $this->currentConfig();
            $remaining = max(1, (int) $this->getSetting('maxPerRun'));
            $report = array(
                'time' => gmdate('Y-m-d H:i:s', $now),
                'dryRun' => $dryRun,
                'total' => 0,
                'surveys' => array(),
                'errors' => array(),
                'limitReached' => false,
            );
            foreach (array_keys($this->getActiveSurveys()) as $sid) {
                $hours = $this->effectiveHours($config, $sid);
                if ($hours <= 0) {
                    continue;
                }
                $survey = Survey::model()->findByPk($sid);
                if (!$survey || !$survey->hasResponsesTable) {
                    continue;
                }
                try {
                    $result = $this->purgeSurvey($survey, $now, $hours * 3600, $config['includeSaved'], $dryRun, $remaining);
                } catch (Exception $e) {
                    $report['errors'][] = $sid . ': ' . $e->getMessage();
                    $this->log($sid . ': ' . $e->getMessage(), CLogger::LEVEL_ERROR);
                    continue;
                }
                $result['rule'] = $hours;
                $report['surveys'][$sid] = $result;
                $report['total'] += $result['count'];
                if (!$dryRun) {
                    $remaining -= $result['count'];
                    if ($remaining <= 0) {
                        $report['limitReached'] = true;
                        break;
                    }
                }
            }
            $this->set('report', $report);
            $this->say($report['time'] . ' UTC: ' . $this->reportSummary($report)
                . ($report['errors'] ? ' ' . sprintf($this->t('Errors: %s'), implode('; ', $report['errors'])) : ''));
            foreach ($report['surveys'] as $sid => $row) {
                $note = $this->noteText($row['note']);
                $this->say(sprintf(
                    '  %d: %s — %d (%s)%s',
                    $sid,
                    $this->hoursLabel($row['rule']),
                    $row['count'],
                    $this->modeText($row['mode']),
                    $note !== '' ? ', ' . $note : ''
                ));
            }
        } finally {
            $this->unlock();
        }
    }

    /**
     * @return array{count:int, mode:string, note:array|null}
     */
    private function purgeSurvey(Survey $survey, $now, $ageSeconds, $includeSaved, $dryRun, $limit)
    {
        $sid = (int) $survey->sid;
        $table = $survey->responsesTableName;
        $db = Yii::app()->db;
        $byDate = $this->isByDate($survey);
        $mode = $byDate ? 'date' : 'checkpoints';
        $boundary = null;
        if (!$byDate) {
            $points = $this->updateCheckpoints($sid, $table, $now, $ageSeconds);
            $boundary = $this->boundary($points, $now, $ageSeconds);
            if ($boundary === null) {
                return array('count' => 0, 'mode' => $mode, 'note' => array('type' => 'starts', 'at' => $points[0][0] + $ageSeconds));
            }
        }
        list($where, $params) = $this->condition($sid, $byDate, $now - $ageSeconds, $boundary, $includeSaved);

        if ($dryRun) {
            $count = (int) $db->createCommand()->select('COUNT(*)')->from("$table r")->where($where, $params)->queryScalar();
            return array('count' => $count, 'mode' => $mode, 'note' => null);
        }

        $heavy = hasFileUploadQuestion($sid);
        $count = 0;
        $finished = 0;
        while ($count < $limit) {
            $batch = min(self::BATCH, $limit - $count);
            $ids = array_map('intval', $db->createCommand()->select('r.id')->from("$table r")->where($where, $params)
                ->order('r.id')->limit($batch)->queryColumn());
            if (!$ids) {
                break;
            }
            $deleted = $this->deleteResponses($survey, $ids, $heavy);
            $count += count($deleted);
            $left = array_diff($ids, $deleted);
            if ($left) {
                // Not deleted: either submitted meanwhile (now complete — as it should be) or an error
                $stuck = (int) $db->createCommand()->select('COUNT(*)')->from($table)
                    ->where(array('and', array('in', 'id', array_values($left)), 'submitdate IS NULL'))->queryScalar();
                $finished += count($left) - $stuck;
                if ($stuck > 0) {
                    return array('count' => $count, 'mode' => $mode, 'note' => array('type' => 'stuck', 'n' => $stuck));
                }
            }
            if (count($ids) < $batch) {
                break;
            }
        }
        return array('count' => $count, 'mode' => $mode, 'note' => $finished ? array('type' => 'finished', 'n' => $finished) : null);
    }

    /**
     * Condition selecting incomplete responses older than the boundary.
     * @return array [where, params]
     */
    private function condition($sid, $byDate, $cutoffTs, $boundary, $includeSaved)
    {
        $sid = (int) $sid;
        $params = array(':cutoff' => gmdate('Y-m-d H:i:s', $cutoffTs));
        if ($byDate) {
            $where = 'r.submitdate IS NULL AND r.datestamp < :cutoff';
        } else {
            $where = "r.submitdate IS NULL AND r.id <= :boundary AND NOT EXISTS (SELECT 1 FROM {{saved_control}} sc"
                . " WHERE sc.sid = $sid AND sc.srid = r.id AND sc.saved_date >= :cutoff)";
            $params[':boundary'] = (int) $boundary;
        }
        if (!$includeSaved) {
            $where .= " AND NOT EXISTS (SELECT 1 FROM {{saved_control}} sk WHERE sk.sid = $sid AND sk.srid = r.id)";
        }
        return array($where, $params);
    }

    /**
     * Deletes responses like the bulk delete of the Responses screen, plus their saved_control rows.
     * Every DELETE of a response has "submitdate IS NULL": a response submitted between selection and
     * deletion is complete by then and stays. Timings, files and saved_control rows are deleted only
     * for responses that were actually deleted.
     * @param int[] $ids
     * @return int[] ids of deleted responses
     */
    private function deleteResponses(Survey $survey, array $ids, $heavy)
    {
        $sid = (int) $survey->sid;
        $model = Response::model($sid);
        if ($heavy) {
            $deleted = array();
            foreach ($model->findAllByPk($ids, 'submitdate IS NULL') as $response) {
                // not $response->delete(): it deletes by primary key without checking submitdate
                if ($model->deleteByPk($response->id, 'submitdate IS NULL') > 0) {
                    $response->deleteFiles();
                    $deleted[] = (int) $response->id;
                }
            }
        } else {
            $model->deleteByPk($ids, 'submitdate IS NULL');
            $still = array_map('intval', Yii::app()->db->createCommand()->select('id')->from($survey->responsesTableName)
                ->where(array('in', 'id', $ids))->queryColumn());
            $deleted = array_values(array_diff($ids, $still));
        }
        if (!$deleted) {
            return array();
        }
        if ($survey->isSaveTimings) {
            SurveyTimingDynamic::model($sid)->deleteByPk($deleted);
        }
        $criteria = new CDbCriteria();
        $criteria->compare('sid', $sid);
        $criteria->addInCondition('srid', $deleted);
        SavedControl::model()->deleteAll($criteria);
        return $deleted;
    }

    /**
     * Adds a checkpoint (time, max id), drops old ones that are no longer needed, returns the rest.
     * @return array[] [[time, id], …], never empty
     */
    private function updateCheckpoints($sid, $table, $now, $ageSeconds)
    {
        $points = $this->get('checkpoints', 'Survey', $sid);
        if (!is_array($points)) {
            $points = array();
        }
        $maxId = (int) Yii::app()->db->createCommand()->select('MAX(id)')->from($table)->queryScalar();
        $last = end($points);
        $step = max(60, intdiv($ageSeconds, self::CHECKPOINTS_PER_AGE));
        if ($last && $maxId < $last[1]) {
            // The responses table was recreated (deactivation/activation): old checkpoints do not apply
            $points = array();
            $last = false;
        }
        if (!$last || $now - $last[0] >= $step) {
            $points[] = array($now, $maxId);
        }
        // Checkpoints older than the newest matured one are not needed
        $keepFrom = 0;
        foreach ($points as $i => $point) {
            if ($point[0] <= $now - $ageSeconds) {
                $keepFrom = $i;
            }
        }
        $points = array_slice($points, $keepFrom);
        $this->set('checkpoints', $points, 'Survey', $sid);
        return $points;
    }

    /** Id from the newest checkpoint older than the period; null if there is none yet */
    private function boundary($points, $now, $ageSeconds)
    {
        $boundary = null;
        foreach (is_array($points) ? $points : array() as $point) {
            if ($point[0] <= $now - $ageSeconds) {
                $boundary = (int) $point[1];
            }
        }
        return $boundary;
    }

    /** Drops checkpoints of surveys that are no longer cleaned (otherwise re-enabling would count from a stale checkpoint) */
    private function dropIdleCheckpoints($config = null)
    {
        $config = $config ?? $this->currentConfig();
        $records = PluginSetting::model()->findAllByAttributes(array('plugin_id' => $this->id, 'model' => 'Survey', 'key' => 'checkpoints'));
        foreach ($records as $record) {
            if ($this->effectiveHours($config, (int) $record->model_id) <= 0) {
                $record->delete();
            }
        }
    }

    // ------------------------------------------------------------------ preview

    /**
     * What changes if the proposed settings are saved, and how many responses that affects.
     * @param array $input commonEnabled, defaultRule, deleteEnabled, maxPerRun, rules {sid: hours}
     */
    private function preview(array $input)
    {
        @set_time_limit(120);
        $now = time();
        $old = $this->currentConfig();
        $new = $old;
        if (array_key_exists('commonEnabled', $input)) {
            $new['commonEnabled'] = (bool) $input['commonEnabled'];
        }
        if (!empty($input['defaultRule']) && (int) $input['defaultRule'] > 0) {
            $new['defaultRule'] = (int) $input['defaultRule'];
        }
        if (array_key_exists('deleteEnabled', $input)) {
            $new['dryRun'] = !$input['deleteEnabled'];
        }
        if (isset($input['maxPerRun'])) {
            $new['maxPerRun'] = max(1, (int) $input['maxPerRun']);
        }
        if (isset($input['rules']) && is_array($input['rules'])) {
            $new['own'] = $this->normalizeRules($input['rules']);
        }

        $titles = $this->getSurveyTitles(array_unique(array_merge(array_keys($old['own']), array_keys($new['own']))));
        $changes = array();
        $commonOld = $this->commonLabel($old);
        $commonNew = $this->commonLabel($new);
        if ($commonOld !== $commonNew) {
            $changes[] = array('what' => $this->t('Default period'), 'from' => $commonOld, 'to' => $commonNew);
        }
        $sids = array_unique(array_merge(array_keys($old['own']), array_keys($new['own'])));
        sort($sids);
        foreach ($sids as $sid) {
            $from = $old['own'][$sid] ?? null;
            $to = $new['own'][$sid] ?? null;
            if ($from !== $to) {
                $changes[] = array(
                    'what' => $sid . ' — ' . ($titles[$sid] ?? $this->t('survey deleted')),
                    'from' => $from === null ? sprintf($this->t('default period (%s)'), $commonOld) : $this->ruleLabel($from),
                    'to' => $to === null ? sprintf($this->t('default period (%s)'), $commonNew) : $this->ruleLabel($to),
                );
            }
        }
        if ($old['dryRun'] !== $new['dryRun']) {
            $off = $this->t('off (count only)');
            $on = $this->t('on');
            $changes[] = array('what' => $this->t('Delete responses'), 'from' => $old['dryRun'] ? $off : $on, 'to' => $new['dryRun'] ? $off : $on);
        }
        if ($old['maxPerRun'] !== $new['maxPerRun']) {
            $changes[] = array('what' => $this->t('Maximum responses per run'), 'from' => (string) $old['maxPerRun'], 'to' => (string) $new['maxPerRun']);
        }

        // Totals cover every survey cleaned before or after the change (otherwise switching only
        // "Delete responses" would show 0). The table lists surveys whose period changes; when only
        // "Delete responses" changes — every survey that will be cleaned.
        $dryRunChanged = $old['dryRun'] !== $new['dryRun'];
        $impact = array();
        $totalOld = 0;
        $totalNew = 0;
        $surveysNew = 0;
        foreach ($this->getActiveSurveys() as $sid => $info) {
            $hOld = $this->effectiveHours($old, $sid);
            $hNew = $this->effectiveHours($new, $sid);
            if ($hOld <= 0 && $hNew <= 0) {
                continue;
            }
            $survey = Survey::model()->findByPk($sid);
            if (!$survey || !$survey->hasResponsesTable) {
                continue;
            }
            $a = $hOld > 0 ? $this->countDeletable($survey, $hOld, $old['includeSaved'], $now, true) : array('count' => 0, 'note' => '');
            $b = $hNew > 0 ? $this->countDeletable($survey, $hNew, $new['includeSaved'], $now, $hOld > 0) : array('count' => 0, 'note' => '');
            $totalOld += $a['count'];
            $totalNew += $b['count'];
            if ($b['count'] > 0) {
                $surveysNew++;
            }
            if ($hOld === $hNew && !($dryRunChanged && $hNew > 0)) {
                continue;
            }
            $impact[] = array(
                'sid' => $sid,
                'title' => $info['title'],
                'from' => $this->ruleLabel($hOld),
                'to' => $this->ruleLabel($hNew),
                'countFrom' => $a['count'],
                'countTo' => $b['count'],
                'note' => $b['note'],
            );
        }
        usort($impact, function ($x, $y) {
            return abs($y['countTo'] - $y['countFrom']) <=> abs($x['countTo'] - $x['countFrom'])
                ?: $y['countTo'] <=> $x['countTo'] ?: $x['sid'] <=> $y['sid'];
        });
        return array(
            'changes' => $changes,
            'impact' => $impact,
            'totalFrom' => $totalOld,
            'totalTo' => $totalNew,
            'surveysTo' => $surveysNew,
            'schedule' => $this->schedule() + array('now' => $now),
            'dryRun' => $new['dryRun'],
            'dryRunFrom' => $old['dryRun'],
            'maxPerRun' => $new['maxPerRun'],
        );
    }

    /**
     * How many responses a run would delete now with period $hours (checkpoints are not written).
     * @return array{count:int, note:string}
     */
    private function countDeletable(Survey $survey, $hours, $includeSaved, $now, $useCheckpoints)
    {
        $sid = (int) $survey->sid;
        $table = $survey->responsesTableName;
        $db = Yii::app()->db;
        $age = $hours * 3600;
        $byDate = $this->isByDate($survey);
        $boundary = null;
        if (!$byDate) {
            $points = $useCheckpoints ? $this->get('checkpoints', 'Survey', $sid) : null;
            if (is_array($points) && $points) {
                $maxId = (int) $db->createCommand()->select('MAX(id)')->from($table)->queryScalar();
                if ($maxId < end($points)[1]) {
                    $points = null; // table recreated
                }
            }
            $boundary = $this->boundary($points, $now, $age);
            if ($boundary === null) {
                $start = is_array($points) && $points ? $points[0][0] : $now;
                $incomplete = (int) $db->createCommand()->select('COUNT(*)')->from($table)->where('submitdate IS NULL')->queryScalar();
                return array('count' => 0, 'note' => sprintf(
                    $this->t('the age is counted from the start of the response — deletion starts after %1$s UTC; incomplete now: %2$s'),
                    gmdate('d.m.Y H:i', $start + $age),
                    $this->num($incomplete)
                ));
            }
        }
        list($where, $params) = $this->condition($sid, $byDate, $now - $age, $boundary, $includeSaved);
        $count = (int) $db->createCommand()->select('COUNT(*)')->from("$table r")->where($where, $params)->queryScalar();
        return array('count' => $count, 'note' => '');
    }

    // ------------------------------------------------------------------ settings page

    /** "Surveys with their own period" table + adding a survey; data and texts go to assets/settings.js as JSON */
    private function renderRulesWidget($report)
    {
        $own = $this->getOwnRules();
        $active = $this->getActiveSurveys();
        $titles = $this->getSurveyTitles(array_keys($own));
        $rows = array();
        foreach ($own as $sid => $hours) {
            if (!isset($titles[$sid])) {
                continue; // survey deleted
            }
            $row = array(
                'sid' => $sid,
                'title' => $titles[$sid],
                'hours' => $hours,
                'active' => isset($active[$sid]),
                'incomplete' => null,
                'last' => '',
            );
            if (isset($active[$sid])) {
                $survey = Survey::model()->findByPk($sid);
                if ($survey && $survey->hasResponsesTable) {
                    $row['incomplete'] = (int) Yii::app()->db->createCommand()->select('COUNT(*)')
                        ->from($survey->responsesTableName)->where('submitdate IS NULL')->queryScalar();
                }
            }
            if (is_array($report) && isset($report['surveys'][$sid])) {
                $r = $report['surveys'][$sid];
                $note = $this->noteText($r['note']);
                $row['last'] = sprintf($report['dryRun'] ? $this->t('would be deleted: %s') : $this->t('deleted: %s'), $this->num($r['count']))
                    . ($note !== '' ? ' (' . $note . ')' : '');
            }
            $rows[] = $row;
        }
        $candidates = array();
        foreach ($active as $sid => $info) {
            $candidates[] = array('sid' => $sid, 'title' => $info['title']);
        }
        $periods = array();
        foreach ($this->ruleOptions(null) as $value => $label) {
            $periods[] = array('value' => (int) $value, 'label' => $label);
        }
        $data = array(
            'rows' => $rows,
            'surveys' => $candidates,
            'periods' => $periods,
            'previewUrl' => App()->createUrl('plugins/direct', array('plugin' => static::$name, 'function' => 'preview')),
            'surveyUrl' => App()->createUrl('surveyAdministration/view', array('surveyid' => '__SID__')),
            'lang' => App()->language,
            'i18n' => $this->jsTexts(),
        );
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return '<h2 class="h5 mt-3 mb-1">' . $this->t('Surveys with their own period', 'html') . '</h2>'
            . '<p class="text-muted">' . $this->t('A survey\'s own period always overrides the default period. "Keep forever" excludes a survey from the default period.', 'html') . '</p>'
            . '<div id="pir-rules"><p class="text-muted">' . $this->t('Loading… (JavaScript is required)', 'html') . '</p></div>'
            . '<input type="hidden" name="surveyRules" id="pir-rules-input" value="">'
            // After "Save" the core re-renders the page as the POST response, so reloading asks to resend the form.
            // The core redirects when "redirect" is posted: come back to this page with a plain GET instead.
            . '<input type="hidden" name="redirect" id="pir-redirect" value="'
            . CHtml::encode(App()->createUrl('admin/pluginmanager', array('sa' => 'configure', 'id' => $this->id)) . '#settings') . '">'
            . '<script>window.PurgeIncompleteResponsesData = ' . $json . ';</script>'
            . '<style>' . file_get_contents(__DIR__ . '/assets/settings.css') . '</style>'
            . '<script>' . file_get_contents(__DIR__ . '/assets/settings.js') . '</script>';
    }

    /** Texts used by assets/settings.js; %1$s… are filled in by the script */
    private function jsTexts()
    {
        return array(
            'colSurvey' => $this->t('Survey'),
            'colPeriod' => $this->t('Retention period'),
            'colIncomplete' => $this->t('Incomplete now'),
            'colLastRun' => $this->t('Last run'),
            'none' => $this->t('None. All active surveys use the default period.'),
            'inactive' => $this->t('inactive'),
            'new' => $this->t('new'),
            'toDefault' => $this->t('will use the default period'),
            'remove' => $this->t('Remove'),
            'restore' => $this->t('Restore'),
            'addSurvey' => $this->t('Add survey…'),
            'add' => $this->t('Add'),
            'restDefault' => $this->t('Other active surveys use the default period: %1$s.'),
            'restOff' => $this->t('The default period is off: nothing is deleted in other active surveys.'),
            'alsoInSurvey' => $this->t('A survey\'s own period can also be set in its settings → "Plugins".'),
            'checkTitle' => $this->t('Review the changes'),
            'counting' => $this->t('Counting the responses affected…'),
            'cancel' => $this->t('Cancel'),
            'save' => $this->t('Save'),
            'close' => $this->t('Close'),
            'saveUnchecked' => $this->t('Save without the check'),
            'checkFailed' => $this->t('Could not calculate the effect: %1$s. You can save without the check.'),
            'noChanges' => $this->t('No changes.'),
            'whatChanges' => $this->t('What changes'),
            'responsesSurveys' => $this->t('%1$s incomplete responses (surveys: %2$s)'),
            'withoutChanges' => $this->t('Without these changes: %1$s.'),
            'dryRunNext' => $this->t('Deletion is off: the next run will only count %1$s and delete nothing.'),
            'deleteNext' => $this->t('The next cron run will permanently delete %1$s.'),
            'perRunLimit' => $this->t('At most %1$s per run, the rest by the next runs.'),
            'nothingNext' => $this->t('The next run deletes nothing: there are no matching incomplete responses.'),
            'runsTable' => $this->t('Surveys being cleaned — what the next run would count or delete'),
            'changedTable' => $this->t('Surveys whose period changes — what the next run would count or delete'),
            'colWithout' => $this->t('Without changes'),
            'colAfter' => $this->t('After saving'),
            'colResponses' => $this->t('Responses'),
            'moreSurveys' => $this->t('and %1$s more surveys (included in the totals)'),
            'idleSurveys' => $this->t('%1$s more surveys: nothing to delete now — %2$s'),
            'totalShown' => $this->t('Total for these surveys'),
            'cronMissing' => $this->t('Cron is not running — deletion will not start until it is set up.'),
            'startsAt' => $this->t('Deletion starts with the next cron run — at about %1$s (in %2$s).'),
            'schedule' => $this->t('Cron runs every %1$s min. Last run at %2$s, next at about %3$s (in %4$s).'),
            'lessThanMinute' => $this->t('less than a minute'),
            'minutes' => $this->t('%1$s min'),
        );
    }

    /**
     * When cron runs: last run, interval, next run.
     * @return array{last:int|null, interval:int|null, next:int|null, late:bool}
     */
    private function schedule()
    {
        $info = $this->get('cronInfo');
        if (!is_array($info) || empty($info['time'])) {
            return array('last' => null, 'interval' => null, 'next' => null, 'late' => true);
        }
        $last = (int) $info['time'];
        $interval = max(1, (int) ($info['interval'] ?? 60));
        $next = $last + $interval * 60;
        // A run was missed by more than one interval (+5 min slack) — cron looks broken
        $late = time() > $next + $interval * 60 + 300;
        return array('last' => $last, 'interval' => $interval, 'next' => $late ? null : $next, 'late' => $late);
    }

    /** Schedule line under the "Deletion" heading; assets/settings.js converts times to the browser's time zone */
    private function renderSchedule()
    {
        $s = $this->schedule();
        $attrs = sprintf(
            ' id="pir-schedule" data-last="%s" data-interval="%s" data-next="%s" data-late="%d" data-now="%d"',
            $s['last'] ?? '',
            $s['interval'] ?? '',
            $s['next'] ?? '',
            $s['late'] ? 1 : 0,
            time()
        );
        if ($s['last'] === null) {
            return '<div class="alert alert-danger mb-0"' . $attrs . '>'
                . $this->t('Cron has never run this plugin — nothing is deleted or counted. Add a cron job (see README):', 'html')
                . ' <code>*/5 * * * * www-data cd /path/to/limesurvey &amp;&amp; php application/commands/console.php plugin cron --interval=5</code></div>';
        }
        $text = sprintf($this->t('Cron runs every %1$s min. Last run: %2$s UTC.'), $s['interval'], gmdate('d.m H:i', $s['last']));
        if ($s['late']) {
            return '<div class="alert alert-danger mb-0"' . $attrs . '>' . CHtml::encode($text . ' '
                . $this->t('Cron has not run for a long time — check the cron job.')) . '</div>';
        }
        return '<p class="text-muted mb-0"' . $attrs . '>' . CHtml::encode($text . ' '
            . sprintf($this->t('Next run at about %s UTC.'), gmdate('H:i', $s['next']))) . '</p>';
    }

    private function renderReport($report)
    {
        if (!is_array($report)) {
            return '<p class="text-muted">' . $this->t('The plugin has not run yet.', 'html') . '</p>';
        }
        $html = '<p>' . CHtml::encode($report['time'] . ' UTC — ' . $this->reportSummary($report)) . '</p>';
        foreach ($report['errors'] as $error) {
            $html .= '<p class="text-danger">' . CHtml::encode($error) . '</p>';
        }
        $lines = array();
        $titles = $this->getSurveyTitles(array_keys($report['surveys']));
        foreach ($report['surveys'] as $sid => $row) {
            $note = $this->noteText($row['note']);
            if ($row['count'] > 0 || $note !== '') {
                $lines[] = '<li>' . CHtml::encode(sprintf(
                    $this->t('%1$s — %2$s: period %3$s, responses: %4$s'),
                    $sid,
                    $titles[$sid] ?? '',
                    $this->hoursLabel($row['rule']),
                    $this->num($row['count'])
                ) . ($note !== '' ? ' (' . $note . ')' : '')) . '</li>';
            }
        }
        if ($lines) {
            $html .= '<details><summary>' . $this->t('By survey', 'html') . '</summary><ul class="mb-0">' . implode('', $lines) . '</ul></details>';
        }
        return $html;
    }

    /** One line about a run, for the settings page and the cron log */
    private function reportSummary(array $report)
    {
        $text = sprintf(
            $report['dryRun']
                ? $this->t('Count only, deletion is off. Incomplete responses that would be deleted: %1$s (surveys: %2$s).')
                : $this->t('Incomplete responses deleted: %1$s (surveys: %2$s).'),
            $this->num($report['total']),
            count($report['surveys'])
        );
        if ($report['limitReached']) {
            $text .= ' ' . $this->t('Per-run limit reached; the rest will be deleted by the next runs.');
        }
        return $text;
    }

    /** Note of a survey in the report: stored as data, rendered in the viewer's language */
    private function noteText($note)
    {
        if (is_string($note)) {
            return $note; // reports written by older versions
        }
        if (!is_array($note) || empty($note['type'])) {
            return '';
        }
        switch ($note['type']) {
            case 'starts':
                return sprintf($this->t('starts deleting after %s UTC'), gmdate('d.m.Y H:i', (int) $note['at']));
            case 'stuck':
                return sprintf($this->t('could not delete %s responses, stopped'), $this->num($note['n']));
            case 'finished':
                return sprintf($this->t('submitted during the run and left untouched: %s'), $this->num($note['n']));
        }
        return '';
    }

    private function modeText($mode)
    {
        return $mode === 'date' ? $this->t('by date') : ($mode === 'checkpoints' ? $this->t('by checkpoints') : (string) $mode);
    }

    // ------------------------------------------------------------------ periods and labels

    /** Options for the default period ("keep forever" is the on/off switch instead) */
    private function periodOptions($current)
    {
        $options = array();
        foreach ($this->presets($current) as $hours) {
            $options[(string) $hours] = $this->hoursLabel($hours);
        }
        return $options;
    }

    /** Options for a survey's own period: "Keep forever" and the periods */
    private function ruleOptions($current)
    {
        $options = array('0' => $this->t('Keep forever'));
        foreach ($this->presets($current) as $hours) {
            $options[(string) $hours] = $this->hoursLabel($hours);
        }
        return $options;
    }

    private function presets($current)
    {
        $presets = self::PRESETS;
        if ($current > 0 && !in_array((int) $current, $presets, true)) {
            $presets[] = (int) $current;
            sort($presets);
        }
        return $presets;
    }

    private function commonOptionLabel()
    {
        return $this->isCommonEnabled()
            ? sprintf($this->t('Default period (%s)'), $this->hoursLabel($this->getDefaultHours()))
            : $this->t('Default period (off — not deleted)');
    }

    private function commonLabel(array $config)
    {
        return $config['commonEnabled'] ? $this->hoursLabel($config['defaultRule']) : $this->t('off');
    }

    private function ruleLabel($hours)
    {
        return $hours > 0 ? $this->hoursLabel($hours) : $this->t('forever');
    }

    /** Each preset is a separate string, so every language can use its own plural forms */
    private function hoursLabel($hours)
    {
        $labels = array(
            1 => $this->t('1 hour'),
            3 => $this->t('3 hours'),
            6 => $this->t('6 hours'),
            12 => $this->t('12 hours'),
            24 => $this->t('1 day'),
            48 => $this->t('2 days'),
            72 => $this->t('3 days'),
            120 => $this->t('5 days'),
            168 => $this->t('7 days'),
            336 => $this->t('14 days'),
            720 => $this->t('30 days'),
            1440 => $this->t('60 days'),
            2160 => $this->t('90 days'),
            4320 => $this->t('180 days'),
            8760 => $this->t('365 days'),
        );
        if (isset($labels[(int) $hours])) {
            return $labels[(int) $hours];
        }
        return sprintf($this->t('%s h'), (int) $hours);
    }

    /** Number with digit grouping of the current language */
    private function num($n)
    {
        try {
            Yii::import('application.helpers.surveytranslator_helper', true);
            return App()->getNumberFormatter()->formatDecimal((int) $n);
        } catch (Throwable $e) {
            return number_format((int) $n, 0, '.', "\u{00A0}");
        }
    }

    /** Translation in the admin's language; plain text unless an escape mode is given */
    private function t($text, $escape = 'unescaped')
    {
        return $this->gT($text, $escape);
    }

    // ------------------------------------------------------------------ storage

    /** All current settings */
    private function currentConfig()
    {
        return array(
            'commonEnabled' => $this->isCommonEnabled(),
            'defaultRule' => $this->getDefaultHours(),
            // "Resume later" drafts are ordinary incomplete responses and follow the same period
            'includeSaved' => true,
            'dryRun' => !$this->isDeleteEnabled(),
            'maxPerRun' => max(1, (int) $this->getSetting('maxPerRun')),
            'own' => $this->getOwnRules(),
        );
    }

    private function effectiveHours(array $config, $sid)
    {
        if (array_key_exists($sid, $config['own'])) {
            return $config['own'][$sid];
        }
        return $config['commonEnabled'] ? $config['defaultRule'] : 0;
    }

    /** @return int[] sid => hours (0 — keep forever) for surveys with their own period */
    private function getOwnRules()
    {
        $rules = array();
        $records = PluginSetting::model()->findAllByAttributes(array('plugin_id' => $this->id, 'model' => 'Survey', 'key' => 'rule'));
        foreach ($records as $record) {
            $value = json_decode((string) $record->value, true);
            if ($value !== null && $value !== '' && $value !== 'default') {
                $rules[(int) $record->model_id] = max(0, (int) $value);
            }
        }
        ksort($rules);
        return $rules;
    }

    /** @return int[] sid => hours from a posted {sid: hours} */
    private function normalizeRules(array $rules)
    {
        $result = array();
        foreach ($rules as $sid => $value) {
            $value = $this->normalizeRule($value);
            if ((int) $sid > 0 && $value !== null) {
                $result[(int) $sid] = $value;
            }
        }
        return $result;
    }

    /** @return int|null hours; null — the default period applies */
    private function getSurveyRule($sid)
    {
        $own = $this->getOwnRules();
        return $own[(int) $sid] ?? null;
    }

    /** null/'default' removes the survey's own period (the default period applies) */
    private function setSurveyRule($sid, $value)
    {
        $rule = $this->normalizeRule($value);
        if ($rule === null) {
            PluginSetting::model()->deleteAllByAttributes(array('plugin_id' => $this->id, 'model' => 'Survey', 'model_id' => (int) $sid, 'key' => 'rule'));
        } else {
            $this->set('rule', $rule, 'Survey', (int) $sid);
        }
    }

    /** @return int|null hours (0 — keep forever); null — the default period applies */
    private function normalizeRule($value)
    {
        if ($value === null || $value === '' || $value === 'default') {
            return null;
        }
        return max(0, (int) $value);
    }

    private function isCommonEnabled()
    {
        $value = $this->get('commonEnabled');
        if ($value === null || $value === '') {
            // Before 1.1 there was no switch: default period 0 meant "off"
            return (int) $this->get('defaultRule') > 0;
        }
        return (bool) $value;
    }

    /** On — delete. Before 1.1 the setting was the opposite: "dryRun" (default 1) */
    private function isDeleteEnabled()
    {
        $value = $this->get('deleteEnabled');
        if ($value === null || $value === '') {
            $dryRun = $this->get('dryRun');
            return $dryRun !== null && $dryRun !== '' && !(bool) $dryRun;
        }
        return (bool) $value;
    }

    private function getDefaultHours()
    {
        $hours = (int) $this->get('defaultRule');
        return $hours > 0 ? $hours : self::DEFAULT_HOURS;
    }

    private function getSetting($key)
    {
        $value = $this->get($key);
        return $value === null ? $this->settings[$key]['default'] : $value;
    }

    // ------------------------------------------------------------------ surveys

    private function isByDate(Survey $survey)
    {
        return $survey->isDateStamp && !$survey->isAnonymized;
    }

    /** Active surveys: sid => [title] */
    private function getActiveSurveys()
    {
        $sids = Yii::app()->db->createCommand()->select('sid')->from('{{surveys}}')->where("active = 'Y'")->order('sid')->queryColumn();
        $titles = $this->getSurveyTitles($sids);
        $surveys = array();
        foreach ($sids as $sid) {
            $surveys[(int) $sid] = array('title' => $titles[(int) $sid] ?? '');
        }
        return $surveys;
    }

    /** @return string[] sid => title in the survey's base language (existing surveys only) */
    private function getSurveyTitles(array $sids)
    {
        $sids = array_values(array_filter(array_map('intval', $sids)));
        if (!$sids) {
            return array();
        }
        $rows = Yii::app()->db->createCommand()
            ->select('s.sid, ls.surveyls_title')
            ->from('{{surveys}} s')
            ->leftJoin('{{surveys_languagesettings}} ls', 'ls.surveyls_survey_id = s.sid AND ls.surveyls_language = s.language')
            ->where(array('in', 's.sid', $sids))
            ->queryAll();
        $titles = array();
        foreach ($rows as $row) {
            $titles[(int) $row['sid']] = trim(html_entity_decode(strip_tags((string) $row['surveyls_title']), ENT_QUOTES, 'UTF-8'));
        }
        return $titles;
    }

    // ------------------------------------------------------------------ helpers

    /** One run at a time: GET_LOCK on MySQL/MariaDB, advisory lock on PostgreSQL, none elsewhere */
    private function lock()
    {
        $db = Yii::app()->db;
        switch ($db->driverName) {
            case 'mysql':
            case 'mysqli':
                return (int) $db->createCommand('SELECT GET_LOCK(:name, 0)')->queryScalar(array(':name' => self::LOCK_NAME)) === 1;
            case 'pgsql':
                return (bool) $db->createCommand('SELECT pg_try_advisory_lock(' . self::PG_LOCK_KEY . ')')->queryScalar();
        }
        return true;
    }

    private function unlock()
    {
        $db = Yii::app()->db;
        switch ($db->driverName) {
            case 'mysql':
            case 'mysqli':
                $db->createCommand('SELECT RELEASE_LOCK(:name)')->queryScalar(array(':name' => self::LOCK_NAME));
                break;
            case 'pgsql':
                $db->createCommand('SELECT pg_advisory_unlock(' . self::PG_LOCK_KEY . ')')->queryScalar();
                break;
        }
    }

    private function say($message)
    {
        $this->log($message, CLogger::LEVEL_INFO);
        if (PHP_SAPI === 'cli') {
            echo $message, PHP_EOL;
        }
    }
}
