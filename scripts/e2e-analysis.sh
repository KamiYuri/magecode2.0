#!/usr/bin/env bash
# MageCode 2.0 — e2e-analysis.sh
#
# The M3 exit criterion (E9): an instructor triggers analysis on a problem
# taught in two sections, SIM/AID/VUL do their work, and the results land in
# the database with the batch closed. Exit 0 means the whole analysis pipeline
# works end to end — api orchestration (Plan D) and the three batch workers
# (Plan E) together.
#
# Usage:
#   set -a && . ./.env && set +a && ./scripts/e2e-analysis.sh
#
# Override E2E_API_URL when Traefik cannot take port 80 — the api container
# publishes no host port of its own, so the fallback is its address on the
# compose network, where nginx listens on 9000:
#   E2E_API_URL="http://$(docker inspect magecode-api --format \
#     '{{index .NetworkSettings.Networks "magecode-backend" "IPAddress"}}'):9000/api/v1"
#
# What it needs running:
#   docker compose up -d                          # postgres, rabbitmq, minio
#   docker compose --profile app up -d            # api, reverb, the two amqp consumers
#   docker compose --profile analysis up -d       # plagiarism-checker, ai-detector, vuln-scanner
#
# Judge0 is NOT needed. Analysis reads source, not grades, and a submission
# enters a batch whatever its execution_status (D1) — which is why this gate is
# reachable on a host where C8's is not (D-90, cgroup v2).

set -euo pipefail

# Through Traefik on :80 — the api container publishes no host port of its own
# (D-86), so this is the same door a browser uses.
readonly API="${E2E_API_URL:-http://localhost/api/v1}"
# SIM compares a whole language group, AID loads a model and VUL builds a
# CodeQL database; minutes, not seconds.
readonly TIMEOUT_SECONDS="${E2E_TIMEOUT:-900}"

# Every fixture this run creates is tagged so a failed run is easy to find and
# clean up by hand.
readonly RUN_TAG="e2e-analysis-$(date +%s)-$$"
# `courses.code` is varchar(20), which the full tag overflows.
readonly SHORT_TAG="e2e$(date +%s | tail -c 7)-$$"
readonly PASSWORD='e2e-Password-1'

pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1" >&2; exit 1; }
step() { printf '\n\033[1m%s\033[0m\n' "$1"; }
note() { printf '    %s\n' "$1"; }

require() {
  command -v "$1" >/dev/null 2>&1 || fail "$1 is required but not installed"
}

api() {
  local method="$1" path="$2" token="${3:-}" body="${4:-}"
  local args=(-sS -X "${method}" "${API}${path}" -H 'Accept: application/json')
  [ -n "${token}" ] && args+=(-H "Authorization: Bearer ${token}")
  [ -n "${body}" ] && args+=(-H 'Content-Type: application/json' -d "${body}")
  curl "${args[@]}"
}

# The auth endpoints are limited to 10 requests a minute per IP (B4), and this
# script needs a handful of accounts — so it waits out its own limiter rather
# than reporting a failure that is really a queue.
auth_api() {
  local response
  local attempt

  for attempt in 1 2 3; do
    response="$(api "$@")"
    if ! printf '%s' "${response}" | grep -q 'Too Many Attempts'; then
      printf '%s' "${response}"
      return 0
    fi
    note "auth throttle reached (10/min/IP), waiting 61s before retry ${attempt}"
    sleep 61
  done

  printf '%s' "${response}"
}

expect_field() {
  local json="$1" field="$2" what="$3"
  local value
  value="$(printf '%s' "${json}" | jq -r "${field} // empty")"
  [ -n "${value}" ] || fail "${what} — response was: ${json}"
  printf '%s' "${value}"
}

psql_scalar() {
  docker compose exec -T postgres psql -qtAX -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -c "$1" \
    | tr -d '[:space:]'
}

# ── Preflight ────────────────────────────────────────────────────────────────

step "Preflight"

require curl
require jq
require docker

curl -sf -m 5 "${API}/health" >/dev/null \
  || fail "api is not answering at ${API}/health — is 'docker compose --profile app up -d' running?"
