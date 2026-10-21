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

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(['help' => false, 'cleanup' => false], ['h' => 'help']);
if ($unrecognised) {
    cli_error('Unknown options: ' . implode(', ', $unrecognised));
}
if ($options['help']) {
    echo "Report Secure Torus mappings. Default: read-only dry run.\n\n";
    echo "--cleanup  Delete only records whose module AND LTI instance no longer exist.\n";
    echo "           Inconsistent records require investigation and are never deleted.\n";
    exit(0);
}
$counts = \local_torussecure\local\lifecycle_cleanup::report(
    (bool) $options['cleanup'],
    static function (stdClass $record, string $status): void {
        mtrace("id={$record->id} cmid={$record->cmid} ltiinstanceid={$record->ltiinstanceid} status={$status}");
    }
);
mtrace(($options['cleanup'] ? 'Cleanup' : 'Dry run') . ': ' . json_encode($counts));
