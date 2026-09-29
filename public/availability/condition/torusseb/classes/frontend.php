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
 * Availability editor integration.
 *
 * @package    availability_torusseb
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_torusseb;

/**
 * Provisioning will attach this condition; it is not manually selectable.
 */
class frontend extends \core_availability\frontend {
    /**
     * Keep the managed condition out of the manual restriction picker.
     *
     * @return bool Never offer this condition in the restriction picker.
     */
    protected function allow_add($course, ?\cm_info $cm = null, ?\section_info $section = null) {
        return false;
    }
}
