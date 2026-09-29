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
 * Deterministic SEB configuration generation for the technical spike.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure\local;

/**
 * Build unencrypted XML with required secure paths and instructor selected client controls.
 */
class seb_config_builder {
    /**
     * Build configuration bytes without accessing or modifying the database.
     *
     * @param int $cmid Positive course-module ID, validated by the repository when persisted.
     * @param string $moodleorigin Public HTTPS origin; subdirectory installations are not supported in the spike.
     * @param string $torusorigin Exact allowed HTTPS origin.
     * @param array $seboptions Validated instructor SEB options.
     * @return string Exact XML bytes to hash and persist, not regenerate during download.
     */
    public function build(int $cmid, string $moodleorigin, string $torusorigin, array $seboptions = []): string {
        if ($cmid <= 0) {
            throw new \moodle_exception('invalidactivitymapping', 'local_torussecure');
        }
        $moodleorigin = $this->normalize_origin($moodleorigin);
        $torusorigin = $this->normalize_origin($torusorigin);
        $moodlepattern = $this->origin_pattern($moodleorigin);
        $toruspattern = $this->origin_pattern($torusorigin);

        // Separate simple expressions avoid advanced regex features unsupported by WKWebView filters.
        $expressions = [];
        foreach (
            [
            '/login/index.php',
            '/login/logout.php',
            '/lib/ajax/service-nologin.php',
            '/local/torussecure/launch.php',
            '/local/torussecure/js/verify.js',
            '/mod/lti/view.php',
            '/mod/lti/launch.php',
            '/mod/lti/auth.php',
            '/mod/lti/return.php',
            '/local/torussecure/diagnostics.php',
            '/local/torussecure/config.php',
            '/theme/yui_combo.php',
            '/lib/ajax/service.php',
            '/lib/polyfills/polyfill.js',
            ] as $path
        ) {
            $expressions[] = '^' . $moodlepattern . preg_quote($path, '~') . '([?#].*)?$';
        }
        // Show the activity's restriction error instead of stranding an authenticated user at login.
        $expressions[] = '^' . $moodlepattern . '/course/cms/' . $cmid . '/restricted([?#].*)?$';
        // Moodle's file-serving endpoints use slash arguments as well as query parameters.
        foreach (
            [
            '/theme/styles.php', '/theme/image.php', '/theme/font.php', '/theme/javascript.php',
            '/lib/javascript.php', '/lib/requirejs.php', '/pluginfile.php',
            ] as $path
        ) {
            $expressions[] = '^' . $moodlepattern . preg_quote($path, '~') . '(/[^?#]*)?([?#].*)?$';
        }
        foreach (['/lib/amd/', '/lib/yuilib/'] as $path) {
            $expressions[] = '^' . $moodlepattern . preg_quote($path, '~') . '.*$';
        }
        // Torus delivery loads React as a webpack external: blocking its CDN leaves LiveView blank.
        // Keep these rules scoped to the packages used by the delivery layout, never entire CDN hosts.
        foreach (
            [
            'https://cdnjs.cloudflare.com/ajax/libs/react/17.0.2/',
            'https://cdnjs.cloudflare.com/ajax/libs/react-dom/17.0.2/',
            'https://cdnjs.cloudflare.com/ajax/libs/nprogress/0.2.0/',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.1/',
            'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.4.0/',
            'https://cdnjs.cloudflare.com/ajax/libs/highlightjs-line-numbers.js/2.9.0/',
            'https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/',
            'https://cdn.jsdelivr.net/npm/mathjax@3/es5/',
            'https://fonts.gstatic.com/s/opensans/',
            'https://fonts.gstatic.com/s/inter/',
            ] as $prefix
        ) {
            $parts = parse_url($prefix);
            $expressions[] = '^' . $this->origin_pattern('https://' . $parts['host']) .
                preg_quote($parts['path'], '~') . '[^?#]*([?#].*)?$';
        }
        foreach (
            [
            'https://code.jquery.com/jquery-3.5.1.slim.min.js',
            'https://cdnjs.cloudflare.com/polyfill/v3/polyfill.min.js',
            'https://cdn.jsdelivr.net/npm/citation-js',
            'https://fonts.googleapis.com/css2',
            ] as $asset
        ) {
            $parts = parse_url($asset);
            $expressions[] = '^' . $this->origin_pattern('https://' . $parts['host']) .
                preg_quote($parts['path'], '~') . '([?#].*)?$';
        }
        $expressions[] = '^' . $toruspattern . '/.*$';
        $expressions[] = '^' . str_replace('https://', 'wss://', $toruspattern) . '/.*$';
        foreach ($seboptions['additionalOrigins'] ?? [] as $origin) {
            $expressions[] = '^' . $this->origin_pattern($this->normalize_origin($origin)) . '/.*$';
        }
        if (isset($seboptions['quitURL'])) {
            $expressions[] = '^' . preg_quote($seboptions['quitURL'], '~') . '([?#].*)?$';
        }
        $rules = array_map(static fn(string $expression): array => [
            'action' => 1,
            'active' => true,
            'expression' => $expression,
            'regex' => true,
        ], $expressions);

        $settings = [
            'sebConfigPurpose' => 0, // Start an exam, rather than configure the SEB client permanently.
            'startURL' => $moodleorigin . '/local/torussecure/launch.php?cmid=' . $cmid,
            'sendBrowserExamKey' => true,
            'browserWindowWebView' => 3,
            'examSessionClearCookiesOnStart' => false,
            'browserWindowAllowReload' => true,
            'URLFilterEnable' => true,
            'URLFilterEnableContentFilter' => true,
            'allowQuit' => true,
            'allowSwitchToApplications' => false,
            'URLFilterRules' => $rules,
        ];
        foreach (seb_settings::boolean_fields() as [$key]) {
            if (array_key_exists($key, $seboptions)) {
                $settings[$key] = (bool) $seboptions[$key];
            }
        }
        if (isset($seboptions['allowedDisplaysMaxNumber'])) {
            $settings['allowedDisplaysMaxNumber'] = (int) $seboptions['allowedDisplaysMaxNumber'];
        }
        foreach (['hashedQuitPassword', 'quitURL'] as $key) {
            if (isset($seboptions[$key])) {
                $settings[$key] = $seboptions[$key];
            }
        }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $plist = $document->appendChild($document->createElement('plist'));
        $plist->setAttribute('version', '1.0');
        $plist->appendChild($this->encode_value($document, $settings));
        return $document->saveXML();
    }