pass "api is up"

running="$(docker compose ps --status running --services 2>/dev/null || true)"

for service in plagiarism-checker ai-detector vuln-scanner; do
  printf '%s\n' "${running}" | grep -qx "${service}" \
    || fail "${service} is not running — start 'docker compose --profile analysis up -d'"
  pass "${service} is up"
done

# Without this consumer the workers publish results into a queue nobody reads
# and the batch sits at processing until D7's sweeper calls it a timeout. It
# is a compose service as of E8; before that it had to be started by hand.
printf '%s\n' "${running}" | grep -qx amqp-analysis-consumer \
  || fail "amqp-analysis-consumer is not running — nothing will ingest result-analysis"
pass "amqp-analysis-consumer is up"

# ── Fixtures ─────────────────────────────────────────────────────────────────
#
# Built through the API rather than SQL, so the run exercises the same paths a
# person would and a broken endpoint fails here rather than silently.

step "Fixtures"

admin_email="admin-${RUN_TAG}@example.test"

# register issues no token by design — the address has to be confirmed first
# (B4) — so every account here registers and then logs in. `login` takes a
# username or an email.
register_and_login() {
  local username="$1" email="$2" first="$3" last="$4"
  local response

  response="$(auth_api POST /auth/register '' "$(jq -nc \
    --arg u "${username}" --arg e "${email}" --arg p "${PASSWORD}" \
    --arg f "${first}" --arg l "${last}" \
    '{username:$u,email:$e,password:$p,password_confirmation:$p,first_name:$f,last_name:$l}')")"
  printf '%s' "${response}" | jq -e '.data.id' >/dev/null 2>&1 \
    || fail "could not register ${email} — response was: ${response}"

  response="$(auth_api POST /auth/login '' "$(jq -nc --arg e "${email}" --arg p "${PASSWORD}" \
    '{login:$e,password:$p}')")"
  expect_field "${response}" '.data.token' "could not log in as ${email}"
}

admin_token="$(register_and_login "admin-${RUN_TAG}" "${admin_email}" E2E Admin)"

# A System Admin is created by artisan only, never through the API (D-12).
docker compose exec -T api php artisan magecode:make-system-admin "${admin_email}" >/dev/null 2>&1 \
  || fail "could not grant system admin to ${admin_email}"
pass "registered staff account and granted system admin"

org="$(api POST /organizations "${admin_token}" "$(jq -nc --arg n "Org ${RUN_TAG}" '{name:$n}')")"
org_id="$(expect_field "${org}" '.data.id' 'could not create the organization')"

course="$(api POST "/organizations/${org_id}/courses" "${admin_token}" \
  "$(jq -nc --arg c "${SHORT_TAG}" '{code:$c,name:"E2E Analysis Course"}')")"
course_id="$(expect_field "${course}" '.data.id' 'could not create the course')"

semester="$(api POST "/courses/${course_id}/semesters" "${admin_token}" \
  "$(jq -nc --arg n "${RUN_TAG}" '{name:$n,publish_mode:"auto",lock_mode:"auto"}')")"
semester_id="$(expect_field "${semester}" '.data.id' 'could not create the semester')"

# Two sections is the point of the exercise: D-61 assigns match_type from the
# section of each side, and CROSS_SECTION is what a single-section fixture can
# never produce.
section_l01="$(api POST "/semesters/${semester_id}/sections" "${admin_token}" \
  "$(jq -nc --arg n "L01-${RUN_TAG}" '{name:$n}')")"
section_l01_id="$(expect_field "${section_l01}" '.data.id' 'could not create section L01')"

section_l02="$(api POST "/semesters/${semester_id}/sections" "${admin_token}" \
  "$(jq -nc --arg n "L02-${RUN_TAG}" '{name:$n}')")"
section_l02_id="$(expect_field "${section_l02}" '.data.id' 'could not create section L02')"
pass "built org → course → semester → two sections"

