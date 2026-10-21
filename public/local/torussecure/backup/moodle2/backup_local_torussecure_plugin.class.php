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
 * Include only managed activity configuration in module.xml, independent of user-data settings.
 */
class backup_local_torussecure_plugin extends backup_local_plugin {
    /**
     * Add a versioned payload at the module connection point.
     */
    protected function define_module_plugin_structure(): void {
        global $DB;
        $cmid = (int) $this->task->get_moduleid();
        $record = $DB->get_record('local_torussecure', ['cmid' => $cmid]);
        if (!$record || $this->task->get_modulename() !== 'lti') {
            return;
        }
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);
        $configuration = new backup_nested_element('configuration', [], [
            'formatversion', 'managed', 'sourcecmid', 'sourceltiinstanceid', 'enabled', 'configxml',
        ]);
        $wrapper->add_child($configuration);
        $configuration->set_source_array([(object) [
            'formatversion' => 1, 'managed' => 1, 'sourcecmid' => $cmid,
            'sourceltiinstanceid' => $record->ltiinstanceid,
            'enabled' => $record->enabled, 'configxml' => $record->configxml,
        ]]);
    }
}
