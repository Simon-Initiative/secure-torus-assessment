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
 * Validation for configured HTTPS origins.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure\local;

/**
 * Restrict origin settings to HTTPS scheme, host and optional port.
 */
class https_origin_setting extends \admin_setting_configtext {
    /**
     * Validate the supplied input.
     *
     * @param string $data Submitted setting.
     * @return bool|string Validation result.
     */
    public function validate($data) {
        if ($data === '' && $this->name === 'moodleorigin') {
            return true;
        }
        $parts = parse_url($data);
        if (
            $parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            !in_array($parts['path'] ?? '', ['', '/'], true) ||
            filter_var($data, FILTER_VALIDATE_URL) === false
        ) {
            return get_string('invalidorigin', 'local_torussecure');
        }
        return true;
    }
}
