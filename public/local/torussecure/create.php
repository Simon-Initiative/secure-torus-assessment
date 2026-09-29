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
 * Protected secure activity creation entry point.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');

$courseid = required_param('courseid', PARAM_INT);
$sectionid = optional_param('sectionid', 0, PARAM_INT);
$section = optional_param('section', 0, PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('moodle/course:manageactivities', $context);
require_capability('mod/lti:addinstance', $context);
require_capability('local/torussecure:configure', $context);

$PAGE->set_url(new moodle_url('/local/torussecure/create.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('configuretitle', 'local_torussecure'));
$PAGE->set_heading(format_string($course->fullname));
$form = new \local_torussecure\form\create_activity_form(null, [
    'course' => $course, 'sectionid' => $sectionid, 'section' => $section,
]);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}
$error = null;
if ($data = $form->get_data()) {
    require_sesskey();
    $data->courseid = $courseid;
    try {
        $activity = (new \local_torussecure\local\activity_provisioner())->create($data);
        redirect(new moodle_url('/local/torussecure/diagnostics.php', ['cmid' => $activity->coursemodule]));
    } catch (moodle_exception $exception) {
        $error = $exception->getMessage();
    }
}
echo $OUTPUT->header();
if ($error !== null) {
    echo $OUTPUT->notification(s($error), 'error');
}
$form->display();
echo $OUTPUT->footer();
