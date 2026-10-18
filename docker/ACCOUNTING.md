# Periodic RADIUS accounting

Both enabled RADIUSdesk virtual servers now supply `Acct-Interim-Interval = 60`
in successful authentication replies if no user/profile interval is present.
The fallback runs after RADIUSdesk resolves reply attributes. Reject subsections
remain unchanged. Session-Timeout and concurrent-device limits are unaffected.
This requests updates from the NAS; it does not manufacture usage counters.

## Deploy from the updated checkout on the server

From the repository root:

```sh
cd docker
docker compose build radiusdesk
docker compose run --rm --no-deps --entrypoint freeradius radiusdesk -XC
```

Proceed only if the configuration check succeeds:

```sh
docker compose up -d --no-deps radiusdesk
docker compose logs --tail=50 radiusdesk
```

Do not remove database volumes. Recreating the app briefly interrupts its services.
The change is baked into the image, so restarting an old image alone is insufficient.

For verification, capture on the server before a fresh test login:

```sh
sudo tcpdump -ni ens18 -vvv -s0 'udp port 1812 or udp port 1813'
```

Deauthenticate only the test user on FortiGate, then log in again. Confirm the
Access-Accept contains Acct-Interim-Interval 60; existing sessions do not receive
new reply attributes retroactively. Generate traffic over the test Wi-Fi. After
roughly 60–90 seconds verify Interim-Update, Framed-IP-Address, Acct-Input-Octets
and Acct-Output-Octets, followed by updated dashboard counters.

If updates arrive with nonzero counters but the dashboard stays at zero, inspect
database accounting updates and dashboard queries separately. If the NAS ignores
the interval, inspect its configuration and Access-Accept before changing the UI.
