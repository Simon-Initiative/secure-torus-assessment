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
 * Secure External Tool activity provisioning.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace local_torussecure\local;

/**
 * Provision an ordinary graded LTI activity with an activity-specific SEB restriction.
 */
class activity_provisioner {
    /**
     * Initialize the service or condition.
     */
    public function __construct(
        /** @var ?seb_config_builder SEB configuration builder. */
        private ?seb_config_builder $builder = null,
        /** @var ?availability_installer Secure availability installer. */
        private ?availability_installer $installer = null
    ) {
        $this->builder ??= new seb_config_builder();
        $this->installer ??= new availability_installer();
    }

    /**
     * Validate form fields without performing writes. Section is a course section number.
     */
    public static function validate_input(\stdClass $data): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $errors = [];
        if (trim($data->name ?? '') === '' || \core_text::strlen($data->name ?? '') > 255) {
            $errors['name'] = get_string('invalidactivityname', 'local_torussecure');
        }
        if (
            !isset($data->section) || filter_var($data->section, FILTER_VALIDATE_INT) === false ||
                !$DB->record_exists('course_sections', ['course' => (int) ($data->courseid ?? 0), 'section' => $data->section])
        ) {
            $errors['section'] = get_string('invalidsection', 'local_torussecure');
        }
        $grade = $data->grade ?? null;
        if (!is_numeric($grade) || !is_finite((float) $grade) || $grade <= 0 || $grade > ($CFG->gradepointmax ?? 100)) {
            $errors['grade'] = get_string('invalidmaximumgrade', 'local_torussecure', $CFG->gradepointmax ?? 100);
        }
        $allowed = get_config('local_torussecure', 'torusorigin');
        if (
            self::origin($data->targeturl ?? '') === null ||
            self::origin($data->targeturl ?? '') !== self::origin($allowed ?: '')
        ) {
            $errors['targeturl'] = get_string('invalidtargeturl', 'local_torussecure');
        }
        $moodleorigin = get_config('local_torussecure', 'moodleorigin') ?: $CFG->wwwroot;
        $errors = array_merge($errors, seb_settings::validate($data, $moodleorigin, $allowed ?: ''));
        $custom = $data->instructorcustomparameters ?? '';
        if (!is_string($custom)) {
            $errors['instructorcustomparameters'] = get_string('invalidcustomparameters', 'local_torussecure');
        } else {
            foreach (preg_split('/\r\n|\r|\n/', $custom) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) !== 2 || trim($parts[0]) === '') {
                    $errors['instructorcustomparameters'] = get_string('invalidcustomparameters', 'local_torussecure');
                    break;
                }
                if (
                    in_array(lti_map_keyname($parts[0]), [
                    'secure_delivery', 'secure_activity_id', 'seb_configuration_id', 'secure_verified_at',
                    ], true)
                ) {
                    $errors['instructorcustomparameters'] = get_string('reservedcustomparameters', 'local_torussecure');
                    break;
                }
            }
        }
        return $errors;
    }

    /**
     * HTTPS origin comparison with effective ports; reject credentials, fragments and wildcard hosts.
     */
    private static function origin(string $url): ?string {
        if (!seb_request::is_public_url($url)) {
            return null;
        }
        $parts = parse_url($url);
        return strtolower($parts['host']) . ':' . ($parts['port'] ?? 443);
    }

    /**
     * Select the configured tool when available to this course, or its sole matching course tool.
     */
    public static function type_for_course(int $courseid): ?\stdClass {
        global $DB;
        $configured = $DB->get_record('lti_types', ['id' => (int) get_config('local_torussecure', 'tooltypeid')]);
        if ($configured && ((int) $configured->course === (int) SITEID || (int) $configured->course === $courseid)) {
            return $configured;
        }

        $allowedorigin = self::origin((string) get_config('local_torussecure', 'torusorigin'));
        if ($allowedorigin === null) {
            return null;
        }
        $matches = [];
        foreach ($DB->get_records('lti_types', ['course' => $courseid]) as $type) {
            if (
                $type->ltiversion !== LTI_VERSION_1P3 || empty($type->clientid) ||
                (int) $type->state !== LTI_TOOL_STATE_CONFIGURED || self::origin($type->baseurl) !== $allowedorigin
            ) {
                continue;
            }
            $config = lti_get_type_config($type->id);
            if (!empty($config['securetoolurl']) && self::origin($config['securetoolurl']) !== $allowedorigin) {
                continue;
            }
            $matches[] = $type;
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Create a fully configured activity; HTTP callers must additionally enforce login and sesskey.
     */
    public function create(\stdClass $data): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $course = get_course((int) ($data->courseid ?? 0));
        $context = \context_course::instance($course->id);
        foreach (['moodle/course:manageactivities', 'mod/lti:addinstance', 'local/torussecure:configure'] as $capability) {
            require_capability($capability, $context);
        }
        if ($errors = self::validate_input($data)) {
            throw new \moodle_exception('invalidcreationdata', 'local_torussecure', '', implode(' ', $errors));
        }
        if (empty($CFG->enableavailability)) {
            throw new \moodle_exception('availabilitydisabled', 'local_torussecure');
        }
        $type = self::type_for_course($course->id);
        if (
            !$type || $type->ltiversion !== LTI_VERSION_1P3 || empty($type->clientid) ||
                (int) $type->state !== LTI_TOOL_STATE_CONFIGURED ||
                (!empty($type->course) && (int) $type->course !== (int) SITEID && (int) $type->course !== (int) $course->id)
        ) {
            throw new \moodle_exception('invalidtoolregistration', 'local_torussecure');
        }
        $config = lti_get_type_config($type->id);
        $torusorigin = get_config('local_torussecure', 'torusorigin');
        if (
            self::origin($type->baseurl) !== self::origin($torusorigin) ||
                (!empty($config['securetoolurl']) && self::origin($config['securetoolurl']) !== self::origin($torusorigin))
        ) {
            throw new \moodle_exception('invalidtoolregistration', 'local_torussecure');
        }
        $scopes = lti_get_permitted_service_scopes($type, $config);
        if (
            !in_array('https://purl.imsglobal.org/spec/lti-ags/scope/score', $scopes, true) ||
                !in_array('https://purl.imsglobal.org/spec/lti-ags/scope/lineitem', $scopes, true) ||
                (isset($config['acceptgrades']) && (int) $config['acceptgrades'] === LTI_SETTING_NEVER)
        ) {
            throw new \moodle_exception('invalidagsconfiguration', 'local_torussecure');
        }
        $moodleorigin = get_config('local_torussecure', 'moodleorigin') ?: $CFG->wwwroot;
        // Validate configured origins before creating an activity; the final cmid is used below.
        $seboptions = seb_settings::from_data($data);
        $this->builder->build(1, $moodleorigin, $torusorigin, $seboptions);
        $module = create_module((object) [
            'modulename' => 'lti', 'course' => $course->id, 'section' => (int) $data->section,
            'name' => trim($data->name), 'visible' => 0, 'visibleoncoursepage' => 1,
            'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
            'typeid' => $type->id, 'toolurl' => $data->targeturl, 'securetoolurl' => $data->targeturl,
            'instructorcustomparameters' => $data->instructorcustomparameters ?? '',
            'launchcontainer' => LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW,
            'instructorchoiceacceptgrades' => LTI_SETTING_ALWAYS, 'grade' => (float) $data->grade,
            'instructorchoicesendname' => LTI_SETTING_NEVER, 'instructorchoicesendemailaddr' => LTI_SETTING_NEVER,
        ]);
        try {
            $instance = $DB->get_record('lti', ['id' => $module->instance], '*', MUST_EXIST);
            if (
                $instance->toolurl !== $data->targeturl || (int) $instance->typeid !== (int) $type->id ||
                    (int) $instance->launchcontainer !== LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW ||
                    (float) $instance->grade !== (float) $data->grade
            ) {
                throw new \moodle_exception('invalidtoolregistration', 'local_torussecure');
            }
            $xml = $this->builder->build((int) $module->coursemodule, $moodleorigin, $torusorigin, $seboptions);
            $key = (new seb_config_key())->generate($xml);
            (new config_repository())->create_for_activity((int) $module->coursemodule, (int) $module->instance, $xml, $key);
            $this->installer->attach((int) $module->coursemodule);
            set_coursemodule_visible($module->coursemodule, 1);
            rebuild_course_cache($course->id, true);
            $module->visible = 1;
            return $module;
        } catch (\Throwable $exception) {
            // Keep any failed activity inaccessible, even if subsequent cleanup itself fails.
            set_coursemodule_visible($module->coursemodule, 0);
            \core_courseformat\formatactions::cm($course->id)->delete($module->coursemodule, false);
            $DB->delete_records('local_torussecure', ['cmid' => $module->coursemodule]);
            rebuild_course_cache($course->id, true);
            throw $exception;
        }
    }
}