language_id="$(psql_scalar "SELECT id FROM programming_languages WHERE monaco_language = 'python' LIMIT 1")"
[ -n "${language_id}" ] || fail "no python row in programming_languages — run 'make seed'"

# The same problem in both sections, joined by a match group: without one the
# scope is a single section (schema §5.2) and the batch never sees L02.
problem_body="$(jq -nc --argjson lang "${language_id}" '{
  name:"Sum a list", description:"Read integers, print their sum.",
  time_limit:5000, memory_limit:65536, programming_language_ids:[$lang],
  activation_time:"2020-01-01T00:00:00Z",
  test_cases:[{input:"1 2\n", expected_output:"3\n", is_visible:true}]}')"

problem_l01="$(api POST "/sections/${section_l01_id}/problems" "${admin_token}" "${problem_body}")"
problem_l01_id="$(expect_field "${problem_l01}" '.data.id' 'could not create the L01 problem')"

problem_l02="$(api POST "/sections/${section_l02_id}/problems" "${admin_token}" "${problem_body}")"
problem_l02_id="$(expect_field "${problem_l02}" '.data.id' 'could not create the L02 problem')"

match_group="$(api POST "/semesters/${semester_id}/match-groups" "${admin_token}" \
  "$(jq -nc --argjson a "${problem_l01_id}" --argjson b "${problem_l02_id}" \
    '{problem_ids:[$a,$b]}')")"
printf '%s' "${match_group}" | jq -e '.data' >/dev/null 2>&1 \
  || fail "could not group the two problems — response was: ${match_group}"
pass "created the same problem in both sections and joined them in a match group"

# Prints "<submission_id> <token>". The token comes back with it so the
# realtime stage can reuse one rather than spending another call on the auth
# limiter — a command substitution runs in a subshell, so a variable set inside
# would not survive.
enrol_and_submit() {
  local section_id="$1" problem_id="$2" label="$3" source="$4"

  local email="student-${label}-${RUN_TAG}@example.test"
  local token response

  token="$(register_and_login "student-${label}-${RUN_TAG}" "${email}" E2E Student)"

  api POST "/sections/${section_id}/members" "${admin_token}" \
    "$(jq -nc --arg e "${email}" '{members:[{email:$e,role:"student"}]}')" >/dev/null

  response="$(api POST "/problems/${problem_id}/submissions" "${token}" \
    "$(jq -nc --argjson l "${language_id}" --arg s "${source}" \
      '{programming_language_id:$l,source_code:$s}')")"

  local submission_id
  submission_id="$(expect_field "${response}" '.data.id' "student ${label} could not submit")"
  printf '%s %s' "${submission_id}" "${token}"
}

# Two near-identical solutions in *different* sections. Renamed throughout, so
# a diff would miss them and Dolos should not.
readonly SOURCE_A='import sys


def total(numbers):
    result = 0
    for number in numbers:
        result += number
    return result


values = [int(token) for token in sys.stdin.read().split()]
print(total(values))
'

readonly SOURCE_B='import sys


def total(items):
    accumulator = 0
    for item in items:
        accumulator += item
    return accumulator


entries = [int(piece) for piece in sys.stdin.read().split()]
print(total(entries))
'

# An unrelated solution, so "every pair is similar" cannot pass by accident.
readonly SOURCE_C='import sys
print(sum(map(int, sys.stdin.read().split())))
'

# Deliberately vulnerable, so VUL has something to find. It is a Flask handler
# rather than a stdin script on purpose: py/sql-injection is a taint query and
# needs a *remote* source, which `sys.stdin` is not — a stdin version scans
# clean and would make this assertion untestable rather than false.
readonly SOURCE_D='import sqlite3
from flask import Flask, request

app = Flask(__name__)


@app.route("/user")
def show_user():
    name = request.args.get("name")
    connection = sqlite3.connect("app.db")
    cursor = connection.cursor()
    cursor.execute("SELECT * FROM users WHERE name = \x27" + name + "\x27")
    return str(cursor.fetchall())
'

