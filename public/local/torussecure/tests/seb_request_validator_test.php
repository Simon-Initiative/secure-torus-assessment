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
 * Validate activity-bound SEB Config Key request evidence.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace local_torussecure;

use local_torussecure\local\seb_request;
use local_torussecure\local\seb_request_validator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verify SEB evidence and diagnostic bypass independently of quiz access rules.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\seb_request_validator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\seb_request::class)]
final class seb_request_validator_test extends \advanced_testcase {
    /** @var \stdClass Stored activity configuration. */
    private \stdClass $record;
    /** @var \stdClass Enrolled test learner. */
    private \stdClass $student;
    /** @var \context_module Activity context. */
    private \context_module $context;
    /** @var string Canonical test request URL. */
    private string $url = 'https://moodle.test:8443/mod/lti/auth.php?x=a%2Fb&x=c+d&empty=';
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
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        $this->context = \context_module::instance($lti->cmid);
        $this->record = (object) ['cmid' => $lti->cmid, 'enabled' => 1, 'configkey' => str_repeat('a', 64)];
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id);
        $this->setUser($this->student);
    }

    protected function tearDown(): void {
        global $FULLME;
        $FULLME = $this->oldfullme;
        $_SERVER = $this->oldserver;
        parent::tearDown();
    }

    /**
     * Named results distinguish absence, disabled configuration, valid evidence and missing headers.
     */
    public function test_result_codes(): void {
        $validator = new seb_request_validator();
        $this->assertSame('activity_not_configured', $validator->validate(null));
        $this->assertSame('configuration_disabled', $validator->validate((object) ['enabled' => 0]));
        $this->assertSame('invalid_header', $validator->validate($this->record));
        $this->assertSame('missing_header', $this->validate([]));
        $hash = hash('sha256', $this->url . $this->record->configkey);
        $names = ['X-SafeExamBrowser-ConfigKeyHash', 'x-safeexambrowser-configkeyhash', 'HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'];
        foreach ($names as $name) {
            $this->assertSame('valid', $this->validate([$name => $hash]));
        }
        $this->assertSame('missing_header', $this->validate(['User-Agent' => 'SafeExamBrowser SEB']));
    }

    /**
     * Reject malformed, wrong or ambiguous evidence rather than normalizing it into acceptance.
     */
    #[DataProvider('invalid_header_provider')]
    public function test_invalid_headers(mixed $header): void {
        $this->assertSame('invalid_header', $this->validate(['X-SafeExamBrowser-ConfigKeyHash' => $header]));
    }

    /**
     * Supply malformed or incorrect request evidence.
     */
    public static function invalid_header_provider(): array {
        return array_map(static fn($value) => [$value], [
            '', str_repeat('0', 64), str_repeat('a', 63), str_repeat('a', 65),
            str_repeat('A', 64), str_repeat('g', 64), str_repeat('a', 64) . "\n",
            ' ' . str_repeat('a', 64), [str_repeat('a', 64)], 123, null,
        ]);
    }

    public function test_duplicate_headers(): void {
        $hash = hash('sha256', $this->url . $this->record->configkey);
        $this->assertSame('invalid_header', $this->validate([
            'X-SafeExamBrowser-ConfigKeyHash' => $hash,
            'HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH' => $hash,
        ]));
    }

    /**
     * Exact path, port, scheme and raw query bytes form part of the proof.
     */
    #[DataProvider('changed_url_provider')]
    public function test_hash_binds_exact_url(string $url): void {
        $hash = hash('sha256', $this->url . $this->record->configkey);
        $this->assertSame('invalid_header', (new seb_request_validator())->validate(
            $this->record,
            $url,
            ['X-SafeExamBrowser-ConfigKeyHash' => $hash],
            $this->student,
            $this->context
        ));
    }

    /**
     * Supply URLs that must not match the original request hash.
     */
    public static function changed_url_provider(): array {
        return array_map(static fn($url) => [$url], [
            'http://moodle.test:8443/mod/lti/auth.php?x=a%2Fb&x=c+d&empty=',
            'https://moodle.test/mod/lti/auth.php?x=a%2Fb&x=c+d&empty=',
            'https://other.test:8443/mod/lti/auth.php?x=a%2Fb&x=c+d&empty=',
            'https://moodle.test:8443/mod/lti/view.php?x=a%2Fb&x=c+d&empty=',
            'https://moodle.test:8443/mod/lti/auth.php?empty=&x=a%2Fb&x=c+d',
            'https://moodle.test:8443/mod/lti/auth.php?x=a/b&x=c%20d&empty=',
            'https://moodle.test:8443/mod/lti/auth.php?x=a%2fb&x=c+d&empty=',
            '',
        ]);
    }

    /**
     * Bypass requires both the setting and the activity-context capability.
     */
    public function test_bypass(): void {
        global $DB;
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($roleid, $manager->id, $this->context->id);
        $validator = new seb_request_validator();
        $this->assertSame('missing_header', $validator->validate(
            $this->record,
            $this->url,
            [],
            $manager,
            $this->context
        ));
        set_config('enablebypass', 1, 'local_torussecure');
        $this->assertSame('missing_header', $this->validate([]));
        $this->assertSame('bypassed', $validator->validate(
            $this->record,
            $this->url,
            [],
            $manager,
            $this->context
        ));
        $this->record->enabled = 0;
        $this->assertSame('configuration_disabled', $validator->validate(
            $this->record,
            $this->url,
            [],
            $manager,
            $this->context
        ));
        $this->assertSame('activity_not_configured', $validator->validate(null, $this->url, [], $manager, $this->context));
    }

    /**
     * Correct hashes cannot compensate for a wrong activity context or a stale configuration key.
     */
    public function test_context_and_key_binding(): void {
        $hash = hash('sha256', $this->url . $this->record->configkey);
        $headers = ['X-SafeExamBrowser-ConfigKeyHash' => $hash];
        $this->record->cmid++;
        $this->assertSame('invalid_header', $this->validate($headers));
        $this->record->cmid--;
        $this->record->configkey = str_repeat('b', 64);
        $this->assertSame('invalid_header', $this->validate($headers));
        $this->record->configkey = '';
        $this->assertSame('invalid_header', $this->validate($headers));
    }

    /**
     * Matching evidence cannot legitimize an invalid public URL.
     */
    public function test_invalid_urls_with_matching_hashes(): void {
        foreach (
            ['', 'http://moodle.test/path', 'https://u:p@moodle.test/path',
                'https://moodle.test/path#fragment', "https://moodle.test/path\n"] as $url
        ) {
            $this->assertSame('invalid_header', (new seb_request_validator())->validate(
                $this->record,
                $url,
                ['X-SafeExamBrowser-ConfigKeyHash' => hash('sha256', $url . $this->record->configkey)],
                $this->student,
                $this->context
            ));
        }
    }

    /**
     * Anonymous, guest and suspended users cannot authorize with a known request hash.
     */
    public function test_user_requirements(): void {
        $hash = hash('sha256', $this->url . $this->record->configkey);
        foreach ([null, guest_user(), (object) ['id' => $this->student->id, 'suspended' => 1]] as $user) {
            $this->assertSame('invalid_header', (new seb_request_validator())->validate(
                $this->record,
                $this->url,
                ['X-SafeExamBrowser-ConfigKeyHash' => $hash],
                $user,
                $this->context
            ));
        }
    }

    /**
     * Canonical request beats PAGE URL and untrusted Host/forwarding headers.
     */
    public function test_canonical_url_and_headers(): void {
        global $FULLME, $PAGE;
        $FULLME = $this->url;
        $PAGE->set_url('/mod/lti/auth.php');
        $_SERVER['HTTP_HOST'] = 'attacker.test';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.test';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $_SERVER['HTTP_FORWARDED'] = 'host=attacker.test;proto=http';
        $hash = hash('sha256', $this->url . $this->record->configkey);
        $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] = $hash;
        $this->assertSame($this->url, seb_request::current_url());
        $this->assertSame('valid', (new seb_request_validator())->validate(
            $this->record,
            seb_request::current_url(),
            seb_request::headers(),
            $this->student,
            $this->context
        ));
        unset($_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']);
        $this->assertSame([], seb_request::headers());
        $FULLME = null;
        $this->assertSame('', seb_request::current_url());
    }

    /**
     * Trusted proxy override replaces only the origin and does not reserialize the query.
     */
    public function test_public_origin_override(): void {
        global $FULLME;
        $FULLME = 'http://internal:8080/mod/lti/auth.php?x=a%2Fb&x=c+d&empty=';
        $this->assertSame('', seb_request::current_url());
        set_config('moodleorigin', 'https://moodle.test:8443/', 'local_torussecure');
        $this->assertSame($this->url, seb_request::current_url());
        $hash = hash('sha256', $this->url . $this->record->configkey);
        $this->assertSame('valid', (new seb_request_validator())->validate(
            $this->record,
            seb_request::current_url(),
            ['X-SafeExamBrowser-ConfigKeyHash' => $hash],
            $this->student,
            $this->context
        ));
        $FULLME = 'http://internal:8080/mod/lti/auth.php?';
        $this->assertSame('https://moodle.test:8443/mod/lti/auth.php?', seb_request::current_url());
        $origins = ['http://public.test', 'https://public.test/path', 'https://u:p@public.test', 'https://public.test?q=1'];
        foreach ($origins as $origin) {
            set_config('moodleorigin', $origin, 'local_torussecure');
            $this->assertSame('', seb_request::current_url());
        }
    }

    /**
     * Validate the supplied input.
     */
    private function validate(array $headers): string {
        return (new seb_request_validator())->validate(
            $this->record,
            $this->url,
            $headers,
            $this->student,
            $this->context
        );
    }
}
