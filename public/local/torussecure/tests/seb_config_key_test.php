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
 * Compatibility fixtures for the spike's bundled Config Key adapter.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure;

use local_torussecure\local\seb_config_key;

/**
 * Compare with fixed expected values, not another invocation of the delegated method.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_torussecure\local\seb_config_key::class)]
final class seb_config_key_test extends \advanced_testcase {
    /**
     * The deliberately unordered fixture hashes as sorted, compact SEB-JSON.
     */
    public function test_known_configuration(): void {
        $xml = file_get_contents(__DIR__ . '/fixtures/known.seb');
        $key = new seb_config_key();
        $expected = 'f08714a7ae16cc9a16cb7f7ee286c42fad160d3ddb8d0f0a89105b40a85e8f8e';
        $this->assertSame($expected, $key->generate($xml));
        $this->assertNotSame(hash('sha256', $xml), $expected);
        $withmetadata = str_replace('<dict>', '<dict><key>originatorVersion</key><string>spike</string>', $xml);
        $this->assertSame($expected, $key->generate($withmetadata));
        $this->assertSame($expected, $key->generate(str_replace("\n", "\r\n", $xml)));
    }

    /**
     * Existing Moodle fixture captured from SEB macOS 2.1.4, with its known client key.
     */
    public function test_bundled_client_fixture(): void {
        global $CFG;
        $xml = file_get_contents($CFG->dirroot . '/mod/quiz/accessrule/seb/tests/fixtures/unencrypted_mac_001.seb');
        $this->assertSame(
            '4fa9af8ec8759eb7c680752ef4ee5eaf1a860628608fccae2715d519849f9292',
            (new seb_config_key())->generate($xml)
        );
    }

    /**
     * Empty input must not silently use SEB defaults.
     */
    public function test_empty_configuration(): void {
        $this->expectException(\moodle_exception::class);
        (new seb_config_key())->generate(" \n\t");
    }

    /**
     * Invalid XML must not produce a usable Config Key.
     */
    public function test_invalid_configuration(): void {
        $this->expectException(\invalid_parameter_exception::class);
        (new seb_config_key())->generate('not a plist');
    }
}
