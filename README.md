# Secure Assessment Moodle development bundle

This repository contains the three Secure Torus plugin components and a local Docker setup. Keep it in a directory named `secure-assessment-plugin` beside a directory named `moodle`.

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
