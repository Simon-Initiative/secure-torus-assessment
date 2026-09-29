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
 * Authorize configuration downloads and prepare an exact-byte response.
 */
class config_download {
    /**
     * Load configuration for the current user; the HTTP entry point also requires course login.
     *
     * @param int $cmid External Tool course-module ID.
     * @return array Response headers and the exact stored body.
     */
    public function get_file(int $cmid): array {
        global $USER;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'lti');
        $context = \context_module::instance($cmid);
        $canconfigure = has_capability('local/torussecure:configure', $context);
        if (!isloggedin() || isguestuser()) {
            throw new \require_login_exception('An authenticated, non-guest user is required.');
        }
        if (!$canconfigure) {
            if (!is_enrolled($context, $USER, '', true)) {
                throw new \moodle_exception('configaccessdenied', 'local_torussecure');
            }
            require_capability('mod/lti:view', $context);
            $section = $cm->get_section_info();
            if (
                (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context)) ||
                !$section->uservisible ||
                (!$cm->uservisible && !$cm->is_visible_on_course_page())
            ) {
                throw new \moodle_exception('configaccessdenied', 'local_torussecure');
            }
        }
        $record = (new config_repository())->get_by_cmid($cmid);
        if ($record === null) {
            throw new \moodle_exception('configurationmissing', 'local_torussecure');
        }
        if ((int) $record->ltiinstanceid !== (int) $cm->instance) {
            throw new \moodle_exception('invalidactivitymapping', 'local_torussecure');
        }
        if (empty($record->enabled)) {
            throw new \moodle_exception('configurationdisabled', 'local_torussecure');
        }
        if (trim($record->configxml) === '') {
            throw new \moodle_exception('emptyconfiguration', 'local_torussecure');
        }
        return [
            'headers' => [
                'Content-Type' => 'application/seb',
                'Content-Disposition' => 'attachment; filename="torus-' . $cmid . '.seb"',
                'Cache-Control' => 'private, no-store, no-cache, max-age=0, must-revalidate, no-transform',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'body' => $record->configxml,
        ];
    }
}
