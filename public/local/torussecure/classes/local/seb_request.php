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


namespace local_torussecure\local;

/**
 * Obtain canonical request inputs without trusting forwarding headers.
 */
class seb_request {
    /**
     * Verify current headers or a narrowly scoped authorization from the SEB JavaScript API.
     */
    public static function validate(?\stdClass $record, \stdClass $user, \context_module $context): string {
        $url = self::current_url();
        $result = (new seb_request_validator())->validate($record, $url, self::headers(), $user, $context);
        if ($result === 'missing_header' && js_launch::permits($record, $url, (int) $user->id)) {
            return 'valid_js';
        }
        return $result;
    }

    /**
     * Resolve Moodle's canonical request URL, optionally replacing only its configured public origin.
     */
    public static function current_url(): string {
        global $FULLME;
        if (!is_string($FULLME) || !self::is_absolute_url($FULLME)) {
            return '';
        }
        $origin = get_config('local_torussecure', 'moodleorigin');
        if ($origin === false || $origin === '') {
            return self::is_public_url($FULLME) ? $FULLME : '';
        }
        if (!self::is_public_url($origin)) {
            return '';
        }
        $parts = parse_url($origin);
        if (!in_array($parts['path'] ?? '', ['', '/'], true) || isset($parts['query'])) {
            return '';
        }
        // Keep the raw path and query, including their order, encoding, repeated keys and empty query.
        $target = preg_replace('~\Ahttps?://[^/?#]+~', '', $FULLME);
        $url = rtrim($origin, '/') . $target;
        return self::is_public_url($url) ? $url : '';
    }

    /**
     * Read only the PHP-normalized SEB header; no User-Agent or forwarding-header fallback.
     */
    public static function headers(): array {
        return array_key_exists('HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH', $_SERVER)
            ? ['X-SafeExamBrowser-ConfigKeyHash' => $_SERVER['HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH']]
            : [];
    }

    /**
     * Validate an HTTPS URL without normalizing any of the bytes used in the request hash.
     */
    public static function is_public_url(string $url): bool {
        return self::is_absolute_url($url) && parse_url($url, PHP_URL_SCHEME) === 'https';
    }

    /**
     * Accept canonical HTTP input only so a trusted explicit public origin can replace a proxy origin.
     */
    private static function is_absolute_url(string $url): bool {
        if (preg_match('/[\x00-\x20\x7f]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return $parts !== false && in_array($parts['scheme'] ?? '', ['http', 'https'], true) &&
            !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) &&
            !isset($parts['fragment']) && (!isset($parts['port']) || $parts['port'] > 0);
    }
}