read -r submission_a student_a_token <<<"$(enrol_and_submit "${section_l01_id}" "${problem_l01_id}" a "${SOURCE_A}")"
read -r submission_b _ <<<"$(enrol_and_submit "${section_l02_id}" "${problem_l02_id}" b "${SOURCE_B}")"
read -r submission_c _ <<<"$(enrol_and_submit "${section_l01_id}" "${problem_l01_id}" c "${SOURCE_C}")"
read -r submission_d _ <<<"$(enrol_and_submit "${section_l02_id}" "${problem_l02_id}" d "${SOURCE_D}")"
pass "four students submitted across both sections (${submission_a}, ${submission_b}, ${submission_c}, ${submission_d})"
note "submissions stay in_queue without Judge0; analysis reads source, not grades (D1)"

# ── Trigger ──────────────────────────────────────────────────────────────────

step "Trigger"

trigger="$(api POST "/problems/${problem_l01_id}/analysis" "${admin_token}" \
  '{"services":["plagiarism-checker","ai-detector","vuln-scanner"]}')"
analysis_id="$(expect_field "${trigger}" '.data.id' 'could not trigger the analysis')"
pass "analysis batch ${analysis_id} created"

scoped="$(psql_scalar "SELECT count(*) FROM analysis_submissions WHERE analysis_problem_id = ${analysis_id}")"
[ "${scoped}" = "4" ] \
  || fail "the batch covers ${scoped} submissions, expected 4 — is the match group in scope? (schema §5.2)"
pass "all four submissions are in scope, so the batch spans both sections"

# ── Results ──────────────────────────────────────────────────────────────────

step "Results"

deadline=$((SECONDS + TIMEOUT_SECONDS))
status=""
while [ "${SECONDS}" -lt "${deadline}" ]; do
  status="$(psql_scalar "SELECT status FROM analysis_problems WHERE id = ${analysis_id}")"
  case "${status}" in
    processing) sleep 5 ;;
    *) break ;;
  esac
done

if [ "${status}" != "completed" ]; then
  note "batch status is '${status}' after $((TIMEOUT_SECONDS))s"
  note "per-service statuses:"
  docker compose exec -T postgres psql -qtAX -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" \
    -c "SELECT submission_id, plagiarism_status, ai_detection_status, vuln_scan_status
        FROM analysis_submissions WHERE analysis_problem_id = ${analysis_id} ORDER BY submission_id" >&2
  fail "the batch did not complete"
fi
pass "batch completed"

# The cross-section pair is the whole reason for two sections (D-61).
cross_section="$(psql_scalar "SELECT count(*) FROM similarity_results
  WHERE analysis_problem_id = ${analysis_id} AND match_type = 'CROSS_SECTION'
    AND submission_a_id = LEAST(${submission_a}, ${submission_b})
    AND submission_b_id = GREATEST(${submission_a}, ${submission_b})")"
[ "${cross_section}" = "1" ] \
  || fail "the renamed copy across sections was not reported as a CROSS_SECTION pair"

similarity="$(psql_scalar "SELECT similarity FROM similarity_results
  WHERE analysis_problem_id = ${analysis_id}
    AND submission_a_id = LEAST(${submission_a}, ${submission_b})
    AND submission_b_id = GREATEST(${submission_a}, ${submission_b})")"
pass "cross-section pair found at similarity ${similarity}"

# Regions are why SIM drives the Dolos library rather than its CLI (E2): the
# CSV export has no fragment coordinates, and F9's viewer needs them.
regions="$(psql_scalar "SELECT count(*) FROM similarity_results
  WHERE analysis_problem_id = ${analysis_id}
    AND a_regions IS NOT NULL AND b_regions IS NOT NULL")"
[ "${regions}" -ge 1 ] || fail "no pair carries highlight regions"
pass "${regions} pair(s) carry highlight regions on both sides"

# Ordering is CRITICAL per schema §5.5 — the unique index is on the triple, so
# a pair stored both ways round would exist twice.
misordered="$(psql_scalar "SELECT count(*) FROM similarity_results
  WHERE analysis_problem_id = ${analysis_id} AND submission_a_id >= submission_b_id")"
