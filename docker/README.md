# RADIUSdesk EDU 1.0.0

Builds the current repository checkout with Ubuntu 24.04 / PHP 8.3,
FreeRADIUS, Nginx and MariaDB 11.8. No additional repository clone is needed.

From the repository root on branch Dev:

```sh
bash docker/local_build.sh
```

The script builds `radiusdesk-edu:1.0.0`, starts MariaDB, waits for readiness,
initializes the database and applies bundled SQL patches before starting the app.
Open http://localhost (TCP 80); RADIUS uses UDP 1812 and 1813.

`docker/.env` defines the local staging directory (`./data`, relative to docker/)
and Compose bridge network. MariaDB data is persisted in the `rd_data` volume;
the initialization marker is stored with that data. SQL patch scripts retain
upstream behavior and run on each invocation; review before upgrading an existing DB.
Database credentials rd/rd and empty root password retain the upstream defaults
and are intended for local development.

```sh
cd docker
docker compose ps
docker compose logs --tail=100 radiusdesk
docker compose down
```

`down` preserves the database volume. To apply database patches explicitly:
`bash docker/patch_db.sh` from the repository root.
The docker_hub Compose file runs an upstream prebuilt image, not EDU 1.0.0.

## Captive portal URL for FortiGate

The dedicated portal mapping is `${PORTAL_HTTP_PORT:-5500}:550` (host:container).
Configure `PORTAL_HTTP_PORT=5500` in `docker/.env`. Admin stays on host port 80;
port 5500 serves only `/login/bootstrap5/` and its static assets.

Set FortiGate external portal URL to `http://<SERVER_LAN_IP>:5500/login/bootstrap5/`.
Use the server IP reachable from the Wi-Fi client VLAN, not `localhost` or a container IP.
Allow unauthenticated clients to reach this IP and TCP port in FortiGate's portal access rules.
This listener is HTTP; HTTPS requires a separate certificate/listener configuration.

FortiGate appends `post` and `magic` to the redirect URL. The existing frontend retains
`magic` and POSTs `magic`, `username`, and `password` to the trusted FortiGate `/fgtauth`
URL supplied in `post`. Set its exact HTTPS origin in `login/bootstrap5/portal-config.js`
and rebuild the image. Do not set this origin to the RADIUSdesk server's portal URL.
FortiGate creates the authenticated session and reports it to RADIUS via UDP 1813;
the portal path itself is not a session creation or disconnect API.

Verify locally: `http://localhost:5500/login/bootstrap5/`.
Changing the port mapping alone requires `docker compose up -d`; changing the baked
Nginx config or frontend assets requires `docker compose build radiusdesk` first.
