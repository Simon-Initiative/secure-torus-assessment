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
 * Instructor controlled Safe Exam Browser settings for new activities.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure\local;

/**
 * Keep form defaults, validation and plist mappings in one place.
 */
class seb_settings {
    /** @var array<string, array{0: string, 1: int}> Form field to plist key and default. */
    private const BOOLEAN_FIELDS = [
        'seb_allowquit' => ['allowQuit', 1],
        'seb_confirmquit' => ['quitURLConfirm', 1],
        'seb_allowreload' => ['browserWindowAllowReload', 1],
        'seb_showtaskbar' => ['showTaskBar', 1],
        'seb_showreloadbutton' => ['showReloadButton', 1],
        'seb_showtime' => ['showTime', 1],
        'seb_showkeyboardlayout' => ['showInputLanguage', 1],
        'seb_allowwifi' => ['allowWlan', 0],
        'seb_audio' => ['audioControlEnabled', 0],
        'seb_mute' => ['audioMute', 0],
        'seb_camera' => ['browserMediaCaptureCamera', 0],
        'seb_microphone' => ['browserMediaCaptureMicrophone', 0],
        'seb_screencapture' => ['enablePrintScreen', 0],
        'seb_spellcheck' => ['allowSpellCheck', 0],
    ];

    /**
     * Boolean fields and their generated plist keys.
     */
    public static function boolean_fields(): array {
        return self::BOOLEAN_FIELDS;
    }

    /**
     * Validate submitted fields before the activity is created.
     */
    public static function validate(\stdClass $data, string $moodleorigin, string $torusorigin): array {
        $errors = [];
        foreach (self::BOOLEAN_FIELDS as $field => [, $default]) {
            $value = $data->$field ?? $default;
            if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
                $errors[$field] = get_string('invalidsebchoice', 'local_torussecure');
            }
        }
        $displays = $data->seb_maxdisplays ?? 1;
        if (filter_var($displays, FILTER_VALIDATE_INT) === false || (int) $displays < 1 || (int) $displays > 3) {
            $errors['seb_maxdisplays'] = get_string('invalidsebdisplays', 'local_torussecure');
        }
        $quitpassword = $data->seb_quitpassword ?? '';
        if (!is_string($quitpassword) || \core_text::strlen($quitpassword) > 255) {
            $errors['seb_quitpassword'] = get_string('invalidsebpassword', 'local_torussecure');
        }
        $quiturl = $data->seb_quiturl ?? '';
        if (!is_string($quiturl) || strlen($quiturl) > 2048 ||
            (trim($quiturl) !== '' && !self::valid_https_url(trim($quiturl), false))) {
            $errors['seb_quiturl'] = get_string('invalidseburl', 'local_torussecure');
        }
        $allowedurls = $data->seb_allowedurls ?? '';
        if (!is_string($allowedurls) || strlen($allowedurls) > 4096) {
            $errors['seb_allowedurls'] = get_string('invalidseballowedurls', 'local_torussecure');
        } else {
            $origins = self::split_origins($allowedurls);
            if (count($origins) > 20) {
                $errors['seb_allowedurls'] = get_string('invalidseballowedurls', 'local_torussecure');
            }
            $reserved = [self::origin($moodleorigin), self::origin($torusorigin)];
            foreach ($origins as $origin) {
                if (!self::valid_https_url($origin, true) || in_array(self::origin($origin), $reserved, true)) {
                    $errors['seb_allowedurls'] = get_string('invalidseballowedurls', 'local_torussecure');
                    break;
                }
            }
        }
        return $errors;
    }

    /**
     * Convert validated form values into the builder's typed options.
     */
    public static function from_data(\stdClass $data): array {
        $hasoptions = false;
        foreach (array_keys(self::BOOLEAN_FIELDS) as $field) {
            $hasoptions = $hasoptions || property_exists($data, $field);
        }
        if (!$hasoptions && !property_exists($data, 'seb_maxdisplays') &&
            !property_exists($data, 'seb_quitpassword') && !property_exists($data, 'seb_quiturl') &&
            !property_exists($data, 'seb_allowedurls')) {
            return [];
        }
        $options = [];
        foreach (self::BOOLEAN_FIELDS as $field => [$key, $default]) {
            $options[$key] = (bool) ($data->$field ?? $default);
        }
        $options['allowedDisplaysMaxNumber'] = (int) ($data->seb_maxdisplays ?? 1);
        $password = $data->seb_quitpassword ?? '';
        if ($password !== '') {
            $options['hashedQuitPassword'] = hash('sha256', $password);
        }
        $quiturl = trim($data->seb_quiturl ?? '');
        if ($quiturl !== '') {
            $options['quitURL'] = $quiturl;
        }
        $options['additionalOrigins'] = self::split_origins($data->seb_allowedurls ?? '');
        return $options;
    }

    /**
     * Split an instructor-supplied list of HTTPS origins.
     */
    private static function split_origins(string $value): array {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value)), 'strlen'));
    }

    /**
     * Reject credentials, fragments and URLs with the wrong scope.
     */
    private static function valid_https_url(string $url, bool $originonly): bool {
        if (!seb_request::is_public_url($url)) {
            return false;
        }
        $parts = parse_url($url);
        return !isset($parts['query']) && !isset($parts['fragment']) &&
            (!$originonly || in_array($parts['path'] ?? '', ['', '/'], true));
    }

    /**
     * Normalize scheme, host and effective port for reserved-origin comparison.
     */
    private static function origin(string $url): string {
        $parts = parse_url($url);
        return strtolower($parts['host'] ?? '') . ':' . ($parts['port'] ?? 443);
    }
}
