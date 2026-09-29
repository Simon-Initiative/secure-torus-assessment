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
 * Activity chooser integration.
 *
 * @package    ltisource_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace ltisource_torussecure;

/**
 * Exercise Moodle's discovery and permission filtering, including the section default.
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('ltisource_torussecure_get_course_content_items')]
#[\PHPUnit\Framework\Attributes\CoversFunction('ltisource_torussecure_get_all_content_items')]
final class content_items_test extends \advanced_testcase {
    /**
     * The real chooser service finds the subplugin and exports its course-specific creation link.
     */
    public function test_instructor_chooser_and_favourites(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $service = \core_course\local\factory\content_item_service_factory::get_content_item_service();
        $items = $service->get_content_items_for_user_in_course($teacher, $course);
        $secure = array_values(array_filter($items, fn($item) => $item->componentname === 'ltisource_torussecure'));
        $this->assertCount(1, $secure);
        $this->assertSame('Secure Torus Assessment', $secure[0]->title);
        $this->assertSame(
            (new \moodle_url('/local/torussecure/create.php', ['courseid' => $course->id]))->out(false),
            $secure[0]->link
        );
        $all = $service->get_all_content_items($teacher);
        $identities = array_values(array_filter($all, fn($item) => $item->componentname === 'ltisource_torussecure'));
        $this->assertCount(1, $identities);
        $this->assertSame($secure[0]->id, $identities[0]->id);
    }

    /**
     * Course roles without secure-creation permission cannot see this item.
     */
    public function test_unauthorized_users_do_not_get_item(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('local/torussecure:configure', CAP_PROHIBIT, $roleid, \context_course::instance($course->id));
        $this->setUser($teacher);
        $service = \core_course\local\factory\content_item_service_factory::get_content_item_service();
        $items = $service->get_content_items_for_user_in_course($teacher, $course);
        $this->assertEmpty(array_filter($items, fn($item) => $item->componentname === 'ltisource_torussecure'));
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $this->assertSame([], $service->get_content_items_for_user_in_course($student, $course));
    }

    /**
     * Chooser section IDs preselect the corresponding section number, scoped to this course.
     */
    public function test_form_section_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $section = get_fast_modinfo($course)->get_section_info(2);
        $form = new \local_torussecure\form\create_activity_form(null, [
            'course' => $course, 'sectionid' => $section->id,
        ]);
        $html = $form->render();
        $this->assertMatchesRegularExpression('/<option[^>]*value="2"[^>]*selected/', $html);
        $other = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $foreignsection = get_fast_modinfo($other)->get_section_info(2);
        $form = new \local_torussecure\form\create_activity_form(null, [
            'course' => $course, 'sectionid' => $foreignsection->id,
        ]);
        $this->assertMatchesRegularExpression('/<option[^>]*value="0"[^>]*selected/', $form->render());
    }
}
