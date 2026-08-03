#!/usr/bin/env bash
#
# Produce cPanel-ready copies of the SQL files.
#
# On shared hosting you cannot CREATE DATABASE from SQL — cPanel creates it
# for you under a prefixed name (deamco_APL) and the MySQL user
# it issues has no such privilege. This strips the CREATE DATABASE / USE
# statements so the files import cleanly into a database you already made.
#
#   ./deploy/strip-create-database.sh
#   -> deploy/out/schema.sql, seed.sql, seed_match.sql
#
# Generated on demand rather than committed, so it can never drift from the
# real schema.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out="$root/deploy/out"
mkdir -p "$out"

for f in schema.sql seed.sql seed_match.sql; do
    src="$root/database/$f"
    [ -f "$src" ] || { echo "skip $f (not found)"; continue; }

    # Drop the CREATE DATABASE statement (it spans three lines) and every
    # USE statement. Everything else is untouched.
    sed -E '/^CREATE DATABASE/,/;$/d; /^USE `cric_auction`;/d' "$src" > "$out/$f"

    echo "wrote deploy/out/$f"
done

cat <<'NOTE'

Next: in cPanel -> MySQL Databases, create the database and a user, grant
ALL PRIVILEGES, then import deploy/out/*.sql into it via phpMyAdmin in this
order: schema.sql, seed.sql, seed_match.sql.
NOTE
