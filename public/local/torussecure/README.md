# Secure Torus Assessment: Phase 1 implementation

This bundle targets the local Moodle 5.3dev checkout (minimum build 2026090300).
Install all three components together:

- `local_torussecure`: settings, capabilities, XMLDB schema, shared service contracts and protected entry points.
- `availability_torusseb`: serialized `{"type":"torusseb"}` restriction and its editor integration.
- `ltisource_torussecure`: callback before LTI launch signing.

## Modern SEB verification

The `.seb` start URL is now `/local/torussecure/launch.php?cmid=<cmid>`. This page first
requires Moodle login without requiring the activity's SEB restriction. After login it creates
a random, short-lived challenge in the URL. The browser calls
`SafeExamBrowser.security.updateKeys(callback)` and POSTs `security.configKey` with Moodle's
session key to that same URL. This API value is the Config Key hashed with the page URL;
Moodle never sends an expected proof or a raw Config Key to the verification script.

This flow supports the modern macOS/iOS WKWebView, which cannot send the Config Key HTTP
header. See [SEB's Config Key and JavaScript API documentation](https://safeexambrowser.org/developer/seb-config-key.html).
The header validator remains supported for clients that send headers. User-Agent strings
are never accepted as proof.

A successful JavaScript proof grants at most 120 seconds, measured from challenge creation,
for one native LTI launch in the same Moodle session. Authorization is bound to the user,
course-module ID, stored configuration version and key digest, and Moodle origin. Only the
native LTI view/launch endpoints for that module and the authentication callback can use it.
The callback still checks the activity mapping, permissions and Torus target origin, and consumes
the authorization before returning the signed SEB claims. Expiry, logout, changing configuration,
a new challenge, a failed proof submission, or a completed launch require fresh verification.
This is launch authorization, not continuous exam attestation.

Ordinary browsers remain denied; they receive a retry/download explanation if SEB's API is
unavailable or produces invalid proof. The availability message also offers **Verify Safe Exam
Browser and launch**, allowing an already-running SEB client to reach verification after a
failed or expired launch. Header and diagnostic bypass authorization retain their existing policy.

New configurations allow the verification page/script, Moodle logout, unauthenticated login
AJAX, and the exact activity's `/course/cms/<cmid>/restricted` error route. Other course pages
remain blocked. These recovery paths prevent a successful login followed by a blocked
restriction redirect from leaving the login form on screen.

Existing stored configurations must be regenerated with the builder and Config Key adapter,
then atomically replaced using `config_repository::replace_generated_config`. Download and
open the replacement `.seb`; reusing an old local file retains its old start URL/filter/key.
No database schema migration or Torus change is needed.

## Current behavior

Normal LTI launches, including Deep Linking messages without an instance ID, return no extra launch parameters.
Enabled secure mappings require validation at the LTI signing boundary. Availability
also checks current headers or the short-lived JavaScript authorization described above. It accepts
`valid`, `valid_js`, or an authorized `bypassed` result.
Moodle inversion applies to this result; missing/disabled configurations remain unavailable.
The availability condition is hidden from the manual restriction picker and can display existing conditions.
It does not filter participant lists or grade-service users.

Activity provisioning, SEB XML generation, Config Key calculation, configuration persistence,
and authenticated configuration downloads are implemented. The creation form provisions new
activities and redirects to a protected diagnostics checklist with download/open links.
Torus assertion recognition is implemented in the companion Torus checkout; live SEB assessment
completion and grade passback still require manual verification. Existing activities are not automatically enrolled.

## Administration

After Moodle's normal plugin installation/upgrade, settings appear under
**Site administration → Plugins → Local plugins → Secure Torus Assessment**.
Set the existing Torus LTI tool-type ID and HTTPS origin. The optional Moodle origin is blank by default.
Diagnostic bypass defaults to off. Configuring these values does not yet enable secure delivery.

Entry points:

- `/local/torussecure/create.php?courseid=<courseid>`
- `/local/torussecure/config.php?cmid=<lti-cmid>`
- `/local/torussecure/diagnostics.php?cmid=<lti-cmid>`

Schema mappings are configuration data only. The database defaults `enabled` to false;
the repository's explicit creation operation creates an enabled secure mapping.
Standard XMLDB foreign-unique keys ensure one mapping per course module and LTI instance.
Step 10 implements Torus recognition; Step 11 implements the diagnostics checklist.

## Configuration repository

`local_torussecure\local\config_repository` provides nullable lookups by course-module ID and
LTI instance ID, plus `is_secure_lti_instance()` (true only for enabled mappings). This lookup
does not validate a browser request.

`create_for_activity($cmid, $ltiinstanceid, $xml, $configkey)` returns the newly persisted,
enabled record and rejects duplicate mappings. `replace_generated_config($cmid, $xml, $configkey)`
requires an existing mapping and returns the updated record, preserving its IDs, enabled state,
and creation timestamp. Both use delegated transactions and participate in an enclosing
provisioning transaction.

Each write verifies that the course module references the specified, existing LTI instance in
the same course. Empty or whitespace-only configurations and keys other than exactly 64 lowercase
hexadecimal characters are rejected with safe localized errors. XML bytes are preserved exactly;
`configversion` is their SHA-256 hash. The repository accepts the supplied Config Key without
deriving it from the XML; use the builder and adapter described below.

These are internal service methods. The caller remains responsible for permissions and for
attaching the availability condition; the repository does not change course activities or caches.

## SEB configuration generation

`seb_config_builder::build($cmid, $moodleorigin, $torusorigin, $seboptions)` produces deterministic,
unencrypted plist XML. It is an instance method with no database side effects. It enforces
content filtering, preserved cookies at exam start, WebView selection `3`, and the activity's
launch and verification routes. Instructor options control the supported SEB client settings.
Positive activity IDs and HTTPS origins are required.
Origin inputs cannot include credentials, wildcards, paths (other than `/`), queries or fragments.
Hosts are lowercased, trailing slashes removed, and explicit port 443 normalized.

The allowlist uses anchored regular expressions for exact hosts and effective ports, including
an explicit `wss` rule for Torus. Moodle access is limited to the login page, four LTI endpoints,
named theme/JavaScript/AJAX/file-serving endpoints and the AMD/YUI asset directories. This is a
path-based allowlist; the diagnostics and configuration-download endpoints are also allowed. Moodle authorization and subsequent SEB validation enforce activity access.
Torus allows all paths on its configured origin, as specified by the plan. Subdomains, alternate
ports, insecure protocols and unrelated Moodle pages are not implicitly allowed.

The Torus delivery layout also requires CDN assets. Rules allow its versioned React, ReactDOM,
NProgress, Font Awesome and highlighting packages on cdnjs; the specific jQuery and polyfill
files; citation-js, Tabler and MathJax package paths on jsDelivr; and Google Fonts CSS plus
Open Sans/Inter font paths. These are package/file rules, not blanket CDN-host permissions.
React is a webpack external; blocking it prevents LiveView startup and can leave the prologue
completely blank. The paths track the current Torus delivery layout and need review when its
dependencies change. ReCAPTCHA and assessment-specific external media are not pre-allowed.
Record blocked dependencies during the SEB test and add the smallest necessary rule with a regression
test. The spike targets root-hosted Moodle with local login; subdirectory installations, external
SSO, Moodle course-page return navigation and client-specific asset needs require explicit review.
The rule format follows the [SEB URL filter documentation](https://safeexambrowser.org/macosx/mac_usermanual_en.html#NetworkPane)
and [configuration specification](https://www.safeexambrowser.org/developer/documents/SEB-Specification-ConfigKeys.pdf).
PHP regex checks do not substitute for testing the selected SEB client's content filter and WebView.

`seb_config_key::generate($xml)` returns Moodle's bundled SEB Config Key as a lowercase hex string.
Only this adapter calls `quizaccess_seb` in production code. The explicit `quizaccess_seb >= 2026042002`
dependency is spike-only technical debt: production must either retain/test this dependency for
supported Moodle releases or replace it with an attributed, specification-based implementation.
The Config Key is **not** the raw XML SHA-256 used for `configversion`.

Generate XML once, calculate its key, then pass both to `config_repository::create_for_activity()`
or `replace_generated_config()`. The download endpoint serves the stored `configxml` bytes. A tested example of
this sequence is in `tests/seb_config_builder_test.php`. Fixed key fixtures and their provenance
are described in [tests/fixtures/README.md](tests/fixtures/README.md).

## Configuration download and launch links

`/local/torussecure/config.php?cmid=<lti-cmid>` requires course login without passing the activity
to `require_login`, so it does not demand an already-authorized SEB launch to download its configuration.
The download service then checks the current user and Moodle's activity visibility. Active enrolled
learners with `mod/lti:view` may download for an accessible activity or one displayed with restriction
information. Hidden activities, hidden/unavailable sections, hidden courses, and restrictions configured
to hide the activity are denied unless the user has `local/torussecure:configure`. Moodle's course-login
checks still apply to configurers. Guests and anonymous users cannot receive configuration bytes.

The service refuses missing, disabled, empty, or mismatched activity configurations. It returns the
exact stored XML as `application/seb`, with attachment name `torus-<cmid>.seb`, private/no-store cache
headers, and no Moodle page HTML. It does not regenerate XML, log configuration contents, change
availability, or authorize an assessment attempt.

The availability message contains **Download Safe Exam Browser configuration** (HTTPS) and
**Open in Safe Exam Browser** (`sebs://`) links to that same endpoint and activity ID. Both use the
configured public Moodle origin, falling back to `$CFG->wwwroot`, and contain no session tokens or keys.
Native-client handling of `sebs://` and an authenticated Moodle session in SEB must still be checked
manually on the target client. Normal-browser authentication is not a promise that SEB shares its cookies.

## SEB request validation

`seb_request_validator::validate($record, $url, $headers, $user, $context)` returns one of
`valid`, `bypassed`, `missing_header`, `invalid_header`, `activity_not_configured`, or
`configuration_disabled`. It requires an enabled configuration, matching module context, a
non-guest/non-suspended user, and a correctly shaped stored Config Key. Invalid context, user,
key or URL inputs fail closed as `invalid_header`. Callers still enforce login and course access.

For header verification, the validator requires one lowercase 64-character hexadecimal
`X-SafeExamBrowser-ConfigKeyHash` value. Header names are case-insensitive and PHP's `HTTP_`
server-key format is supported. Duplicate or non-scalar values are rejected. The expected hash is
SHA-256 of the exact public URL concatenated with the stored Config Key, compared using
`hash_equals`. User-Agent and Browser Exam Key headers do not substitute for this evidence.

Diagnostic bypass returns `bypassed` only when `enablebypass` is enabled and the supplied user
has `local/torussecure:bypassseb` in the matching activity context. It skips header verification
but cannot override absent/disabled configurations or invalid user/context/key inputs.

Availability and the signing callback use `seb_request::validate()`, which checks current headers
with the stateless validator and only falls back to JavaScript authorization when the header is absent.
The raw inputs come from `seb_request::current_url()` and `seb_request::headers()`.
The URL comes from Moodle's `$FULLME`, rather than `$PAGE->url`, which can omit
request parameters. An explicit public Moodle origin replaces only the origin; path, parameter
order, repeated query keys, percent-encoding, plus signs and an empty query are preserved.
The helper never reads Host or forwarding headers itself. Without a trusted HTTPS canonical
URL or explicit HTTPS origin override, it returns an empty URL and hash validation fails.
Moodle/proxy configuration must preserve the actual request path and query seen by SEB.

The validator emits no logs and returns no hashes, tokens, keys or URLs in its result. Optional
future logging must use only the non-sensitive fields permitted by the plan.

## Availability enforcement and attachment

The `torusseb` condition works only on LTI course modules with a matching enabled configuration.
It resolves the current public URL and request header and validates them with the current user
and module context. Requests evaluated for a different user are rejected; one user's browser
evidence is not evidence for users inspected in reports. Section use is rejected.

After validation, Moodle's `$not` inversion applies to `valid`/`valid_js`/`bypassed` versus denial. Missing,
disabled or mismatched configurations remain unavailable before inversion. The installer always
attaches a positive mandatory condition. The condition does not override Moodle's own privileged
availability-bypass behavior; the separate signing check still validates those requests.

`availability_installer::attach($cmid)` is the internal helper the provisioner calls after creating
the real LTI activity and its enabled secure configuration. It requires Moodle availability to
be enabled, validates existing restriction JSON, and appends a visible `torusseb` condition to
an AND root. Existing OR/negated roots are wrapped in an AND, so they cannot bypass SEB. Original
restriction logic is preserved; a wrapped negated-OR group's description is conservatively hidden
if any original child was hidden. Repeated attachment does not add duplicate mandatory conditions.
Writes use a delegated transaction and rebuild the course cache. The caller handles permissions
and cleanup if later provisioning fails. No existing activity is enrolled automatically.

Unavailable learners receive activity-specific HTTPS download and `sebs://` links. The attachment
helper exposes the SEB restriction description while retaining other restrictions' visibility rules.

## Creating an activity

In a course, turn on **Edit mode**, click **Add an activity or resource** in the desired
section, and select **Secure Torus Assessment**. The chooser opens the secure creation form
with that section preselected. The entry is supplied by the existing LTI source subplugin and
uses the same secure provisioning and native LTI gradebook path. It appears only for users
with all three creation capabilities; Moodle also applies its course-level LTI module restrictions.
After deploying this change, purge Moodle caches; no schema migration is required.

The direct URL `/local/torussecure/create.php?courseid=<courseid>` remains available to an editing
teacher or manager with `moodle/course:manageactivities`, `mod/lti:addinstance`, and
`local/torussecure:configure`. Enter the activity name, existing course section, known Torus
target-link URL, and maximum grade (default 100, bounded by Moodle's grade-point maximum).
The form and endpoint enforce a valid Moodle session key on submission.

The creation form also offers per-activity Safe Exam Browser controls for quitting, reloading,
taskbar and clock display, audio and media capture, spellchecking, display count, an optional
quit password or HTTPS quit URL, and up to 20 additional HTTPS origins. Only the password hash
is included in the generated configuration. The start URL, SEB proof settings, and required
Moodle, Torus, asset and LTI URL rules remain generated by the plugin. These controls apply
only when creating a new activity; existing stored configurations are unchanged.

The provisioner uses the site-configured tool type when it applies to the course. Otherwise, it
uses the sole course-level registration on the configured Torus HTTPS origin; if several match,
creation stops so an administrator can resolve the ambiguity. It requires a configured LTI 1.3 registration
with a client ID, a matching Torus HTTPS origin, applicability to the course, grade acceptance,
and AGS score and line-item creation scopes. The latter preserves the baseline's ability to
create new line items; a score-only registration is insufficient for this spike. The target URL
must use the same effective HTTPS origin, without credentials or a fragment. No registration
or grade-service configuration is modified automatically.

Moodle's `create_module()` creates the real External Tool with Existing window launch and a
native grade item. Creation starts hidden. The provisioner generates XML with the final cmid,
calculates/stores the Config Key, attaches the SEB restriction, and only then makes the activity
visible and rebuilds the course cache. It verifies that Moodle retained the requested target,
tool type, launch container and grade. Failure after creation hides and synchronously deletes
the module through Moodle's course-format deletion API, then removes the plugin configuration.
That API is the current replacement for the deprecated `course_delete_module()` wrapper.

On success, the instructor is redirected to `diagnostics.php?cmid=<cmid>`, which
shows the full status checklist and offers HTTPS/SEB links for enabled stored configurations.
Moodle can sign a validated secure launch, and Torus can recognize it when its opt-in gate is configured.
A real SEB/AGS test remains pending.
Deep Linking, availability dates, attempts and assessment timing are not added to this form.

## LTI signing boundary

`ltisource_torussecure_before_launch()` returns no parameters for ordinary, disabled, or ID-less
Deep Linking instances. For an enabled secure mapping it checks the endpoint's HTTPS origin,
including its effective port, against the configured Torus origin. It verifies the actual course
module/instance/course relationship, configuration-version format and current user's LTI view
capability, then calls the shared validator with the current authorization request URL and headers.
It does not reuse an earlier availability result; it rechecks current headers or a still-valid
one-launch JavaScript authorization. Diagnostic bypass cannot override an invalid
endpoint or activity mapping.

After a `valid`, `valid_js`, or `bypassed` result, it returns `custom_secure_delivery=seb`, the course-module ID
as `custom_secure_activity_id`, the stored version as `custom_seb_configuration_id`, and the
current Unix timestamp as `custom_secure_verified_at` (all strings). Moodle merges these values
before signing, replacing conflicting custom parameters on the managed activity and placing them
in the LTI custom claim. Other launch parameters retain Moodle's normal behavior.

Failures throw safe Moodle errors; failed SEB verification links back to the configuration download.
The callback logs no headers, keys or tokens. The timestamp is diagnostic evidence only, not replay
protection; standard LTI state/nonce validation still applies, and one-time assertions remain Phase 2.
Tests run Moodle's real launch builder, verify the resulting RS256 signature, and inspect the custom
claim. They do not establish live Torus acceptance or real SEB client behavior.

## Custom LTI parameters

The secure activity creation form includes optional **Custom parameters**, one `key=value`
pair per line. For a Torus direct assessment launch, for example:

```text
torus_resource_type=page
torus_resource_id=new_assessment
```

These are stored in the native LTI activity's `instructorcustomparameters` field and included
in Moodle's signed LTI custom claim alongside the SEB assertion. Existing activities can use
the ordinary External Tool edit form to change their custom parameters. The secure creation
form rejects malformed lines and the four plugin-managed SEB keys, including normalized
case/punctuation aliases. Launch signing still replaces managed keys with verified values.
This addition requires no schema migration or Torus change.

## Activity diagnostics

`diagnostics.php?cmid=<lti-cmid>` requires course login and
`local/torussecure:configure` in the module context. It checks enrollment without evaluating the
activity's SEB restriction, so an authorized instructor can diagnose a failing launch. Hidden-activity
access remains subject to Moodle's capability check. Learners cannot access the checklist.

The read-only service reports course-module and instance presence, the configured Torus registration
and LTI version, effective target origin and launch container, the native LTI grade item, enabled
availability plugin and mandatory restriction, matching stored XML, enabled state, and Config Key
format. The page displays only localized status labels, never stored XML, full keys, headers,
launch URLs, cookies, tokens or raw configuration values.

Current-request evidence is reported as **None**, **Valid**, **Invalid**, or **Bypassed** using the
same validator as availability and launch signing. It is recomputed on each request and does not
assert that a later launch, assessment attempt or grade passback succeeded. Bypass still requires
both the site setting and the separate bypass capability.

The checklist links to the existing authenticated configuration download and `sebs://` launch
endpoint when matching, enabled XML exists. Newly generated SEB files allow these two exact paths:
`/local/torussecure/diagnostics.php` and `/local/torussecure/config.php`. Previously stored files are
not changed automatically. Regenerate and persist XML and its Config Key together using the builder,
key adapter and repository replacement method before downloading a replacement configuration;
alternatively provision a new spike activity. Real SEB navigation remains a manual check.

## Verification

The [Step 12 verification guide](tests/README.md) maps the plan requirements to the tests and
lists Moodle style checks and Torus regression commands. Tests include an ordinary sibling
activity/resource alongside an enabled secure activity, and explicit Config Key determinism.

The PHPUnit tests cover configuration persistence and validation, transaction rollback, settings,
capabilities, plist policies, URL rule boundaries, Config Key fixtures, download access and response
metadata, both launch links, availability/launch denial, and ordinary LTI compatibility.
Run these suites in an initialized, isolated Moodle PHPUnit environment:

```sh
vendor/bin/phpunit --testsuite local_torussecure_testsuite
vendor/bin/phpunit --testsuite availability_torusseb_testsuite
vendor/bin/phpunit --testsuite ltisource_torussecure_testsuite
```

The tiny YUI build artifacts accompany their source so activity edit forms work without a developer
build. All three build variants currently contain the same unminified module.
