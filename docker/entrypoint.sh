#!/bin/sh
# Carbure container entrypoint: prepares /data, brings the database schema up to
# date, optionally schedules the bank synchronization, then starts PHP-FPM and nginx.
# On the first start (no /data/conf/prod.ini), the installation is done in the
# portal: open http://<host>:8080/ in a browser.
set -e

APP=/var/www/carbure
mkdir -p /data/conf/certs /data/logs /data/cache /data/woob
chown -R www-data:www-data /data
# woob (logins and passwords of the banks, modules): readable by www-data only. woob
# refuses to start if its file of the banks is readable by other users, as it is once
# copied into a NAS shared folder; a copied folder may also have lost its owner rights.
chmod -R u+rwX,go-rwx /data/woob 2>/dev/null || true

# Update of woob at each start: repositories, keyring of the woob repository (to check
# the signature of the bank modules; without it, installing a module fails with "No
# keyring found") and installed bank modules. In the background (the portal starts
# without waiting for the network), as www-data (the owner of /data/woob).
# The keyring of the woob repository is trusted by an administrator, who compares its
# fingerprint in the portal (Accounts page, "update the bank modules"): until then, the
# modules are not updated. The next keyrings are checked by woob with the previous one.
woob_update() {
    if [ -z "$(ls -A /data/woob/.local/share/woob/keyrings 2>/dev/null)" ]; then
        echo "Carbure: woob repository not trusted yet: an administrator must trust it from the Accounts page of the portal to update the bank modules" >&2
        return 0
    fi
    # Only once a keyring is trusted: without one, woob would trust the keyring of its
    # repository by itself ("*** ASSUMING YES ***"); with one, woob checks the next ones
    output=$(su -s /bin/sh www-data -c "env HOME=/data/woob woob config update" < /dev/null 2>&1) || true
    if echo "$output" | grep -qiE "aborted|error|not trusted|invalid signature"; then
        echo "Carbure: woob update failed, it will be retried at the next start: $(echo "$output" | grep -iE "aborted|error|not trusted|invalid signature" | tail -1)" >&2
        return 1
    fi
}
woob_update &

if [ -f /data/conf/prod.ini ]; then
    # Wait for the database, then apply the migrations of the new version (if any);
    # the container stops if a migration fails rather than running on a wrong schema
    for i in $(seq 1 60); do
        php -r '$c = parse_ini_file("/data/conf/prod.ini"); mysqli_report(MYSQLI_REPORT_OFF);
            exit(@new mysqli($c["db_hostname"], $c["db_username"], $c["db_password"], $c["db_name"], (int)($c["db_port"] ?? 3306)) && !mysqli_connect_errno() ? 0 : 1);' 2>/dev/null && break
        echo "Carbure: waiting for the database..."
        sleep 2
    done
    su -s /bin/sh www-data -c "php $APP/tools/migrate.php"
else
    echo "Carbure: not installed yet, open the portal in a browser to install it (http://HOST:8080/)"
fi

# Optional periodic synchronization (e.g. SYNC_INTERVAL=86400 for once a day);
# the token is read at each run: the configuration may be created later by the wizard
if [ -n "${SYNC_INTERVAL}" ]; then
    (
        while true; do
            sleep "${SYNC_INTERVAL}"
            TOKEN=$(php -r '$c = @parse_ini_file("/data/conf/prod.ini"); echo $c["sync_token"] ?? "";')
            [ -n "${TOKEN}" ] || continue
            curl -fsS -H "X-Sync-Token: ${TOKEN}" http://localhost/api/bank/sync > /dev/null 2>&1 \
                || echo "Carbure: scheduled synchronization failed" >&2
        done
    ) &
fi

php-fpm -D
exec "$@"
