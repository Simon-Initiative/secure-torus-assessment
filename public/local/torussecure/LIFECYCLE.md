# Backup, restore and deletion

Secure Torus lifecycle support is implemented in `local_torussecure` version
`2026102100`. It uses Moodle's module-level local-plugin backup/restore extension
points and deletion events. The existing provisioning, launch verification,
availability evaluation, configuration builder and LTI signing behavior is retained.

## Supported operations

- Duplicate an activity within a course.
- Import activities into another course.
- Copy a course through Moodle's asynchronous course-copy task.
- Back up and restore courses or individual activities, including archives with or
  without user data.
- Restore activities from the course recycle bin and courses from the category recycle bin.
- Delete activities and courses synchronously or asynchronously, and replace course
  contents during restore.

Each successful restore creates a destination-specific mapping, start URL,
configuration XML, Config Key and configuration version. It keeps the source's
supported instructor SEB options, enabled/disabled state, other availability
restrictions, and Moodle's intended visibility. Native LTI restoration owns the
activity, tool-registration mapping and grade item. Secure Torus validates that
restored registration; it does not select a substitute registration or modify LTI
registration/grade-service configuration.

Moodle may create a new client ID when it clones a course-level tool registration.
Local registration fields cannot prove that Torus has accepted that new client ID.
Check the Moodle/Torus registration pairing in staging before releasing such a
restored course; this plugin does not register a new client with Torus automatically.

The source activity's configuration and session authorizations are never copied or
modified. Students must download the destination activity's new `.seb` file.

## Archive format and policy compatibility

Module backups include a version-1 `plugin_local_torussecure_module/configuration`
payload only for managed LTI activities. It contains the source module/instance IDs
as mapping references, the managed marker, source enabled state and exact stored
XML. It contains no Config Key field, configuration-version field, session grant,
challenge or learner evidence. The XML can contain a hashed quit password; protect
archives using the same controls as other sensitive Moodle backups.

Restore decodes a bounded plist subset without DTDs or external entities. It
recovers explicit supported options, then reconstructs the source settings with
the current builder and compares their typed values, including all URL-filter
rules. Dictionary key order is ignored; array order and value types are preserved.
Unknown settings, changed mandatory policy, malformed XML and unrecognised URL
rules require review. This intentionally prevents silently losing security settings
or silently replacing an older policy with new defaults. Older configuration
formats may therefore require recreation instead of automatic restoration.

Destination Moodle/Torus origins come from destination site settings. Custom
additional origins and quit URLs require review if either origin changes; these
institution-specific destinations are not silently carried into a different site.

## Held activities and recovery

Before the native LTI instance is restored, managed destinations are hidden.
Finalization runs after native LTI registration and availability ID remapping. Only
successful finalization restores the intended visibility. Disabled source mappings
stay disabled, including when their intended module visibility is retained.

Missing/incompatible registrations, missing payloads on legacy restricted activities,
unsupported policy, conflicting mappings and invalid payload versions leave the new
activity hidden. The activity name receives `[Secure Torus configuration required]`
and Moodle's restore log includes a warning with its module ID and recovery guidance.
Unexpected restore errors can abort the restore; already-held destinations are not
made visible by this plugin. A repeated finalization callback does not create a second
mapping, and an existing destination mapping is never overwritten.

To recover a held activity:

1. Keep it hidden and inspect the destination's Torus origin, LTI 1.3 registration,
   client ID, course applicability, and AGS score/line-item scopes.
2. Review the source SEB settings, additional origins, quit URL and destination policy.
3. Recreate it through **Secure Torus Assessment**, using the existing tested creation
   form. There is no new in-place repair screen in this release.
4. Test its new configuration, secure launch and grade passback before releasing it.
5. Remove the unused held activity through Moodle's normal deletion workflow.

