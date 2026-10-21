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

/**
 * Hold managed destinations until all native LTI mappings and availability remapping have completed.
 */
class restore_local_torussecure_plugin extends restore_local_plugin {
    /** @var ?array Payload read from this module's archive. */
    private ?array $payload = null;
    /** @var ?array Original destination visibility while finalization is pending. */
    private ?array $visibility = null;
    /** @var bool Prevent duplicate finalization in the same restore task. */
    private bool $finalized = false;

    /**
     * Register even when the payload is absent, so legacy restrictions are held too.
     */
    protected function define_module_plugin_structure(): array {
        return [new restore_path_element($this->get_namefor('configuration'), $this->get_pathfor('/configuration'))];
    }

    /**
     * Read an archive payload without trusting its content or source IDs.
     */
    public function process_local_torussecure_configuration($data): void {
        if ($this->payload !== null) {
            throw new restore_step_exception('Duplicate Secure Torus payload');
        }
        $this->payload = (array) $data;
    }

    /**
     * Hold the new module before its LTI instance is restored, including legacy archives.
     */
    public function after_execute_module(): void {
        global $DB;
        if ($this->task->get_modulename() !== 'lti') {
            return;
        }
        $cm = $DB->get_record('course_modules', ['id' => $this->task->get_moduleid()], '*', MUST_EXIST);
        if (
            $this->payload === null &&
            !\local_torussecure\local\lifecycle_cleanup::has_restriction($cm->availability) &&
            !$this->source_has_restriction()
        ) {
            return;
        }
        $this->visibility = ['visible' => (int) $cm->visible, 'visibleold' => (int) $cm->visibleold];
        $DB->set_field('course_modules', 'visible', 0, ['id' => $cm->id]);
        $DB->set_field('course_modules', 'visibleold', 0, ['id' => $cm->id]);
    }

    /**
     * Detect legacy restrictions even if Moodle disabled availability and discarded them on import.
     */
    private function source_has_restriction(): bool {
        $xml = file_get_contents($this->task->get_taskbasepath() . '/module.xml');
        if ($xml === false || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return false;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                return false;
            }
            $nodes = (new DOMXPath($document))->query('/module/availability');
            return $nodes->length > 0 && \local_torussecure\local\lifecycle_cleanup::has_restriction($nodes->item(0)->textContent);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Finalize only after native tool and restriction mappings exist. Failures remain hidden.
     */
    public function after_restore_module(): void {
        global $DB;
        if ($this->visibility === null || $this->finalized) {
            return;
        }
        $this->finalized = true;
        $cmid = (int) $this->task->get_moduleid();
        try {
            if (
                $this->payload === null ||
                (int) ($this->payload['sourcecmid'] ?? 0) !== (int) $this->task->get_old_moduleid() ||
                (int) ($this->payload['sourceltiinstanceid'] ?? 0) !== (int) $this->task->get_old_activityid()
            ) {
                throw new moodle_exception('restoreinvalidpayload', 'local_torussecure');
            }
            (new \local_torussecure\local\activity_restorer())->restore(
                $cmid,
                (int) $this->task->get_activityid(),
                $this->payload
            );
            $DB->update_record('course_modules', (object) (['id' => $cmid] + $this->visibility));
        } catch (moodle_exception $exception) {
            // Do not expose exception text: it may contain configuration or third-party data.
            $suffix = get_string('restorerequiredsuffix', 'local_torussecure');
            $instance = $DB->get_record('lti', ['id' => $this->task->get_activityid()]);
            if ($instance && !str_ends_with($instance->name, $suffix)) {
                $DB->set_field(
                    'lti',
                    'name',
                    \core_text::substr($instance->name, 0, 180) . ' ' . $suffix,
                    ['id' => $instance->id]
                );
            }
            $this->task->log(get_string('restoreheld', 'local_torussecure', $cmid), backup::LOG_WARNING);
        }
        rebuild_course_cache($this->task->get_courseid(), true);
    }

    /**
     * Source configuration is regenerated, never passed through the content link decoder.
     */
    public static function define_decode_contents(): array {
        return [];
    }
}
