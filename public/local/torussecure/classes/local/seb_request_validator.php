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
 * Validate activity-bound SEB Config Key request evidence.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure\local;

/**
 * Stateless request validation, independent of Moodle's quiz access manager.
 */
class seb_request_validator {
    /**
     * Return a reason code without logging or returning sensitive evidence.
     *
     * Callers must enforce login, enrolment and activity access separately. Omitted
     * request inputs fail closed, retaining compatibility with the Step 7 scaffold.
     *
     * @param \stdClass|null $record Activity configuration.
     * @param string $url Exact externally visible URL, including the raw query string.
     * @param array $headers HTTP headers, or PHP server-style header names.
     * @param \stdClass|null $user Authenticated user being checked.
     * @param \context_module|null $context Activity context, matching the record's cmid.
     * @return string valid, bypassed, missing_header, invalid_header, activity_not_configured or configuration_disabled.
     */
    public function validate(
        ?\stdClass $record,
        string $url = '',
        array $headers = [],
        ?\stdClass $user = null,
        ?\context_module $context = null
    ): string {
        if ($record === null) {
            return 'activity_not_configured';
        }
        if (empty($record->enabled)) {
            return 'configuration_disabled';
        }
        if (
            $context === null || (int) ($record->cmid ?? 0) !== (int) $context->instanceid ||
            empty($user->id) || isguestuser($user) || !empty($user->deleted) || !empty($user->suspended) ||
            !is_string($record->configkey ?? null) || preg_match('/\A[0-9a-f]{64}\z/', $record->configkey) !== 1
        ) {
            return 'invalid_header';
        }
        if (
            get_config('local_torussecure', 'enablebypass') === '1' &&
            has_capability('local/torussecure:bypassseb', $context, $user)
        ) {
            return 'bypassed';
        }
        $values = [];
        foreach ($headers as $name => $value) {
            $name = strtolower(str_replace('_', '-', (string) $name));
            if (in_array($name, ['x-safeexambrowser-configkeyhash', 'http-x-safeexambrowser-configkeyhash'], true)) {
                $values[] = $value;
            }
        }
        if (!$values) {
            return 'missing_header';
        }
        if (
            count($values) !== 1 || !is_string($values[0]) ||
            preg_match('/\A[0-9a-f]{64}\z/', $values[0]) !== 1 ||
            !seb_request::is_public_url($url)
        ) {
            return 'invalid_header';
        }
        $expected = hash('sha256', $url . $record->configkey);
        return hash_equals($expected, $values[0]) ? 'valid' : 'invalid_header';
    }
}
