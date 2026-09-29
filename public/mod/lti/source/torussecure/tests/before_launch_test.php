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
 * Ordinary-launch compatibility and secure-launch rejection.
 *
 * @package    ltisource_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace ltisource_torussecure;

/**
 * Verify ordinary-launch compatibility and denial without SEB evidence.
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('ltisource_torussecure_before_launch')]
final class before_launch_test extends \advanced_testcase {
    /**
     * Load the callback.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/lti/source/torussecure/lib.php');
    }

    /**
     * Unmanaged and Deep Linking requests must retain normal launch behavior.
     */
    public function test_ordinary_launches_are_unchanged(): void {
        $this->resetAfterTest();
        $lti = $this->getDataGenerator()->create_module('lti', [
            'course' => $this->getDataGenerator()->create_course()->id,
        ]);
        $params = ['custom_existing' => 'preserved'];
        $this->assertSame([], ltisource_torussecure_before_launch($lti, 'https://torus.test', $params));
        $this->assertSame([], ltisource_torussecure_before_launch(new \stdClass(), 'https://torus.test', $params));
        $this->assertSame(['custom_existing' => 'preserved'], $params);
    }

    /**
     * An enabled mapping must block signing without valid request evidence.
     */
    public function test_managed_launch_is_blocked(): void {
        global $DB;
        $this->resetAfterTest();
        $lti = $this->getDataGenerator()->create_module('lti', [
            'course' => $this->getDataGenerator()->create_course()->id,
        ]);
        $id = $DB->insert_record('local_torussecure', [
            'cmid' => $lti->cmid, 'ltiinstanceid' => $lti->id, 'enabled' => 0,
            'configxml' => '<plist/>', 'configkey' => str_repeat('a', 64),
            'configversion' => str_repeat('b', 64),
        ]);
        $this->assertSame([], ltisource_torussecure_before_launch($lti, 'https://torus.test', []));
        $DB->set_field('local_torussecure', 'enabled', 1, ['id' => $id]);
        $this->expectException(\moodle_exception::class);
        set_config('torusorigin', 'https://torus.test', 'local_torussecure');
        $this->setAdminUser();
        $this->expectExceptionMessage(get_string('securelaunchdenied', 'local_torussecure'));
        ltisource_torussecure_before_launch($lti, 'https://torus.test', []);
    }
}
