#!/bin/bash
# modClinicPay phase-3 integration driver (acceptance B / E, spec §7).
# Real-DB checks: sell -> invoice + card + CREATE log; 20 parallel consumes
# of the last visit -> exactly one success; over-value refused; expired card
# lazily marked EXPIRE; card refund with the two-person red line.
#
# Usage: run from Git Bash with the DoliWamp PHP 7.4 binary:
#   PHP=/d/dolibarr/bin/php/php7.4.26/php.exe bash run_phase3.sh
set -u
PHP=${PHP:-/d/dolibarr/bin/php/php7.4.26/php.exe}
ROOT="$(cd "$(dirname "$0")" && pwd)"
STEP="$ROOT/card_step.php"
SQLTOOL="$ROOT/../../../scripts/doli_sql.php"
WORK="$(mktemp -d)"

q() { "$PHP" "$SQLTOOL" "$1" 2>/dev/null | tail -n +2; }

echo "== pick fixtures =="
PATIENT=$(q "SELECT rowid FROM llx_patient_profile WHERE fk_soc > 0 ORDER BY rowid LIMIT 1" | head -1)
USER2=$(q "SELECT login FROM llx_user WHERE admin = 0 ORDER BY rowid LIMIT 1" | head -1)
echo "patient=$PATIENT user2=${USER2:-<none>}"
if [ -z "$PATIENT" ]; then
	echo "FAIL: need a patient with fk_soc"; exit 1
fi

