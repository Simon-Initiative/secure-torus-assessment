# Local Moodle with Docker

This guide is part of the Secure Assessment plugin bundle. See the repository's
top-level `README.md` for the sibling checkout and plugin symlink layout.

From the repository root, with Docker Desktop running:

```sh
docker compose up
```

Open **https://moodle.test:8443** once initialization finishes. Sign in with:

- Username: `admin`
- Password: `LocalMoodle123!`

The first run clones a pinned Moodle checkout into `../moodle`, links the plugins,
generates TLS certificates, builds PHP 8.3/Apache, downloads Composer dependencies,
and installs Moodle into PostgreSQL 17. Allow several minutes. The installer accepts Moodle's
GPL license (`COPYING.txt`). Subsequent starts reuse the database and uploaded files.
These defaults are for local development; published ports bind only to loopback.
HTTP on port 8088 redirects to HTTPS. On a new machine, configure local hostnames
and CA trust as described below.

For background operation use `docker compose up -d`. Check progress with
`docker compose logs -f init` and status with `docker compose ps -a`.
The `init` container exiting with code 0 is normal; web and cron start after it succeeds.

## Services and storage

| Service | Purpose | Host port |
| --- | --- | --- |
| bootstrap | Clones Moodle if needed and links the three plugins | None |
| web | HTTPS Apache/PHP serving `public/`, with Moodle routing | 8443; 8088 redirects |
| db | PostgreSQL 17 | None |
| init | Installs dependencies and initializes an empty database | None |
| cron | Runs Moodle background jobs every minute | None |

PostgreSQL is reachable as `db:5432` inside this Compose project. It does not use
the host's port 5432 or any existing Torus database. The project name is
`moodle-local`, with its own network and named volumes for PostgreSQL, `moodledata`,
and Composer's `vendor` directory.

The working tree is mounted into the containers, so PHP and template edits are
available immediately. Initialization creates an ignored root `config.php` that
loads `docker/config.php`. An existing non-Docker config is never overwritten:
move it aside before starting. No host PHP, Composer, or PostgreSQL is required.
The repository includes built frontend assets; changing frontend source still
requires Moodle's normal Node/Grunt build workflow.

## Everyday commands

```sh
# Stop the stack and keep all data.
docker compose down

# Restart it in the background.
docker compose up -d

# Follow application and background job logs.
docker compose logs -f web cron

# Run Moodle CLI commands as the web server user.
docker compose exec --user www-data web php admin/cli/purge_caches.php
docker compose exec --user www-data web php admin/cli/cron.php

# Open a PostgreSQL console.
docker compose exec db psql -U moodle -d moodle

# Rebuild after changing the Dockerfile, PHP settings, or Apache settings.
docker compose up -d --build
```

After changing Composer manifests, run `docker compose up -d` so initialization
can refresh the dependencies. Only runtime dependencies are installed by default;
this is an application environment, not a configured PHPUnit/Behat test environment.

After changing Moodle versions or adding plugins, stop cron and apply upgrades:

```sh
docker compose stop cron
docker compose exec --user www-data web php admin/cli/upgrade.php --non-interactive
docker compose start cron
```

Upgrades are explicit; restarting does not automatically change an existing schema.

## Optional settings

Defaults work without an environment file. Override values in your shell, for example:

```sh
MOODLE_HTTPS_PORT=8444 docker compose up -d
```

The URL updates to match the port. Use the same overrides for subsequent Compose
commands. You can also put them in a local `.env` file (keep passwords out of Git).
Supported variables are `MOODLE_HTTPS_PORT`, `MOODLE_HTTP_PORT` (redirect only), `MOODLE_DB_PASSWORD`,
`MOODLE_ADMIN_USER`, `MOODLE_ADMIN_PASSWORD`, and `MOODLE_ADMIN_EMAIL`.
Admin variables apply only during the first installation. Changing the database
password variable does not change the password in an existing PostgreSQL volume.

## HTTPS and local Torus LTI 1.3

The canonical platform URL is `https://moodle.test:8443`, and the intended tool URL
is `https://torus.test` (port 443). macOS resolves both names to `127.0.0.1` via
`/etc/hosts`. Inside Docker, `moodle.test` resolves to the web service, while
`torus.test` uses Docker's host gateway to reach Torus running on the Mac.
Apache uses the same HTTPS port inside and outside Docker, so the registered URLs
work from the browser and containers without rewriting issuer or endpoint URLs.

