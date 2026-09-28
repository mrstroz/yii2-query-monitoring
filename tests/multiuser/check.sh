#!/bin/sh
# YQM-54, spec 03 §3: the file adapter shared by two users of one group (web server and console).
# Runs as root inside the package image and drops to two unprivileged users with setpriv; see run.sh.
set -eu

GROUP=2700
WEB=2701
CLI=2702
DIR=$(mktemp -d /tmp/qm-multiuser-XXXXXX)
LOGDIR="$DIR/logs/query-monitoring"
LOG="$LOGDIR/queries.jsonl"
PHP="php /app/tests/multiuser/write.php"
as() { uid=$1; shift; setpriv --reuid="$uid" --regid="$uid" --groups="$GROUP" --inh-caps=-all "$@"; }
fail() { echo "FAIL: $*" >&2; exit 1; }
trap 'rm -rf "$DIR"' EXIT

# The shared directory: group-owned, setgid, group-writable, as the README tells the operator to prepare it.
chgrp "$GROUP" "$DIR"
chmod 2775 "$DIR"

# 1. Alternating writes and rotations by both users: every send succeeds, every line is valid JSON.
for round in 1 2 3 4 5 6; do
    as "$WEB" $PHP "$LOG" 7 2000 3 "web$round" || fail "web user write in round $round"
    as "$CLI" $PHP "$LOG" 7 2000 3 "cli$round" || fail "console user write in round $round"
done
[ -f "$LOG.1" ] || fail "no rotation happened"
# Both directories the adapter created (the missing parent too) inherit the group, keep the inherited setgid
# bit and get dirMode, or files created in them later would get the creating user's own group.
for d in "$DIR/logs" "$LOGDIR"; do
    [ "$(stat -c %g "$d")" = "$GROUP" ] || fail "$d not in the shared group"
    [ -g "$d" ] || fail "$d lost the inherited setgid bit (mode $(stat -c %a "$d"))"
    [ "$(stat -c %a "$d")" = "2775" ] || fail "$d mode $(stat -c %a "$d"), expected 2775"
done
for f in "$LOG" "$LOG".[0-9]*; do
    php -r '
        foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $n => $line) {
            json_decode($line, flags: JSON_THROW_ON_ERROR);
        }
    ' "$f" || fail "invalid JSON line in $f"
done
for f in "$LOG" "$LOG.lock" "$LOG".[0-9]*; do
    [ "$(stat -c %g "$f")" = "$GROUP" ] || fail "$f not in the shared group"
    case "$(stat -c %a "$f")" in 664) ;; *) fail "$f mode $(stat -c %a "$f"), expected 664" ;; esac
done
echo "ok: alternating writes and rotations by two users"

# 2. Directory without group write: the console user's rotation fails, the copies survive, the file stops growing.
chmod 2755 "$LOGDIR"
chown "$WEB" "$LOGDIR"
before=$(cd "$LOGDIR" && sha256sum queries.jsonl.[0-9]* | sort)
line=$(tail -n 1 "$LOG" | wc -c)
if as "$CLI" $PHP "$LOG" 40 2000 3 "denied" 2>"$DIR/err"; then fail "rotation without group write did not fail"; fi
after=$(cd "$LOGDIR" && sha256sum queries.jsonl.[0-9]* | sort)
[ "$before" = "$after" ] || fail "an archived copy changed after a failed rotation"
size=$(stat -c %s "$LOG")
[ "$size" -le $((2000 + line + 200)) ] || fail "current file grew to $size bytes"
grep -q "$LOG" "$DIR/err" || fail "the failure does not name the file: $(cat "$DIR/err")"
echo "ok: failed rotation keeps copies and bounds the file"
