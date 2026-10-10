#!/usr/bin/env bash
# Concurrency proof: N parallel processes reserve budget / claim jobs at the same time.
# Run inside the wp-env cli container: npx wp-env run cli bash wp-content/plugins/ai-internal-link-suggester/tests/concurrency/run.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
N=${N:-30}

wp eval 'AILSUG\Settings::update( array( "budget_cap_micros" => 10000 ) ); global $wpdb; $t = AILSUG\Activator::tables(); $wpdb->query( "DELETE FROM {$t["budget"]}" ); $wpdb->query( "DELETE FROM {$t["reservations"]}" ); AILSUG\Cost\Budget::ensure_row();'

OUT=$(mktemp)
for i in $(seq 1 "$N"); do AILSUG_RACE_MODE=budget wp eval-file "$DIR/worker.php" >> "$OUT" & done
wait
OK=$(grep -c '^OK' "$OUT" || true)
MAXSEEN=$(grep '^OK' "$OUT" | awk '{print $2}' | sort -n | tail -1)
FINAL=$(wp eval 'echo wp_json_encode( AILSUG\Cost\Budget::status() );')
echo "budget: $OK of $N reservations granted; highest committed (spent+reserved) seen: ${MAXSEEN:-0}; final: $FINAL"
SPENT=$(echo "$FINAL" | php -r '$s=json_decode(stream_get_contents(STDIN),true); echo $s["spent"]+$s["reserved"];')
if [ "${MAXSEEN:-0}" -gt 10000 ] || [ "$SPENT" -gt 10000 ]; then echo "FAIL: cap exceeded"; exit 1; fi

RUN=$(wp eval '$jobs = array(); for ( $i = 1; $i <= 60; $i++ ) { $jobs[] = array( "race", $i ); } echo AILSUG\Queue\Queue::create_run( "race", $jobs );')
: > "$OUT"
for i in $(seq 1 "$N"); do AILSUG_RACE_MODE=queue AILSUG_RACE_RUN="$RUN" wp eval-file "$DIR/worker.php" >> "$OUT" & done
wait
TOTAL=$(grep -c '^JOB' "$OUT" || true)
UNIQUE=$(grep '^JOB' "$OUT" | sort -u | wc -l | tr -d ' ')
echo "queue: $TOTAL claims, $UNIQUE unique jobs"
wp eval "AILSUG\Queue\Queue::set_run_status( '$RUN', 'cancelled' ); AILSUG\Settings::update( array( 'budget_cap_micros' => 10000000 ) );"
if [ "$TOTAL" != "$UNIQUE" ]; then echo "FAIL: a job was claimed twice"; exit 1; fi
echo "PASS"
