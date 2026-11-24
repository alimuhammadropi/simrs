#!/usr/bin/env bash
set -euo pipefail

BASE="${1:-http://127.0.0.1:8086/api}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
TOKEN=""

api() {
  local method="$1" path="$2" body="${3:-}" code
  local args=(-sS -X "$method" -H 'Content-Type: application/json' -o "$TMP/out" -w '%{http_code}' "$BASE$path")
  if [[ -n "$TOKEN" ]]; then args+=(-H "Authorization: Bearer $TOKEN"); fi
  if [[ -n "$body" ]]; then args+=(--data "$body"); fi
  code="$(curl "${args[@]}")"
  if [[ ! "$code" =~ ^2 ]]; then
    echo "FAIL $method $path -> HTTP $code" >&2
    python3 -m json.tool "$TMP/out" >&2 2>/dev/null || cat "$TMP/out" >&2
    exit 1
  fi
}

data() {
  python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d["data"][sys.argv[2]])' "$TMP/out" "$1"
}

nested() {
  python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(eval("d[\"data\"]" + sys.argv[2]))' "$TMP/out" "$1"
}

uuid() { python3 -c 'import uuid; print(uuid.uuid4())'; }
nik() { python3 -c 'import random; print(random.randint(1000000000000000,9999999999999999))'; }

echo "Smoke test: $BASE"

api POST /auth/login '{"username":"admin","password":"Admin123!"}'
TOKEN="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["data"]["token"])' "$TMP/out")"
echo "OK login"

api POST /patients "{\"name\":\"Pasien Smoke\",\"nik\":\"$(nik)\",\"gender\":\"L\",\"phone\":\"081234567890\"}"
PATIENT_ID="$(data id)"
echo "OK pasien #$PATIENT_ID"

KEY="$(uuid)"
TODAY="$(date +%F)"
REG_BODY="{\"patient_id\":$PATIENT_ID,\"polyclinic_id\":1,\"doctor_id\":1,\"registration_date\":\"$TODAY\",\"idempotency_key\":\"$KEY\"}"
api POST /registrations "$REG_BODY"
REG_ID="$(data id)"
FIRST_ID="$REG_ID"
api POST /registrations "$REG_BODY"
SECOND_ID="$(data id)"
if [[ "$FIRST_ID" != "$SECOND_ID" ]]; then echo "FAIL idempotensi registrasi" >&2; exit 1; fi
echo "OK registrasi #$REG_ID (idempoten)"

api POST "/registrations/$REG_ID/status" '{"status":"waiting"}'
api POST /examinations "{\"registration_id\":$REG_ID,\"subjective\":\"Demam\",\"objective\":\"Baik\",\"assessment\":\"J00\",\"plan\":\"Istirahat\",\"vital_signs\":{\"temperature\":\"38\"},\"diagnoses\":[{\"diagnosis_id\":1,\"is_primary\":true}]}"
EXAM_ID="$(data id)"
echo "OK pemeriksaan #$EXAM_ID"

api POST "/registrations/$REG_ID/actions" '{"medical_action_id":1,"quantity":1}'
echo "OK tindakan"

api POST /prescriptions "{\"registration_id\":$REG_ID,\"examination_id\":$EXAM_ID,\"items\":[{\"drug_id\":1,\"dose\":\"1 tablet\",\"frequency\":\"3x hari\",\"quantity\":3}]}"
PRESCRIPTION_ID="$(data id)"
api POST "/prescriptions/$PRESCRIPTION_ID/dispense"
echo "OK resep dan penyerahan obat"

api POST /lab/requests "{\"registration_id\":$REG_ID,\"examination_id\":$EXAM_ID,\"lab_test_id\":1}"
LAB_ID="$(data id)"
api POST "/lab/requests/$LAB_ID/result" '{"result":"Hb 13 g/dL","reference_range":"12-16"}'
echo "OK laboratorium"

api POST /invoices "{\"registration_id\":$REG_ID}"
INVOICE_ID="$(nested '["invoice"]["id"]')"
TOTAL="$(nested '["invoice"]["total"]')"
echo "OK invoice #$INVOICE_ID total $TOTAL"

PAY_KEY="$(uuid)"
PAY_BODY="{\"amount\":$TOTAL,\"method\":\"cash\",\"idempotency_key\":\"$PAY_KEY\"}"
api POST "/invoices/$INVOICE_ID/pay" "$PAY_BODY"
PAYMENT_ID="$(nested '["payment"]["id"]')"
api POST "/invoices/$INVOICE_ID/pay" "$PAY_BODY"
SECOND_PAYMENT_ID="$(nested '["payment"]["id"]')"
if [[ "$PAYMENT_ID" != "$SECOND_PAYMENT_ID" ]]; then echo "FAIL idempotensi pembayaran" >&2; exit 1; fi
echo "OK pembayaran (idempoten)"

api GET /reports/dashboard
VISITS="$(data visits)"
echo "OK dashboard: kunjungan=$VISITS"

api POST "/examinations/$EXAM_ID/finalize"
echo "OK finalisasi"
echo "SMOKE TEST LULUS"
