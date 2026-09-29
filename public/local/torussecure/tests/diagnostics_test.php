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
 * Safe diagnostics and authorization tests.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure;

use local_torussecure\local\activity_provisioner;
use local_torussecure\local\config_repository;
use local_torussecure\local\diagnostics;

/**
 * Exercise real activity status without exposing stored configuration or evidence.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\diagnostics::class)]
final class diagnostics_test extends \advanced_testcase {
    /** @var \stdClass Provisioned module. */
    private \stdClass $module;
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var int Registered tool type. */
    private int $typeid;

    /**
     * Provision an activity using the production builder and repository.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $this->setAdminUser();
        set_config('enableavailability', 1);
        set_config('torusorigin', 'https://torus.test', 'local_torussecure');
        set_config('moodleorigin', 'https://moodle.test:8443', 'local_torussecure');
        $this->course = $this->getDataGenerator()->create_course();
        $this->typeid = $this->getDataGenerator()->get_plugin_generator('mod_lti')->create_tool_types([
            'name' => 'Torus', 'baseurl' => 'https://torus.test/lti/launch',
            'ltiversion' => LTI_VERSION_1P3, 'state' => LTI_TOOL_STATE_CONFIGURED,
            'ltiservice_gradesynchronization' => 2,
        ]);
        set_config('tooltypeid', $this->typeid, 'local_torussecure');
        $this->module = (new activity_provisioner())->create((object) [
            'courseid' => $this->course->id, 'section' => 0, 'name' => 'Secure assessment',
            'targeturl' => 'https://torus.test/lti/launch', 'grade' => 100,
        ]);
    }

    /**
     * A course editor sees only boolean checks and a fixed evidence classification.
     */
    public function test_provisioned_activity_and_safe_result(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        $status = (new diagnostics())->get_status($this->module->coursemodule);
        $this->assertSame('none', $status['evidence']);
        unset($status['evidence']);
        foreach ($status as $check => $value) {
            $this->assertTrue($value, $check);
        }
    }

    /**
     * Enrollment alone does not authorize diagnostics.
     */
    public function test_learner_cannot_read_diagnostics(): void {
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $this->course->id, 'student');
        $this->setUser($learner);
        $this->expectException(\required_capability_exception::class);
        (new diagnostics())->get_status($this->module->coursemodule);
    }

    /**
     * Each URL is checked independently, with no evidence included in the report.
     */
    public function test_current_request_evidence_is_not_cached_or_exposed(): void {
        global $FULLME;
        $oldurl = $FULLME;
        $oldserver = $_SERVER;
        try {
            $FULLME = 'https://moodle.test:8443/local/torussecure/diagnostics.php?cmid=' . $this->module->coursemodule;
            $record = (new config_repository())->get_by_cmid($this->module->coursemodule);
            $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] = hash('sha256', $FULLME . $record->configkey);
            $service = new diagnostics();
            $status = $service->get_status($this->module->coursemodule);
            $this->assertSame('valid', $status['evidence']);
            $encoded = json_encode($status);
            $secrets = [$record->configkey, $record->configxml, $FULLME, $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']];
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }
            $FULLME .= '&different=1';
            $this->assertSame('invalid', $service->get_status($this->module->coursemodule)['evidence']);
            unset($_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']);
            $this->assertSame('none', $service->get_status($this->module->coursemodule)['evidence']);
            set_config('enablebypass', 1, 'local_torussecure');
            $this->assertSame('bypassed', $service->get_status($this->module->coursemodule)['evidence']);
            $teacher = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
            $this->setUser($teacher);
            $this->assertSame('none', $service->get_status($this->module->coursemodule)['evidence']);
        } finally {
            $FULLME = $oldurl;
            $_SERVER = $oldserver;
        }
    }

    /**
     * Detect settings and records changed after provisioning.
     */
    public function test_configuration_drift_is_reported(): void {
        global $DB;
        $DB->set_field('lti_types', 'ltiversion', LTI_VERSION_1, ['id' => $this->typeid]);
        $DB->set_field('lti', 'toolurl', 'https://torus.test:444/launch', ['id' => $this->module->instance]);
        $DB->set_field('lti', 'securetoolurl', 'https://torus.test:444/launch', ['id' => $this->module->instance]);
        $DB->set_field('lti', 'launchcontainer', LTI_LAUNCH_CONTAINER_EMBED, ['id' => $this->module->instance]);
        $DB->delete_records('grade_items', ['itemmodule' => 'lti', 'iteminstance' => $this->module->instance]);
        $DB->set_field('course_modules', 'availability', null, ['id' => $this->module->coursemodule]);
        $DB->set_field('local_torussecure', 'configkey', 'invalid-private-marker', ['cmid' => $this->module->coursemodule]);
        $DB->set_field('local_torussecure', 'enabled', 0, ['cmid' => $this->module->coursemodule]);
        set_config('disabled', 1, 'availability_torusseb');
        $status = (new diagnostics())->get_status($this->module->coursemodule);
        foreach (['lti13', 'origin', 'container', 'grade', 'restriction', 'key', 'enabled', 'availability'] as $check) {
            $this->assertFalse($status[$check], $check);
        }
        $this->assertStringNotContainsString('invalid-private-marker', json_encode($status));
    }

    /**
     * Missing registration or mapping must not crash the checklist.
     */
    public function test_missing_configuration_and_type_are_reported(): void {
        global $DB;
        $DB->delete_records('local_torussecure', ['cmid' => $this->module->coursemodule]);
        $DB->set_field('lti', 'typeid', 999999, ['id' => $this->module->instance]);
        $status = (new diagnostics())->get_status($this->module->coursemodule);
        foreach (['tool', 'lti13', 'configuration', 'enabled', 'key'] as $check) {
            $this->assertFalse($status[$check], $check);
        }
    }

    /**
     * An orphaned course module can still be diagnosed by an authorized editor.
     */
    public function test_missing_instance_is_reported(): void {
        global $DB;
        $DB->delete_records('lti', ['id' => $this->module->instance]);
        $status = (new diagnostics())->get_status($this->module->coursemodule);
        $this->assertTrue($status['module']);
        foreach (['instance', 'tool', 'lti13', 'origin', 'container', 'grade', 'configuration', 'enabled', 'key'] as $check) {
            $this->assertFalse($status[$check], $check);
        }
    }
}