A legacy backup that has neither a Secure Torus payload nor a `torusseb` restriction
cannot be identified reliably as formerly secure. Ordinary LTI activities are not
classified as secure by guessing from their URLs. Availability-disabled restores
still inspect archived restrictions to identify legacy managed activities.

## Deletion and recycle bins

`course_module_deleted` removes only a matching managed LTI mapping after both its
module and instance have disappeared. Whole-course removal bypasses module deletion
events in the supported Moodle checkout, so `course_content_deleted` uses the stored
`courseid` ownership metadata to inspect only that course's mappings. Cleanup is
idempotent and checks authoritative records again immediately before deletion.
Mappings with surviving or inconsistent references are retained for investigation.

Moodle creates the recycle-bin archive before deleting source data. The archive
contains the plugin payload, so restoration works after the active mapping is
removed. Asynchronous deletion retains the mapping until the deletion task runs.
Recycle-bin expiration/purge requires no retained active mapping.

## Upgrade and deployment

The only schema addition is an indexed `courseid` ownership field. Upgrade backfills
it only where the module, module type, LTI instance and course agree. Unverifiable
legacy rows retain the default ownership value of zero and can be inspected using
the maintenance command. Upgrade changes no XML, key, configuration version,
enabled state or activity visibility, and performs no orphan deletion.

Use the normal Moodle deployment procedure: deploy code, run the Moodle upgrade in
maintenance mode as appropriate, then validate in staging. From the Moodle repository
root the upgrade command is:

```sh
php public/admin/cli/upgrade.php --non-interactive
```

Take the usual code/database backups before upgrade. No schema downgrade is supplied;
a rollback after upgrade must account for the added ownership metadata and new
restored activities. No production upgrade or deployment was performed as part of
implementation.

## Existing orphan records

From the Moodle repository root:

```sh
# Default: report only; makes no database changes.
php public/local/torussecure/cli/orphans.php

# Explicitly delete only rows whose module AND LTI instance no longer exist.
php public/local/torussecure/cli/orphans.php --cleanup
```

Output contains only record/module/instance IDs, classifications and counts. Live
mappings remain untouched. A row with only one missing reference, mismatched course
ownership, or an incorrect module/instance relationship is `inconsistent` and is
never removed by this command. The upgrade does not run this command automatically.
The command requires administrator shell access to Moodle's configuration.

## Automated and manual verification

Run against an initialized, isolated Moodle PHPUnit database and dataroot:

```sh
vendor/bin/phpunit --testsuite local_torussecure_testsuite,availability_torusseb_testsuite,ltisource_torussecure_testsuite
vendor/bin/phpunit --testsuite local_torussecure_testsuite --group lifecycle
node --test public/local/torussecure/tests/js/verify_test.cjs
```

Lifecycle tests invoke the actual backup/restore controllers, archive extraction,
activity duplication, asynchronous course copy, synchronous/asynchronous deletion,
and course/category recycle-bin APIs. They also check policy preservation,
destination registration failures, legacy archives with availability disabled,
unknown payload versions, rollback after mapping creation, mapping conflicts,
source preservation, ownership upgrade, schema consistency and conservative cleanup.
A restored-launch test rejects the old Config Key proof and accepts the new one.
These tests preserve the existing security model; they do not resolve the separate
Config Key attestation issue identified in the implementation review.

Before production rollout, perform these manual staging checks on each supported
SEB client/version:

1. Verify an original existing activity still launches, completes and passes a grade.
2. Duplicate it, download its new `.seb`, launch, complete and verify its own grade item.
3. Import/copy the course and repeat with the destination activity.
4. Delete and restore an activity and a course through the recycle bins; repeat launch,
   completion and grade checks using newly downloaded configurations.
5. Confirm held activities remain hidden to students, are clearly labelled for
   instructors and emit useful restore warnings.

Live SEB completion and actual Torus AGS passback are manual release gates. Automated
native grade-item and signed-claim checks do not substitute for those client tests.
