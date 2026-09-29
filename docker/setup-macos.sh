#!/bin/sh
# Run with administrator privileges to configure local DNS and macOS TLS trust.
set -eu
cd "$(dirname "$0")"
certdir="$(pwd)/certs"
test -s "$certdir/root-ca.crt"
/usr/bin/python3 - <<'PY'
from pathlib import Path
path = Path('/etc/hosts')
original = path.read_text()
names = ('moodle.test', 'torus.test')
present = set()
for line in original.splitlines():
    fields = line.split('#', 1)[0].split()
    if not fields:
        continue
    for name in names:
        if name in fields[1:]:
            if fields[0] != '127.0.0.1':
                raise SystemExit(f'{name} already maps to {fields[0]}; refusing to overwrite it.')
            present.add(name)
missing = [name for name in names if name not in present]
if missing:
    with path.open('a') as stream:
        stream.write('\n127.0.0.1 ' + ' '.join(missing) + ' # Moodle/Torus local LTI development\n')
PY
/usr/bin/security add-trusted-cert -d -r trustRoot -p ssl \
    -k /Library/Keychains/System.keychain "$certdir/root-ca.crt"
/usr/bin/dscacheutil -flushcache
echo 'Local hostnames and CA trust configured.'
