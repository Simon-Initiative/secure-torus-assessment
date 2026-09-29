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
 * Service scaffold for Step 8.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace local_torussecure;

use local_torussecure\local\activity_provisioner;
use local_torussecure\local\availability_installer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Provision actual LTI modules and verify rollback through Moodle's deletion API.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\activity_provisioner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\form\create_activity_form::class)]
final class activity_provisioner_test extends \advanced_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;
    /** @var \stdClass Provisioning inputs. */
    private \stdClass $data;
    /** @var int Registered Torus tool type. */
    private int $typeid;

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
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->typeid = $this->getDataGenerator()->get_plugin_generator('mod_lti')->create_tool_types([
            'name' => 'Torus', 'baseurl' => 'https://torus.test/lti/launch',
            'ltiversion' => LTI_VERSION_1P3, 'state' => LTI_TOOL_STATE_CONFIGURED,
            'ltiservice_gradesynchronization' => 2,
        ]);
        set_config('tooltypeid', $this->typeid, 'local_torussecure');
        $this->data = (object) [
            'courseid' => $this->course->id, 'section' => 1, 'name' => 'Secure assessment',
            'targeturl' => 'https://torus.test/lti/launch?resource=assessment', 'grade' => 75,
            'instructorcustomparameters' => "torus_resource_type=page\ntorus_resource_id=new_assessment",
        ];
    }

    public function test_successful_provisioning(): void {
        global $DB;
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        $result = (new activity_provisioner())->create($this->data);
        $cm = $DB->get_record('course_modules', ['id' => $result->coursemodule], '*', MUST_EXIST);
        $lti = $DB->get_record('lti', ['id' => $result->instance], '*', MUST_EXIST);
        $record = (new \local_torussecure\local\config_repository())->get_by_cmid($cm->id);
        $this->assertEquals(1, $cm->visible);
        $this->assertEquals($this->typeid, $lti->typeid);
        $this->assertSame($this->data->targeturl, $lti->toolurl);
        $this->assertSame($this->data->instructorcustomparameters, $lti->instructorcustomparameters);
        $this->assertEquals(LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW, $lti->launchcontainer);
        $this->assertEquals(75, $lti->grade);
        $this->assertSame('torusseb', json_decode($cm->availability)->c[0]->type);
        $this->assertEquals($cm->id, $record->cmid);
        $this->assertSame((new \local_torussecure\local\seb_config_key())->generate($record->configxml), $record->configkey);
        $this->assertStringContainsString('/local/torussecure/launch.php?cmid=' . $cm->id, $record->configxml);
        $item = $DB->get_record('grade_items', ['itemmodule' => 'lti', 'iteminstance' => $lti->id], '*', MUST_EXIST);
        $this->assertEquals(75, $item->grademax);
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $this->course->id);
        $this->setUser($learner);
        $this->assertFalse(get_fast_modinfo($this->course)->get_cm($cm->id)->uservisible);
        $this->assertSame($record->configxml, (new \local_torussecure\local\config_download())->get_file($cm->id)['body']);
    }

    /**
     * Creation uses the submitted SEB controls for the stored downloadable configuration.
     */
    public function test_creation_stores_instructor_seb_settings(): void {
        global $DB;
        $this->data->seb_allowquit = 0;
        $this->data->seb_allowreload = 0;
        $this->data->seb_camera = 1;
        $this->data->seb_maxdisplays = 2;
        $this->data->seb_allowedurls = 'https://media.example.org';
        $result = (new activity_provisioner())->create($this->data);
        $record = $DB->get_record('local_torussecure', ['cmid' => $result->coursemodule], '*', MUST_EXIST);
        $plist = new \quizaccess_seb\property_list($record->configxml);
        $this->assertFalse($plist->get_element_value('allowQuit'));
        $this->assertFalse($plist->get_element_value('browserWindowAllowReload'));
        $this->assertTrue($plist->get_element_value('browserMediaCaptureCamera'));
        $this->assertSame(2, $plist->get_element_value('allowedDisplaysMaxNumber'));
        $this->assertSame((new \local_torussecure\local\seb_config_key())->generate($record->configxml), $record->configkey);
    }

    /**
     * Invalid SEB choices are rejected before writing an LTI activity.
     */
    public function test_invalid_seb_settings_are_rejected(): void {
        global $DB;
        $this->data->seb_allowquit = 'maybe';
        $this->data->seb_maxdisplays = 4;
        $this->data->seb_allowedurls = 'https://moodle.test:8443';
        $errors = activity_provisioner::validate_input($this->data);
        foreach (['seb_allowquit', 'seb_maxdisplays', 'seb_allowedurls'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
        try {
            (new activity_provisioner())->create($this->data);
            $this->fail('Expected invalid SEB settings to be rejected');
        } catch (\moodle_exception $exception) {
            $this->assertSame('invalidcreationdata', $exception->errorcode);
        }
        $this->assertFalse($DB->record_exists('lti', ['course' => $this->course->id]));
    }

    /**
     * A course registration may differ from the registration configured for another course.
     */
    public function test_uses_matching_course_registration_without_changing_existing_course(): void {
        global $DB;
        $othercourse = $this->getDataGenerator()->create_course();
        $DB->set_field('lti_types', 'course', $othercourse->id, ['id' => $this->typeid]);
        $coursetypeid = $this->getDataGenerator()->get_plugin_generator('mod_lti')->create_course_tool_types([
            'course' => $this->course->id, 'name' => 'Torus',
            'baseurl' => 'https://torus.test/lti/launch',
            'ltiversion' => LTI_VERSION_1P3,
            'ltiservice_gradesynchronization' => 2,
        ]);

        $result = (new activity_provisioner())->create($this->data);
        $this->assertEquals($coursetypeid, $DB->get_field('lti', 'typeid', ['id' => $result->instance]));
        $this->assertTrue((new \local_torussecure\local\diagnostics())->get_status($result->coursemodule)['tool']);
        $this->assertEquals($this->typeid, get_config('local_torussecure', 'tooltypeid'));
    }

    /**
     * Never guess between multiple course registrations on the same origin.
     */
    public function test_rejects_ambiguous_course_registrations(): void {
        global $DB;
        $othercourse = $this->getDataGenerator()->create_course();
        $DB->set_field('lti_types', 'course', $othercourse->id, ['id' => $this->typeid]);
        for ($i = 0; $i < 2; $i++) {
            $this->getDataGenerator()->get_plugin_generator('mod_lti')->create_course_tool_types([
                'course' => $this->course->id, 'name' => 'Torus ' . $i,
                'baseurl' => 'https://torus.test/lti/launch',
                'ltiversion' => LTI_VERSION_1P3,
                'ltiservice_gradesynchronization' => 2,
            ]);
        }
        try {
            (new activity_provisioner())->create($this->data);
            $this->fail('Expected ambiguous registrations to be rejected');
        } catch (\moodle_exception $exception) {
            $this->assertSame('invalidtoolregistration', $exception->errorcode);
        }
        $this->assertFalse($DB->record_exists('lti', ['course' => $this->course->id]));
    }

    #[DataProvider('invalid_provider')]
    public function test_rejected_before_creation(string $case): void {
        global $DB;
        switch ($case) {
            case 'section':
                $this->data->section = 99;
                break;
            case 'name':
                $this->data->name = '';
                break;
            case 'grade':
                $this->data->grade = -1;
                break;
            case 'target':
                $this->data->targeturl = 'https://torus.test.evil.example/launch';
                break;
            case 'tool':
                set_config('tooltypeid', 0, 'local_torussecure');
                break;
            case 'version':
                $DB->set_field('lti_types', 'ltiversion', 'LTI-1p0', ['id' => $this->typeid]);
                break;
            case 'origin':
                $DB->set_field('lti_types', 'baseurl', 'https://other.test/launch', ['id' => $this->typeid]);
                break;
            case 'ags':
                $DB->set_field(
                    'lti_types_config',
                    'value',
                    '1',
                    ['typeid' => $this->typeid, 'name' => 'ltiservice_gradesynchronization']
                );
                break;
            case 'availability':
                set_config('enableavailability', 0);
                break;
            case 'permission':
                $this->setUser($this->getDataGenerator()->create_user());
                break;
        }
        try {
            (new activity_provisioner())->create($this->data);
            $this->fail('Expected rejection');
        } catch (\moodle_exception $exception) {
            $this->assertFalse($DB->record_exists('lti', ['course' => $this->course->id]));
            $this->assertFalse($DB->record_exists('local_torussecure', []));
        }
    }

    /**
     * Supply invalid provisioning inputs.
     */
    public static function invalid_provider(): array {
        return array_map(static fn($case) => [$case], [
            'section', 'name', 'grade', 'target', 'tool', 'version', 'origin', 'ags', 'availability', 'permission',
        ]);
    }

    public function test_failure_after_persistence_removes_activity_and_grade(): void {
        global $DB;
        $installer = $this->getMockBuilder(availability_installer::class)->onlyMethods(['attach'])->getMock();
        $installer->method('attach')->willReturnCallback(function (int $cmid) use ($DB): void {
            $this->assertTrue($DB->record_exists('local_torussecure', ['cmid' => $cmid]));
            $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $cmid]));
            throw new \moodle_exception('availabilitydisabled', 'local_torussecure');
        });
        try {
            (new activity_provisioner(null, $installer))->create($this->data);
            $this->fail('Expected provisioning failure');
        } catch (\moodle_exception $exception) {
            $this->assertSame('availabilitydisabled', $exception->errorcode);
        }
        $this->assertFalse($DB->record_exists('lti', ['course' => $this->course->id]));
        $this->assertFalse($DB->record_exists('course_modules', ['course' => $this->course->id]));
        $this->assertFalse($DB->record_exists('grade_items', ['courseid' => $this->course->id, 'itemmodule' => 'lti']));
        $this->assertFalse($DB->record_exists('local_torussecure', []));
    }

    public function test_form_validation(): void {
        $form = new \local_torussecure\form\create_activity_form(null, ['course' => $this->course]);
        $this->assertSame([], $form->validation((array) $this->data, []));
        $bad = (array) $this->data;
        $bad['targeturl'] = 'http://torus.test/launch';
        $bad['grade'] = 'not-a-number';
        $errors = $form->validation($bad, []);
        $this->assertArrayHasKey('targeturl', $errors);
        $this->assertArrayHasKey('grade', $errors);
    }

    public function test_form_accepts_valid_submission(): void {
        $oldpost = $_POST;
        try {
            \local_torussecure\form\create_activity_form::mock_submit((array) $this->data);
            $form = new \local_torussecure\form\create_activity_form(null, ['course' => $this->course]);
            $submitted = $form->get_data();
            $this->assertNotFalse($submitted);
            $this->assertSame($this->data->targeturl, $submitted->targeturl);
            $this->assertSame($this->data->instructorcustomparameters, $submitted->instructorcustomparameters);
        } finally {
            $_POST = $oldpost;
        }
    }

    public function test_form_rejects_invalid_session_key(): void {
        $oldpost = $_POST;
        try {
            \local_torussecure\form\create_activity_form::mock_submit((array) $this->data);
            $_POST['sesskey'] = 'invalid';
            $this->expectException(\moodle_exception::class);
            new \local_torussecure\form\create_activity_form(null, ['course' => $this->course]);
        } finally {
            $_POST = $oldpost;
        }
    }
    /**
     * Reject malformed lines and normalized aliases of reserved SEB fields before provisioning.
     */
    public function test_custom_parameter_validation(): void {
        global $DB;
        foreach (
            [
            'missing-equals', '=missing-key', 'secure_delivery=forged', 'Secure-Delivery=forged',
            'secure_activity_id=forged', 'seb_configuration_id=forged', 'secure_verified_at=forged',
            ] as $custom
        ) {
            $this->data->instructorcustomparameters = $custom;
            $form = new \local_torussecure\form\create_activity_form(null, ['course' => $this->course]);
            $this->assertArrayHasKey('instructorcustomparameters', $form->validation((array) $this->data, []));
            try {
                (new activity_provisioner())->create($this->data);
                $this->fail('Invalid custom parameters were accepted');
            } catch (\moodle_exception $exception) {
                $this->assertSame('invalidcreationdata', $exception->errorcode);
                $this->assertFalse($DB->record_exists('lti', ['course' => $this->course->id]));
            }
        }
        foreach (['', "torus_resource_type=page\r\ntorus_resource_id=new_assessment", 'example=a=b'] as $custom) {
            $this->data->instructorcustomparameters = $custom;
            $this->assertSame([], activity_provisioner::validate_input($this->data));
        }
    }
}
