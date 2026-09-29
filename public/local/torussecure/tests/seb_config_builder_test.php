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
 * Configuration builder verification.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure;

use local_torussecure\local\seb_config_builder;
use local_torussecure\local\seb_config_key;
use local_torussecure\local\config_repository;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verify typed policy, deterministic output and URL rule boundaries.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\seb_config_builder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\seb_config_key::class)]
final class seb_config_builder_test extends \advanced_testcase {
    /**
     * The same inputs produce identical bytes and actual plist boolean/integer values.
     */
    public function test_policy_and_determinism(): void {
        $builder = new seb_config_builder();
        $xml = $builder->build(42, 'https://moodle.test:8443', 'https://torus.test');
        $this->assertSame($xml, $builder->build(42, 'https://moodle.test:8443/', 'https://TORUS.test:443/'));
        $plist = new \quizaccess_seb\property_list($xml);
        foreach (
            [
            'sebConfigPurpose' => 0,
            'startURL' => 'https://moodle.test:8443/local/torussecure/launch.php?cmid=42',
            'sendBrowserExamKey' => true,
            'browserWindowWebView' => 3,
            'examSessionClearCookiesOnStart' => false,
            'browserWindowAllowReload' => true,
            'URLFilterEnable' => true,
            'URLFilterEnableContentFilter' => true,
            'allowQuit' => true,
            'allowSwitchToApplications' => false,
            ] as $name => $expected
        ) {
            $this->assertSame($expected, $plist->get_element_value($name), $name);
        }
        $key = new seb_config_key();
        $this->assertSame(
            $key->generate($xml),
            $key->generate($builder->build(42, 'https://moodle.test:8443', 'https://torus.test'))
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key->generate($xml));
        $this->assertNotSame($key->generate($xml), $key->generate(
            $builder->build(43, 'https://moodle.test:8443', 'https://torus.test')
        ));
    }

    /**
     * Instructor controls alter the plist while required launch and filter controls remain fixed.
     */
    public function test_instructor_settings_are_applied(): void {
        $options = \local_torussecure\local\seb_settings::from_data((object) [
            'seb_allowquit' => 0,
            'seb_allowreload' => 0,
            'seb_showtaskbar' => 0,
            'seb_audio' => 1,
            'seb_mute' => 1,
            'seb_camera' => 1,
            'seb_maxdisplays' => 2,
            'seb_quitpassword' => 'exit test',
            'seb_quiturl' => 'https://example.org/done',
            'seb_allowedurls' => "https://example.org\nhttps://media.example.org:9443",
        ]);
        $xml = (new seb_config_builder())->build(42, 'https://moodle.test:8443', 'https://torus.test', $options);
        $plist = new \quizaccess_seb\property_list($xml);
        foreach ([
            'allowQuit' => false,
            'browserWindowAllowReload' => false,
            'showTaskBar' => false,
            'audioControlEnabled' => true,
            'audioMute' => true,
            'browserMediaCaptureCamera' => true,
            'allowedDisplaysMaxNumber' => 2,
            'startURL' => 'https://moodle.test:8443/local/torussecure/launch.php?cmid=42',
            'URLFilterEnable' => true,
            'sendBrowserExamKey' => true,
        ] as $key => $expected) {
            $this->assertSame($expected, $plist->get_element_value($key), $key);
        }
        $this->assertSame(hash('sha256', 'exit test'), $plist->get_element_value('hashedQuitPassword'));
        $this->assertStringNotContainsString('exit test', $xml);
        $rules = $plist->get_element_value('URLFilterRules');
        foreach ([
            'https://moodle.test:8443/local/torussecure/launch.php?cmid=42',
            'https://torus.test/lti/launch',
            'https://example.org/page',
            'https://media.example.org:9443/video',
            'https://example.org/done',
        ] as $url) {
            $this->assertTrue($this->matches_rule($rules, $url), $url);
        }
        $this->assertFalse($this->matches_rule($rules, 'https://media.example.org:9444/video'));
    }

