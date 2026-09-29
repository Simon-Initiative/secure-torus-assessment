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
 * Availability enforcement verification.
 *
 * @package    availability_torusseb
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_torusseb;

/**
 * Exercise request validation, Moodle inversion, provisioning attachment and cached visibility.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\availability_torusseb\condition::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\availability_installer::class)]
final class condition_test extends \advanced_testcase {
    /** @var mixed Canonical request URL to restore. */
    private mixed $oldfullme;
    /** @var array Server globals to restore. */
    private array $oldserver;

    protected function setUp(): void {
        global $FULLME;
        parent::setUp();
        $this->resetAfterTest();
        $this->oldfullme = $FULLME;
        $this->oldserver = $_SERVER;
        unset($_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']);
        set_config('enableavailability', 1);
    }

    protected function tearDown(): void {
        global $FULLME;
        $FULLME = $this->oldfullme;
        $_SERVER = $this->oldserver;
        parent::tearDown();
    }

    /**
     * Missing/disabled configurations fail closed; configured conditions follow Moodle inversion.
     */
    public function test_configuration_and_inversion(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);
        $cm = get_fast_modinfo($course)->get_cm($lti->cmid);
        $info = new \core_availability\info_module($cm);
        $condition = new condition((object) ['type' => 'torusseb']);
        $this->assertEquals((object) ['type' => 'torusseb'], $condition->save());
        $this->assertFalse($condition->is_available(false, $info, false, $user->id));
        $this->assertFalse($condition->is_available(true, $info, false, $user->id));
        $DB->insert_record('local_torussecure', [
            'cmid' => $cm->id, 'ltiinstanceid' => $lti->id, 'enabled' => 1,
            'configxml' => '<plist/>', 'configkey' => str_repeat('a', 64),
            'configversion' => str_repeat('b', 64),
        ]);
        $this->assertFalse($condition->is_available(false, $info, false, $user->id));
        $this->assertTrue($condition->is_available(true, $info, false, $user->id));
        $description = $condition->get_description(false, false, $info);
        $this->assertStringContainsString('/local/torussecure/config.php?cmid=' . $cm->id, $description);
        $this->assertStringContainsString('href="sebs://', $description);
        $this->assertStringContainsString('href="https://', $description);
        $DB->set_field('local_torussecure', 'enabled', 0, ['cmid' => $cm->id]);
        $this->assertFalse($condition->is_available(true, $info, false, $user->id));
    }

    /**
     * Exercise actual course-module availability with current-request evidence and refreshed caches.
     */
    public function test_attached_condition_and_request_evidence(): void {
        global $FULLME, $DB;
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);
        $FULLME = 'https://moodle.test:8443/mod/lti/view.php?id=' . $lti->cmid;
        $key = str_repeat('a', 64);
        (new \local_torussecure\local\config_repository())->create_for_activity($lti->cmid, $lti->id, '<plist/>', $key);
        // Prime the cache before attachment to prove the helper invalidates it.
        $this->assertTrue(get_fast_modinfo($course)->get_cm($lti->cmid)->uservisible);
        $installer = new \local_torussecure\local\availability_installer();
        $installer->attach($lti->cmid);
        $json = $DB->get_field('course_modules', 'availability', ['id' => $lti->cmid]);
        $installer->attach($lti->cmid);
        $this->assertSame($json, $DB->get_field('course_modules', 'availability', ['id' => $lti->cmid]));
        $cm = get_fast_modinfo($course)->get_cm($lti->cmid);
        $this->assertFalse($cm->uservisible);
        $this->assertTrue($cm->is_visible_on_course_page());
        $condition = new condition((object) ['type' => 'torusseb']);
        $info = new \core_availability\info_module($cm);
        $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] = hash('sha256', $FULLME . $key);
        $this->assertTrue($condition->is_available(false, $info, true, $user->id));
        $this->assertFalse($condition->is_available(true, $info, false, $user->id));
        $other = $this->getDataGenerator()->create_user();
        $this->assertFalse($condition->is_available(false, $info, false, $other->id));
        // Simulate the next HTTP request, whose dynamic modinfo must be evaluated anew.
        get_fast_modinfo($course, 0, true);
        $this->assertTrue(get_fast_modinfo($course)->get_cm($lti->cmid)->uservisible);
        $FULLME .= '&different=1';
        $this->assertFalse($condition->is_available(false, $info, false, $user->id));
        unset($_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']);
        $_SERVER['HTTP_USER_AGENT'] = 'SafeExamBrowser SEB';
        $this->assertFalse($condition->is_available(false, $info, false, $user->id));
        $section = new \core_availability\info_section(get_fast_modinfo($course)->get_section_info(0));
        $this->assertFalse($condition->is_available(false, $section, false, $user->id));
        $this->assertFalse($condition->is_available(true, $section, false, $user->id));
    }

    /**
     * Bypass uses the evaluated user's permission and still observes inversion.
     */
    public function test_diagnostic_bypass(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        (new \local_torussecure\local\config_repository())->create_for_activity(
            $lti->cmid,
            $lti->id,
            '<plist/>',
            str_repeat('a', 64)
        );
        $user = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($roleid, $user->id, \context_module::instance($lti->cmid)->id);
        $this->setUser($user);
        $info = new \core_availability\info_module(get_fast_modinfo($course)->get_cm($lti->cmid));
        $condition = new condition((object) ['type' => 'torusseb']);
        $this->assertFalse($condition->is_available(false, $info, false, $user->id));
        set_config('enablebypass', 1, 'local_torussecure');
        $this->assertTrue($condition->is_available(false, $info, false, $user->id));
        $this->assertFalse($condition->is_available(true, $info, false, $user->id));
    }

    /**
     * Existing OR restrictions are retained inside a mandatory AND, preserving their hide flag.
     */
    public function test_attachment_preserves_existing_restrictions(): void {
        global $DB, $FULLME;
        $course = $this->getDataGenerator()->create_course();
        $existing = (object) ['op' => '|', 'show' => false, 'c' => [
            (object) ['type' => 'date', 'd' => '>=', 't' => time() - 100],
            (object) ['type' => 'date', 'd' => '>=', 't' => time() + 100],
        ]];
        $lti = $this->getDataGenerator()->create_module('lti', [
            'course' => $course->id, 'availability' => json_encode($existing),
        ]);
        (new \local_torussecure\local\config_repository())->create_for_activity(
            $lti->cmid,
            $lti->id,
            '<plist/>',
            str_repeat('a', 64)
        );
        (new \local_torussecure\local\availability_installer())->attach($lti->cmid);
        $root = json_decode($DB->get_field('course_modules', 'availability', ['id' => $lti->cmid]));
        $this->assertSame('&', $root->op);
        $this->assertSame([false, true], $root->showc);
        unset($existing->show);
        $this->assertEquals($existing, $root->c[0]);
        $this->assertSame('torusseb', $root->c[1]->type);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);
        $FULLME = 'https://moodle.test:8443/mod/lti/view.php?id=' . $lti->cmid;
        $this->assertFalse(get_fast_modinfo($course)->get_cm($lti->cmid)->uservisible);
        $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] = hash('sha256', $FULLME . str_repeat('a', 64));
        get_fast_modinfo($course, 0, true);
        $this->assertTrue(get_fast_modinfo($course)->get_cm($lti->cmid)->uservisible);
    }

    /**
     * Attaching a secure restriction does not change a sibling ordinary activity.
     */
    public function test_unrelated_activity_remains_available(): void {
        $course = $this->getDataGenerator()->create_course();
        $secure = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        $ordinary = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        (new \local_torussecure\local\config_repository())->create_for_activity(
            $secure->cmid,
            $secure->id,
            '<plist/>',
            str_repeat('a', 64)
        );
        (new \local_torussecure\local\availability_installer())->attach($secure->cmid);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);
        $info = get_fast_modinfo($course);
        $this->assertFalse($info->get_cm($secure->cmid)->uservisible);
        $this->assertTrue($info->get_cm($ordinary->cmid)->uservisible);
        $this->assertEmpty($info->get_cm($ordinary->cmid)->availability);
    }

    /**
     * No restriction can be provisioned when Moodle's availability system is disabled.
     */
    public function test_attachment_requires_availability(): void {
        set_config('enableavailability', 0);
        $this->expectException(\moodle_exception::class);
        (new \local_torussecure\local\availability_installer())->attach(1);
    }
}
