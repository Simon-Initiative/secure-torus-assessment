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
 * Secure activity configuration download.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace local_torussecure\local;

/**
 * Attach the mandatory SEB condition after creating the LTI activity and its configuration.
 */
class availability_installer {
    /**
     * Internal provisioning operation; the caller is responsible for permissions and cleanup on failure.
     */
    public function attach(int $cmid): void {
        global $CFG, $DB;
        if (empty($CFG->enableavailability)) {
            throw new \moodle_exception('availabilitydisabled', 'local_torussecure');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
            $record = (new config_repository())->get_by_cmid($cmid);
            if (
                $record === null || empty($record->enabled) ||
                (int) $record->ltiinstanceid !== (int) $cm->instance ||
                !$DB->record_exists('modules', ['id' => $cm->module, 'name' => 'lti'])
            ) {
                throw new \moodle_exception('invalidactivitymapping', 'local_torussecure');
            }
            $root = (object) ['op' => '&', 'c' => [], 'showc' => []];
            if (!empty($cm->availability)) {
                $existing = json_decode($cm->availability, false, 512, JSON_THROW_ON_ERROR);
                new \core_availability\tree($existing); // Validate before modifying; never discard malformed restrictions.
                if ($existing->op === '&') {
                    $root = $existing;
                } else {
                    // Preserve OR/negated logic inside an AND so it cannot bypass the new condition.
                    // For a negated OR, conservatively hide its group if any original child was hidden.
                    $show = $existing->show ?? !in_array(false, $existing->showc ?? [], true);
                    unset($existing->show, $existing->showc);
                    $root->c[] = $existing;
                    $root->showc[] = $show;
                }
            }
            $found = false;
            foreach ($root->c as $index => $child) {
                if (($child->type ?? '') === 'torusseb') {
                    $root->showc[$index] = true;
                    $found = true;
                }
            }
            if (!$found) {
                $root->c[] = (object) ['type' => 'torusseb'];
                $root->showc[] = true;
            }
            new \core_availability\tree($root);
            $DB->set_field('course_modules', 'availability', json_encode($root, JSON_THROW_ON_ERROR), ['id' => $cmid]);
            rebuild_course_cache($cm->course, true);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($exception);
            }
            throw $exception;
        }
    }
}
