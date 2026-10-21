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

use local_torussecure\local\activity_provisioner;
use local_torussecure\local\config_repository;
use local_torussecure\local\seb_config_key;

/**
 * Integration tests through Moodle's real lifecycle APIs.
 */
#[\PHPUnit\Framework\Attributes\Group('lifecycle')]
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class lifecycle_test extends \advanced_testcase {
    /** @var \stdClass Source course. */
    private \stdClass $course;
    /** @var \stdClass Original secure LTI activity. */
    private \stdClass $activity;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        set_config('enableavailability', 1);
        set_config('coursebinenable', 0, 'tool_recyclebin');
        set_config('categorybinenable', 0, 'tool_recyclebin');
        set_config('torusorigin', 'https://torus.test', 'local_torussecure');
        set_config('moodleorigin', 'https://moodle.test:8443', 'local_torussecure');
        $type = $this->getDataGenerator()->get_plugin_generator('mod_lti')->create_tool_types([
            'name' => 'Torus', 'baseurl' => 'https://torus.test/lti/launch',
            'ltiversion' => LTI_VERSION_1P3, 'state' => LTI_TOOL_STATE_CONFIGURED,
            'ltiservice_gradesynchronization' => 2,
        ]);
        set_config('tooltypeid', $type, 'local_torussecure');
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->activity = (new activity_provisioner())->create((object) [
            'courseid' => $this->course->id, 'section' => 1, 'name' => 'Secure lifecycle',
            'targeturl' => 'https://torus.test/lti/launch?resource=assessment', 'grade' => 75,
            'seb_allowquit' => 0, 'seb_camera' => 1, 'seb_maxdisplays' => 2,
            'seb_allowedurls' => 'https://media.example.org', 'seb_quitpassword' => 'test-password',
        ]);
    }

    public function test_source_policy_is_recoverable(): void {
        $record = $this->record();
        $policy = \local_torussecure\local\restore_policy::decode($record->configxml, (int) $record->cmid);
        $this->assertSame('https://torus.test', $policy['torusorigin']);
    }

    public function test_restorer_accepts_valid_destination(): void {
        $record = $this->record();
        $destination = $this->getDataGenerator()->create_module('lti', [
            'course' => $this->course->id, 'typeid' => get_config('local_torussecure', 'tooltypeid'),
            'toolurl' => 'https://torus.test/lti/launch', 'grade' => 75,
        ]);
        (new \local_torussecure\local\activity_restorer())->restore((int) $destination->cmid, (int) $destination->id, [
            'formatversion' => 1, 'managed' => 1, 'sourcecmid' => $record->cmid,
            'sourceltiinstanceid' => $record->ltiinstanceid, 'enabled' => 1, 'configxml' => $record->configxml,
        ]);
        $this->assert_restored($destination->cmid);
    }

    public function test_duplicate_regenerates_config_without_modifying_source(): void {
        global $DB;
        $source = $this->record();
        $cm = $DB->get_record('course_modules', ['id' => $source->cmid]);
        $lti = $DB->get_record('lti', ['id' => $source->ltiinstanceid]);
        $copy = \core_courseformat\formatactions::cm($this->course->id)->duplicate($source->cmid);
        $restored = $this->assert_restored($copy->id);
        $this->assertNotSame($source->configkey, $restored->configkey);
        $this->assertEquals($source, $this->record());
        $this->assertEquals($cm, $DB->get_record('course_modules', ['id' => $source->cmid]));
        $this->assertEquals($lti, $DB->get_record('lti', ['id' => $source->ltiinstanceid]));
        $plist = new \quizaccess_seb\property_list($restored->configxml);
        $this->assertFalse($plist->get_element_value('allowQuit'));
        $this->assertTrue($plist->get_element_value('browserMediaCaptureCamera'));
        $this->assertSame(2, $plist->get_element_value('allowedDisplaysMaxNumber'));
        $this->assertSame(hash('sha256', 'test-password'), $plist->get_element_value('hashedQuitPassword'));
        $this->assertStringContainsString('media', $restored->configxml);
    }

    public function test_duplicate_disabled_mapping_stays_disabled(): void {
        global $DB;
        $DB->set_field('local_torussecure', 'enabled', 0, ['cmid' => $this->activity->coursemodule]);
        $copy = \core_courseformat\formatactions::cm($this->course->id)->duplicate($this->activity->coursemodule);
        $this->assertEquals(0, $this->assert_restored($copy->id)->enabled);
    }

    public function test_ordinary_duplicate_remains_ordinary(): void {
        global $DB;
        $ordinary = $this->getDataGenerator()->create_module('lti', ['course' => $this->course->id]);
        $copy = \core_courseformat\formatactions::cm($this->course->id)->duplicate($ordinary->cmid);
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $copy->id]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $copy->id]));
    }

    public function test_module_deletion_cleans_only_its_mapping(): void {
        global $DB;
        $copy = \core_courseformat\formatactions::cm($this->course->id)->duplicate($this->activity->coursemodule);
        \core_courseformat\formatactions::cm($this->course->id)->delete($copy->id);
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $copy->id]));
        $this->assertNotNull($this->record());
        \core_courseformat\formatactions::cm($this->course->id)->delete($copy->id);
    }

    public function test_course_content_deletion_cleans_only_its_mappings(): void {
        global $DB;
        $destination = $this->getDataGenerator()->create_course();
        $ids = $this->roundtrip($destination->id);
        remove_course_contents($this->course->id, false);
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $this->activity->coursemodule]));
        $this->assert_restored($ids[0]);
    }

    public function test_activity_recycle_bin_recovers_after_cleanup(): void {
        global $DB;
        set_config('coursebinenable', 1, 'tool_recyclebin');
        \core_courseformat\formatactions::cm($this->course->id)->delete($this->activity->coursemodule);
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $this->activity->coursemodule]));
        $bin = new \tool_recyclebin\course_bin($this->course->id);
        $this->assertCount(1, $bin->get_items());
        foreach ($bin->get_items() as $item) {
            $bin->restore_item($item);
        }
        $record = $DB->get_record('local_torussecure', [], '*', MUST_EXIST);
        $this->assert_restored($record->cmid);
    }

    public function test_course_recycle_bin_recovers_after_cleanup(): void {
        global $DB;
        // Course tools can be restored after their source course has gone.
        $DB->set_field('lti_types', 'course', $this->course->id, [
            'id' => get_config('local_torussecure', 'tooltypeid'),
        ]);
        set_config('categorybinenable', 1, 'tool_recyclebin');
        delete_course($this->course, false, false);
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $this->activity->coursemodule]));
        $bin = new \tool_recyclebin\category_bin($this->course->category);
        $this->assertCount(1, $bin->get_items());
        foreach ($bin->get_items() as $item) {
            $bin->restore_item($item);
        }
        $record = $DB->get_record('local_torussecure', [], '*', MUST_EXIST);
        $this->assert_restored($record->cmid);
    }

    public function test_course_backup_restore_preserves_secure_and_ordinary_activities(): void {
        global $DB;
        $this->getDataGenerator()->create_module('lti', ['course' => $this->course->id, 'name' => 'Ordinary']);
        $destination = $this->getDataGenerator()->create_course();
        $ids = $this->roundtrip($destination->id);
        $this->assertCount(2, $ids);
        $this->assert_restored($ids[0]);
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $ids[1]]));
    }

    public function test_missing_registration_keeps_restored_activity_hidden(): void {
        global $DB;
        $destination = $this->getDataGenerator()->create_course();
        $ids = $this->roundtrip($destination->id, function (): void {
            set_config('torusorigin', 'https://other.test', 'local_torussecure');
        });
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $ids[0]]));
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $ids[0]]));
        $this->assertNotNull($this->record());
    }

    public function test_legacy_backup_keeps_restored_activity_hidden(): void {
        global $DB;
        $destination = $this->getDataGenerator()->create_course();
        $ids = $this->roundtrip($destination->id, function (string $path): void {
            $module = $path . '/activities/lti_' . $this->activity->coursemodule . '/module.xml';
            $xml = new \DOMDocument();
            $xml->load($module);
            foreach (iterator_to_array($xml->getElementsByTagName('plugin_local_torussecure_module')) as $element) {
                $element->parentNode->removeChild($element);
            }
            $xml->save($module);
        });
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $ids[0]]));
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $ids[0]]));
    }

    public function test_async_deletion_retains_mapping_until_task_runs(): void {
        global $DB;
        set_config('coursebinenable', 1, 'tool_recyclebin');
        \core_courseformat\formatactions::cm($this->course->id)->delete($this->activity->coursemodule, true);
        $this->assertNotNull($this->record());
        $this->assertEquals(1, $DB->get_field('course_modules', 'deletioninprogress', [
            'id' => $this->activity->coursemodule,
        ]));
        $this->runAdhocTasks('\core_course\task\course_delete_modules');
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $this->activity->coursemodule]));
        $this->assertCount(1, (new \tool_recyclebin\course_bin($this->course->id))->get_items());
    }

    public function test_restored_hidden_activity_stays_hidden(): void {
        global $DB;
        set_coursemodule_visible($this->activity->coursemodule, 0);
        $copy = \core_courseformat\formatactions::cm($this->course->id)->duplicate($this->activity->coursemodule);
        $this->assert_restored($copy->id);
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $copy->id]));
    }

    public function test_corrupt_payload_stays_hidden_and_has_recovery_label(): void {
        global $DB;
        $destination = $this->getDataGenerator()->create_course();
        $ids = $this->roundtrip($destination->id, function (string $path): void {
            $module = $path . '/activities/lti_' . $this->activity->coursemodule . '/module.xml';
            $xml = new \DOMDocument();
            $xml->load($module);
            $xml->getElementsByTagName('formatversion')->item(0)->nodeValue = '999';
            $xml->save($module);
        });
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $ids[0]]));
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $ids[0]]));
        $cm = $DB->get_record('course_modules', ['id' => $ids[0]]);
        $this->assertStringContainsString(
            '[Secure Torus configuration required]',
            $DB->get_field('lti', 'name', ['id' => $cm->instance])
        );
    }

    public function test_cross_site_origin_without_custom_destinations_is_regenerated(): void {
        global $DB;
        $record = $this->record();
        $xml = (new \local_torussecure\local\seb_config_builder())->build(
            $record->cmid,
            'https://moodle.test:8443',
            'https://torus.test',
            ['allowQuit' => false]
        );
        (new config_repository())->replace_generated_config($record->cmid, $xml, (new seb_config_key())->generate($xml));
        $destination = $this->getDataGenerator()->create_course();
        $ids = $this->roundtrip($destination->id, function (): void {
            set_config('moodleorigin', 'https://destination.example.org', 'local_torussecure');
        });
        $restored = $this->assert_restored($ids[0]);
        $this->assertStringContainsString('https://destination.example.org/local/torussecure/launch.php', $restored->configxml);
        $this->assertStringNotContainsString('moodle.test', $restored->configxml);
        $this->assertSame($xml, $this->record()->configxml);
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $ids[0]]));
    }

    public function test_cross_site_custom_destinations_require_review(): void {
        global $DB;
        $destination = $this->getDataGenerator()->create_course();
        $ids = $this->roundtrip($destination->id, function (): void {
            set_config('moodleorigin', 'https://destination.example.org', 'local_torussecure');
        });
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $ids[0]]));
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $ids[0]]));
    }

    public function test_manual_backup_restore_with_and_without_users(): void {
        global $USER;
        foreach ([false, true] as $users) {
            $destination = $this->getDataGenerator()->create_course();
            $backup = new \backup_controller(
                \backup::TYPE_1COURSE,
                $this->course->id,
                \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO,
                \backup::MODE_GENERAL,
                $USER->id
            );
            $backup->get_plan()->get_setting('users')->set_value($users);
            $id = $backup->get_backupid();
            $backup->execute_plan();
            $result = $backup->get_results();
            $archive = $result['backup_destination'];
            $this->assertInstanceOf(\stored_file::class, $archive);
            $archive->extract_to_pathname(
                get_file_packer('application/vnd.moodle.backup'),
                make_backup_temp_directory('lifecycle_' . $id)
            );
            $backup->destroy();
            $restore = new \restore_controller(
                'lifecycle_' . $id,
                $destination->id,
                \backup::INTERACTIVE_NO,
                \backup::MODE_GENERAL,
                $USER->id,
                \backup::TARGET_CURRENT_ADDING
            );
            $this->assertTrue($restore->execute_precheck());
            $restore->execute_plan();
            foreach ($restore->get_plan()->get_tasks() as $task) {
                if ($task instanceof \restore_activity_task) {
                    $this->assert_restored($task->get_moduleid());
                }
            }
            $restore->destroy();
        }
    }

    public function test_course_copy_adhoc_task_preserves_secure_mapping(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/helper/copy_helper.class.php');
        $data = \copy_helper::process_formdata((object) [
            'courseid' => $this->course->id, 'fullname' => 'Lifecycle copy', 'shortname' => 'lifecycle-copy',
            'category' => $this->course->category, 'visible' => 1, 'startdate' => $this->course->startdate,
            'enddate' => 0, 'idnumber' => '', 'userdata' => 0,
        ]);
        \copy_helper::create_copy($data);
        ob_start();
        try {
            $this->runAdhocTasks('\core\task\asynchronous_copy_task');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('Copy completed', $output);
        $destination = $DB->get_record('course', ['shortname' => 'lifecycle-copy'], '*', MUST_EXIST);
        $record = $DB->get_record('local_torussecure', ['courseid' => $destination->id], '*', MUST_EXIST);
        $this->assert_restored($record->cmid);
        $this->assertNotNull($this->record());
    }

    public function test_async_course_delete_cleans_only_after_task_runs(): void {
        global $DB;
        set_config('enablecourseasyncdeletion', 1, 'moodlecourse');
        delete_course($this->course, false, true);
        $this->assertNotNull($this->record());
        ob_start();
        try {
            $this->runAdhocTasks('\core_course\task\course_async_deletion');
        } finally {
            ob_end_clean();
        }
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $this->activity->coursemodule]));
    }

    public function test_duplicate_retains_other_availability_rules(): void {
        global $DB;
        $tree = json_decode($DB->get_field('course_modules', 'availability', ['id' => $this->activity->coursemodule]));
        $tree->c[] = (object) ['type' => 'date', 'd' => '>=', 't' => 2000000000];
        $tree->showc[] = false;
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $this->activity->coursemodule]);
        rebuild_course_cache($this->course->id, true);
        $copy = \core_courseformat\formatactions::cm($this->course->id)->duplicate($this->activity->coursemodule);
        $this->assertNotNull((new config_repository())->get_by_cmid($copy->id));
        $restored = json_decode($DB->get_field('course_modules', 'availability', ['id' => $copy->id]));
        $this->assertEquals($tree, $restored);
    }

    public function test_legacy_restriction_is_held_when_availability_disabled(): void {
        global $DB;
        $destination = $this->getDataGenerator()->create_course();
        $ids = $this->roundtrip($destination->id, function (string $path): void {
            $module = $path . '/activities/lti_' . $this->activity->coursemodule . '/module.xml';
            $xml = new \DOMDocument();
            $xml->load($module);
            foreach (iterator_to_array($xml->getElementsByTagName('plugin_local_torussecure_module')) as $element) {
                $element->parentNode->removeChild($element);
            }
            $xml->save($module);
            set_config('enableavailability', 0);
        });
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $ids[0]]));
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $ids[0]]));
    }

    public function test_conflicting_destination_mapping_is_never_overwritten(): void {
        $record = $this->record();
        try {
            (new \local_torussecure\local\activity_restorer())->restore($record->cmid, $record->ltiinstanceid, [
                'formatversion' => 1, 'managed' => 1, 'sourcecmid' => $record->cmid,
                'sourceltiinstanceid' => $record->ltiinstanceid, 'enabled' => 1, 'configxml' => $record->configxml,
            ]);
            $this->fail('Expected a mapping conflict');
        } catch (\moodle_exception $exception) {
            $this->assertSame('configurationexists', $exception->errorcode);
        }
        $this->assertEquals($record, $this->record());
    }

    public function test_restored_launch_requires_the_new_configuration_proof(): void {
        global $CFG, $DB, $FULLME, $SESSION, $USER;
        require_once($CFG->dirroot . '/mod/lti/source/torussecure/lib.php');
        $source = $this->record();
        $SESSION->torussecure_grants[$source->cmid] = [
            'userid' => (int) $USER->id, 'version' => $source->configversion,
            'keydigest' => hash('sha256', $source->configkey), 'created' => time(),
            'url' => 'https://moodle.test:8443/local/torussecure/launch.php?cmid=' . $source->cmid,
        ];
        $copy = \core_courseformat\formatactions::cm($this->course->id)->duplicate($source->cmid);
        $restored = $this->assert_restored($copy->id);
        $this->assertArrayNotHasKey($copy->id, $SESSION->torussecure_grants);
        $instance = $DB->get_record('lti', ['id' => $restored->ltiinstanceid], '*', MUST_EXIST);
        $oldurl = $FULLME;
        $oldserver = $_SERVER;
        try {
            $FULLME = 'https://moodle.test:8443/mod/lti/launch.php?id=' . $copy->id;
            $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] = hash('sha256', $FULLME . $source->configkey);
            try {
                \ltisource_torussecure_before_launch($instance, $instance->toolurl, []);
                $this->fail('Source configuration must not authorize the restored activity');
            } catch (\moodle_exception $exception) {
                $this->assertSame('securelaunchdenied', $exception->errorcode);
            }
            $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] = hash('sha256', $FULLME . $restored->configkey);
            $claims = \ltisource_torussecure_before_launch($instance, $instance->toolurl, []);
            $this->assertSame((string) $copy->id, $claims['custom_secure_activity_id']);
            $this->assertSame($restored->configversion, $claims['custom_seb_configuration_id']);
        } finally {
            $FULLME = $oldurl;
            $_SERVER = $oldserver;
        }
    }

    public function test_failure_after_mapping_creation_rolls_back_only_destination(): void {
        global $DB;
        $source = $this->record();
        $destination = $this->getDataGenerator()->create_module('lti', [
            'course' => $this->course->id, 'typeid' => get_config('local_torussecure', 'tooltypeid'),
            'toolurl' => 'https://torus.test/lti/launch', 'grade' => 75, 'visible' => 0,
        ]);
        $DB->set_field('course_modules', 'availability', '{invalid', ['id' => $destination->cmid]);
        try {
            (new \local_torussecure\local\activity_restorer())->restore((int) $destination->cmid, (int) $destination->id, [
                'formatversion' => 1, 'managed' => 1, 'sourcecmid' => $source->cmid,
                'sourceltiinstanceid' => $source->ltiinstanceid, 'enabled' => 1, 'configxml' => $source->configxml,
            ]);
            $this->fail('Expected restriction attachment failure');
        } catch (\JsonException $exception) {
            $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $destination->cmid]));
            $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $destination->cmid]));
            $this->assertEquals($source, $this->record());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('registration_failure_provider')]
    public function test_unusable_destination_registration_is_held(string $case): void {
        global $DB;
        $destination = $this->getDataGenerator()->create_course();
        $ids = $this->roundtrip($destination->id, function () use ($DB, $case): void {
            $typeid = get_config('local_torussecure', 'tooltypeid');
            switch ($case) {
                case 'missing':
                    $DB->delete_records('lti_types', ['id' => $typeid]);
                    break;
                case 'client':
                    $DB->set_field('lti_types', 'clientid', '', ['id' => $typeid]);
                    break;
                case 'ags':
                    $DB->set_field('lti_types_config', 'value', '1', [
                        'typeid' => $typeid, 'name' => 'ltiservice_gradesynchronization',
                    ]);
                    break;
                case 'availability':
                    set_config('enableavailability', 0);
                    break;
            }
        });
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $ids[0]]));
        $this->assertFalse($DB->record_exists('local_torussecure', ['cmid' => $ids[0]]));
    }

    /**
     * Registration failures that must never result in an ordinary visible launch.
     */
    public static function registration_failure_provider(): array {
        return [['missing'], ['client'], ['ags'], ['availability']];
    }

    public function test_backup_payload_excludes_keys_versions_and_session_state(): void {
        $source = $this->record();
        $destination = $this->getDataGenerator()->create_course();
        $this->roundtrip($destination->id, function (string $path) use ($source): void {
            $module = $path . '/activities/lti_' . $source->cmid . '/module.xml';
            $document = new \DOMDocument();
            $document->load($module);
            $configuration = $document->getElementsByTagName('configuration')->item(0);
            $this->assertNotNull($configuration);
            $fields = [];
            foreach ($configuration->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $fields[] = $child->tagName;
                }
            }
            $this->assertSame(['formatversion', 'managed', 'sourcecmid', 'sourceltiinstanceid', 'enabled', 'configxml'], $fields);
            $this->assertSame($source->configxml, $document->getElementsByTagName('configxml')->item(0)->textContent);
        });
        $this->assertEquals($source, $this->record());
    }

    /**
     * Run the actual course backup and restore controllers.
     */
    private function roundtrip(int $destination, ?callable $before = null, int $mode = \backup::MODE_IMPORT): array {
        global $USER;
        $backup = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            $mode,
            $USER->id
        );
        $id = $backup->get_backupid();
        $path = $backup->get_plan()->get_basepath();
        $backup->execute_plan();
        $backup->destroy();
        if ($before) {
            $before($path);
        }
        $restore = new \restore_controller(
            $id,
            $destination,
            \backup::INTERACTIVE_NO,
            $mode,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $this->assertTrue($restore->execute_precheck());
        $restore->execute_plan();
        $ids = [];
        foreach ($restore->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_activity_task) {
                $ids[$task->get_old_moduleid()] = $task->get_moduleid();
            }
        }
        $restore->destroy();
        ksort($ids);
        return array_values($ids);
    }

    /**
     * Read the original mapping for preservation checks.
     */
    private function record(): \stdClass {
        return (new config_repository())->get_by_cmid($this->activity->coursemodule);
    }

    /**
     * Verify destination mapping, regenerated configuration, restriction and native grade item.
     */
    private function assert_restored(int $cmid): \stdClass {
        global $DB;
        $record = (new config_repository())->get_by_cmid($cmid);
        $this->assertNotNull($record);
        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $this->assertEquals($cm->instance, $record->ltiinstanceid);
        $this->assertSame((new seb_config_key())->generate($record->configxml), $record->configkey);
        $this->assertStringContainsString('/local/torussecure/launch.php?cmid=' . $cmid, $record->configxml);
        $this->assertSame('torusseb', json_decode($cm->availability)->c[count(json_decode($cm->availability)->c) - 1]->type);
        $this->assertTrue($DB->record_exists('grade_items', [
            'itemmodule' => 'lti', 'iteminstance' => $cm->instance, 'grademax' => 75,
        ]));
        return $record;
    }
}
