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

use local_torussecure\local\restore_policy;
use local_torussecure\local\seb_config_builder;

/**
 * Restore must preserve policy or reject it, never silently weaken it.
 */
#[\PHPUnit\Framework\Attributes\Group('lifecycle')]
#[\PHPUnit\Framework\Attributes\CoversClass(restore_policy::class)]
final class restore_policy_test extends \advanced_testcase {
    public function test_minimal_and_explicit_policies_roundtrip(): void {
        $builder = new seb_config_builder();
        foreach (
            [[], [
            'allowQuit' => false, 'browserWindowAllowReload' => false, 'browserMediaCaptureCamera' => true,
            'hashedQuitPassword' => hash('sha256', 'example'), 'allowedDisplaysMaxNumber' => 3,
            'additionalOrigins' => ['https://media.example.org:8443'], 'quitURL' => 'https://exit.example.org/done',
            ]] as $options
        ) {
            $xml = $builder->build(123, 'https://moodle.example.org:8443', 'https://torus.example.org', $options);
            $policy = restore_policy::decode($xml, 123);
            $this->assertSame('https://moodle.example.org:8443', $policy['moodleorigin']);
            $this->assertSame('https://torus.example.org', $policy['torusorigin']);
            $this->assertSame($xml, $builder->build(123, $policy['moodleorigin'], $policy['torusorigin'], $policy['options']));
        }
    }

    public function test_dictionary_key_order_is_not_significant(): void {
        $builder = new seb_config_builder();
        $xml = $builder->build(123, 'https://moodle.example.org', 'https://torus.example.org');
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $dict = $document->documentElement->firstElementChild;
        $key = $dict->firstElementChild;
        $value = $key->nextElementSibling;
        $dict->appendChild($key);
        $dict->appendChild($value);
        $this->assertSame('https://torus.example.org', restore_policy::decode($document->saveXML(), 123)['torusorigin']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_policy_provider')]
    public function test_unsupported_policy_fails_closed(string $case): void {
        $xml = (new seb_config_builder())->build(123, 'https://moodle.example.org', 'https://torus.example.org');
        $xml = match ($case) {
            'unknown' => str_replace('<key>startURL</key>', '<key>unknownSecuritySetting</key><true/><key>startURL</key>', $xml),
            'duplicate' => str_replace('<key>startURL</key>', '<key>allowQuit</key><true/><key>startURL</key>', $xml),
            'mandatory' => str_replace('<key>allowSwitchToApplications</key>\n    <false/>', '', $xml),
            'entity' => '<!DOCTYPE plist [<!ENTITY a SYSTEM "file:///etc/passwd">]>' . $xml,
            'malformed' => '<plist><dict>',
            'wrongid' => str_replace('cmid=123', 'cmid=456', $xml),
            'extra-rule' => str_replace('https://torus', 'http://torus', $xml),
            'oversize' => str_repeat(' ', 1048577),
            default => throw new \coding_exception('Unknown case'),
        };
        if ($case === 'mandatory') {
            $xml = str_replace('<key>allowSwitchToApplications</key>', '<key>allowOtherApplications</key>', $xml);
        }
        $this->expectException(\moodle_exception::class);
        restore_policy::decode($xml, 123);
    }

    /**
     * Unsupported or malformed source policies.
     */
    public static function invalid_policy_provider(): array {
        return array_map(static fn($case) => [$case], [
            'unknown', 'duplicate', 'mandatory', 'entity', 'malformed', 'wrongid', 'extra-rule', 'oversize',
        ]);
    }
}
