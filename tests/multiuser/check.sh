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
# A one-entry batch is a 295-byte line in format v: 4, so the file rotates at every seventh line. The 72 lines
# written below leave two in the current file, which the checks after the loop read; keep the total off a
# multiple of seven.
for round in 1 2 3 4 5 6; do
    as "$WEB" $PHP "$LOG" 6 2000 3 "web$round" || fail "web user write in round $round"
    as "$CLI" $PHP "$LOG" 6 2000 3 "cli$round" || fail "console user write in round $round"
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

# 3. A rotation whose last step fails keeps every copy, also when it is tried again. In a sticky directory the
# console user may move its own copies but not the web user's current file, so `.2 → .3` and `.1 → .2` succeed
# and `current → .1` fails; the retry must only fill the gap at `.1`, not move `.2` over `.3` again.
STICKY="$DIR/sticky"
mkdir "$STICKY"
chgrp "$GROUP" "$STICKY"
chmod 3775 "$STICKY"
LOG3="$STICKY/queries.jsonl"
# The lock belongs to the console user too: with fs.protected_regular an O_CREAT open of another user's file in a
# sticky directory fails, which would stop the adapter before it reaches the rotation.
as "$CLI" sh -c "printf '{\"copy\":\"A\"}\n' > '$LOG3.1'; printf '{\"copy\":\"B\"}\n' > '$LOG3.2'; printf '{\"copy\":\"C\"}\n' > '$LOG3.3'; : > '$LOG3.lock'; chmod 664 '$LOG3'.[123] '$LOG3.lock'"
as "$WEB" sh -c "head -c 3000 /dev/zero | tr '\\\\0' 'x' > '$LOG3'; chmod 664 '$LOG3'"
size3=$(stat -c %s "$LOG3")
for try in 1 2; do
    if as "$CLI" $PHP "$LOG3" 1 2000 3 "sticky$try" 2>"$DIR/err3"; then fail "rotation of another user's file in a sticky directory did not fail (try $try)"; fi
done
copies=$(cat "$LOG3".[0-9]* 2>/dev/null | sort | tr -d '\n')
case "$copies" in *'"A"'*) ;; *) fail "copy A lost after a failed rotation and its retry: $copies" ;; esac
case "$copies" in *'"B"'*) ;; *) fail "copy B lost after a failed rotation and its retry: $copies" ;; esac
[ "$(stat -c %s "$LOG3")" -eq "$size3" ] || fail "the current file changed after failed rotations"
grep -q "rename $LOG3 to" "$DIR/err3" || fail "the retry did not fail at the last step: $(cat "$DIR/err3")"
echo "ok: failed last rotation step and its retry keep the copies"