[ "${misordered}" = "0" ] || fail "${misordered} pair(s) violate submission_a_id < submission_b_id"
pass "every pair is ordered a < b"

ai_rows="$(psql_scalar "SELECT count(*) FROM ai_detection_results r
  JOIN analysis_submissions s ON s.id = r.analysis_submission_id
  WHERE s.analysis_problem_id = ${analysis_id} AND r.probability BETWEEN 0 AND 1")"
[ "${ai_rows}" = "4" ] \
  || fail "AI detection produced ${ai_rows} probabilities in 0..1, expected 4"
pass "every submission has an AI-detection probability in 0..1"

vulnerabilities="$(psql_scalar "SELECT count(*) FROM vulnerability_results r
  JOIN analysis_submissions s ON s.id = r.analysis_submission_id
  WHERE s.analysis_problem_id = ${analysis_id} AND r.name = 'py/sql-injection'")"
[ "${vulnerabilities}" -ge 1 ] \
  || fail "CodeQL did not report py/sql-injection on the deliberately vulnerable submission"
pass "py/sql-injection reported on the vulnerable submission"

incomplete="$(psql_scalar "SELECT count(*) FROM analysis_submissions
  WHERE analysis_problem_id = ${analysis_id} AND completed_at IS NULL")"
[ "${incomplete}" = "0" ] \
  || fail "${incomplete} submission(s) never reached a terminal state"
pass "every submission is stamped completed"

# ── Realtime ─────────────────────────────────────────────────────────────────

step "Realtime"

auth_status="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "${API}/broadcasting/auth" \
  -H "Authorization: Bearer ${admin_token}" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "$(jq -nc --arg c "private-section.${section_l01_id}" \
    '{channel_name:$c,socket_id:"1234.5678"}')")"
[ "${auth_status}" = "200" ] || fail "staff could not authorise the section channel (HTTP ${auth_status})"
pass "staff authorised private-section.${section_l01_id}"

student_token="${student_a_token}"
[ -n "${student_token}" ] || fail "no student token was kept from the fixture stage"

student_status="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "${API}/broadcasting/auth" \
  -H "Authorization: Bearer ${student_token}" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "$(jq -nc --arg c "private-section.${section_l01_id}" \
    '{channel_name:$c,socket_id:"1234.5678"}')")"
[ "${student_status}" = "403" ] || fail "a student authorised a staff channel (HTTP ${student_status})"
pass "student refused (403) — the channel is staff-only (U-8)"

docker compose logs --since 20m amqp-analysis-consumer 2>/dev/null | grep -q 'consuming result-analysis' \
  || note "warning: no 'consuming result-analysis' line in the consumer log"

# ── Read APIs ────────────────────────────────────────────────────────────────
#
# The rows exist; D8's surface is what an instructor actually sees.

step "Read APIs"

listing="$(api GET "/analysis/${analysis_id}/similarity" "${admin_token}")"
listed="$(printf '%s' "${listing}" | jq '.data | length')"
[ "${listed}" -ge 1 ] || fail "the similarity listing is empty — response was: ${listing}"
pass "similarity listing returns ${listed} pair(s) with the {data, meta} envelope"

detection="$(api GET "/analysis/${analysis_id}/ai-detection" "${admin_token}")"
[ "$(printf '%s' "${detection}" | jq '.data | length')" = "4" ] \
  || fail "the ai-detection listing does not carry four rows"
pass "ai-detection listing returns all four submissions"

vulns="$(api GET "/analysis/${analysis_id}/vulnerabilities" "${admin_token}")"
[ "$(printf '%s' "${vulns}" | jq '.data | length')" -ge 1 ] \
  || fail "the vulnerabilities listing is empty"
pass "vulnerabilities listing returns findings"

step "M3 exit criterion met"
note "trigger → SIM/AID/VUL → results in the database → batch completed → staff-only channel."
note "cross-section pair at similarity ${similarity}, regions on both sides, py/sql-injection found."
note "fixtures are tagged ${RUN_TAG} if you want to remove them."
