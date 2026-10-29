#!/bin/sh
# Generates the throwaway self-signed TLS cert for the paypalstub service (E2E sandbox only).
# Runs in the one-shot "e2ecerts" service and writes into the e2ecerts volume, which is removed
# by `scripts/e2e.sh down`. Only the e2eapp container trusts this cert (docker/e2e/php/e2e.ini).
set -eu
dir="${1:-/certs}"
if [ -s "$dir/stub.crt" ] && [ -s "$dir/stub.key" ]; then
    echo "e2ecerts: cert already present"
    exit 0
fi
openssl req -x509 -newkey rsa:2048 -nodes -days 30 \
    -subj "/CN=paypalstub" \
    -addext "subjectAltName=DNS:paypalstub" \
    -addext "basicConstraints=critical,CA:TRUE" \
    -addext "keyUsage=critical,digitalSignature,keyEncipherment,keyCertSign" \
    -addext "extendedKeyUsage=serverAuth" \
    -keyout "$dir/stub.key" -out "$dir/stub.crt" 2>/dev/null
# Throwaway key, readable by the stub's non-root user.
chmod 0644 "$dir/stub.key" "$dir/stub.crt"
echo "e2ecerts: generated"
