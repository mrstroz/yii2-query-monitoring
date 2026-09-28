#!/bin/sh
# Runs tests/multiuser/check.sh as root in the package image, outside PHPUnit (the suite runs as UID:GID and
# cannot switch users). Usage from the repository root: tests/multiuser/run.sh [compose project], PHP_VERSION honoured.
set -eu
PROJECT=${1:-yii2-query-monitoring}
exec docker compose -p "$PROJECT" run --rm --no-deps --user 0 php sh /app/tests/multiuser/check.sh
