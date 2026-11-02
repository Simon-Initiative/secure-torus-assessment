# Secure Assessment for Moodle

This repository contains three plugin components for delivering Torus assessments through Safe Exam Browser (SEB), plus a local Docker setup.

## Installation on an existing Moodle site

### Requirements

- This alpha bundle targets **Moodle 5.3dev**, minimum build **2026090300**. Its declared dependencies are `mod_lti >= 2026042000` and Moodle's bundled `quizaccess_seb >= 2026042002`. Compatibility with older Moodle releases is not established.
- You need a Moodle administrator account and access to deploy plugin files (or help from your hosting provider).
- Moodle and Torus must be accessible over HTTPS, with a working Torus **LTI 1.3** registration. Torus must also support and enable the companion secure-delivery assertion handling; this repository installs only the Moodle side.

### Install the three components

These steps follow [Moodle's plugin installation guide](https://docs.moodle.org/503/en/Installing_plugins). Test the alpha bundle on staging first, and back up your Moodle database, code and `moodledata` before deployment.

1. Download or clone this repository outside your Moodle installation.
2. Copy all three directories below into your existing Moodle codebase, keeping their names and matching the ownership/permissions of other plugins so the web server can read them. Here, `<moodle>` means the Moodle checkout root. The paths include the [`public/` layout used since Moodle 5.1](https://moodledev.io/docs/5.3/guides/restructure).

   | Source in this repository | Destination on your server |
   | --- | --- |
   | `public/local/torussecure` | `<moodle>/public/local/torussecure` |
   | `public/availability/condition/torusseb` | `<moodle>/public/availability/condition/torusseb` |
   | `public/mod/lti/source/torussecure` | `<moodle>/public/mod/lti/source/torussecure` |

3. Sign in as an administrator, open **Site administration → Notifications**, review the dependency checks, and complete the installation/upgrade for all three components.
4. Use **Site administration → Development → Purge caches**, then confirm all three components appear under **Site administration → Plugins → Plugins overview**.

For installation through **Site administration → Plugins → Install plugins**, package each component separately with its named directory at the ZIP root and `version.php` directly inside it. Install `local_torussecure` first, then the other two. The web server needs write access to the corresponding plugin directories. The repository ZIP contains multiple plugins and cannot be uploaded as a single plugin package.

### Configure and use

1. Enable **Site administration → Advanced features → Enable restricted access** and ensure the Secure Torus availability condition is enabled under **Plugins → Availability restrictions → Manage restrictions**.
2. Ensure your Torus LTI 1.3 tool registration is available to the course, has a client ID, accepts grades, and permits AGS score submission and line-item creation.
3. Open **Site administration → Plugins → Local plugins → Secure Torus Assessment**. Set the Torus tool-type ID and Torus HTTPS origin (scheme, hostname and optional port, without a resource path). Leave the Moodle origin blank to use `$CFG->wwwroot`, or set its canonical public HTTPS origin. Keep diagnostic SEB bypass off for normal use.
4. As an editing teacher or manager, turn on course **Edit mode**, choose **Add an activity or resource → Secure Torus Assessment**, and enter the Torus target-link URL, grade and SEB options. Custom roles need `moodle/course:manageactivities`, `mod/lti:addinstance`, and `local/torussecure:configure`. Existing External Tool activities are not converted automatically.
5. Review the resulting diagnostics checklist. With an enrolled learner account and SEB installed, download/open the activity's `.seb` configuration, sign in inside SEB, and test the assessment launch and grade return. Confirm an ordinary browser is denied. Live SEB completion and grade passback still require this manual verification.

The current SEB policy targets root-hosted Moodle with local login; subdirectory sites and external SSO require additional review. See the [component guide](public/local/torussecure/README.md) for configuration details and the [lifecycle guide](public/local/torussecure/LIFECYCLE.md) for upgrades, backup/restore and recovery.

## Local Docker development

Keep this repository in a directory named `secure-assessment-plugin` beside a directory named `moodle`.

From this directory, run:

```sh
docker compose up -d --build
```

On first start, Compose clones Moodle into `../moodle` at the pinned 5.3dev revision, links the three plugin directories into its `public` tree, generates a local CA and TLS certificates in `docker/certs`, installs Moodle, and starts the web and cron containers. Later starts reuse the checkout, certificates, database, and Moodle data. A preexisting Moodle Git checkout is kept at its current revision. If it already contains those plugin directories, bootstrap moves them into `.bootstrap-backup/` before creating the links.

The site uses `https://moodle.test:8443`. On macOS, after the first start, run `sudo sh docker/setup-macos.sh` once to map `moodle.test` to localhost and trust the generated local CA. This changes host DNS and trust settings, so review the script first. You can override the ports with `MOODLE_HTTPS_PORT` and `MOODLE_HTTP_PORT` if another local Moodle is running.

Plugin source lives in this repository under:

- `public/local/torussecure`
- `public/availability/condition/torusseb`
- `public/mod/lti/source/torussecure`

The symlinks use relative paths. Compose mounts this repository alongside Moodle inside the containers, so edits to plugin files are immediately visible to Moodle. Plugin database changes still require Moodle's normal upgrade step.

The generated `docker/certs` directory, `.env`, and bootstrap backups are Git ignored. This Compose file uses the existing `moodle-local` project name, so it reuses that project's database and Moodle data volumes if you already ran the original local Moodle setup.
