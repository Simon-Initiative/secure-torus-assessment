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
 * Short-lived SEB JavaScript verification for one native LTI launch.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure\local;

/**
 * Bind URL-salted SEB proof to the authenticated session, activity and current configuration.
 */
class js_launch {
    /** @var int Maximum challenge and launch authorization age in seconds. */
    private const LIFETIME = 120;

    /**
     * Create an unpredictable verification URL after activity access checks.
     */
    public static function begin(\stdClass $record): string {
        global $CFG, $SESSION, $USER;
        (new config_download())->get_file((int) $record->cmid);
        $origin = get_config('local_torussecure', 'moodleorigin') ?: $CFG->wwwroot;
        $url = new \moodle_url(rtrim($origin, '/') . '/local/torussecure/launch.php', [
            'cmid' => $record->cmid, 'challenge' => bin2hex(random_bytes(32)),
        ]);
        if (!seb_request::is_public_url($url->out(false))) {
            throw new \moodle_exception('invalidorigin', 'local_torussecure');
        }
        unset($SESSION->torussecure_grants[$record->cmid]);
        $SESSION->torussecure_challenges[$record->cmid] = [
            'url' => $url->out(false), 'userid' => (int) $USER->id,
            'version' => $record->configversion, 'keydigest' => hash('sha256', $record->configkey), 'created' => time(),
        ];
        return $url->out(false);
    }

    /**
     * Verify POSTed proof for the exact stored URL; consume the challenge even on failure.
     */
    public static function verify(\stdClass $record, string $url, string $proof, string $sesskey): bool {
        global $SESSION, $USER;
        if (!confirm_sesskey($sesskey)) {
            throw new \moodle_exception('invalidsesskey', 'error');
        }
        $challenge = $SESSION->torussecure_challenges[$record->cmid] ?? null;
        unset($SESSION->torussecure_challenges[$record->cmid], $SESSION->torussecure_grants[$record->cmid]);
        (new config_download())->get_file((int) $record->cmid);
        if (
            !self::matches($challenge, $record, (int) $USER->id) ||
                !seb_request::is_public_url($url) || $challenge['url'] !== $url ||
                preg_match('/\A[0-9a-f]{64}\z/', $proof) !== 1 ||
                preg_match('/\A[0-9a-f]{64}\z/', $record->configkey) !== 1 ||
                !hash_equals(hash('sha256', $url . $record->configkey), $proof)
        ) {
            return false;
        }
        $SESSION->torussecure_grants[$record->cmid] = $challenge;
        return true;
    }

    /**
     * Accept authorization only on native launch endpoints, never as general course access.
     */
    public static function permits(\stdClass $record, string $url, int $userid): bool {
        global $SESSION, $USER;
        if (
            (int) $USER->id !== $userid ||
                !self::matches($SESSION->torussecure_grants[$record->cmid] ?? null, $record, $userid)
        ) {
            return false;
        }
        $state = $SESSION->torussecure_grants[$record->cmid];
        $expected = parse_url($state['url']);
        $actual = parse_url($url);
        if (
            !seb_request::is_public_url($url) ||
                strtolower($actual['host']) !== strtolower($expected['host']) ||
                ($actual['port'] ?? 443) !== ($expected['port'] ?? 443)
        ) {
            return false;
        }
        $path = $actual['path'] ?? '';

        if ($path === '/mod/lti/auth.php') {
            return true;
        }
        if (!in_array($path, ['/mod/lti/view.php', '/mod/lti/launch.php'], true)) {
            return false;
        }
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        return is_string($query['id'] ?? null) && $query['id'] === (string) $record->cmid;
    }

    /**
     * Consume authorization at the signing boundary; reloads require a fresh verification.
     */
    public static function consume(int $cmid): void {
        global $SESSION;
        unset($SESSION->torussecure_grants[$cmid]);
    }

    /**
     * Reject expired, changed, disabled, other-user and other-configuration state.
     */
    private static function matches(?array $state, \stdClass $record, int $userid): bool {
        return $state !== null && !empty($record->enabled) && $state['userid'] === $userid &&
            $state['version'] === $record->configversion &&
            hash_equals($state['keydigest'], hash('sha256', $record->configkey)) &&
            $state['created'] <= time() && $state['created'] >= time() - self::LIFETIME;
    }
}
