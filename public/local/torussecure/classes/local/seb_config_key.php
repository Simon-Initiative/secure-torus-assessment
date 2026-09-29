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
 * Adapter for Moodle's bundled SEB Config Key implementation.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure\local;

/**
 * Spike-only dependency on quizaccess_seb; keep quiz implementation details inside this adapter.
 */
class seb_config_key {
    /**
     * Calculate the SEB Config Key, which differs from a hash of the XML bytes.
     *
     * @param string $xml Non-empty plist XML generated for the activity.
     * @return string Lowercase hexadecimal Config Key.
     */
    public function generate(string $xml): string {
        if (trim($xml) === '') {
            throw new \moodle_exception('emptyconfiguration', 'local_torussecure');
        }
        return \quizaccess_seb\config_key::generate($xml)->get_hash();
    }
}
