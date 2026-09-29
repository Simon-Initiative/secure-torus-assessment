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
 * Secure launch and signed-claim verification.
 *
 * @package    ltisource_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace ltisource_torussecure;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verify secure claims through the real Moodle launch and signing path.
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('ltisource_torussecure_before_launch')]
final class secure_launch_test extends \advanced_testcase {
    /** @var \stdClass Test LTI activity. */
    private \stdClass $lti;
    /** @var \stdClass Stored activity configuration. */
    private \stdClass $record;
    /** @var mixed Canonical request URL to restore. */
    private mixed $oldfullme;
    /** @var array Server globals to restore. */
    private array $oldserver;

    protected function setUp(): void {
        global $CFG, $DB, $FULLME, $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->oldfullme = $FULLME;
        $this->oldserver = $_SERVER;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        require_once($CFG->dirroot . '/mod/lti/source/torussecure/lib.php');
        $this->setAdminUser();
        set_config('torusorigin', 'https://torus.test', 'local_torussecure');
        $course = $this->getDataGenerator()->create_course();
        $typeid = $this->getDataGenerator()->get_plugin_generator('mod_lti')->create_tool_types([
            'name' => 'Torus', 'baseurl' => 'https://torus.test/lti/launch',
            'ltiversion' => LTI_VERSION_1P3, 'state' => LTI_TOOL_STATE_CONFIGURED,
        ]);
        $lti = $this->getDataGenerator()->create_module('lti', [
            'course' => $course->id, 'typeid' => $typeid, 'toolurl' => 'https://torus.test/lti/launch',
            'launchcontainer' => LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW,
            'instructorcustomparameters' => "secure_delivery=forged\nsecure_activity_id=forged\nexisting=preserved" .
                "\ntorus_resource_type=page\ntorus_resource_id=new_assessment",
        ]);
        $this->lti = $DB->get_record('lti', ['id' => $lti->id], '*', MUST_EXIST);
        $this->record = (new \local_torussecure\local\config_repository())->create_for_activity(
            $lti->cmid,
            $lti->id,
            '<plist/>',
            str_repeat('a', 64)
        );
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);
        $PAGE->set_course($course);
        $FULLME = 'https://moodle.test:8443/mod/lti/auth.php?state=example&x=a%2Fb';
        $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH'] = hash('sha256', $FULLME . $this->record->configkey);
    }

    protected function tearDown(): void {
        global $FULLME;
        $FULLME = $this->oldfullme;
        $_SERVER = $this->oldserver;
        parent::tearDown();
    }

    public function test_signed_custom_claims(): void {
        $before = time();
        [$endpoint, $params] = lti_get_launch_data($this->lti, 'test-nonce');
        $this->assertSame('https://torus.test/lti/launch', $endpoint);
        // Verify the signature as well as decoding the payload.
        $private = \mod_lti\local\ltiopenid\jwks_helper::get_private_key();
        $public = openssl_pkey_get_details(openssl_pkey_get_private($private['key']))['key'];
        $jwt = \Firebase\JWT\JWT::decode($params['id_token'], new \Firebase\JWT\Key($public, 'RS256'));
        $custom = (array) $jwt->{'https://purl.imsglobal.org/spec/lti/claim/custom'};
        $this->assertSame('seb', $custom['secure_delivery']);
        $this->assertSame((string) $this->record->cmid, $custom['secure_activity_id']);
        $this->assertSame($this->record->configversion, $custom['seb_configuration_id']);
        $this->assertGreaterThanOrEqual($before, (int) $custom['secure_verified_at']);
        $this->assertLessThanOrEqual(time(), (int) $custom['secure_verified_at']);
        $this->assertSame('preserved', $custom['existing']);
        $this->assertSame('page', $custom['torus_resource_type']);
        $this->assertSame('new_assessment', $custom['torus_resource_id']);
        $this->assertSame('test-nonce', $jwt->nonce);
    }

    /**
     * Modern SEB verifies once, survives the OIDC redirect, and consumes proof at signing.
     */
    public function test_js_verified_signed_launch(): void {
        set_config('moodleorigin', 'https://moodle.test:8443', 'local_torussecure');
        unset($_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']);
        $url = \local_torussecure\local\js_launch::begin($this->record);
        $this->assertTrue(\local_torussecure\local\js_launch::verify(
            $this->record,
            $url,
            hash('sha256', $url . $this->record->configkey),
            sesskey()
        ));
        $this->test_signed_custom_claims();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('securelaunchdenied', 'local_torussecure'));
        lti_get_launch_data($this->lti, 'another-nonce');
    }

    /**
     * A previous successful callback must not authorize a later request without fresh evidence.
     */
    public function test_revalidation_before_signing(): void {
        $params = ltisource_torussecure_before_launch($this->lti, 'https://torus.test:443/lti/launch', []);
        $this->assertSame('seb', $params['custom_secure_delivery']);
        unset($_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']);
        $_SERVER['HTTP_USER_AGENT'] = 'SafeExamBrowser SEB';
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('securelaunchdenied', 'local_torussecure'));
        lti_get_launch_data($this->lti, 'test-nonce');
    }

    #[DataProvider('endpoint_provider')]
    public function test_endpoint_rejection(string $endpoint): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('secureendpointinvalid', 'local_torussecure'));
        ltisource_torussecure_before_launch($this->lti, $endpoint, []);
    }

    /**
     * Supply unapproved launch endpoints.
     */
    public static function endpoint_provider(): array {
        return array_map(static fn($url) => [$url], [
            'http://torus.test/lti/launch', 'https://torus.test:8443/lti/launch',
            'https://sub.torus.test/lti/launch', 'https://torus.test.evil.example/lti/launch',
            'https://u:p@torus.test/lti/launch', 'https://torus.test/lti/launch#fragment',
        ]);
    }

    public function test_changed_request_url_rejected(): void {
        global $FULLME;
        $FULLME .= '&changed=1';
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('securelaunchdenied', 'local_torussecure'));
        ltisource_torussecure_before_launch($this->lti, 'https://torus.test/lti/launch', []);
    }

    public function test_corrupt_mapping_rejected(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_module('lti', ['course' => $this->lti->course]);
        $DB->set_field('local_torussecure', 'cmid', $other->cmid, ['id' => $this->record->id]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidactivitymapping', 'local_torussecure'));
        ltisource_torussecure_before_launch($this->lti, 'https://torus.test/lti/launch', []);
    }

    public function test_bypass_still_checks_endpoint(): void {
        $this->setAdminUser();
        set_config('enablebypass', 1, 'local_torussecure');
        unset($_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']);
        $claims = ltisource_torussecure_before_launch($this->lti, 'https://torus.test/lti/launch', []);
        $this->assertSame('seb', $claims['custom_secure_delivery']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('secureendpointinvalid', 'local_torussecure'));
        ltisource_torussecure_before_launch($this->lti, 'https://other.test/lti/launch', []);
    }

    public function test_version_corruption_rejected(): void {
        global $DB;
        $DB->set_field('local_torussecure', 'configversion', '', ['id' => $this->record->id]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidactivitymapping', 'local_torussecure'));
        ltisource_torussecure_before_launch($this->lti, 'https://torus.test/lti/launch', []);
    }
}
