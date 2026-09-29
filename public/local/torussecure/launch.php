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
 * Verify SEB before entering Moodle's restricted LTI activity.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');

$cmid = required_param('cmid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'lti');
require_login($course, false);
$context = context_module::instance($cmid);
require_capability('mod/lti:view', $context);
// Reuse the download access policy to enforce enrolment, visibility and configuration validity.
(new \local_torussecure\local\config_download())->get_file($cmid);
$record = (new \local_torussecure\local\config_repository())->get_by_cmid($cmid);
$PAGE->set_url(new moodle_url('/local/torussecure/launch.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('verifytitle', 'local_torussecure'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_cacheable(false);
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
$target = new moodle_url('/mod/lti/view.php', ['id' => $cmid]);
$ispost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($ispost) {
    $verified = \local_torussecure\local\js_launch::verify(
        $record,
        \local_torussecure\local\seb_request::current_url(),
        required_param('configkeyhash', PARAM_RAW),
        required_param('sesskey', PARAM_RAW)
    );
    if ($verified) {
        redirect($target);
    }
} else {
    $result = \local_torussecure\local\seb_request::validate($record, $USER, $context);
    if (in_array($result, ['valid', 'bypassed'], true)) {
        redirect($target);
    }
    if (!optional_param('challenge', '', PARAM_ALPHANUM)) {
        redirect(\local_torussecure\local\js_launch::begin($record));
    }
}
$PAGE->requires->js(new moodle_url('/local/torussecure/js/verify.js'));
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('verifytitle', 'local_torussecure'));
echo html_writer::div(get_string($ispost ? 'verifyfailed' : 'verifywaiting', 'local_torussecure'), '', [
    'id' => 'torus-seb-status', 'role' => 'status',
]);
if (!$ispost) {
    echo html_writer::start_tag('form', [
        'id' => 'torus-seb-verify', 'method' => 'post',
        'action' => \local_torussecure\local\seb_request::current_url(),
        'data-failure' => get_string('verifyfailed', 'local_torussecure'),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'configkeyhash', 'value' => '']);
    echo html_writer::end_tag('form');
    echo html_writer::tag('noscript', get_string('verifyfailed', 'local_torussecure'));
}
echo html_writer::div(html_writer::link($PAGE->url, get_string('verifyretry', 'local_torussecure')));
echo html_writer::div(\local_torussecure\local\config_links::render($cmid));
echo html_writer::div(html_writer::link(
    new moodle_url('/login/logout.php', ['sesskey' => sesskey(), 'loginpage' => 1]),
    get_string('logout')
));
echo $OUTPUT->footer();
