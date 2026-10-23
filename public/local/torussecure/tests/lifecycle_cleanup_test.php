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
 * Secure Torus lifecycle regression coverage.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace local_torussecure;

use local_torussecure\local\lifecycle_cleanup;
use local_torussecure\local\config_repository;

/**
 * Conservative cleanup and metadata-only upgrade regression tests.
 */
#[\PHPUnit\Framework\Attributes\Group('lifecycle')]
#[\PHPUnit\Framework\Attributes\CoversClass(lifecycle_cleanup::class)]
final class lifecycle_cleanup_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    public function test_cleanup_rechecks_and_preserves_live_or_inconsistent_mappings(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        $record = (new config_repository())->create_for_activity($lti->cmid, $lti->id, '<plist/>', str_repeat('a', 64));
        $this->assertSame('valid', lifecycle_cleanup::classify($record));
        $this->assertFalse(lifecycle_cleanup::delete_orphan($record->id));
        $orphan = clone $record;
        unset($orphan->id);
        $orphan->cmid = 999991;
        $orphan->ltiinstanceid = 999991;
        $orphan->id = $DB->insert_record('local_torussecure', $orphan);
        $this->assertSame('orphan', lifecycle_cleanup::classify($orphan));
        // Delete rechecks the latest database record, not a stale object supplied by the caller.
        $DB->set_field('local_torussecure', 'ltiinstanceid', 999992, ['id' => $orphan->id]);
        $this->assertTrue(lifecycle_cleanup::delete_orphan($orphan->id));
        $this->assertFalse(lifecycle_cleanup::delete_orphan($orphan->id));
        $DB->set_field('local_torussecure', 'cmid', 999991, ['id' => $record->id]);
        $record->cmid = 999991;
        $this->assertSame('inconsistent', lifecycle_cleanup::classify($record));
        $this->assertFalse(lifecycle_cleanup::delete_orphan($record->id));
        $this->assertTrue($DB->record_exists('local_torussecure', ['id' => $record->id]));
    }

    public function test_dry_run_and_cleanup_preserve_valid_and_newly_referenced_records(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        $live = (new config_repository())->create_for_activity($lti->cmid, $lti->id, '<original/>', str_repeat('a', 64));
        $orphan = clone $live;
        unset($orphan->id);
        $orphan->cmid = 999991;
        $orphan->ltiinstanceid = 999991;
        $orphan->id = $DB->insert_record('local_torussecure', $orphan);
        $before = $DB->get_records('local_torussecure');
        $reports = [];
        $counts = lifecycle_cleanup::report(false, function ($record, $status) use (&$reports): void {
            $reports[] = [$record, $status];
        });
        $this->assertSame(['valid' => 1, 'inconsistent' => 0, 'orphan' => 1, 'deleted' => 0], $counts);
        $this->assertEquals($before, $DB->get_records('local_torussecure'));
        $this->assertCount(1, $reports);
        $this->assertObjectNotHasProperty('configxml', $reports[0][0]);
        $revived = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        $counts = lifecycle_cleanup::report(true, function ($record, $status) use ($DB, $revived): void {
            // State changes after classification, before deletion: it must be rechecked.
            $DB->set_field('local_torussecure', 'ltiinstanceid', $revived->id, ['id' => $record->id]);
        });
        $this->assertEquals(0, $counts['deleted']);
        $this->assertTrue($DB->record_exists('local_torussecure', ['id' => $orphan->id]));
        $DB->set_field('local_torussecure', 'ltiinstanceid', 999991, ['id' => $orphan->id]);
        $counts = lifecycle_cleanup::report(true, static function (): void {
        });
        $this->assertEquals(1, $counts['deleted']);
        $this->assertEquals($live, $DB->get_record('local_torussecure', ['id' => $live->id]));
    }

    public function test_upgraded_schema_matches_install_schema(): void {
        global $CFG, $DB;
        $file = new \xmldb_file($CFG->dirroot . '/local/torussecure/db/install.xml');
        $this->assertTrue($file->loadXMLStructure());
        $this->assertSame([], $DB->get_manager()->check_database_schema($file->getStructure(), ['extratables' => false]));
    }

    public function test_metadata_backfill_preserves_all_exam_fields(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/torussecure/db/upgrade.php');
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        $record = (new config_repository())->create_for_activity($lti->cmid, $lti->id, '<original/>', str_repeat('a', 64));
        $DB->set_field('local_torussecure', 'courseid', 0, ['id' => $record->id]);
        $before = $DB->get_record('local_torussecure', ['id' => $record->id]);
        set_config('version', 2026092500, 'local_torussecure');
        $this->assertTrue(\xmldb_local_torussecure_upgrade(2026092500));
        $after = $DB->get_record('local_torussecure', ['id' => $record->id]);
        $this->assertEquals($course->id, $after->courseid);
        unset($before->courseid, $after->courseid);
        $this->assertEquals($before, $after);
    }
}
