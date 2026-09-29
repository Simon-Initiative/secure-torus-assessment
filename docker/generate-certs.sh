#!/bin/sh
# Idempotent: preserve the CA and certificates across Compose restarts.
set -eu
certdir="${MOODLE_CERT_DIR:-$(dirname "$0")/certs}"
mkdir -p "$certdir"
cd "$certdir"
umask 077

if [ ! -f root-ca.crt ] && [ ! -f root-ca.key ]; then
    openssl req -x509 -newkey rsa:3072 -nodes -sha256 -days 3650 \
        -keyout root-ca.key -out root-ca.crt \
        -subj '/CN=Moodle Torus Local Development CA' \
        -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' \
        -addext 'keyUsage=critical,keyCertSign,cRLSign'
fi
test -s root-ca.crt && test -s root-ca.key

for name in moodle torus; do
    if [ ! -f "$name.crt" ] && [ ! -f "$name.key" ]; then
        openssl req -new -newkey rsa:2048 -nodes -sha256 \
            -keyout "$name.key" -out "$name.csr" -subj "/CN=$name.test"
        cat > "$name.ext" <<EOF
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:$name.test,DNS:localhost,IP:127.0.0.1,IP:::1
EOF
        openssl x509 -req -in "$name.csr" -CA root-ca.crt -CAkey root-ca.key \
            -CAcreateserial -out "$name.crt" -days 365 -sha256 -extfile "$name.ext"
        rm "$name.csr" "$name.ext"
    fi
    test -s "$name.crt" && test -s "$name.key"
    openssl verify -CAfile root-ca.crt -verify_hostname "$name.test" "$name.crt"
    chmod 600 "$name.key"
    chmod 644 "$name.crt"
done
chmod 600 root-ca.key
chmod 644 root-ca.crt
