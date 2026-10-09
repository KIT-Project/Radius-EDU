#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"
set -a
source ./.env
set +a
mkdir -p "$RADIUSDESK_VOLUME/db_startup/db_patches" "$RADIUSDESK_VOLUME/db_conf"
cp ../cake4/rd_cake/setup/db/rd.sql "$RADIUSDESK_VOLUME/db_startup/"
cp ../cake4/rd_cake/setup/db/*.sql "$RADIUSDESK_VOLUME/db_startup/db_patches/"
cp db_priveleges.sql startup.sh "$RADIUSDESK_VOLUME/db_startup/"
cp my_custom.cnf "$RADIUSDESK_VOLUME/db_conf/"
docker compose config --quiet
docker compose build radiusdesk
docker compose up -d --wait --wait-timeout 300 rdmariadb
docker compose exec -T rdmariadb bash /opt/radiusdesk-init/startup.sh
docker compose up -d radiusdesk
