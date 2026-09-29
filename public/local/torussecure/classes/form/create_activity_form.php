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
 * Secure External Tool creation form.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_torussecure\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Minimal instructor form for provisioning a secure External Tool activity.
 */
class create_activity_form extends \moodleform {
    /**
     * Define the activity inputs and submission controls.
     */
    public function definition() {
        $form = $this->_form;
        $course = $this->_customdata['course'];
        $form->addElement('hidden', 'courseid', $course->id);
        $form->setType('courseid', PARAM_INT);
        $form->addElement('text', 'name', get_string('name'), ['maxlength' => 255]);
        $form->setType('name', PARAM_TEXT);
        $form->addRule('name', null, 'required');
        $sections = [];
        $defaultsection = (int) ($this->_customdata['section'] ?? 0);
        foreach (get_fast_modinfo($course)->get_section_info_all() as $section) {
            $sections[$section->section] = get_section_name($course, $section);
            if ((int) $section->id === (int) ($this->_customdata['sectionid'] ?? 0)) {
                $defaultsection = $section->section;
            }
        }
        $form->addElement('select', 'section', get_string('sectionname', 'format_topics'), $sections);
        $form->setType('section', PARAM_INT);
        $form->setDefault('section', isset($sections[$defaultsection]) ? $defaultsection : 0);
        $form->addElement('text', 'targeturl', get_string('targeturl', 'local_torussecure'), ['size' => 80]);
        $form->setType('targeturl', PARAM_RAW_TRIMMED);
        $form->addRule('targeturl', null, 'required');
        $form->addElement(
            'textarea',
            'instructorcustomparameters',
            get_string('customparameters', 'local_torussecure'),
            ['rows' => 5, 'cols' => 80]
        );
        $form->setType('instructorcustomparameters', PARAM_RAW_TRIMMED);
        $form->addHelpButton('instructorcustomparameters', 'customparameters', 'local_torussecure');
        $form->addElement('text', 'grade', get_string('maximumgrade', 'local_torussecure'));
        $form->setType('grade', PARAM_RAW_TRIMMED);
        $form->setDefault('grade', 100);

        $form->addElement('header', 'sebsettings', get_string('sebsettings', 'local_torussecure'));
        $form->addElement('static', 'sebsettingsnote', '', get_string('sebsettingsnote', 'local_torussecure'));
        foreach (\local_torussecure\local\seb_settings::boolean_fields() as $field => [, $default]) {
            $form->addElement('selectyesno', $field, get_string($field, 'local_torussecure'));
            $form->setType($field, PARAM_BOOL);
            $form->setDefault($field, $default);
        }
        $form->addElement('select', 'seb_maxdisplays', get_string('seb_maxdisplays', 'local_torussecure'), [
            1 => 1, 2 => 2, 3 => 3,
        ]);
        $form->setType('seb_maxdisplays', PARAM_INT);
        $form->setDefault('seb_maxdisplays', 1);
        $form->addElement('passwordunmask', 'seb_quitpassword', get_string('seb_quitpassword', 'local_torussecure'));
        $form->setType('seb_quitpassword', PARAM_RAW);
        $form->addHelpButton('seb_quitpassword', 'seb_quitpassword', 'local_torussecure');
        $form->addElement('text', 'seb_quiturl', get_string('seb_quiturl', 'local_torussecure'), ['size' => 80]);
        $form->setType('seb_quiturl', PARAM_RAW_TRIMMED);
        $form->addHelpButton('seb_quiturl', 'seb_quiturl', 'local_torussecure');
        $form->addElement('textarea', 'seb_allowedurls', get_string('seb_allowedurls', 'local_torussecure'), [
            'rows' => 4, 'cols' => 80,
        ]);
        $form->setType('seb_allowedurls', PARAM_RAW_TRIMMED);
        $form->addHelpButton('seb_allowedurls', 'seb_allowedurls', 'local_torussecure');
        $form->hideIf('seb_confirmquit', 'seb_allowquit', 'eq', 0);
        $form->hideIf('seb_quitpassword', 'seb_allowquit', 'eq', 0);
        $form->hideIf('seb_mute', 'seb_audio', 'eq', 0);
        foreach (['seb_showreloadbutton', 'seb_showtime', 'seb_showkeyboardlayout', 'seb_allowwifi'] as $field) {
            $form->hideIf($field, 'seb_showtaskbar', 'eq', 0);
        }
        $this->add_action_buttons(true, get_string('createsecureactivity', 'local_torussecure'));
    }

    /**
     * Validate submitted activity settings.
     */
    public function validation($data, $files) {
        $data['courseid'] = $this->_customdata['course']->id;
        return array_merge(
            parent::validation($data, $files),
            \local_torussecure\local\activity_provisioner::validate_input((object) $data)
        );
    }
}