    /**
     * Caller options cannot replace the activity's start URL or turn off URL filtering.
     */
    public function test_required_policy_is_not_overridden(): void {
        $xml = (new seb_config_builder())->build(42, 'https://moodle.test:8443', 'https://torus.test', [
            'startURL' => 'https://evil.test', 'URLFilterEnable' => false,
            'allowSwitchToApplications' => true, 'sendBrowserExamKey' => false,
        ]);
        $plist = new \quizaccess_seb\property_list($xml);
        $this->assertSame(
            'https://moodle.test:8443/local/torussecure/launch.php?cmid=42',
            $plist->get_element_value('startURL')
        );
        $this->assertTrue($plist->get_element_value('URLFilterEnable'));
        $this->assertTrue($plist->get_element_value('sendBrowserExamKey'));
        $this->assertFalse($plist->get_element_value('allowSwitchToApplications'));
    }

    /**
     * Match the generated SEB expressions as the URL rule test does.
     */
    private function matches_rule(array $rules, string $url): bool {
        foreach ($rules as $rule) {
            if (preg_match('~' . $rule['expression'] . '~', $url) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Exercise the actual generated regular expressions, not a parallel allowlist.
     */
    #[DataProvider('url_provider')]
    public function test_url_rules(string $url, bool $expected): void {
        $xml = (new seb_config_builder())->build(42, 'https://moodle.test:8443', 'https://torus.test');
        $rules = (new \quizaccess_seb\property_list($xml))->get_element_value('URLFilterRules');
        $allowed = false;
        foreach ($rules as $rule) {
            $this->assertSame(1, $rule['action']);
            $this->assertTrue($rule['active']);
            $this->assertTrue($rule['regex']);
            $allowed = preg_match('~' . $rule['expression'] . '~', $url) === 1 || $allowed;
        }
        $this->assertSame($expected, $allowed, $url);
    }

    /**
     * Allowed launch/resources and disallowed origin/path near misses.
     */
    public static function url_provider(): array {
        return [
            ['https://moodle.test:8443/mod/lti/view.php?id=42', true],
            ['https://moodle.test:8443/mod/lti/launch.php?id=42&triggerview=0', true],
            ['https://moodle.test:8443/mod/lti/auth.php?state=example', true],
            ['https://moodle.test:8443/mod/lti/return.php?course=2', true],
            ['https://moodle.test:8443/login/index.php', true],
            ['https://moodle.test:8443/login/logout.php?loginpage=1', true],
            ['https://moodle.test:8443/lib/ajax/service-nologin.php?info=core_output_load_template', true],
            ['https://moodle.test:8443/local/torussecure/launch.php?cmid=42&challenge=abc', true],
            ['https://moodle.test:8443/local/torussecure/js/verify.js', true],
            ['https://moodle.test:8443/course/cms/42/restricted', true],
            ['https://moodle.test:8443/course/cms/43/restricted', false],
            ['https://moodle.test:8443/local/torussecure/launch.php.evil', false],
            ['https://moodle.test:8443/login/logout.php.evil', false],
            ['https://moodle.test:8443/local/torussecure/diagnostics.php?cmid=42', true],
            ['https://moodle.test:8443/local/torussecure/config.php?cmid=42', true],
            ['https://moodle.test:8443/local/torussecure/diagnostics.php.evil', false],
            ['https://moodle.test:8443/local/torussecure/create.php?courseid=2', false],
            ['https://moodle.test:8443/theme/styles.php/boost/123/all', true],
            ['https://moodle.test:8443/lib/requirejs.php/123/core/first.js', true],
            ['https://moodle.test:8443/pluginfile.php/1/theme_boost/logo/logo.png', true],
            ['https://torus.test/lti/launch', true],
            ['https://torus.test:443/assets/app.js', true],
            ['wss://torus.test/live/websocket?vsn=2.0.0', true],
            ['https://torus.test.evil.example/lti/launch', false],
            ['https://sub.torus.test/lti/launch', false],
            ['https://torus.test:444/lti/launch', false],
            ['https://torusXtest/lti/launch', false],
            ['https://torus.test@evil.example/lti/launch', false],
            ['http://torus.test/lti/launch', false],
            ['ws://torus.test/live/websocket', false],
            ['https://moodle.test/mod/lti/view.php?id=42', false],
            ['https://moodle.test:8443/admin/index.php', false],
            ['https://moodle.test:8443/mod/lti/view.php.evil', false],
            ['https://moodle.test:8443/mod/forum/view.php?id=42', false],
            ['https://unapproved-cdn.example/asset.js', false],
            ['https://cdnjs.cloudflare.com/ajax/libs/react/17.0.2/umd/react.development.js', true],
            ['https://cdnjs.cloudflare.com/ajax/libs/react-dom/17.0.2/umd/react-dom.production.min.js', true],
            ['https://cdnjs.cloudflare.com/ajax/libs/nprogress/0.2.0/nprogress.min.js', true],
            ['https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.1/webfonts/fa-solid-900.woff2', true],
            ['https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.4.0/highlight.min.js', true],
            ['https://cdnjs.cloudflare.com/ajax/libs/highlightjs-line-numbers.js/2.9.0/highlightjs-line-numbers.min.js', true],
            ['https://cdnjs.cloudflare.com/polyfill/v3/polyfill.min.js?features=es6', true],
            ['https://code.jquery.com/jquery-3.5.1.slim.min.js', true],
            ['https://cdn.jsdelivr.net/npm/citation-js', true],
            ['https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js', true],
            ['https://cdn.jsdelivr.net/npm/mathjax@3/es5/output/chtml/fonts/woff-v2/MathJax_Main-Regular.woff', true],
            ['https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css', true],
            ['https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap', true],
            ['https://fonts.gstatic.com/s/inter/v20/example.woff2', true],
            ['https://fonts.gstatic.com/s/opensans/v44/example.woff2', true],
            ['https://cdnjs.cloudflare.com/ajax/libs/unrelated/1/app.js', false],
            ['https://cdnjs.cloudflare.com.evil.test/ajax/libs/react/17.0.2/umd/react.development.js', false],
            ['https://cdnjs.cloudflare.com:444/ajax/libs/react/17.0.2/umd/react.development.js', false],
            ['http://cdnjs.cloudflare.com/ajax/libs/react/17.0.2/umd/react.development.js', false],
            ['https://cdn.jsdelivr.net/npm/citation-js-evil', false],
            ['https://cdn.jsdelivr.net/npm/unrelated', false],
            ['https://www.google.com/search?q=exam', false],
        ];
    }

    /**
     * Invalid origins are rejected regardless of which origin argument contains them.
     */
    #[DataProvider('invalid_origin_provider')]
    public function test_invalid_origin(string $origin): void {
        foreach ([[$origin, 'https://torus.test'], ['https://moodle.test:8443', $origin]] as $origins) {
            try {
                (new seb_config_builder())->build(42, ...$origins);
                $this->fail('Invalid origin was accepted');
            } catch (\moodle_exception $exception) {
                $this->assertSame('invalidorigin', $exception->errorcode);
            }
        }
    }

    /**
     * No wildcard, credential, path, query, fragment or insecure origin input.
     */
    public static function invalid_origin_provider(): array {
        return array_map(static fn($value) => [$value], [
            '', '*', 'http://torus.test', 'https://*.torus.test', 'https://u:p@torus.test',
            'https://torus.test/path', 'https://torus.test?q=1', 'https://torus.test#fragment',
            'https://torus.test:0', 'https://torus.test:65536',
        ]);
    }

    /**
     * A nondefault Torus port applies to both HTTPS and WebSocket rules.
     */
    public function test_nondefault_torus_port(): void {
        $xml = (new seb_config_builder())->build(42, 'https://moodle.test', 'https://torus.test:9443');
        $rules = (new \quizaccess_seb\property_list($xml))->get_element_value('URLFilterRules');
        $expression = end($rules)['expression'];
        $this->assertSame(1, preg_match('~' . $expression . '~', 'wss://torus.test:9443/live/websocket'));
        $this->assertSame(0, preg_match('~' . $expression . '~', 'wss://torus.test/live/websocket'));
    }

    /**
     * Builder, adapter and repository agree on the exact bytes to serve later.
     */
    public function test_persist_generated_configuration(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        $xml = (new seb_config_builder())->build($lti->cmid, 'https://moodle.test:8443', 'https://torus.test');
        $key = (new seb_config_key())->generate($xml);
        $repository = new config_repository();
        $repository->create_for_activity($lti->cmid, $lti->id, $xml, $key);
        $stored = $repository->get_by_cmid($lti->cmid);
        $this->assertSame($xml, $stored->configxml);
        $this->assertSame($key, $stored->configkey);
        $this->assertSame(hash('sha256', $xml), $stored->configversion);
    }

    /**
     * Reject a placeholder ID instead of generating a broken start URL.
     */
    public function test_invalid_cmid(): void {
        $this->expectException(\moodle_exception::class);
        (new seb_config_builder())->build(0, 'https://moodle.test:8443', 'https://torus.test');
    }
}
