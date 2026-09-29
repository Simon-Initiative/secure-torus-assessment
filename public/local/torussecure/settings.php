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
 * Site settings for the technical spike.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_torussecure', get_string('pluginname', 'local_torussecure'));
    $ADMIN->add('localplugins', $settings);
    $settings->add(new admin_setting_configtext(
        'local_torussecure/tooltypeid',
        get_string('tooltypeid', 'local_torussecure'),
        get_string('tooltypeid_desc', 'local_torussecure'),
        0,
        PARAM_INT
    ));
    $settings->add(new \local_torussecure\local\https_origin_setting(
        'local_torussecure/torusorigin',
        get_string('torusorigin', 'local_torussecure'),
        get_string('torusorigin_desc', 'local_torussecure'),
        'https://torus.test',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_torussecure/enablebypass',
        get_string('enablebypass', 'local_torussecure'),
        get_string('enablebypass_desc', 'local_torussecure'),
        0
    ));
    $settings->add(new \local_torussecure\local\https_origin_setting(
        'local_torussecure/moodleorigin',
        get_string('moodleorigin', 'local_torussecure'),
        get_string('moodleorigin_desc', 'local_torussecure'),
        '',
        PARAM_RAW_TRIMMED
    ));
}
