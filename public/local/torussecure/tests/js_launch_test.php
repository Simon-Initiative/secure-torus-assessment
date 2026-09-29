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
 * SEB JavaScript proof and short-lived launch authorization tests.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure;

use local_torussecure\local\config_repository;
use local_torussecure\local\js_launch;
use local_torussecure\local\seb_request;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verify the modern-client flow without accepting a browser identity string as proof.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(js_launch::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(seb_request::class)]
final class js_launch_test extends \advanced_testcase {
    /** @var \stdClass Stored configuration. */
    private \stdClass $record;
    /** @var \stdClass Enrolled student. */
    private \stdClass $student;
    /** @var mixed Canonical URL to restore. */
    private mixed $oldfullme;
    /** @var array Headers to restore. */
    private array $oldserver;

    protected function setUp(): void {
        global $FULLME;
        parent::setUp();
        $this->resetAfterTest();
        $this->oldfullme = $FULLME;
        $this->oldserver = $_SERVER;
        unset($_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']);
        set_config('enableavailability', 1);
        set_config('moodleorigin', 'https://moodle.test:8443', 'local_torussecure');
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', [
            'course' => $course->id,
            'availability' => json_encode(['op' => '&', 'c' => [['type' => 'torusseb']], 'showc' => [true]]),
        ]);
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id);
        $this->setUser($this->student);
        $this->record = (new config_repository())->create_for_activity($lti->cmid, $lti->id, '<plist/>', str_repeat('a', 64));
    }

    protected function tearDown(): void {
        global $FULLME;
        $FULLME = $this->oldfullme;
        $_SERVER = $this->oldserver;
        parent::tearDown();
    }

    /**
     * A valid JS proof permits the native launch path but not unrelated Moodle navigation.
     */
    public function test_verified_launch_and_consumption(): void {
        global $FULLME;
        $context = \context_module::instance($this->record->cmid);
        $FULLME = 'https://moodle.test:8443/mod/lti/view.php?id=' . $this->record->cmid;
        $this->assertSame('missing_header', seb_request::validate($this->record, $this->student, $context));
        $url = js_launch::begin($this->record);
        $this->assertStringNotContainsString($this->record->configkey, $url);
        $this->assertTrue(js_launch::verify($this->record, $url, hash('sha256', $url . $this->record->configkey), sesskey()));
        $this->assertSame('valid_js', seb_request::validate($this->record, $this->student, $context));
        get_fast_modinfo(0, 0, true);
        $this->assertTrue(get_fast_modinfo(get_coursemodule_from_id('lti', $this->record->cmid)->course)
            ->get_cm($this->record->cmid)->uservisible);
        foreach (
            [
            '/course/view.php?id=1', '/local/torussecure/diagnostics.php?cmid=' . $this->record->cmid,
            '/mod/lti/view.php?id=999999',
            ] as $path
        ) {
            $this->assertFalse(js_launch::permits($this->record, 'https://moodle.test:8443' . $path, $this->student->id));
        }
        $this->assertFalse(js_launch::permits($this->record, 'https://other.test/mod/lti/auth.php', $this->student->id));
        js_launch::consume($this->record->cmid);
        $this->assertSame('missing_header', seb_request::validate($this->record, $this->student, $context));
        $this->assertFalse(js_launch::verify($this->record, $url, hash('sha256', $url . $this->record->configkey), sesskey()));
    }

    /**
     * A previously valid proof cannot satisfy a different URL, user, configuration or challenge.
     */
    #[DataProvider('rejection_provider')]
    public function test_rejected_proof(string $change): void {
        global $SESSION, $DB;
        $url = js_launch::begin($this->record);
        $proof = hash('sha256', $url . $this->record->configkey);
        switch ($change) {
            case 'url':
                $url .= '&other=1';
                break;
            case 'wrong':
                $proof = str_repeat('0', 64);
                break;
            case 'uppercase':
                $proof = strtoupper($proof);
                break;
            case 'expired':
                $SESSION->torussecure_challenges[$this->record->cmid]['created'] -= 121;
                break;
            case 'user':
                $SESSION->torussecure_challenges[$this->record->cmid]['userid']++;
                break;
            case 'configuration':
                $this->record = (new config_repository())->replace_generated_config(
                    $this->record->cmid,
                    '<plist><dict/></plist>',
                    str_repeat('b', 64)
                );
                break;
            case 'replacement':
                js_launch::begin($this->record);
                break;
        }
        $this->assertFalse(js_launch::verify($this->record, $url, $proof, sesskey()));
        $this->assertFalse(js_launch::permits($this->record, 'https://moodle.test:8443/mod/lti/auth.php', $this->student->id));
    }

    /**
     * Supply independent proof-binding failures.
     */
    public static function rejection_provider(): array {
        $cases = ['url', 'wrong', 'uppercase', 'expired', 'user', 'configuration', 'replacement'];
        return array_map(static fn($value) => [$value], $cases);
    }

    /**
     * Session-bound authorization expires and is invalidated by configuration replacement.
     */
    public function test_grant_binding(): void {
        global $SESSION;
        $url = js_launch::begin($this->record);
        $this->assertTrue(js_launch::verify($this->record, $url, hash('sha256', $url . $this->record->configkey), sesskey()));
        $target = 'https://moodle.test:8443/mod/lti/auth.php';
        $this->assertTrue(js_launch::permits($this->record, $target, $this->student->id));
        $this->assertFalse(js_launch::permits($this->record, $target, $this->student->id + 1));
        $changed = clone $this->record;
        $changed->enabled = 0;
        $this->assertFalse(js_launch::permits($changed, $target, $this->student->id));
        $changed = clone $this->record;
        $changed->configversion = 'changed';
        $this->assertFalse(js_launch::permits($changed, $target, $this->student->id));
        $SESSION->torussecure_grants[$this->record->cmid]['created'] -= 121;
        $this->assertFalse(js_launch::permits($this->record, $target, $this->student->id));
    }

    /**
     * An invalid CSRF token cannot authorize a launch even with correct proof.
     */
    public function test_csrf_required(): void {
        $url = js_launch::begin($this->record);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidsesskey', 'error'));
        js_launch::verify($this->record, $url, hash('sha256', $url . $this->record->configkey), 'wrong');
    }

    /**
     * Unenrolled users cannot obtain a challenge for an assessment.
     */
    public function test_access_required(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\moodle_exception::class);
        js_launch::begin($this->record);
    }
}
