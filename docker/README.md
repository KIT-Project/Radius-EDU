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

## Captive login destination and failed-login page

The school portal sends its own `/login/success.html#<random-attempt>` destination
as `CONTINUE_URL` with the native `/fgtauth` POST (query string and form field).
The form explicitly targets the current top-level tab. The continuation replaces
itself with Google and uses a same-origin BroadcastChannel to notify a login tab
still awaiting the matching attempt. It never finishes a different or earlier login
just because the client IP is the same. No credentials are included in this signal.
FortiGate still validates
credentials before redirecting; the portal does not navigate on submit or assume
that an HTTP 200 response means authentication succeeded. Fortinet documents
`CONTINUE_URL` as overriding the interface's redirect destination:
[Captive portals](https://docs.fortinet.com/document/fortigate/latest/administration-guide/934626/captive-portals).
Verify the redirect on the deployed FortiGate firmware with a real client.

When FortiGate returns `/login/?Auth=Failed`, the school page displays a Thai
login-failure alert. If the response omits `magic` and `post`, the retry link opens
an HTTP page so the gateway can issue a fresh challenge. No credentials or
transaction tokens are saved in browser storage. Login failures can also reflect
group permissions, so the alert does not assume every rejection is a bad password.

Run the portal flow checks with `node --test login/bootstrap5/tests/schoolPortal.test.cjs`.

## Automatic session and idle timeouts

Non-EAP logins with a `NAS-Identifier` beginning with `FortiGate` receive default
RADIUS reply attributes `Session-Timeout := 28800` (8 hours per login) and
`Idle-Timeout := 1800` (30 minutes). Explicit user/profile reply values take
precedence. Existing remaining-time quota and expiration policies can shorten
the session limit. These defaults are applied to new authentications, not sessions
that are already connected. The school Profile create/edit screen asks only for a name. Creating a Profile uses
an 8-hour default; renaming one preserves its existing timeout. The separate
**ตั้งเวลา Session** toolbar/context-menu action accepts a positive integer and
**minutes** or **hours**, converting the value on the server to the standard
`Session-Timeout := <seconds>` reply. Values are limited to at most seven days.
For example, 90 minutes becomes 5400 seconds and 8 hours becomes 28800 seconds.
Saving session settings replaces the managed `SimpleAdd_<id>` component's legacy
quota, speed, time-slot and simultaneous-session entries with the selected session
limit. Other attached components are not modified. Existing sessions must
authenticate again to receive a changed duration. Idle enforcement remains a
FortiGate setting, as described below. The school UI removes the legacy FUP and
advanced component edit actions.


FortiGate must enforce the RADIUS session limit and the actual traffic idle timer:

```text
config user setting
    set radius-ses-timeout-act hard-timeout
    set auth-timeout-type idle-timeout
    set auth-timeout 30
end
```

This user setting applies to authenticated users in the current VDOM. Check the
school's RADIUS user groups for nonzero `authtimeout` overrides, which can change
the effective idle time. These settings are described in Fortinet's
[authentication settings](https://docs.fortinet.com/document/fortigate/7.4.7/administration-guide/709376/authentication-settings)
and [RADIUS session timeout action](https://docs.fortinet.com/document/fortigate/7.6.3/cli-reference/263822197/config-user-setting).
Do not rely on support for the RADIUS `Idle-Timeout` attribute alone for firewall
authentication; configure and verify the FortiGate idle timer as above.

Idle means no qualifying network traffic, not no mouse/keyboard interaction or
no accounting updates. Background traffic may keep a user active. FortiGate
expires the authentication and sends Accounting-Stop, after which the dashboard
removes the online session. No username or IP is banned: the user can authenticate
again from the same IP, starting a new 8-hour session.

After deploying, disconnect and authenticate again. Check the real Access-Accept
for both attributes, then validate expiration on the device with shorter temporary
Profile limits (for example `Session-Timeout := 120`) before an 8-hour test.

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

Watch the application trace while clicking **ตัดการเชื่อมต่อ** (Kick).
**ปิดรายการ** (Close Open Session) only closes the database record; it does not
disconnect access on FortiGate. For older containers where the log file has not
been created yet, initialize it as the PHP user before following it:

```bash
sudo docker exec -u www-data radiusdesk touch /var/www/html/cake4/rd_cake/logs/debug.log
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

### FortiGate QoS group matching

On successful authentication, NAS identifiers beginning with `FortiGate` receive
`Fortinet-Group-Name` from the authenticated user's server-side `Rd-Realm`
(the **Realms (Groups)** selection), independently of the time-limit Profile.
Configure the FortiGate RADIUS remote group match with that exact Realm name,
then use the matching firewall group in the QoS policy. This overrides legacy
Profile group replies when the user's Realm is available. Existing sessions
require a new login to receive the updated attribute. Verify it in the
`Access-Accept` packet, alongside `Session-Timeout`.

### Captive login retry confirmation

The login page captures a signed, three-minute accounting watermark before
posting credentials directly to FortiGate. If the native continuation leaves
the login tab waiting, the page polls the restricted same-origin action
`/cake4/rd_cake/radaccts/portal-session-status.json` every two seconds.
Only a newer active Accounting row for the submitted username and the actual
requesting IP confirms the login; older, closed and other-device sessions do
not. Passwords are never sent to this action. The portal listener exposes
only this action, without opening other admin API routes. Direct browser-to-portal
IP routing is required for this fallback (source NAT would prevent matching
`Framed-IP-Address`). The usual native success continuation remains enabled.

Debug on the server with:

```bash
sudo docker exec -it radiusdesk sh -c 'tail -n 0 -F /var/www/html/cake4/rd_cake/logs/debug.log | grep --line-buffered "portal-session"'
```

`portal-session started` records the caller IP and username; `portal-session
confirmed` indicates that the new Accounting session was found. No passwords
or confirmation tokens are logged.

### Concurrent device limits for school users

Set **จำนวนอุปกรณ์พร้อมกัน** in user creation or immediately below Profile in
Permanent Users → Edit → RADIUS info. Values are whole numbers from 0 to 20;
0 means unlimited. The existing `permanent_users.session_limit` column is used,
so no database migration is required.

For non-EAP requests from NAS identifiers beginning with `FortiGate`, RADIUS
counts distinct active Accounting devices for the username and rejects a new
device when the limit is reached. MAC separators/case are normalized, duplicate
rows for the same MAC count once, and reauthentication by an existing MAC is
allowed. Without a MAC, counting falls back to IP/session identity. Closed rows
are ignored. Accounting Start/Interim/Stop must arrive reliably; stale open rows
continue to occupy slots. Requests arriving together before their Accounting
Start records arrive can exceed the limit: this check does not reserve slots.
Changing the setting does not itself guarantee removal of existing devices;
verify the limit with new logins after active sessions are accounted for.

Validation used an isolated RADIUS listener and `docker/tests/device_limit.php`
against a disposable local database: third device accepted, fourth rejected,
same-MAC reauthentication, missing MAC, duplicate/closed rows, Accounting Stop,
unlimited mode, edited limits, invalid inputs, and unrelated NAS requests.
The script requires a local-only test server on port 19120 invoking
`RADIUSdesk_fortigate_device_limit` with PAP password `device-limit-fixture`.
Do not run it on the production database.

Deploy with the usual `git pull origin Dev` and
`docker compose up -d --build radiusdesk`, then refresh the admin page.
Test on FortiGate with 3 devices using the same user, followed by a fourth.
Disconnect one of the first three, wait for Accounting Stop, and retry the fourth.
