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
 * Secure activity configuration download.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure;

use local_torussecure\local\config_download;
use local_torussecure\local\config_links;
use local_torussecure\local\config_repository;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exercise the access policy with Moodle's real availability, enrolment and capability APIs.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\config_download::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\config_links::class)]
final class config_download_test extends \advanced_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;
    /** @var \stdClass Test activity. */
    private \stdClass $lti;
    /** @var \stdClass Enrolled learner. */
    private \stdClass $student;
    /** @var string Stored bytes, intentionally unlike the builder's serialization. */
    private string $xml = "<?xml version=\"1.0\"?>\r\n<plist version=\"1.0\"><dict/></plist>\r\n";

    /**
     * Set up an activity visible on the course page but unavailable without SEB.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->lti = $this->getDataGenerator()->create_module('lti', [
            'course' => $this->course->id,
            'section' => 1,
            'availability' => json_encode([
                'op' => '&', 'c' => [['type' => 'torusseb']], 'showc' => [true],
            ]),
        ]);
        (new config_repository())->create_for_activity($this->lti->cmid, $this->lti->id, $this->xml, str_repeat('a', 64));
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id);
        $this->setUser($this->student);
    }

    /**
     * Preflight works despite SEB denial, returns exact bytes and prevents caching.
     */
    public function test_student_download(): void {
        $cm = get_fast_modinfo($this->course)->get_cm($this->lti->cmid);
        $this->assertFalse($cm->uservisible);
        $this->assertTrue($cm->is_visible_on_course_page());
        $response = (new config_download())->get_file($cm->id);
        $this->assertSame($this->xml, $response['body']);
        $this->assertSame('application/seb', $response['headers']['Content-Type']);
        $this->assertSame('attachment; filename="torus-' . $cm->id . '.seb"', $response['headers']['Content-Disposition']);
        $this->assertStringContainsString('private', $response['headers']['Cache-Control']);
        $this->assertStringContainsString('no-store', $response['headers']['Cache-Control']);
        $this->assertSame('no-cache', $response['headers']['Pragma']);
        $this->assertSame('0', $response['headers']['Expires']);
        // The download must not authorize the actual activity.
        $this->assertFalse($cm->uservisible);
    }

    /**
     * Reject users who lack an active enrolment, activity visibility or view capability.
     */
    #[DataProvider('denied_provider')]
    public function test_denied_access(string $scenario): void {
        global $DB;
        $context = \context_module::instance($this->lti->cmid);
        switch ($scenario) {
            case 'hidden activity':
                $DB->set_field('course_modules', 'visible', 0, ['id' => $this->lti->cmid]);
                break;
            case 'hidden restriction':
                $DB->set_field('course_modules', 'availability', json_encode([
                    'op' => '&', 'c' => [['type' => 'torusseb']], 'showc' => [false],
                ]), ['id' => $this->lti->cmid]);
                break;
            case 'hidden course':
                $DB->set_field('course', 'visible', 0, ['id' => $this->course->id]);
                break;
            case 'hidden section':
                $DB->set_field('course_sections', 'visible', 0, ['course' => $this->course->id, 'section' => 1]);
                break;
            case 'not enrolled':
                $this->setUser($this->getDataGenerator()->create_user());
                break;
            case 'suspended enrolment':
                $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $this->student->id]);
                break;
            case 'no view capability':
                $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
                assign_capability('mod/lti:view', CAP_PROHIBIT, $roleid, $context->id);
                break;
            case 'guest':
                $this->setGuestUser();
                break;
            case 'anonymous':
                $this->setUser(null);
                break;
        }
        rebuild_course_cache($this->course->id, true);
        $this->expectException(\moodle_exception::class);
        (new config_download())->get_file($this->lti->cmid);
    }

    /**
     * Access denial cases, including direct endpoint access.
     */
    public static function denied_provider(): array {
        return array_map(static fn($scenario) => [$scenario], [
            'hidden activity', 'hidden restriction', 'hidden course', 'hidden section', 'not enrolled',
            'suspended enrolment', 'no view capability', 'guest', 'anonymous',
        ]);
    }

    /**
     * Configure capability permits downloading a hidden activity's configuration.
     */
    public function test_configurer_override(): void {
        global $DB;
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $DB->set_field('course_modules', 'visible', 0, ['id' => $this->lti->cmid]);
        rebuild_course_cache($this->course->id, true);
        $this->setUser($teacher);
        $this->assertSame($this->xml, (new config_download())->get_file($this->lti->cmid)['body']);
    }

    /**
     * Missing, disabled, empty or stale records never result in a file response.
     */
    #[DataProvider('bad_configuration_provider')]
    public function test_bad_configuration(string $scenario, string $error): void {
        global $DB;
        if ($scenario === 'missing') {
            $DB->delete_records('local_torussecure', ['cmid' => $this->lti->cmid]);
        } else {
            [$field, $value] = match ($scenario) {
                'disabled' => ['enabled', 0],
                'empty' => ['configxml', ' '],
                'mismatch' => ['ltiinstanceid', 999999],
            };
            $DB->set_field('local_torussecure', $field, $value, ['cmid' => $this->lti->cmid]);
        }
        // Use a configurer so missing mappings cannot be masked by availability rules.
        $this->setAdminUser();
        try {
            (new config_download())->get_file($this->lti->cmid);
            $this->fail('Expected configuration rejection');
        } catch (\moodle_exception $exception) {
            $this->assertSame($error, $exception->errorcode);
        }
    }

    /**
     * Corruption and absent state cases.
     */
    public static function bad_configuration_provider(): array {
        return [
            ['missing', 'configurationmissing'],
            ['disabled', 'configurationdisabled'],
            ['empty', 'emptyconfiguration'],
            ['mismatch', 'invalidactivitymapping'],
        ];
    }

    /**
     * A different activity cannot retrieve the first activity's configuration.
     */
    public function test_other_activity_has_no_configuration(): void {
        $other = $this->getDataGenerator()->create_module('lti', ['course' => $this->course->id]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('configurationmissing', 'local_torussecure'));
        (new config_download())->get_file($other->cmid);
    }

    /**
     * The endpoint supports only LTI course modules.
     */
    public function test_wrong_module_type(): void {
        $label = $this->getDataGenerator()->create_module('label', ['course' => $this->course->id]);
        $this->expectException(\moodle_exception::class);
        (new config_download())->get_file($label->cmid);
    }

    /**
     * Both rendered links point at the same configured public host, port, path and cmid.
     */
    public function test_links(): void {
        set_config('moodleorigin', 'https://moodle.test:8443', 'local_torussecure');
        $links = config_links::render($this->lti->cmid);
        foreach (['https', 'sebs'] as $scheme) {
            $this->assertStringContainsString(
                'href="' . $scheme . '://moodle.test:8443/local/torussecure/config.php?cmid=' . $this->lti->cmid . '"',
                $links
            );
        }
        $this->assertStringContainsString(get_string('downloadconfig', 'local_torussecure'), $links);
        $this->assertStringContainsString(get_string('launchseb', 'local_torussecure'), $links);
    }
}
