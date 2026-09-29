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
 * Links to the same authenticated configuration endpoint using HTTPS and SEB's scheme.
 */
class config_links {
    /**
     * Render download and native-client launch links without embedding any configuration secrets.
     */
    public static function render(int $cmid): string {
        global $CFG;
        $origin = get_config('local_torussecure', 'moodleorigin') ?: $CFG->wwwroot;
        $url = new \moodle_url(rtrim($origin, '/') . '/local/torussecure/config.php', ['cmid' => $cmid]);
        if ($url->get_scheme() !== 'https') {
            throw new \moodle_exception('invalidorigin', 'local_torussecure');
        }
        $seburl = clone $url;
        $seburl->set_scheme('sebs');
        $verifyurl = new \moodle_url(rtrim($origin, '/') . '/local/torussecure/launch.php', ['cmid' => $cmid]);
        return \html_writer::link($verifyurl, get_string('verifytitle', 'local_torussecure')) . ' ' .
            \html_writer::link($url, get_string('downloadconfig', 'local_torussecure')) . ' ' .
            \html_writer::link($seburl, get_string('launchseb', 'local_torussecure'));
    }
}