Initialization generates a local CA and separate Moodle/Torus certificates in
the ignored `docker/certs/` directory. Existing certificates are preserved on
restart. The CA is valid for ten years; the server certificates for one year.
Certificates include their respective `.test` hostname and localhost/loopback SANs.
Keys have mode 600, certificates mode 644, and none are included in the Docker build.
The CA private key remains in `docker/certs/root-ca.key`; do not distribute it.

Every application container installs the public CA into its system trust bundle,
which PHP/cURL uses with certificate verification enabled. Apache serves TLS
directly and Moodle generates HTTPS URLs and secure session cookies.

For a new Mac, after generating the certificates with `sh docker/generate-certs.sh`
(or starting Compose), add the hostnames and trust the CA:

```sh
sudo sh docker/setup-macos.sh
```

If macOS rejects system-wide trust from a background session, run these commands
in an interactive terminal. User-level trust is also supported:

```sh
security add-trusted-cert -r trustRoot -p ssl \
  -k "$HOME/Library/Keychains/login.keychain-db" docker/certs/root-ca.crt
```

This Mac uses that login-keychain trust. Some browsers or runtimes with their own
CA stores may need the public CA imported separately.

Moodle's Docker-only configuration excludes the current IPv4 addresses of
`moodle.test` and `torus.test` from the default private-network deny ranges.
Loopback, cloud metadata, other private addresses, and unrelated ports remain
blocked. This exception applies to those IPs, including other names resolving to
the same IPs. Allowed ports are 80, 443, and the configured Moodle HTTPS port.

### Torus handoff

Files have been copied to `~/dev/oli-torus/priv/ssl/moodle-local/`:

- `torus.crt`: Torus server certificate.
- `torus.key`: Torus server private key.
- `root-ca.crt`: CA certificate for verifying Moodle HTTPS connections.
- `.gitignore`: keeps the generated files out of Torus Git changes.

Configure Torus separately with the environment settings its development endpoint
already reads:

```sh
export SCHEME=https
export HOST=torus.test
export PORT=443
export HTTPS_PORT=443
export SSL_CERT_PATH="$HOME/dev/oli-torus/priv/ssl/moodle-local/torus.crt"
export SSL_KEY_PATH="$HOME/dev/oli-torus/priv/ssl/moodle-local/torus.key"
```

Also configure the HTTP client used for LTI keyset and service calls to trust
`root-ca.crt` while retaining peer and hostname verification. The server's
`SSL_CERT_PATH` setting does not configure outbound HTTPS trust. For Erlang SSL
clients this usually means supplying a `cacertfile` or augmenting the client's CA
bundle; use the configuration supported by the actual Torus HTTP adapter.
macOS Keychain trust is not necessarily used by Erlang/Hackney.

Register Moodle using its canonical HTTPS URLs. The public signing-key endpoint is
`https://moodle.test:8443/mod/lti/certs.php`. Obtain the client/deployment IDs and
other endpoints from Moodle's external-tool configuration. A full LTI launch
still requires that registration and the separate Torus configuration.

For certificate renewal, stop Compose, move the expired `moodle.crt`/`moodle.key`
and `torus.crt`/`torus.key` pairs aside, and run `sh docker/generate-certs.sh`.
Keep the existing root CA pair to preserve trust. Copy the renewed Torus pair into
Torus and restart both applications. Compose volume resets do not delete certificates.

## Installation failures and starting over

Inspect `docker compose logs init` first. A partial database installation blocks
startup instead of deleting data or silently reinstalling. For a disposable local
site, the following **permanently deletes this project's database, uploads, and
dependency volume**, then performs a fresh installation:

```sh
docker compose down --volumes
docker compose up --build
```

For further background, see Moodle's [installation documentation](https://docs.moodle.org/en/Installing_Moodle)
and [router configuration](https://docs.moodle.org/en/Configuring_the_Router).
Moodle also maintains a separate [Docker development toolkit](https://github.com/moodlehq/moodle-docker);
this repository's Compose stack is self-contained.
