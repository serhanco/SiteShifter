#!/usr/bin/env bash
# End-to-end test: builds a real WordPress site, moves it to a new folder,
# database and domain in both modes, and checks the result by loading
# WordPress on the destination.
#
#   WP_CORE=/path/to/wordpress  DB_USER=ss DB_PASS=ss tests/e2e/run.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WP_CORE="${WP_CORE:?set WP_CORE to an unpacked WordPress}"
DB_USER="${DB_USER:-ss}"; DB_PASS="${DB_PASS:-ss}"; DB_HOST="${DB_HOST:-localhost}"
WORK="${WORK:-$(mktemp -d)}"
SS="php $ROOT/bin/siteshifter"
mysql_q() { mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "$1"; }

for db in ss_e2e_src ss_e2e_pkg ss_e2e_manual; do mysql_q "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4;"; done
rm -rf "$WORK"/{source,target-pkg,target-manual,files} && mkdir -p "$WORK"/{source,target-pkg,target-manual,files}

echo "== Source site"
cp -r "$WP_CORE"/. "$WORK/source/"
sed -e "s/database_name_here/ss_e2e_src/" -e "s/username_here/$DB_USER/" -e "s/password_here/$DB_PASS/" -e "s/localhost/$DB_HOST/" \
    "$WORK/source/wp-config-sample.php" > "$WORK/source/wp-config.php"
php "$ROOT/tests/e2e/seed-source.php" "$WORK/source" "http://old.test"

echo "== Package mode: export + install"
$SS export --path="$WORK/source" --out="$WORK/files/site.zip"
$SS install --dir="$WORK/target-pkg" --zip="$WORK/files/site.zip" --url="https://new.example.com" \
    --db-name=ss_e2e_pkg --db-user="$DB_USER" --db-pass="$DB_PASS" --db-host="$DB_HOST" --yes
php "$ROOT/tests/e2e/check-target.php" "$WORK/target-pkg" "https://new.example.com"

echo "== Manual mode: phpMyAdmin-style dump + zip of wp-content only"
mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" ss_e2e_src > "$WORK/files/dump.sql"
(cd "$WORK/source" && zip -qr "$WORK/files/wp-content.zip" wp-content)
(cd "$(dirname "$WP_CORE")" && rm -f "$WORK/files/core.zip" && ln -sfn "$(basename "$WP_CORE")" wordpress && zip -qr "$WORK/files/core.zip" wordpress/ -x 'wordpress/wp-content/*' && rm wordpress)
$SS install --dir="$WORK/target-manual" --zip="$WORK/files/wp-content.zip" --sql="$WORK/files/dump.sql" \
    --url="https://manual.example.com" --core-zip="$WORK/files/core.zip" \
    --db-name=ss_e2e_manual --db-user="$DB_USER" --db-pass="$DB_PASS" --db-host="$DB_HOST" --yes
php "$ROOT/tests/e2e/check-target.php" "$WORK/target-manual" "https://manual.example.com"

echo "== All end-to-end checks passed"
