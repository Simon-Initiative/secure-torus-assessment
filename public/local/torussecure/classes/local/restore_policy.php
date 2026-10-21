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
 * Secure Torus activity lifecycle integration.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure\local;

/**
 * Strictly recover only configurations reproducible by the existing builder.
 */
class restore_policy {
    /**
     * Decode a source configuration without carrying source URLs into the destination.
     *
     * @param string $xml Source configuration.
     * @param int $cmid Source course-module ID.
     * @return array Source origins and explicit builder options.
     */
    public static function decode(string $xml, int $cmid): array {
        $settings = self::parse($xml);
        $start = $settings['startURL'] ?? '';
        if (
            !is_string($start) || !preg_match(
                '~\A(https://[^/?#]+)/local/torussecure/launch\.php\?cmid=' . $cmid . '\z~',
                $start,
                $match
            )
        ) {
            self::invalid();
        }
        $moodleorigin = $match[1];
        $options = [];
        foreach (seb_settings::boolean_fields() as [$key]) {
            if (array_key_exists($key, $settings)) {
                if (!is_bool($settings[$key])) {
                    self::invalid();
                }
                $options[$key] = $settings[$key];
            }
        }
        if (array_key_exists('allowedDisplaysMaxNumber', $settings)) {
            $displays = $settings['allowedDisplaysMaxNumber'];
            if (!is_int($displays) || $displays < 1 || $displays > 3) {
                self::invalid();
            }
            $options['allowedDisplaysMaxNumber'] = $displays;
        }
        if (array_key_exists('hashedQuitPassword', $settings)) {
            if (
                !is_string($settings['hashedQuitPassword']) ||
                !preg_match('/\A[0-9a-f]{64}\z/', $settings['hashedQuitPassword'])
            ) {
                self::invalid();
            }
            $options['hashedQuitPassword'] = $settings['hashedQuitPassword'];
        }
        if (array_key_exists('quitURL', $settings)) {
            if (
                !is_string($settings['quitURL']) || !seb_request::is_public_url($settings['quitURL']) ||
                parse_url($settings['quitURL'], PHP_URL_QUERY) !== null
            ) {
                self::invalid();
            }
            $options['quitURL'] = $settings['quitURL'];
        }
        // The builder places Torus's whole-origin rule before any additional whole-origin rules.
        $origins = [];
        if (!is_array($settings['URLFilterRules'] ?? null)) {
            self::invalid();
        }
        foreach ($settings['URLFilterRules'] ?? [] as $rule) {
            if (!is_array($rule)) {
                self::invalid();
            }
            $expression = $rule['expression'] ?? null;
            if (
                is_string($expression) && str_starts_with($expression, '^https://') &&
                str_ends_with($expression, '/.*$')
            ) {
                $origin = substr($expression, 1, -4);
                if (str_ends_with($origin, '(:443)?')) {
                    $origin = substr($origin, 0, -7);
                }
                if (!str_contains(substr($origin, 8), '/')) {
                    $origins[] = stripslashes($origin);
                }
            }
        }
        if (!$origins || count($origins) > 21) {
            self::invalid();
        }
        $torusorigin = array_shift($origins);
        if ($origins) {
            $options['additionalOrigins'] = $origins;
        }
        // Reject unknown settings, altered mandatory policies and unrecognised URL rules.
        $rebuilt = (new seb_config_builder())->build($cmid, $moodleorigin, $torusorigin, $options);
        if (self::canonical($settings) !== self::canonical(self::parse($rebuilt))) {
            self::invalid();
        }
        return ['moodleorigin' => $moodleorigin, 'torusorigin' => $torusorigin, 'options' => $options];
    }

    /**
     * Parse the bounded plist subset emitted by our builder, without DTDs or entity expansion.
     */
    private static function parse(string $xml): array {
        if (strlen($xml) > 1048576 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            self::invalid();
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                self::invalid();
            }
            $root = $document->documentElement;
            if (
                $root->tagName !== 'plist' || $root->getAttribute('version') !== '1.0' ||
                $root->childElementCount !== 1 || $root->firstElementChild->tagName !== 'dict'
            ) {
                self::invalid();
            }
            return self::value($root->firstElementChild, 0);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Decode typed plist values, rejecting duplicates and unsupported element types.
     */
    private static function value(\DOMElement $element, int $depth): mixed {
        if ($depth > 12 || $element->hasAttributes()) {
            self::invalid();
        }
        $children = iterator_to_array($element->childNodes);
        foreach ($children as $child) {
            if (!($child instanceof \DOMElement) && !($child instanceof \DOMText)) {
                self::invalid();
            }
        }
        if (in_array($element->tagName, ['dict', 'array'], true)) {
            $elements = [];
            foreach ($children as $child) {
                if ($child instanceof \DOMElement) {
                    $elements[] = $child;
                } else if (trim($child->textContent) !== '') {
                    self::invalid();
                }
            }
            $result = [];
            for ($i = 0; $i < count($elements); $i++) {
                if ($element->tagName === 'dict') {
                    $key = $elements[$i];
                    if (
                        $key->tagName !== 'key' || $key->hasAttributes() || $key->childElementCount ||
                        $key->textContent === '' || array_key_exists($key->textContent, $result) ||
                        !isset($elements[$i + 1])
                    ) {
                        self::invalid();
                    }
                    $result[$key->textContent] = self::value($elements[++$i], $depth + 1);
                } else {
                    $result[] = self::value($elements[$i], $depth + 1);
                }
            }
            return $result;
        }
        if ($element->childElementCount) {
            self::invalid();
        }
        return match ($element->tagName) {
            'true', 'false' => $element->textContent === '' ? $element->tagName === 'true' : self::invalid(),
            'string' => $element->textContent,
            'integer' => preg_match('/\A[0-9]{1,10}\z/', $element->textContent)
                ? (int) $element->textContent : self::invalid(),
            default => self::invalid(),
        };
    }

    /**
     * Compare dictionaries independent of key ordering, preserving value types and array order.
     */
    private static function canonical(mixed $value): string {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (is_array($item)) {
                if (!array_is_list($item)) {
                    ksort($item);
                }
                $item = array_map($sort, $item);
            }
            return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }

    /**
     * Throw a safe error without including source configuration.
     */
    private static function invalid(): never {
        throw new \moodle_exception('restoreinvalidpolicy', 'local_torussecure');
    }
}
