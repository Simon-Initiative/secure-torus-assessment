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
 * Protected, read-only secure activity diagnostics.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');

$cmid = required_param('cmid', PARAM_INT);
$cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
$course = get_course($cm->course);
// Check enrollment without recursing into this activity's SEB restriction.
require_login($course);
$context = context_module::instance($cmid);
require_capability('local/torussecure:configure', $context);
if (!$cm->visible && !has_capability('moodle/course:viewhiddenactivities', $context)) {
    throw new moodle_exception('activityiscurrentlyhidden');
}

$PAGE->set_url(new moodle_url('/local/torussecure/diagnostics.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('diagnosticstitle', 'local_torussecure'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_cacheable(false);
$status = (new \local_torussecure\local\diagnostics())->get_status($cmid);
echo $OUTPUT->header();
echo $OUTPUT->notification(get_string('diagnosticsrequestnotice', 'local_torussecure'), 'info');
$table = new html_table();
$table->head = [get_string('diagnosticscheck', 'local_torussecure'), get_string('diagnosticsstatus', 'local_torussecure')];
foreach ($status as $check => $value) {
    $label = get_string('diagnostics' . $check, 'local_torussecure');
    $result = get_string('diagnosticsstatus' . (is_bool($value) ? ($value ? 'yes' : 'no') : $value), 'local_torussecure');
    $table->data[] = [s($label), s($result)];
}
echo html_writer::table($table);
if ($status['configuration'] && $status['enabled']) {
    echo html_writer::div(\local_torussecure\local\config_links::render($cmid));
}
echo $OUTPUT->footer();
