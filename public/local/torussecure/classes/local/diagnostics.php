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
 * Safe, read-only activity diagnostics.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure\local;

/**
 * Report status codes only; never return configuration, URLs or request evidence.
 */
class diagnostics {
    /**
     * Collect diagnostics for an authorized configurer using only the current request's evidence.
     */
    public function get_status(int $cmid): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        if (!$DB->record_exists('modules', ['id' => $cm->module, 'name' => 'lti'])) {
            throw new \moodle_exception('invalidactivity', 'local_torussecure');
        }
        $context = \context_module::instance($cmid);
        require_capability('local/torussecure:configure', $context);
        $instance = $DB->get_record('lti', ['id' => $cm->instance, 'course' => $cm->course]);
        // Moodle's lti_get_instance_type() declares ?object but can return false for a deleted type.
        $type = null;
        if ($instance) {
            $type = !empty($instance->typeid) ? lti_get_type($instance->typeid) :
                (lti_get_tool_by_url_match($instance->toolurl, $instance->course) ?:
                lti_get_tool_by_url_match($instance->securetoolurl, $instance->course));
        }
        $config = $type ? lti_get_type_config($type->id) : [];
        $selectedtype = activity_provisioner::type_for_course((int) $cm->course);
        $istorus = $type && $selectedtype && (int) $type->id === (int) $selectedtype->id;
        $record = (new config_repository())->get_by_cmid($cmid);
        $mapped = $record && $instance && (int) $record->ltiinstanceid === (int) $instance->id;
        $tree = json_decode($cm->availability ?? '');
        // The provisioner places a mandatory condition directly in the outer AND group.
        $attached = false;
        if (is_object($tree) && ($tree->op ?? '') === '&' && is_array($tree->c ?? null)) {
            foreach ($tree->c as $child) {
                if (is_object($child) && ($child->type ?? '') === 'torusseb') {
                    $attached = true;
                }
            }
        }
        $validation = (new seb_request_validator())->validate(
            $mapped ? $record : null,
            seb_request::current_url(),
            seb_request::headers(),
            $USER,
            $context
        );
        $evidence = match ($validation) {
            'valid' => 'valid',
            'bypassed' => 'bypassed',
            'missing_header' => 'none',
            default => seb_request::headers() ? 'invalid' : 'none',
        };
        return [
            'module' => true,
            'instance' => (bool) $instance,
            'tool' => (bool) $istorus,
            'lti13' => $istorus && $type->ltiversion === LTI_VERSION_1P3,
            'origin' => $instance && $this->origin_matches($instance, $config),
            'container' => $instance && (int) lti_get_launch_container(clone $instance, $config)
                === LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW,
            'grade' => $instance && $DB->record_exists('grade_items', [
                'courseid' => $cm->course, 'itemtype' => 'mod', 'itemmodule' => 'lti',
                'iteminstance' => $instance->id, 'itemnumber' => 0,
            ]),
            'availability' => array_key_exists('torusseb', \core\plugininfo\availability::get_enabled_plugins()),
            'restriction' => !empty($CFG->enableavailability) && $attached,
            'configuration' => $mapped && trim($record->configxml ?? '') !== '',
            'enabled' => $mapped && !empty($record->enabled),
            'key' => $mapped && preg_match('/\A[0-9a-f]{64}\z/', $record->configkey ?? '') === 1,
            'evidence' => $evidence,
        ];
    }

    /**
     * Compare the effective launch endpoint using Moodle's launch URL selection rules.
     */
    private function origin_matches(\stdClass $instance, array $config): bool {
        $endpoint = trim(!empty($instance->toolurl) ? $instance->toolurl : ($config['toolurl'] ?? ''));
        if ((lti_request_is_using_ssl() || ($config['forcessl'] ?? '') === '1') && !empty($instance->securetoolurl)) {
            $endpoint = trim($instance->securetoolurl);
        }
        if (($config['forcessl'] ?? '') === '1' && $endpoint !== '') {
            $endpoint = lti_ensure_url_is_https($endpoint);
        }
        $allowed = get_config('local_torussecure', 'torusorigin');
        if (!is_string($allowed) || !seb_request::is_public_url($allowed) || !seb_request::is_public_url($endpoint)) {
            return false;
        }
        $origin = parse_url($allowed);
        $target = parse_url($endpoint);
        return in_array($origin['path'] ?? '', ['', '/'], true) && !isset($origin['query']) &&
            strtolower($origin['host']) === strtolower($target['host']) &&
            ($origin['port'] ?? 443) === ($target['port'] ?? 443);
    }
}
