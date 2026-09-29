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
 * Configuration persistence tests.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure;

use local_torussecure\local\config_repository;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verify real database writes, mapping validation and transaction boundaries.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\config_repository::class)]
final class config_repository_test extends \advanced_testcase {
    /**
     * Exercise real commits and rollbacks rather than PHPUnit's enclosing transaction.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest();
    }

    /**
     * Create a real LTI course module.
     */
    private function create_lti(): \stdClass {
        return $this->getDataGenerator()->create_module('lti', [
            'course' => $this->getDataGenerator()->create_course()->id,
        ]);
    }

    /**
     * Bytes round-trip without normalization and the two lookup paths agree.
     */
    public function test_create_and_lookup(): void {
        $this->resetAfterTest();
        $lti = $this->create_lti();
        $repository = new config_repository();
        $this->assertNull($repository->get_by_cmid($lti->cmid));
        $this->assertNull($repository->get_by_lti_instance_id($lti->id));
        $this->assertFalse($repository->is_secure_lti_instance($lti->id));
        $xml = "<?xml version=\"1.0\"?>\r\n<plist><dict/></plist>\n";
        $key = str_repeat('a1', 32);
        $before = time();
        $created = $repository->create_for_activity($lti->cmid, $lti->id, $xml, $key);
        $stored = $repository->get_by_cmid($lti->cmid);
        $this->assertEquals($created, $stored);
        $this->assertEquals($stored, $repository->get_by_lti_instance_id($lti->id));
        $this->assertSame($xml, $stored->configxml);
        $this->assertSame($key, $stored->configkey);
        $this->assertSame(hash('sha256', $xml), $stored->configversion);
        $this->assertGreaterThanOrEqual($before, (int) $stored->timecreated);
        $this->assertLessThanOrEqual(time(), (int) $stored->timemodified);
        $this->assertEquals($stored->timecreated, $stored->timemodified);
        $this->assertTrue($repository->is_secure_lti_instance($lti->id));
    }

    /**
     * Regeneration changes generated data but preserves identity and disabled status.
     */
    public function test_replace_preserves_mapping_and_enabled_state(): void {
        global $DB;
        $this->resetAfterTest();
        $lti = $this->create_lti();
        $repository = new config_repository();
        $original = $repository->create_for_activity($lti->cmid, $lti->id, '<plist/>', str_repeat('a', 64));
        $DB->update_record('local_torussecure', (object) [
            'id' => $original->id, 'enabled' => 0, 'timecreated' => 100, 'timemodified' => 100,
        ]);
        $xml = "<plist><dict/></plist>\n";
        $key = str_repeat('b', 64);
        $updated = $repository->replace_generated_config($lti->cmid, $xml, $key);
        $this->assertEquals($updated, $repository->get_by_cmid($lti->cmid));
        $this->assertEquals($original->id, $updated->id);
        $this->assertEquals($lti->cmid, $updated->cmid);
        $this->assertEquals($lti->id, $updated->ltiinstanceid);
        $this->assertEquals(100, $updated->timecreated);
        $this->assertGreaterThan(100, (int) $updated->timemodified);
        $this->assertSame($xml, $updated->configxml);
        $this->assertSame($key, $updated->configkey);
        $this->assertSame(hash('sha256', $xml), $updated->configversion);
        $this->assertFalse($repository->is_secure_lti_instance($lti->id));
        $again = $repository->replace_generated_config($lti->cmid, $xml, $key);
        $this->assertSame($updated->configversion, $again->configversion);
        $this->assertEquals(1, $DB->count_records('local_torussecure'));
    }

    /**
     * Invalid configuration must neither create a row nor change an existing row.
     */
    #[DataProvider('invalid_configuration_provider')]
    public function test_invalid_configuration(string $xml, string $key, string $error): void {
        global $DB;
        $this->resetAfterTest();
        $lti = $this->create_lti();
        $repository = new config_repository();
        $this->assert_rejected($error, fn() => $repository->create_for_activity($lti->cmid, $lti->id, $xml, $key));
        $this->assertEquals(0, $DB->count_records('local_torussecure'));
        $original = $repository->create_for_activity($lti->cmid, $lti->id, '<plist/>', str_repeat('a', 64));
        $this->assert_rejected($error, fn() => $repository->replace_generated_config($lti->cmid, $xml, $key));
        $this->assertEquals($original, $repository->get_by_cmid($lti->cmid));
    }

