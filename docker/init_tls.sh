#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"
source ./.env
mkdir -p "$RADIUSDESK_VOLUME/tls"
TLS_DIRECTORY=$(cd "$RADIUSDESK_VOLUME/tls" && pwd)
if [[ -f "$TLS_DIRECTORY/server.crt" && -f "$TLS_DIRECTORY/server.key" ]]; then
    echo "Using existing TLS certificate."
    exit 0
fi
if [[ -e "$TLS_DIRECTORY/server.crt" || -e "$TLS_DIRECTORY/server.key" ]]; then
    echo "TLS certificate is incomplete: provide both server.crt and server.key." >&2
    exit 1
fi
TLS_SAN="DNS:localhost,IP:127.0.0.1"
if [[ -n "${TLS_SERVER_IP:-}" ]]; then
    TLS_SAN="$TLS_SAN,IP:$TLS_SERVER_IP"
fi
if [[ -n "${TLS_SERVER_DNS:-}" ]]; then
    TLS_SAN="$TLS_SAN,DNS:$TLS_SERVER_DNS"
fi
docker run --rm --entrypoint bash -v "$TLS_DIRECTORY:/tls" radiusdesk-edu:1.0.0 -c '
    umask 077
    openssl req -x509 -newkey rsa:3072 -sha256 -nodes -days 365 \
        -keyout /tls/server.key -out /tls/server.crt \
        -subj "/CN=EDU WiFi Test Server" \
        -addext "subjectAltName=$1" \
        -addext "extendedKeyUsage=serverAuth"
    chmod 644 /tls/server.crt
' -- "$TLS_SAN"
echo "Test certificate created. Clients must trust it before using HTTPS without warnings."
