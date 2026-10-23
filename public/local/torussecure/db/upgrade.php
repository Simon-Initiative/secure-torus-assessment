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

/**
 * Add ownership metadata without changing any exam policy, key, XML or enabled state.
 */
function xmldb_local_torussecure_upgrade(int $oldversion): bool {
    global $DB;
    if ($oldversion < 2026102100) {
        $manager = $DB->get_manager();
        $table = new xmldb_table('local_torussecure');
        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'ltiinstanceid');
        if (!$manager->field_exists($table, $field)) {
            $manager->add_field($table, $field);
        }
        $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$manager->index_exists($table, $index)) {
            $manager->add_index($table, $index);
        }
        $records = $DB->get_recordset_sql('SELECT s.id, cm.course
              FROM {local_torussecure} s
              JOIN {course_modules} cm ON cm.id = s.cmid AND cm.instance = s.ltiinstanceid
              JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              JOIN {lti} l ON l.id = s.ltiinstanceid AND l.course = cm.course
             WHERE s.courseid = 0', ['modname' => 'lti']);
        try {
            foreach ($records as $record) {
                $DB->set_field('local_torussecure', 'courseid', $record->course, ['id' => $record->id]);
            }
        } finally {
            $records->close();
        }
        upgrade_plugin_savepoint(true, 2026102100, 'local', 'torussecure');
    }
    return true;
}
