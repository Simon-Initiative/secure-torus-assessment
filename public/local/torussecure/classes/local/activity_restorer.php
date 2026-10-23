<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Secure Torus activity lifecycle integration.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure\local;

/**
 * Destination-only finalization; never called on ordinary launches or existing activities.
 */
class activity_restorer {
    /**
     * Generate and attach configuration after Moodle has restored the native LTI instance.
     *
     * @param int $cmid New course-module ID.
     * @param int $ltiid New LTI instance ID from Moodle's task mapping.
     * @param array $payload Versioned backup payload.
     */
    public function restore(int $cmid, int $ltiid, array $payload): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        foreach ($payload as $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new \moodle_exception('restoreinvalidpayload', 'local_torussecure');
            }
        }
        if (
            (string) ($payload['formatversion'] ?? '') !== '1' ||
            (string) ($payload['managed'] ?? '') !== '1' ||
            !in_array((string) ($payload['enabled'] ?? ''), ['0', '1'], true) ||
            !ctype_digit((string) ($payload['sourcecmid'] ?? '')) || (int) $payload['sourcecmid'] <= 0 ||
            !ctype_digit((string) ($payload['sourceltiinstanceid'] ?? '')) ||
            (int) $payload['sourceltiinstanceid'] <= 0 || !is_string($payload['configxml'] ?? null)
        ) {
            throw new \moodle_exception('restoreinvalidpayload', 'local_torussecure');
        }
        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        if (
            (int) $cm->instance !== $ltiid ||
            !$DB->record_exists('modules', ['id' => $cm->module, 'name' => 'lti'])
        ) {
            throw new \moodle_exception('invalidactivitymapping', 'local_torussecure');
        }
        $instance = $DB->get_record('lti', ['id' => $ltiid, 'course' => $cm->course], '*', MUST_EXIST);
        // Use the exact registration restored by Moodle. Never substitute the site default.
        $type = $DB->get_record('lti_types', ['id' => $instance->typeid]);
        $torusorigin = (string) get_config('local_torussecure', 'torusorigin');
        if (
            !$type || $type->ltiversion !== LTI_VERSION_1P3 || empty($type->clientid) ||
            (int) $type->state !== LTI_TOOL_STATE_CONFIGURED ||
            !in_array((int) $type->course, [(int) SITEID, (int) $cm->course], true)
        ) {
            throw new \moodle_exception('invalidtoolregistration', 'local_torussecure');
        }
        $config = lti_get_type_config($type->id);
        foreach ([$type->baseurl, $instance->toolurl, $instance->securetoolurl, $config['securetoolurl'] ?? ''] as $url) {
            if (!empty($url) && self::origin($url) !== self::origin($torusorigin)) {
                throw new \moodle_exception('invalidtoolregistration', 'local_torussecure');
            }
        }
        $scopes = lti_get_permitted_service_scopes($type, $config);
        if (
            !in_array('https://purl.imsglobal.org/spec/lti-ags/scope/score', $scopes, true) ||
            !in_array('https://purl.imsglobal.org/spec/lti-ags/scope/lineitem', $scopes, true) ||
            (isset($config['acceptgrades']) && (int) $config['acceptgrades'] === LTI_SETTING_NEVER)
        ) {
            throw new \moodle_exception('invalidagsconfiguration', 'local_torussecure');
        }
        if (
            empty($CFG->enableavailability) ||
            !array_key_exists('torusseb', \core\plugininfo\availability::get_enabled_plugins()) ||
            !class_exists(\availability_torusseb\condition::class) ||
            !array_key_exists('torussecure', \core_component::get_plugin_list('ltisource'))
        ) {
            throw new \moodle_exception('availabilitydisabled', 'local_torussecure');
        }
        $policy = restore_policy::decode($payload['configxml'], (int) $payload['sourcecmid']);
        $moodleorigin = get_config('local_torussecure', 'moodleorigin') ?: $CFG->wwwroot;
        // Extra destinations have institution-specific meaning. Require review when moving institutions.
        if (
            (self::origin($moodleorigin) !== self::origin($policy['moodleorigin']) ||
            self::origin($torusorigin) !== self::origin($policy['torusorigin'])) &&
            (!empty($policy['options']['additionalOrigins']) || isset($policy['options']['quitURL']))
        ) {
            throw new \moodle_exception('restoreoriginreview', 'local_torussecure');
        }
        $xml = (new seb_config_builder())->build($cmid, $moodleorigin, $torusorigin, $policy['options']);
        $key = (new seb_config_key())->generate($xml);
        $repository = new config_repository();
        if ($repository->get_by_cmid($cmid) !== null || $repository->get_by_lti_instance_id($ltiid) !== null) {
            throw new \moodle_exception('configurationexists', 'local_torussecure');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $record = $repository->create_for_activity($cmid, $ltiid, $xml, $key);
            // Attach while enabled, then preserve the source's disabled state within this transaction.
            (new availability_installer())->attach($cmid);
            $DB->set_field('local_torussecure', 'enabled', (int) $payload['enabled'], ['id' => $record->id]);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
    }

    /**
     * Compare valid HTTPS origins, including effective ports.
     */
    private static function origin(string $url): string {
        if (!seb_request::is_public_url($url)) {
            throw new \moodle_exception('invalidorigin', 'local_torussecure');
        }
        $parts = parse_url($url);
        return strtolower($parts['host']) . ':' . ($parts['port'] ?? 443);
    }
}