    /**
     * Validate and canonicalize an HTTPS origin without accepting wildcard hosts or paths.
     */
    private function normalize_origin(string $origin): string {
        $parts = parse_url($origin);
        if (
            $parts === false || filter_var($origin, FILTER_VALIDATE_URL) === false ||
            ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            !in_array($parts['path'] ?? '', ['', '/'], true) ||
            (isset($parts['port']) && $parts['port'] < 1)
        ) {
            throw new \moodle_exception('invalidorigin', 'local_torussecure');
        }
        $port = $parts['port'] ?? 443;
        return 'https://' . strtolower($parts['host']) . ($port === 443 ? '' : ':' . $port);
    }

    /**
     * Anchor callers to the exact host and effective port, including an explicit default port.
     */
    private function origin_pattern(string $origin): string {
        $parts = parse_url($origin);
        return 'https://' . preg_quote($parts['host'], '~') .
            (isset($parts['port']) ? ':' . $parts['port'] : '(:443)?');
    }

    /**
     * Encode the small set of plist types used by the fixed policy, preserving insertion order.
     */
    private function encode_value(\DOMDocument $document, mixed $value): \DOMElement {
        if (is_array($value)) {
            $islist = array_is_list($value);
            $element = $document->createElement($islist ? 'array' : 'dict');
            foreach ($value as $key => $item) {
                if (!$islist) {
                    $keyelement = $document->createElement('key');
                    $keyelement->appendChild($document->createTextNode($key));
                    $element->appendChild($keyelement);
                }
                $element->appendChild($this->encode_value($document, $item));
            }
            return $element;
        }
        if (is_bool($value)) {
            return $document->createElement($value ? 'true' : 'false');
        }
        $element = $document->createElement(is_int($value) ? 'integer' : 'string');
        $element->appendChild($document->createTextNode((string) $value));
        return $element;
    }
}
