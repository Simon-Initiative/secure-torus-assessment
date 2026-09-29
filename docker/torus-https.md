# Torus configuration for local Moodle LTI 1.3

Moodle is running at **https://moodle.test:8443**. Both `moodle.test` and
`torus.test` are mapped to localhost on this Mac. The shared development CA is
trusted in the macOS login keychain and in Moodle's PHP containers.

Your generated files are in `~/dev/oli-torus/priv/ssl/moodle-local/`:

| File | Purpose |
| --- | --- |
| `torus.crt` | Torus HTTPS server certificate, valid for `torus.test` and localhost |
| `torus.key` | Matching private key |
| `root-ca.crt` | CA to trust for outgoing HTTPS connections to Moodle |

The directory is ignored by Git. The CA signing key has not been copied here.

The existing Torus development endpoint reads these settings:

```sh
export SCHEME=https
export HOST=torus.test
export PORT=443
export HTTPS_PORT=443
export SSL_CERT_PATH="$HOME/dev/oli-torus/priv/ssl/moodle-local/torus.crt"
export SSL_KEY_PATH="$HOME/dev/oli-torus/priv/ssl/moodle-local/torus.key"
```

Use your normal Torus mechanism for serving port 443. Moodle permits that port
and resolves `torus.test` to the Mac through Docker's host gateway.

You must also configure the HTTP client used by Torus's LTI library to trust
`root-ca.crt` with peer and hostname verification enabled. The server certificate
settings above do not configure outgoing TLS trust, and Erlang/Hackney may use a
different trust store from macOS. Supply the CA through the adapter's `cacertfile`
setting or add it to its CA bundle. Preserve public CAs if Torus also contacts
external services.

For platform registration, use the endpoints and IDs shown by Moodle's external
tool configuration. Moodle's public signing-key endpoint is:

```text
https://moodle.test:8443/mod/lti/certs.php
```

The Moodle issuer/base URL is `https://moodle.test:8443`; register the exact HTTPS
URLs consistently. Launch and service testing remain to be done after configuring
Torus and registering the tool. Moodle-to-host TLS using this Torus certificate
has been verified with a temporary HTTPS test server, which has been stopped.

Server certificates expire one year after generation. Renewal instructions are
in the Moodle repository's `docker/README.md`.
