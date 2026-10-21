# Phase 1 automated verification

Run tests against an initialized Moodle PHPUnit database and dataroot, separate from the
development site. The Step 12 run used Moodle 5.3dev (2026090300), PHP 8.3.33,
PostgreSQL 17.11 and PHPUnit 11.5.56. All paths below are relative to the Moodle repository root.

```sh
vendor/bin/phpunit --testsuite local_torussecure_testsuite,availability_torusseb_testsuite,ltisource_torussecure_testsuite
```

| Plan requirement | Test coverage |
| --- | --- |
| Exact start URL, allow rules, browser key header, preserved cookies, deterministic XML and Config Key | `public/local/torussecure/tests/seb_config_builder_test.php` |
| Known Config Key fixtures | `public/local/torussecure/tests/seb_config_key_test.php` |
| Correct/missing/wrong/malformed/uppercase headers, exact query/port binding, capability plus setting bypass | `public/local/torussecure/tests/seb_request_validator_test.php` |
| Secure activity unavailable/available, ordinary sibling unaffected, section rejection | `public/availability/condition/torusseb/tests/condition_test.php` |
| Ordinary launch unchanged, secure launch denial, four signed custom claims, unapproved endpoint rejection | `public/mod/lti/source/torussecure/tests/before_launch_test.php` and `secure_launch_test.php` |
| Native LTI instance, registration, grade item, existing window, mapping, restriction, failure cleanup | `public/local/torussecure/tests/activity_provisioner_test.php` |
| Protected diagnostics and download, safe status values, stored bytes, replacement and rollback | `public/local/torussecure/tests/diagnostics_test.php`, `config_download_test.php` and `config_repository_test.php` |

The header policy is strict: values must be exactly 64 lowercase hexadecimal characters.
Uppercase values are rejected; HTTP header names remain case-insensitive.

Use PHP_CodeSniffer with `moodlehq/moodle-cs` registered as an installed standard. Step 12
used moodle-cs 3.7.0 and PHP_CodeSniffer 3.13.6, installed in a separate temporary tools
directory without changing this repository's Composer dependencies:

```sh
phpcs --standard=moodle --extensions=php public/local/torussecure public/availability/condition/torusseb public/mod/lti/source/torussecure
```

From the Torus repository, run:

```sh
mix test test/oli/lti/secure_launch_test.exs test/oli_web/controllers/lti_controller_test.exs test/oli_web/lti_redirect_test.exs test/oli_web/controllers/lti_ags_controller_test.exs
mix format --check-formatted lib/oli/lti/secure_launch.ex lib/oli/lti/launch_errors.ex lib/oli_web/controllers/lti_controller.ex config/runtime.exs test/oli/lti/secure_launch_test.exs test/oli_web/controllers/lti_controller_test.exs
```

Torus tests exercise valid signed assertions, missing assertions, stale timestamps, invalid JWTs,
and an ordinary sibling resource with no secure custom claim while enforcement is enabled for
another resource in the same deployment. Unit tests cover malformed values and scope/freshness
boundaries. Acceptance tests proceed into the existing launch handler; their fixture has no
available course section. They do not prove live assessment delivery or Moodle grade passback.
Real SEB navigation, assessment completion and AGS passback remain Step 13's manual checks.

## Modern macOS/iOS SEB regression coverage

`js_launch_test.php` covers the URL challenge, CSRF, authorization lifetime, user/configuration/origin
binding, one-launch consumption, replay rejection and ordinary-browser denial. The signed-launch
suite also exercises the JavaScript authorization with no Config Key HTTP header. URL-filter tests
cover the verification page/assets, logout, login AJAX and activity-specific restriction route.

Run the client-script tests without installing Node dependencies:

```sh
node --test public/local/torussecure/tests/js/verify_test.cjs
```

These simulate native API callbacks, including delayed injection, invalid values, duplicate callbacks
and timeout. They do not replace a real SEB client run with a newly downloaded configuration.

The macOS callback regression models the native bridge's `callback.name` message and global
`name();` evaluation, rather than directly invoking the JavaScript function object. An anonymous
callback reproduced the real client's `Unexpected token ')'` failure; the named global callback
passes. The same check was also run against the JavaScript served by Moodle's asset endpoint.

## Lifecycle regression coverage

`lifecycle_test.php`, `restore_policy_test.php` and `lifecycle_cleanup_test.php`
exercise backup/restore controllers, archive extraction, duplication/import, asynchronous
course copy, course/activity recycle bins, deletion tasks, configuration regeneration,
policy rejection, ownership migration and dry-run-first cleanup. They belong to the
`lifecycle` PHPUnit group. See [the lifecycle guide](../LIFECYCLE.md) for recovery and
manual SEB/grade-passback release checks. Existing test files were not changed.
