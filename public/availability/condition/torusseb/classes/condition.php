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
 * Request-bound Secure Torus availability condition.
 *
 * @package    availability_torusseb
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_torusseb;

use local_torussecure\local\config_repository;
use local_torussecure\local\seb_request;

/**
 * Require activity-bound SEB request evidence or an authorized diagnostic bypass.
 */
class condition extends \core_availability\condition {
    /**
     * Initialize the service or condition.
     *
     * @param \stdClass $structure Serialized condition.
     */
    public function __construct($structure) {
        if (($structure->type ?? null) !== 'torusseb') {
            throw new \coding_exception('Invalid Secure Torus availability condition');
        }
    }

    /**
     * Serialize the secure availability condition.
     *
     * @return \stdClass Serialized condition.
     */
    public function save() {
        return (object) ['type' => 'torusseb'];
    }

    /**
     * Evaluate current-request evidence and condition inversion.
     *
     * @return bool Whether the current request satisfies the condition and its inversion.
     */
    public function is_available($not, \core_availability\info $info, $grabthelot, $userid) {
        global $USER;
        if (!($info instanceof \core_availability\info_module)) {
            return false;
        }
        $cm = $info->get_course_module();
        // Request evidence belongs to the current user, never to a user being inspected in a report.
        if ($cm->modname !== 'lti' || (!empty($userid) && (int) $userid !== (int) $USER->id)) {
            return false;
        }
        $record = (new config_repository())->get_by_cmid((int) $cm->id);
        if ($record === null || empty($record->enabled) || (int) $record->ltiinstanceid !== (int) $cm->instance) {
            return false;
        }
        $result = seb_request::validate($record, $USER, \context_module::instance($cm->id));
        $available = in_array($result, ['valid', 'valid_js', 'bypassed'], true);
        return $not ? !$available : $available;
    }

    /**
     * Describe the restriction and offer configuration links.
     *
     * @return string Description and access to the configuration status page.
     */
    public function get_description($full, $not, \core_availability\info $info) {
        $description = get_string($not ? 'requiresnotseb' : 'requiresseb', 'availability_torusseb');
        if ($info instanceof \core_availability\info_module) {
            $description .= ' ' . \local_torussecure\local\config_links::render((int) $info->get_course_module()->id);
        }
        return $description;
    }

    /**
     * Return a debug label without sensitive data.
     *
     * @return string Debug representation without keys or configuration contents.
     */
    protected function get_debug_string() {
        return 'torusseb';
    }
}