echo "== sell VALUE card (admin, CASH, 25.5) =="
SELL=$("$PHP" "$STEP" sell "$PATIENT" VALUE 0 25.5 CASH "" admin)
echo "$SELL"
CARD=$(echo "$SELL" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
CKREF=$(echo "$SELL" | grep -o '"ref":"[^"]*"' | head -1 | cut -d'"' -f4)
BILL=$(echo "$SELL" | grep -o '"bill":[0-9]*' | head -1 | cut -d: -f2)
if [ -z "$CARD" ] || [ "$CARD" = "0" ]; then echo "FAIL: sell failed"; exit 1; fi
echo "card=$CARD ref=$CKREF bill=$BILL"
BSTATUS=$(q "SELECT status FROM llx_clinicpay_bill WHERE rowid = $BILL" | head -1)
INVOICE=$(q "SELECT COUNT(*) FROM llx_facture WHERE note_public = CONCAT('ClinicPay bill ', (SELECT ref FROM llx_clinicpay_bill WHERE rowid = $BILL)) AND type = 0 AND fk_statut > 0" | head -1)
CREATELOG=$(q "SELECT COUNT(*) FROM llx_clinicpay_card_log WHERE fk_card = $CARD AND op = 'CREATE'" | head -1)
echo "bill status: $BSTATUS (expect 1); invoices: $INVOICE (expect 1); CREATE logs: $CREATELOG (expect 1)"

echo "== top up +10 (CHARGE log, same-transaction bill) =="
"$PHP" "$STEP" charge "$CARD" VALUE 0 10 CASH "" admin
TOTALVAL=$(q "SELECT total_value FROM llx_clinicpay_card WHERE rowid = $CARD" | head -1)
CHLOG=$(q "SELECT COUNT(*) FROM llx_clinicpay_card_log WHERE fk_card = $CARD AND op = 'CHARGE'" | head -1)
echo "total_value after top-up: $TOTALVAL (expect 35.5); CHARGE logs: $CHLOG (expect 1)"

echo "== 20 parallel consumes of the last visit (COUNT card, count=1) =="
SELL2=$("$PHP" "$STEP" sell "$PATIENT" COUNT 1 1 CASH "" admin)
echo "$SELL2"
CARD2=$(echo "$SELL2" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
if [ -z "$CARD2" ] || [ "$CARD2" = "0" ]; then echo "FAIL: count-card sell failed"; exit 1; fi
for i in $(seq 1 20); do
	"$PHP" "$STEP" consume "$CARD2" "" admin > "$WORK/c$i.json" 2>"$WORK/c$i.err" &
done
wait
OK=0; for i in $(seq 1 20); do
	RC=$(grep -o '"rc":[^,}]*' "$WORK/c$i.json" | head -1 | cut -d: -f2)
	[ "$RC" = "1" ] && OK=$((OK+1))
done
echo "consume rc=1 count: $OK (expect 1)"
USED=$(q "SELECT used_count FROM llx_clinicpay_card WHERE rowid = $CARD2" | head -1)
CSTATUS=$(q "SELECT status FROM llx_clinicpay_card WHERE rowid = $CARD2" | head -1)
CLOGS=$(q "SELECT COUNT(*) FROM llx_clinicpay_card_log WHERE fk_card = $CARD2 AND op = 'CONSUME'" | head -1)
echo "used_count: $USED (expect 1); status: $CSTATUS (expect 1 used-up); CONSUME logs: $CLOGS (expect 1)"

echo "== VALUE over-balance refused =="
"$PHP" "$STEP" consume "$CARD" 100 admin
echo "above must be rc=-2 ClinicPayErrCardOverValue"
USEDVAL=$(q "SELECT used_value FROM llx_clinicpay_card WHERE rowid = $CARD" | head -1)
echo "used_value unchanged: $USEDVAL (expect 0)"

echo "== expired card lazily marked EXPIRE =="
SELL3=$("$PHP" "$STEP" sell "$PATIENT" COUNT 5 1 CASH "" admin)
CARD3=$(echo "$SELL3" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
q "UPDATE llx_clinicpay_card SET date_end = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE rowid = $CARD3" > /dev/null
"$PHP" "$STEP" consume "$CARD3" "" admin
echo "above must be rc=-2 ClinicPayErrCardExpired"
ESTATUS=$(q "SELECT status FROM llx_clinicpay_card WHERE rowid = $CARD3" | head -1)
ELOG=$(q "SELECT COUNT(*) FROM llx_clinicpay_card_log WHERE fk_card = $CARD3 AND op = 'EXPIRE'" | head -1)
echo "expired status: $ESTATUS (expect 2); EXPIRE logs: $ELOG (expect 1)"

echo "== card refund two-person red line (fresh card, no top-ups) =="
if [ -n "$USER2" ]; then
	SELL4=$("$PHP" "$STEP" sell "$PATIENT" COUNT 3 2 CASH "" admin)
	echo "$SELL4"
	CARD4=$(echo "$SELL4" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
	BILL4=$(echo "$SELL4" | grep -o '"bill":[0-9]*' | head -1 | cut -d: -f2)
	if [ -z "$CARD4" ] || [ "$CARD4" = "0" ]; then echo "FAIL: refund-fixture sell failed"; exit 1; fi
	"$PHP" "$STEP" refund_create "$CARD4" "integration refund" "$USER2"
	"$PHP" "$STEP" refund_exec "$CARD4" "$USER2"
	echo "same-person execution above must end with rc=-2 ClinicPayErrSamePerson"
	"$PHP" "$STEP" refund_exec "$CARD4" admin
	RSTATUS=$(q "SELECT status FROM llx_clinicpay_card WHERE rowid = $CARD4" | head -1)
	RLOG=$(q "SELECT COUNT(*) FROM llx_clinicpay_card_log WHERE fk_card = $CARD4 AND op = 'REFUND'" | head -1)
	CN=$(q "SELECT COUNT(*) FROM llx_facture WHERE type = 2 AND fk_statut > 0 AND fk_facture_source = (SELECT fk_invoice FROM llx_clinicpay_bill WHERE rowid = $BILL4)" | head -1)
	echo "card status: $RSTATUS (expect 3); REFUND logs: $RLOG (expect 1); validated credit notes: $CN (expect 1)"
else
	echo "SKIP: no non-admin user in this database"
fi

rm -rf "$WORK"
