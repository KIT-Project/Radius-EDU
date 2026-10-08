# Disposable admin UI demo

Uses existing Cloud 23 and its first realm. Creates only `demo-ui-` records:
12 disabled user accounts without passwords or radcheck credentials; 3 profiles
and their policy components; Session-Timeout and Fortinet-Group-Name examples;
2 NAS entries at documentation-only IPs with random secrets; 6 open accounting
sessions; 5 closed sessions/history records; and 12 synthetic authentication logs.
These are display fixtures, not real login/accounting results or proof of FortiGate connectivity.
Do not use the demo NAS entries for a real gateway or attempt Disconnect against them.

From the docker directory:

```bash
docker cp demo/seed-dashboard.php radiusdesk:/tmp/seed-dashboard.php
docker exec radiusdesk php /tmp/seed-dashboard.php
```

Remove all fixture records while preserving existing records:

```bash
docker cp demo/seed-dashboard.php radiusdesk:/tmp/seed-dashboard.php
docker exec radiusdesk php /tmp/seed-dashboard.php --remove
```

The script wraps insertion/removal in a transaction. Re-running refreshes the same fixture set.
