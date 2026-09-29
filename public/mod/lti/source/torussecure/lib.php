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
 * Revalidate secure delivery immediately before LTI signing.
 *
 * @package    ltisource_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Leave ordinary launches unchanged; add secure claims only after request validation.
 *
 * @param stdClass $instance LTI instance (may have no ID for Deep Linking).
 * @param string $endpoint Tool endpoint.
 * @param array $requestparams Launch parameters before signing.
 * @return array Secure custom parameters, or an empty array for unmanaged/disabled instances.
 */
function ltisource_torussecure_before_launch(stdClass $instance, string $endpoint, array $requestparams): array {
    global $USER;
    if (empty($instance->id)) {
        return [];
    }
    $record = (new \local_torussecure\local\config_repository())->get_by_lti_instance_id((int) $instance->id);
    if ($record === null || empty($record->enabled)) {
        return [];
    }
    $allowed = get_config('local_torussecure', 'torusorigin');
    if (
        !is_string($allowed) || !\local_torussecure\local\seb_request::is_public_url($allowed) ||
            !\local_torussecure\local\seb_request::is_public_url($endpoint)
    ) {
        throw new moodle_exception('secureendpointinvalid', 'local_torussecure');
    }
    $origin = parse_url($allowed);
    $target = parse_url($endpoint);
    if (
        !in_array($origin['path'] ?? '', ['', '/'], true) || isset($origin['query']) ||
            strtolower($origin['host']) !== strtolower($target['host']) ||
            ($origin['port'] ?? 443) !== ($target['port'] ?? 443)
    ) {
        throw new moodle_exception('secureendpointinvalid', 'local_torussecure');
    }
    [$course, $cm] = get_course_and_cm_from_cmid((int) $record->cmid, 'lti');
    if (
        (int) $cm->instance !== (int) $instance->id ||
            (int) $course->id !== (int) ($instance->course ?? 0) ||
            !is_string($record->configversion) || preg_match('/\A[0-9a-f]{64}\z/', $record->configversion) !== 1
    ) {
        throw new moodle_exception('invalidactivitymapping', 'local_torussecure');
    }
    $context = context_module::instance($cm->id);
    require_capability('mod/lti:view', $context);
    $result = \local_torussecure\local\seb_request::validate($record, $USER, $context);
    if (!in_array($result, ['valid', 'valid_js', 'bypassed'], true)) {
        $setup = new moodle_url('/local/torussecure/config.php', ['cmid' => $cm->id]);
        throw new moodle_exception('securelaunchdenied', 'local_torussecure', $setup->out(false));
    }
    \local_torussecure\local\js_launch::consume((int) $cm->id);
    return [
        'custom_secure_delivery' => 'seb',
        'custom_secure_activity_id' => (string) $cm->id,
        'custom_seb_configuration_id' => $record->configversion,
        'custom_secure_verified_at' => (string) time(),
    ];
}

/**
 * Advertise secure assessment creation to authorized instructors in the activity chooser.
 *
 * @param \core_course\local\entity\content_item $defaultmodulecontentitem Parent LTI item with course link.
 * @param stdClass $user User viewing the chooser.
 * @return array Content items available in this course.
 */
function ltisource_torussecure_get_course_content_items(
    \core_course\local\entity\content_item $defaultmodulecontentitem,
    stdClass $user
): array {
    $courseid = (int) $defaultmodulecontentitem->get_link()->param('id');
    if (!$courseid) {
        return [];
    }
    $context = context_course::instance($courseid);
    foreach (['moodle/course:manageactivities', 'mod/lti:addinstance', 'local/torussecure:configure'] as $capability) {
        if (!has_capability($capability, $context, $user)) {
            return [];
        }
    }
    $items = ltisource_torussecure_get_all_content_items($defaultmodulecontentitem);
    $items[0]->get_link()->param('courseid', $courseid);
    return $items;
}

/**
 * Provide a stable item identity for activity chooser search, favourites and recommendations.
 *
 * @param \core_course\local\entity\content_item $defaultmodulecontentitem Parent LTI item.
 * @return array Secure assessment content item.
 */
function ltisource_torussecure_get_all_content_items(
    \core_course\local\entity\content_item $defaultmodulecontentitem
): array {
    return [new \core_course\local\entity\content_item(
        id: 1,
        name: 'torussecure',
        title: new \core_course\local\entity\lang_string_title('activityname', 'ltisource_torussecure'),
        link: new moodle_url('/local/torussecure/create.php'),
        icon: $defaultmodulecontentitem->get_icon(),
        help: get_string('activityhelp', 'ltisource_torussecure'),
        archetype: MOD_ARCHETYPE_OTHER,
        componentname: 'ltisource_torussecure',
        purpose: MOD_PURPOSE_ASSESSMENT,
        gradable: true,
        summary: get_string('activitysummary', 'ltisource_torussecure'),
    )];
}
