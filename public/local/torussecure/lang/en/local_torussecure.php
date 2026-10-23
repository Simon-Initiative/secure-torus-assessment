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
 * Language strings.
 *
 * @package    local_torussecure
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['availabilitydisabled'] = 'Enable Moodle availability restrictions before creating a secure activity.';
$string['configaccessdenied'] = 'You do not have access to this activity configuration.';
$string['configtitle'] = 'Safe Exam Browser configuration';
$string['configurationdisabled'] = 'The secure configuration for this activity is disabled.';
$string['configurationexists'] = 'This activity or External Tool instance already has a secure configuration. Replace its generated configuration instead.';
$string['configurationmissing'] = 'This activity has no secure configuration to replace.';
$string['configurationready'] = 'This activity has a stored Safe Exam Browser configuration.';
$string['configuretitle'] = 'Set up a Secure Torus Assessment';
$string['createsecureactivity'] = 'Create secure activity';
$string['customparameters'] = 'Custom parameters';
$string['customparameters_help'] = 'Optional LTI parameters, one key=value pair per line. For example:

    torus_resource_type=page
    torus_resource_id=new_assessment

The secure_delivery, secure_activity_id, seb_configuration_id and secure_verified_at parameters are managed automatically and cannot be set here.';
$string['diagnosticsavailability'] = 'Secure availability plugin installed and enabled';
$string['diagnosticscheck'] = 'Check';
$string['diagnosticsconfiguration'] = 'Matching stored SEB configuration exists';
$string['diagnosticscontainer'] = 'Launch container is Existing window';
$string['diagnosticsenabled'] = 'Secure configuration enabled';
$string['diagnosticsevidence'] = 'Current request SEB evidence';
$string['diagnosticsgrade'] = 'LTI grade item exists';
$string['diagnosticsinstance'] = 'LTI instance found in this course';
$string['diagnosticskey'] = 'Stored Config Key format is valid';
$string['diagnosticslti13'] = 'Torus tool uses LTI 1.3';
$string['diagnosticsmodule'] = 'External Tool course module found';
$string['diagnosticsorigin'] = 'Target-link origin matches allowed Torus origin';
$string['diagnosticsrequestnotice'] = 'SEB evidence applies only to this diagnostics request. A valid or bypassed result does not authorize a later launch or confirm assessment completion or grade passback.';
$string['diagnosticsrestriction'] = 'Availability enabled with a mandatory secure restriction attached';
$string['diagnosticsstatus'] = 'Status';
$string['diagnosticsstatusbypassed'] = 'Bypassed (diagnostic bypass)';
$string['diagnosticsstatusinvalid'] = 'Invalid';
$string['diagnosticsstatusno'] = 'No';
$string['diagnosticsstatusnone'] = 'None';
$string['diagnosticsstatusvalid'] = 'Valid';
$string['diagnosticsstatusyes'] = 'Yes';
$string['diagnosticstitle'] = 'Secure Torus diagnostics';
$string['diagnosticstool'] = 'Configured Torus tool type found';
$string['downloadconfig'] = 'Download Safe Exam Browser configuration';
$string['emptyconfiguration'] = 'The Safe Exam Browser configuration must not be empty.';
$string['enablebypass'] = 'Enable diagnostic SEB bypass';
$string['enablebypass_desc'] = 'For diagnostics only. Allows SEB verification to be bypassed for users who have the bypass capability for the activity.';
$string['invalidactivity'] = 'The selected activity is not an External Tool.';
$string['invalidactivitymapping'] = 'The course activity must reference the specified External Tool instance in the same course.';
$string['invalidactivityname'] = 'Enter an activity name of 1 to 255 characters.';
$string['invalidagsconfiguration'] = 'The Torus registration must accept grades and allow AGS score submission and line-item creation.';
$string['invalidconfigkey'] = 'The Safe Exam Browser Config Key must contain exactly 64 lowercase hexadecimal characters.';
$string['invalidcreationdata'] = 'Cannot create the activity: {$a}';
$string['invalidcustomparameters'] = 'Enter one key=value pair per line, with a non-empty key.';
$string['invalidmaximumgrade'] = 'Enter a positive maximum grade no greater than {$a}.';
$string['invalidorigin'] = 'Enter an HTTPS origin without credentials, a resource path, query string or fragment.';
$string['invalidsection'] = 'Select an existing section in this course.';
$string['invalidseballowedurls'] = 'Enter up to 20 additional HTTPS origins, one per line. Do not enter the Moodle or Torus origin.';
$string['invalidsebchoice'] = 'Select Yes or No.';
$string['invalidsebdisplays'] = 'Select one, two or three displays.';
$string['invalidsebpassword'] = 'Enter a quit password of at most 255 characters.';
$string['invalidseburl'] = 'Enter an HTTPS quit URL without credentials, a query string or fragment.';
$string['invalidtargeturl'] = 'Enter an HTTPS target-link URL on the configured Torus origin, without credentials or a fragment.';
$string['invalidtoolregistration'] = 'Select a configured LTI 1.3 Torus registration for this course, with a client ID and matching HTTPS origin.';
$string['launchseb'] = 'Open in Safe Exam Browser';
$string['maximumgrade'] = 'Maximum grade';
$string['moodleorigin'] = 'Moodle public HTTPS origin';
$string['moodleorigin_desc'] = 'Optional override for the externally visible Moodle origin. Leave blank to use the site URL.';
$string['notimplemented'] = 'Secure Torus delivery is not ready. Configuration and launch validation must be implemented before this activity can launch.';
$string['pluginname'] = 'Secure Torus Assessment';
$string['privacy:metadata'] = 'This scaffold stores activity configuration only. It does not store learner information or send additional personal data.';
$string['reservedcustomparameters'] = 'SEB verification parameters are managed automatically. Remove secure_delivery, secure_activity_id, seb_configuration_id and secure_verified_at, including equivalent names with different casing or punctuation.';
$string['restoreheld'] = 'Secure Torus activity {$a} remains hidden because its secure configuration could not be restored. Recreate it using Secure Torus Assessment and check its registration and exam policy before making it available.';
$string['restoreinvalidpayload'] = 'Secure Torus backup configuration is missing or invalid.';
$string['restoreinvalidpolicy'] = 'The archived Safe Exam Browser policy is unsupported or invalid.';
$string['restoreoriginreview'] = 'Additional origins or quit URLs require review when restoring to another site.';
$string['restorerequiredsuffix'] = '[Secure Torus configuration required]';
$string['scaffoldnotice'] = 'Secure assessment setup is not available yet. This installation contains the Step 2 scaffolds.';
$string['sebsettings'] = 'Safe Exam Browser configuration';
$string['sebsettingsnote'] = 'These choices are saved in the configuration for this new activity. The assessment start URL, browser verification and required Moodle and Torus URLs are set automatically.';
$string['seb_allowquit'] = 'Allow students to quit SEB';
$string['seb_confirmquit'] = 'Confirm before quitting';
$string['seb_allowreload'] = 'Allow reload during the assessment';
$string['seb_showtaskbar'] = 'Show SEB taskbar';
$string['seb_showreloadbutton'] = 'Show reload button';
$string['seb_showtime'] = 'Show clock';
$string['seb_showkeyboardlayout'] = 'Show keyboard layout';
$string['seb_allowwifi'] = 'Show Wi-Fi controls';
$string['seb_audio'] = 'Enable audio controls';
$string['seb_mute'] = 'Mute audio at startup';
$string['seb_camera'] = 'Allow camera capture';
$string['seb_microphone'] = 'Allow microphone capture';
$string['seb_screencapture'] = 'Allow screen capture';
$string['seb_spellcheck'] = 'Allow spellchecking';
$string['seb_maxdisplays'] = 'Maximum displays';
$string['seb_quitpassword'] = 'Quit password';
$string['seb_quitpassword_help'] = 'Optional password required when a student quits SEB. The generated configuration contains only its SHA-256 hash.';
$string['seb_quiturl'] = 'Quit URL';
$string['seb_quiturl_help'] = 'Optional HTTPS URL that SEB treats as a quit link. Torus must provide a link to this URL for students to use it.';
$string['seb_allowedurls'] = 'Additional allowed HTTPS sites';
$string['seb_allowedurls_help'] = 'Enter one HTTPS origin per line, for example https://example.org. These sites are added to the required Moodle and Torus URL rules. Moodle and Torus origins remain managed automatically.';
$string['secureendpointinvalid'] = 'The secure activity launch URL must use the configured Torus HTTPS origin. Ask the course instructor to check the tool settings.';
$string['securelaunchdenied'] = 'Safe Exam Browser verification failed for this launch. Open the activity using its current Safe Exam Browser configuration and try again.';
$string['targeturl'] = 'Torus target-link URL';
$string['tooltypeid'] = 'Torus LTI tool-type ID';
$string['tooltypeid_desc'] = 'Preferred Moodle LTI 1.3 tool-type ID. When it does not apply to a course, the sole matching course registration is used.';
$string['torusorigin'] = 'Allowed Torus HTTPS origin';
$string['torusorigin_desc'] = 'Scheme, hostname and optional port, for example https://torus.test. Resource paths are configured per activity.';
$string['torussecure:bypassseb'] = 'Bypass SEB for secure Torus diagnostics';
$string['torussecure:configure'] = 'Configure secure Torus activities';
$string['verifyfailed'] = 'Safe Exam Browser verification did not succeed. Open the latest configuration for this activity in Safe Exam Browser, then try again.';
$string['verifyretry'] = 'Try verification again';
$string['verifytitle'] = 'Verify Safe Exam Browser and launch';
$string['verifywaiting'] = 'Checking Safe Exam Browser. Your assessment will open automatically after verification.';
