#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"
source ./.env
mkdir -p "$RADIUSDESK_VOLUME/db_startup/db_patches"
cp ../cake4/rd_cake/setup/db/*.sql "$RADIUSDESK_VOLUME/db_startup/db_patches/"
cp startup.sh "$RADIUSDESK_VOLUME/db_startup/"
docker compose exec -T rdmariadb bash /opt/radiusdesk-init/startup.sh
