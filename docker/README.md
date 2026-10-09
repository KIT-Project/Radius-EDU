# RADIUSdesk EDU 1.0.0

Builds the current repository checkout with Ubuntu 24.04 / PHP 8.3,
FreeRADIUS, Nginx and MariaDB 11.8. No additional repository clone is needed.

From the repository root on branch Dev:

```sh
bash docker/local_build.sh
```

The script builds `radiusdesk-edu:1.0.0`, starts MariaDB, waits for readiness,
initializes the database and applies bundled SQL patches before starting the app.
Open https://localhost:8000 (TCP 8000); RADIUS uses UDP 1812 and 1813.

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

The dedicated portal mapping is `${PORTAL_HTTP_PORT:-443}:550` (host:container).
Configure `PORTAL_HTTP_PORT=443` in `docker/.env`. Admin uses `${ADMIN_HTTP_PORT:-8000}:443` on host port 8000;
port 443 serves `/login/` (and the original `/login/bootstrap5/`) and its static assets.

Set FortiGate external portal URL to `https://<SERVER_LAN_IP>/login/`.
Use the server IP reachable from the Wi-Fi client VLAN, not `localhost` or a container IP.
Allow unauthenticated clients to reach this IP and TCP port in FortiGate's portal access rules.
Both web listeners use HTTPS. Host port variables retain their existing names for compatibility.

FortiGate appends `post` and `magic` to the redirect URL. The existing frontend retains
`magic` and POSTs `magic`, `username`, and `password` with the supplied gateway context to the FortiGate HTTPS `/fgtauth`
URL supplied in `post`, without requiring a preconfigured gateway IP. Optionally
restrict it by setting `fortigateOrigin` in `login/bootstrap5/portal-config.js` and
rebuilding the image. The default accepts any HTTPS origin with the exact `/fgtauth`
path; only open portal URLs received from your network gateway.
FortiGate creates the authenticated session and reports it to RADIUS via UDP 1813;
the portal path itself is not a session creation or disconnect API.

Verify locally: `https://localhost/login/`.
Changing the port mapping alone requires `docker compose up -d`; changing the baked
Nginx config or frontend assets requires `docker compose build radiusdesk` first.

## HTTPS certificates

Before the first build, set `TLS_SERVER_IP` and/or `TLS_SERVER_DNS` in `docker/.env`
to the address clients will use. For example, `TLS_SERVER_IP=192.168.230.90`.
`local_build.sh` creates a self-signed test certificate only when both files are absent.
It preserves any existing certificate and private key.

Provide an organization certificate in these files before building, or replace the
existing pair afterwards:

- `docker/data/tls/server.crt`: PEM certificate with intermediate chain, leaf first.
- `docker/data/tls/server.key`: matching unencrypted PEM private key, readable by container root.

Keep the key private (`chmod 600`); never add it to Git. The staging directory is
excluded from Git and Docker build context; certificates are mounted read-only.
The certificate must cover the IP or DNS name used in the URL. For a self-signed
certificate, distribute only `server.crt` to client devices and configure trust there.
A trusted organization certificate avoids browser trust warnings when its issuer
and address are valid. Captive portal browsers may refuse untrusted certificates.
After replacing a certificate, restart the app with `docker compose restart radiusdesk`
from `docker/`. After pulling this HTTPS change on an existing VM, run
`bash docker/local_build.sh` to generate/mount certificates and rebuild Nginx config.

## Update an existing VM to the standard HTTPS portal port

Host TCP 443 must be available. From the repository root:

```sh
git pull origin Dev
cd docker
sudo docker compose up -d
```

This mapping update does not require rebuilding an existing HTTPS image.
Admin remains at `https://<SERVER_LAN_IP>:8000/`; the portal is now
`https://<SERVER_LAN_IP>/login/`. Update the FortiGate external portal
URL and allow unauthenticated clients to reach server TCP 443.
Existing certificates are preserved because the server address is unchanged.

The short `/login/` path is an internal Nginx rewrite to the existing portal files;
relative assets and FortiGate query parameters are preserved. After pulling this
path change, run `sudo docker compose up -d --build` from `docker/`.

Login submissions use `/fgtauth?magic=<redirect-token>&auth=1`, matching the
working external portal integration. The same token is retained in form data,
along with the supplied login/post and client/AP fields. Credentials stay in
the POST body, and redirect fields cannot overwrite username/password.

## Disconnect a FortiGate captive-portal session

In NAS settings, select **FortiGate-COA**, use the FortiGate NAS IP reported in
Accounting, configure its existing RADIUS shared secret, and set **COA Port 3799**.
On FortiGate, enable CoA on the RADIUS server used for this portal:

```text
config user radius
    edit "Test"
        set radius-coa enable
    next
end
```

Allow the RADIUS server to reach the FortiGate on UDP 3799. Existing RADIUS
Accounting on UDP 1813 must remain enabled. The source IP of disconnect packets
must match the RADIUS server configured on FortiGate (normally the server LAN IP,
not a Docker container IP).

Use the existing **Activity Monitor → Accounting data → Kick/Disconnect** control.
The Dashboard is for monitoring only. For FortiGate captive portal the server sends
`User-Name` and `Framed-IP-Address`, plus Event-Timestamp and Message-Authenticator.
An authenticated Disconnect-ACK is required before reporting acknowledgment;
NAK, invalid responses, and timeout are reported as failures. The accounting row
stays open until FortiGate sends Accounting Stop. The command does not disable the
account, blacklist the client IP, or delete session history, so the client can
open the portal again and log in with the same IP. Real-device verification should
check ACK, Accounting Stop, then a new Accounting Start after re-login.

References: [Fortinet CoA configuration](https://community.fortinet.com/fortigate-3/technical-tip-how-to-configure-coa-change-of-authorization-support-on-the-fortigate-181038)
and [captive portal session attributes](https://community.fortinet.com/fortigate-3/technical-tip-radius-coa-behavior-100060).

### Trace a disconnect that does not send a packet

Watch the application trace while clicking the existing Disconnect control:

```bash
sudo docker exec -it radiusdesk sh -c 'tail -n 0 -F /var/www/html/cake4/rd_cake/logs/debug.log | grep --line-buffered -Ei "fortigate.?disconnect"'
```

The lookup lines identify the matched NAS type and CoA port. The structured
`[fortigate-disconnect]` entries record sending/rejection, the selected accounting
session, outgoing username/IP/timestamp, exit code and verified ACK/NAK output.
Shared secrets are excluded. Shared System NAS entries (`cloud_id = -1`) can be
used by an authorized session in the selected cloud, alongside cloud-owned NAS.

To confirm the packet reaches the host network independently of the interface:

```bash
sudo tcpdump -ni any -s0 -vvv 'host 10.10.10.1 and udp port 3799'
```
