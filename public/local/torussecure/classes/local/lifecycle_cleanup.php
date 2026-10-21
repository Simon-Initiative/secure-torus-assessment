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
 * Conservative lifecycle cleanup, also used by the dry-run-first maintenance command.
 */
class lifecycle_cleanup {
    /**
     * Recursively identify our restriction without evaluating request-bound availability.
     */
    public static function has_restriction(?string $availability): bool {
        $tree = json_decode($availability ?? '');
        $visit = function ($node) use (&$visit): bool {
            if (!is_object($node)) {
                return false;
            }
            if (($node->type ?? '') === 'torusseb') {
                return true;
            }
            foreach (is_array($node->c ?? null) ? $node->c : [] as $child) {
                if ($visit($child)) {
                    return true;
                }
            }
            return false;
        };
        return $visit($tree);
    }

    /**
     * Report mappings without mutation unless cleanup was explicitly requested.
     *
     * @param bool $cleanup Whether to remove confirmed orphans.
     * @param callable $report Receives only IDs and a classification, never configuration.
     * @return array Counts by classification and deletion outcome.
     */
    public static function report(bool $cleanup, callable $report): array {
        global $DB;
        $counts = ['valid' => 0, 'inconsistent' => 0, 'orphan' => 0, 'deleted' => 0];
        $records = $DB->get_recordset('local_torussecure', [], 'id', 'id,cmid,ltiinstanceid,courseid');
        try {
            foreach ($records as $record) {
                $status = self::classify($record);
                $counts[$status]++;
                if ($status !== 'valid') {
                    $report($record, $status);
                }
                if ($cleanup && $status === 'orphan' && self::delete_orphan((int) $record->id)) {
                    $counts['deleted']++;
                }
            }
        } finally {
            $records->close();
        }
        return $counts;
    }

    /**
     * Classify against authoritative records. Inconsistent mappings require investigation.
     */
    public static function classify(\stdClass $record): string {
        global $DB;
        $cm = $DB->get_record('course_modules', ['id' => $record->cmid]);
        $instance = $DB->get_record('lti', ['id' => $record->ltiinstanceid]);
        if (!$cm && !$instance) {
            return 'orphan';
        }
        if (
            !$cm || !$instance || (int) $cm->instance !== (int) $instance->id ||
            (int) $cm->course !== (int) $instance->course ||
            !$DB->record_exists('modules', ['id' => $cm->module, 'name' => 'lti']) ||
            ((int) $record->courseid !== 0 && (int) $record->courseid !== (int) $cm->course)
        ) {
            return 'inconsistent';
        }
        return 'valid';
    }

    /**
     * Delete only after reloading and rechecking immediately inside a transaction.
     */
    public static function delete_orphan(int $id): bool {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $record = $DB->get_record('local_torussecure', ['id' => $id]);
        $deleted = $record && self::classify($record) === 'orphan';
        if ($deleted) {
            $DB->delete_records('local_torussecure', ['id' => $id]);
        }
        $transaction->allow_commit();
        return (bool) $deleted;
    }

    /**
     * Clean only the mapping belonging to the module Moodle has actually deleted.
     */
    public static function module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;
        if (($event->other['modulename'] ?? '') !== 'lti') {
            return;
        }
        $record = $DB->get_record('local_torussecure', ['cmid' => $event->objectid]);
        if ($record && (int) $record->ltiinstanceid === (int) $event->other['instanceid']) {
            self::delete_orphan((int) $record->id);
        }
    }

    /**
     * Whole-course removal bypasses module events, so use stored, validated course ownership.
     */
    public static function course_content_deleted(\core\event\course_content_deleted $event): void {
        global $DB;
        $records = $DB->get_recordset('local_torussecure', ['courseid' => $event->objectid], '', 'id');
        try {
            foreach ($records as $record) {
                self::delete_orphan((int) $record->id);
            }
        } finally {
            $records->close();
        }
    }
}
