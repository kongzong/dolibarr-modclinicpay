#!/bin/bash
# modClinicPay phase-2 integration driver (acceptance B / D, spec §7).
# Real-DB concurrency: 20 parallel confirms of one draft -> exactly one
# invoice; refund two-step with the double-person red line.
#
# Usage: run from Git Bash with the DoliWamp PHP 7.4 binary:
#   PHP=/d/dolibarr/bin/php/php7.4.26/php.exe bash run_phase2.sh
set -u
PHP=${PHP:-/d/dolibarr/bin/php/php7.4.26/php.exe}
ROOT="$(cd "$(dirname "$0")" && pwd)"
STEP="$ROOT/bill_step.php"
SQLTOOL="$ROOT/../../../scripts/doli_sql.php"
WORK="$(mktemp -d)"

q() { "$PHP" "$SQLTOOL" "$1" 2>/dev/null | tail -n +2; }

echo "== pick fixtures =="
PATIENT=$(q "SELECT rowid FROM llx_patient_profile WHERE fk_soc > 0 ORDER BY rowid LIMIT 1" | head -1)
PRODUCT=$(q "SELECT rowid FROM llx_product WHERE price > 0 ORDER BY rowid LIMIT 1" | head -1)
USER2=$(q "SELECT login FROM llx_user WHERE admin = 0 ORDER BY rowid LIMIT 1" | head -1)
echo "patient=$PATIENT product=$PRODUCT user2=${USER2:-<none>}"
if [ -z "$PATIENT" ] || [ -z "$PRODUCT" ]; then
	echo "FAIL: need a patient with fk_soc and at least one product"; exit 1
fi

echo "== create draft (admin) =="
CREATE=$("$PHP" "$STEP" create "$PATIENT" "$PRODUCT" 2 admin)
echo "$CREATE"
BILL=$(echo "$CREATE" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
REF=$(echo "$CREATE" | grep -o '"ref":"[^"]*"' | head -1 | cut -d'"' -f4)
if [ -z "$BILL" ] || [ "$BILL" = "0" ]; then
	# ref field is returned only on create; get rowid via SQL
	BILL=$(q "SELECT rowid FROM llx_clinicpay_bill ORDER BY rowid DESC LIMIT 1" | head -1)
	REF=$(q "SELECT ref FROM llx_clinicpay_bill WHERE rowid = $BILL" | head -1)
fi
echo "bill=$BILL ref=$REF"

echo "== 20 parallel confirms =="
for i in $(seq 1 20); do
	"$PHP" "$STEP" confirm "$BILL" CASH "RACE-$i" admin > "$WORK/c$i.json" 2>"$WORK/c$i.err" &
done
wait
OK=0; for i in $(seq 1 20); do
	RC=$(grep -o '"rc":[^,}]*' "$WORK/c$i.json" | head -1 | cut -d: -f2)
	[ "$RC" = "1" ] && OK=$((OK+1))
done
echo "confirm rc=1 count: $OK (expect 1)"
INVOICES=$(q "SELECT COUNT(*) FROM llx_facture WHERE note_public = 'ClinicPay bill $REF' AND type = 0" | head -1)
echo "invoices created: $INVOICES (expect 1)"
BSTATUS=$(q "SELECT status FROM llx_clinicpay_bill WHERE rowid = $BILL" | head -1)
echo "bill status: $BSTATUS (expect 1)"

echo "== refund two-step + double-person red line =="
if [ -n "$USER2" ]; then
	# creator = USER2, first execution attempt by the same (non-admin) user
	"$PHP" "$STEP" refund_draft "$BILL" "test refund" "$USER2"
	"$PHP" "$STEP" refund_exec "$BILL" "$USER2"
	echo "same-person execution above must end with rc=-2 ClinicPayErrSamePerson"
	"$PHP" "$STEP" refund_exec "$BILL" admin
	STATUS9=$(q "SELECT status FROM llx_clinicpay_bill WHERE rowid = $BILL" | head -1)
	echo "bill status after refund: $STATUS9 (expect 9)"
	CN=$(q "SELECT COUNT(*) FROM llx_facture WHERE type = 2 AND fk_statut > 0 AND note_public = 'ClinicPay refund of bill $REF'" | head -1)
	echo "validated credit notes: $CN (expect 1)"
	CNPAY=$(q "SELECT COUNT(*) FROM llx_paiement_facture WHERE fk_facture IN (SELECT rowid FROM llx_facture WHERE type = 2 AND note_public = 'ClinicPay refund of bill $REF')" | head -1)
	echo "refund payments: $CNPAY (expect 1)"
else
	echo "SKIP: no non-admin user in this database"
fi
rm -rf "$WORK"