    /**
     * Invalid bytes and keys, including boundaries and a trailing newline.
     */
    public static function invalid_configuration_provider(): array {
        return [
            'empty XML' => ['', str_repeat('a', 64), 'emptyconfiguration'],
            'whitespace XML' => [" \r\n\t", str_repeat('a', 64), 'emptyconfiguration'],
            'empty key' => ['<plist/>', '', 'invalidconfigkey'],
            'short key' => ['<plist/>', str_repeat('a', 63), 'invalidconfigkey'],
            'long key' => ['<plist/>', str_repeat('a', 65), 'invalidconfigkey'],
            'uppercase key' => ['<plist/>', str_repeat('A', 64), 'invalidconfigkey'],
            'nonhex key' => ['<plist/>', str_repeat('g', 64), 'invalidconfigkey'],
            'newline key' => ['<plist/>', str_repeat('a', 64) . "\n", 'invalidconfigkey'],
        ];
    }

    /**
     * Stale or incorrect activity mappings are rejected on both write paths.
     */
    #[DataProvider('invalid_mapping_provider')]
    public function test_invalid_mapping(string $scenario): void {
        global $DB;
        $this->resetAfterTest();
        $lti = $this->create_lti();
        $repository = new config_repository();
        $original = $repository->create_for_activity($lti->cmid, $lti->id, '<plist/>', str_repeat('a', 64));
        switch ($scenario) {
            case 'missing module':
                $DB->delete_records('course_modules', ['id' => $lti->cmid]);
                break;
            case 'wrong type':
                $labelid = $DB->get_field('modules', 'id', ['name' => 'label'], MUST_EXIST);
                $DB->set_field('course_modules', 'module', $labelid, ['id' => $lti->cmid]);
                break;
            case 'wrong instance':
                $other = $this->create_lti();
                $DB->set_field('course_modules', 'instance', $other->id, ['id' => $lti->cmid]);
                break;
            case 'missing instance':
                $DB->delete_records('lti', ['id' => $lti->id]);
                break;
            case 'wrong course':
                $course = $this->getDataGenerator()->create_course();
                $DB->set_field('lti', 'course', $course->id, ['id' => $lti->id]);
                break;
        }
        $this->assert_rejected('invalidactivitymapping', fn() =>
            $repository->replace_generated_config($lti->cmid, '<plist><dict/></plist>', str_repeat('b', 64)));
        $this->assertEquals($original, $repository->get_by_cmid($lti->cmid));
        $DB->delete_records('local_torussecure', ['id' => $original->id]);
        $this->assert_rejected('invalidactivitymapping', fn() =>
            $repository->create_for_activity($lti->cmid, $lti->id, '<plist/>', str_repeat('a', 64)));
        $this->assertEquals(0, $DB->count_records('local_torussecure'));
    }

    /**
     * Mapping corruptions that can occur after initial configuration.
     */
    public static function invalid_mapping_provider(): array {
        return array_map(fn($scenario) => [$scenario], [
            'missing module', 'wrong type', 'wrong instance', 'missing instance', 'wrong course',
        ]);
    }

    /**
     * Duplicate creation does not silently overwrite a configuration.
     */
    public function test_duplicate_and_missing_configuration(): void {
        global $DB;
        $this->resetAfterTest();
        $lti = $this->create_lti();
        $repository = new config_repository();
        $this->assert_rejected('configurationmissing', fn() =>
            $repository->replace_generated_config($lti->cmid, '<plist/>', str_repeat('a', 64)));
        $original = $repository->create_for_activity($lti->cmid, $lti->id, '<plist/>', str_repeat('a', 64));
        $this->assert_rejected('configurationexists', fn() =>
            $repository->create_for_activity($lti->cmid, $lti->id, '<plist><dict/></plist>', str_repeat('b', 64)));
        $this->assertEquals($original, $repository->get_by_cmid($lti->cmid));
        $this->assertEquals(1, $DB->count_records('local_torussecure'));
    }

    /**
     * Repository commits participate in the provisioner's surrounding transaction.
     */
    public function test_outer_transaction_rollback(): void {
        global $DB;
        $this->resetAfterTest();
        $lti = $this->create_lti();
        $repository = new config_repository();
        $transaction = $DB->start_delegated_transaction();
        $repository->create_for_activity($lti->cmid, $lti->id, '<plist/>', str_repeat('a', 64));
        $repository->replace_generated_config($lti->cmid, '<plist><dict/></plist>', str_repeat('b', 64));
        try {
            $transaction->rollback(new \moodle_exception('notimplemented', 'local_torussecure'));
        } catch (\moodle_exception $exception) {
            $this->assertSame('notimplemented', $exception->errorcode);
        }
        $this->assertNull($repository->get_by_cmid($lti->cmid));
        $this->assertFalse($repository->is_secure_lti_instance($lti->id));
    }

    /**
     * Assert a safe error without exposing input bytes or key contents.
     */
    private function assert_rejected(string $error, callable $operation): void {
        try {
            $operation();
            $this->fail('Expected configuration write to be rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame($error, $exception->errorcode);
        }
    }
}
