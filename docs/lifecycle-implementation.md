# Lifecycle implementation and verification record

Implemented on branch `lifecycle-backup-restore`, based on repository commit
`74b25bc57afe0174c34ffd5e94cb9fcbb04f6803`, on 2026-10-21.
The approved scope is recorded in [plan.md](../plan.md).

## Preservation boundary

The launch endpoint, request/header validation, JavaScript authorization, availability
condition evaluation, LTI signing callback, activity provisioner and SEB builder were
not changed. All existing PHP and JavaScript test code remains unchanged.

The only existing service change is a single ownership-field assignment when the
configuration repository creates a new mapping. Existing mappings receive ownership
metadata through the upgrade, without changes to any exam configuration fields.

The running development site's plugin copies are separate from this repository.
They were not replaced or upgraded. Tests ran in a separate container with this
repository's plugin directories mounted read-only, a test-only config, `tlife_`
tables and `/tmp/torus-lifecycle-phpunit` dataroot. No `mdl_` test resets or production
upgrade were performed. The test database shares the PostgreSQL service but uses
Moodle's isolated PHPUnit table prefix; no live course data was used as a fixture.

## Lifecycle map

Verified against Moodle commit `8eae8fc94d0e3932cbc64f1e81414903a1e2e83b`
(Moodle 5.3dev, build 20260903).

| Operation/stage | Moodle mechanism | Secure Torus behavior |
| --- | --- | --- |
| Module backup | `backup_module_structure_step` attaches local plugins to `module.xml` | Write a versioned managed payload without touching source records |
| Module skeleton restore | `restore_module_structure_step` creates a new module with instance zero | `after_execute_module()` saves intended visibility and hides managed/legacy restricted destinations |
| Native LTI restore | `restore_lti_activity_structure_step` creates the LTI instance and remaps its tool type | Moodle owns registration and activity ID mappings |
| Final availability processing | `restore_final_task` remaps availability IDs | Other restrictions remain under Moodle's control |
| Final plugin processing | `restore_execute_after_restore` dispatches `after_restore_module()` | Validate task source/destination IDs and registration, regenerate configuration and attach the mandatory condition; restore intended visibility only on success |
| Duplicate/import | Native backup and restore controllers in import mode | Same module-level hooks apply |
| Course copy | `copy_helper` and `asynchronous_copy_task`, copy mode | Same hooks, tested through the actual queued task |
| Activity deletion | Recycle-bin pre-delete backup, then module deletion event | Archive captures payload first; observer removes only a matching confirmed orphan |
| Whole-course deletion/contents removal | Recycle-bin course backup when applicable, then `course_content_deleted` | Observer scopes cleanup to stored course ownership; this core path bypasses individual module-deletion events |
| Asynchronous deletion | Native task eventually invokes synchronous deletion | Mapping remains until actual deletion, then the same cleanup runs |
| Recycle-bin recovery | Native archive extraction and restore controllers | Reconstruct from archived payload after the original active mapping has been removed |

Restore is not treated as one guaranteed all-or-nothing transaction. The destination
is hidden before native LTI restoration. The new configuration and restriction are
written in a delegated transaction; a failure after mapping creation rolls back
those writes. Expected validation failures keep the activity hidden with a recovery
label and restore warning. Unexpected failures may abort restore with the destination
still held. Existing mappings are never overwritten.

## Necessary plan adjustment

Whole-course removal does not emit individual module-deletion events in this Moodle
checkout. Once deletion finishes, the old schema has no authoritative course ownership
left to scope cleanup. Therefore this implementation adds indexed `courseid` metadata
and a validation-only backfill. This is the concrete schema need anticipated by plan
step 9. No configuration XML/key migration or automatic orphan cleanup is included.

## Verification results

Environment: PHP 8.3.33, PostgreSQL 17.11, PHPUnit 11.5.56, Moodle 5.3dev.

- Before implementation: all 202 existing PHP tests passed, 7,421 assertions.
- The initial duplication regression test failed because the copied mapping was absent.
- The metadata upgrade executed successfully in the isolated PHPUnit environment.
- Final combined suite: **246 tests passed, 7,622 assertions**. This includes all
  202 original tests and 44 new lifecycle tests.
- All six existing JavaScript tests passed.
- Moodle coding-standard checks pass for all new lifecycle PHP files and the touched
  repository/version files. Pre-existing coding-style issues in `seb_settings.php`,
  `seb_config_builder_test.php` and existing language-string ordering were left intact.
- The actual CLI script passed `--help`, default dry run and `--cleanup` smoke checks
  against the isolated test tables. Service tests additionally cover populated live,
  orphan and inconsistent fixtures, report immutability and rechecking before deletion.
- Upgrade tests check original exam fields remain unchanged and the upgraded plugin
  table matches the fresh-install XMLDB schema.
- `git diff --check` passed.

The new suite covers duplicate/import, actual asynchronous course copy, manual archive
restore with and without user data, activity/category recycle bins, synchronous and
asynchronous module/course deletion, course-content removal, source preservation,
other restriction preservation, hidden/disabled states, missing/invalid destination
registration, cross-site origin handling, legacy payload absence (also with availability
disabled), unknown payload versions, malformed/unsupported SEB policy, rollback after
mapping creation, destination conflicts, no session-grant copying and new-key launch
verification. Archive extraction is exercised through Moodle's stored-file packer.

## Release gates and limitations

Live SEB navigation, Torus assessment completion and actual AGS grade passback have
not been performed for this change. They remain staging release gates for both an
original and a restored activity. No production deployment was performed. New or cloned
LTI registrations must also be paired correctly with Torus; static Moodle fields do
not prove external client registration. The separate Config Key attestation finding
is outside this lifecycle scope and remains unchanged.

Operational commands, recovery guidance, archive compatibility and the manual checklist
are in [LIFECYCLE.md](../public/local/torussecure/LIFECYCLE.md). No in-place repair UI,
automatic cleanup task, bulk configuration regeneration, or schema downgrade is added.
