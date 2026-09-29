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
 * Verify installation and administrator settings.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure;

/**
 * Verify the installed components expose usable settings and capability defaults.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\https_origin_setting::class)]
final class settings_test extends \advanced_testcase {
    /**
     * Site settings load through Moodle's real admin tree and validate origins.
     */
    public function test_admin_settings(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = admin_get_root(true, true);
        $page = $admin->locate('local_torussecure');
        $this->assertInstanceOf(\admin_settingpage::class, $page);
        $this->assertCount(4, (array) $page->settings);
        $origin = $page->settings->local_torussecuretorusorigin;
        $this->assertInstanceOf(\local_torussecure\local\https_origin_setting::class, $origin);
        $this->assertTrue($origin->validate('https://torus.test'));
        $this->assertTrue($origin->validate('https://torus.test:8443'));
        foreach (
            ['', 'http://torus.test', 'https://torus.test/path', 'https://u:p@torus.test',
                'https://torus.test?q=1', 'https://torus.test#fragment'] as $invalid
        ) {
            $this->assertNotSame(true, $origin->validate($invalid));
        }
        $moodleorigin = $page->settings->local_torussecuremoodleorigin;
        $this->assertTrue($moodleorigin->validate(''));
    }

    /**
     * Teachers configure activities but only managers receive the bypass capability.
     */
    public function test_capability_defaults(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        foreach (['editingteacher' => false, 'manager' => true] as $role => $canbypass) {
            $user = $this->getDataGenerator()->create_user();
            $roleid = $DB->get_field('role', 'id', ['shortname' => $role], MUST_EXIST);
            role_assign($roleid, $user->id, $context->id);
            $this->assertTrue(has_capability('local/torussecure:configure', $context, $user));
            $this->assertSame($canbypass, has_capability('local/torussecure:bypassseb', $context, $user));
        }
    }
}
