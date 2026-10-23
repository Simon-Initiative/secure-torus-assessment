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
 * Persistence for activity-specific SEB configurations.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure\local;

/**
 * Store configuration independently of learner, attempt and grade data.
 */
class config_repository {
    /**
     * Find the configuration for a course module.
     *
     * @param int $cmid Course-module ID.
     * @return \stdClass|null Configuration, if present.
     */
    public function get_by_cmid(int $cmid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_torussecure', ['cmid' => $cmid]) ?: null;
    }

    /**
     * Find the configuration for an LTI instance.
     *
     * @param int $ltiinstanceid LTI instance ID.
     * @return \stdClass|null Configuration, if present.
     */
    public function get_by_lti_instance_id(int $ltiinstanceid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_torussecure', ['ltiinstanceid' => $ltiinstanceid]) ?: null;
    }

    /**
     * Create an enabled secure mapping for an existing LTI activity.
     *
     * The caller handles capabilities and attaching the availability condition.
     * Duplicate mappings are rejected; use replace_generated_config to regenerate.
     *
     * @param int $cmid Course-module ID.
     * @param int $ltiinstanceid Matching LTI instance ID.
     * @param string $xml Exact configuration bytes to serve to SEB.
     * @param string $configkey Config Key calculated by the SEB-specific algorithm.
     * @return \stdClass Persisted configuration.
     */
    public function create_for_activity(int $cmid, int $ltiinstanceid, string $xml, string $configkey): \stdClass {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $this->validate_write($cmid, $ltiinstanceid, $xml, $configkey);
            if ($this->get_by_cmid($cmid) !== null || $this->get_by_lti_instance_id($ltiinstanceid) !== null) {
                throw new \moodle_exception('configurationexists', 'local_torussecure');
            }
            $now = time();
            $record = (object) [
                'cmid' => $cmid,
                'ltiinstanceid' => $ltiinstanceid,
                'courseid' => $DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST),
                'enabled' => 1,
                'configxml' => $xml,
                'configkey' => $configkey,
                'configversion' => hash('sha256', $xml),
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $record->id = $DB->insert_record('local_torussecure', $record);
            $transaction->allow_commit();
            return $record;
        } catch (\Throwable $exception) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($exception);
            }
            throw $exception;
        }
    }

    /**
     * Atomically replace configuration bytes, key and version for an existing mapping.
     *
     * Preserves the mapping, enabled state and creation timestamp. Does not create
     * missing mappings or enable disabled activities.
     *
     * @param int $cmid Course-module ID.
     * @param string $xml Exact replacement configuration bytes.
     * @param string $configkey Config Key for the replacement configuration.
     * @return \stdClass Persisted configuration.
     */
    public function replace_generated_config(int $cmid, string $xml, string $configkey): \stdClass {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $record = $this->get_by_cmid($cmid);
            if ($record === null) {
                throw new \moodle_exception('configurationmissing', 'local_torussecure');
            }
            $this->validate_write($cmid, (int) $record->ltiinstanceid, $xml, $configkey);
            // Update only generated fields, avoiding overwriting a concurrently changed enabled state.
            $changes = (object) [
                'id' => $record->id,
                'configxml' => $xml,
                'configkey' => $configkey,
                'configversion' => hash('sha256', $xml),
                'timemodified' => time(),
            ];
            $DB->update_record('local_torussecure', $changes);
            $record = $DB->get_record('local_torussecure', ['id' => $record->id], '*', MUST_EXIST);
            $transaction->allow_commit();
            return $record;
        } catch (\Throwable $exception) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($exception);
            }
            throw $exception;
        }
    }

    /**
     * Whether an LTI instance has an enabled secure mapping.
     *
     * This is a configuration lookup, not proof of a valid SEB request.
     *
     * @param int $ltiinstanceid LTI instance ID.
     * @return bool Whether secure enforcement is configured.
     */
    public function is_secure_lti_instance(int $ltiinstanceid): bool {
        $record = $this->get_by_lti_instance_id($ltiinstanceid);
        return $record !== null && !empty($record->enabled);
    }

    /**
     * Validate both the current activity mapping and configuration on every write.
     *
     * @param int $cmid Course-module ID.
     * @param int $ltiinstanceid Expected LTI instance ID.
     * @param string $xml Configuration bytes.
     * @param string $configkey SEB Config Key.
     */
    private function validate_write(int $cmid, int $ltiinstanceid, string $xml, string $configkey): void {
        global $DB;
        $cm = $DB->get_record('course_modules', ['id' => $cmid]);
        if (
            !$cm || (int) $cm->instance !== $ltiinstanceid ||
            !$DB->record_exists('modules', ['id' => $cm->module, 'name' => 'lti']) ||
            !$DB->record_exists('lti', ['id' => $ltiinstanceid, 'course' => $cm->course])
        ) {
            throw new \moodle_exception('invalidactivitymapping', 'local_torussecure');
        }
        if (trim($xml) === '') {
            throw new \moodle_exception('emptyconfiguration', 'local_torussecure');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $configkey) !== 1) {
            throw new \moodle_exception('invalidconfigkey', 'local_torussecure');
        }
    }
}
