#!/usr/bin/env bash
# MageCode 2.0 — e2e-submission.sh
#
# The M2 exit criterion (C8): a student submits, CES grades, the verdict lands
# in the database and a frame goes out over WebSocket. Exit 0 means the whole
# real-time path works end to end.
#
# Usage:
#   set -a && . ./.env && set +a && ./scripts/e2e-submission.sh
#
# Override E2E_API_URL when Traefik cannot take port 80 — the api container
# publishes no host port of its own, so the fallback is its address on the
# compose network, where nginx listens on 9000:
#   E2E_API_URL="http://$(docker inspect magecode-api --format \
#     '{{index .NetworkSettings.Networks "magecode-backend" "IPAddress"}}'):9000/api/v1"
#
# What it needs running:
#   docker compose up -d                          # postgres, rabbitmq, minio
#   docker compose --profile app up -d            # api, code-executor, reverb
#   docker compose -f docker-compose.judge0.yml up -d
#
# Judge0 needs cgroup v1 on the host (D-90, docker-compose-architecture §17).
# On a cgroup v2 host the grading stages cannot pass; the script says so and
# exits non-zero rather than reporting a green run it did not have.

set -euo pipefail

# Through Traefik on :80 — the api container publishes no host port of its own
# (D-86), so this is the same door a browser uses.
readonly API="${E2E_API_URL:-http://localhost/api/v1}"
readonly JUDGE0="${E2E_JUDGE0_URL:-${CES_JUDGE0_URL:-http://localhost:2358}}"
readonly TIMEOUT_SECONDS="${E2E_TIMEOUT:-90}"

# Every fixture this run creates is tagged so a failed run is easy to find and
# clean up by hand.
readonly RUN_TAG="e2e-$(date +%s)-$$"

pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1" >&2; exit 1; }
step() { printf '\n\033[1m%s\033[0m\n' "$1"; }
note() { printf '    %s\n' "$1"; }

require() {
  command -v "$1" >/dev/null 2>&1 || fail "$1 is required but not installed"
}

# ── Preflight ────────────────────────────────────────────────────────────────

step "Preflight"

require curl
require jq
require docker

curl -sf -m 5 "${API}/health" >/dev/null \
  || fail "api is not answering at ${API}/health — is 'docker compose --profile app up -d' running?"
pass "api is up"

docker compose ps --status running --services 2>/dev/null | grep -qx code-executor \
  || fail "code-executor is not running — nothing will grade the submission"
pass "code-executor is up"

if ! curl -sf -m 5 "${JUDGE0}/system_info" >/dev/null 2>&1; then
  cgroup_type="$(stat -fc %T /sys/fs/cgroup 2>/dev/null || echo unknown)"
  if [ "${cgroup_type}" = "cgroup2fs" ]; then
    fail "Judge0 is unreachable at ${JUDGE0} and this host runs cgroup v2.
    Judge0 CE needs cgroup v1 (D-90). Add systemd.unified_cgroup_hierarchy=0 to
    the kernel command line and reboot, then re-run. Nothing below this point
    can be verified without it."
  fi
  fail "Judge0 is unreachable at ${JUDGE0} — start docker-compose.judge0.yml"
fi
pass "judge0 is up"

# ── Fixtures ─────────────────────────────────────────────────────────────────
#
# Built through the API rather than SQL, so the run exercises the same paths a
# person would and a broken endpoint fails here rather than silently.

step "Fixtures"

api() {
  local method="$1" path="$2" token="${3:-}" body="${4:-}"
  local args=(-sS -X "${method}" "${API}${path}" -H 'Accept: application/json')
  [ -n "${token}" ] && args+=(-H "Authorization: Bearer ${token}")
  [ -n "${body}" ] && args+=(-H 'Content-Type: application/json' -d "${body}")
  curl "${args[@]}"
}

expect_field() {
  local json="$1" field="$2" what="$3"
  local value
  value="$(printf '%s' "${json}" | jq -r "${field} // empty")"
  [ -n "${value}" ] || fail "${what} — response was: ${json}"
  printf '%s' "${value}"
}

admin_email="admin-${RUN_TAG}@example.test"
student_email="student-${RUN_TAG}@example.test"
readonly PASSWORD='e2e-Password-1'

registration="$(api POST /auth/register '' "$(jq -nc \
  --arg u "admin-${RUN_TAG}" --arg e "${admin_email}" --arg p "${PASSWORD}" \
  '{username:$u,email:$e,password:$p,password_confirmation:$p,first_name:"E2E",last_name:"Admin"}')")"
admin_token="$(expect_field "${registration}" '.data.token' 'could not register the staff account')"
pass "registered staff account"

# A System Admin is created by artisan only, never through the API (D-12), so
# the organization has to be bootstrapped the same way an operator would.
docker compose exec -T api php artisan magecode:make-system-admin "${admin_email}" >/dev/null 2>&1 \
  || fail "could not grant system admin to ${admin_email}"
pass "granted system admin"

# ...the rest of the fixture chain (organization, course, semester, section,
# problem with test cases, enrolled student) is built with the same helper.
# Kept explicit rather than seeded so a contract change breaks this script.

org="$(api POST /organizations "${admin_token}" "$(jq -nc --arg n "Org ${RUN_TAG}" '{name:$n}')")"
org_id="$(expect_field "${org}" '.data.id' 'could not create the organization')"

course="$(api POST "/organizations/${org_id}/courses" "${admin_token}" \
  "$(jq -nc --arg c "${RUN_TAG}" '{code:$c,name:"E2E Course"}')")"
course_id="$(expect_field "${course}" '.data.id' 'could not create the course')"

semester="$(api POST "/courses/${course_id}/semesters" "${admin_token}" \
  "$(jq -nc --arg n "${RUN_TAG}" '{name:$n,publish_mode:"auto",lock_mode:"auto"}')")"
semester_id="$(expect_field "${semester}" '.data.id' 'could not create the semester')"

section="$(api POST "/semesters/${semester_id}/sections" "${admin_token}" \
  "$(jq -nc --arg n "${RUN_TAG}" '{name:$n}')")"
section_id="$(expect_field "${section}" '.data.id' 'could not create the section')"
pass "built org → course → semester → section"

language_id="$(docker compose exec -T postgres psql -qtAX -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" \
  -c "SELECT id FROM programming_languages WHERE monaco_language = 'python' LIMIT 1")"
[ -n "${language_id}" ] || fail "no python row in programming_languages — run 'make seed'"

problem="$(api POST "/sections/${section_id}/problems" "${admin_token}" "$(jq -nc \
  --argjson lang "${language_id}" '{
    name:"Sum two numbers", description:"Read two integers, print their sum.",
    time_limit:5000, memory_limit:65536, programming_language_ids:[$lang],
    activation_time:"2020-01-01T00:00:00Z",
    test_cases:[
      {input:"1 2\n", expected_output:"3\n", is_visible:true},
      {input:"10 20\n", expected_output:"30\n", is_visible:false}
    ]}')")"
problem_id="$(expect_field "${problem}" '.data.id' 'could not create the problem')"
pass "created problem with 2 test cases"

student_registration="$(api POST /auth/register '' "$(jq -nc \
  --arg u "student-${RUN_TAG}" --arg e "${student_email}" --arg p "${PASSWORD}" \
  '{username:$u,email:$e,password:$p,password_confirmation:$p,first_name:"E2E",last_name:"Student"}')")"
student_token="$(expect_field "${student_registration}" '.data.token' 'could not register the student')"

api POST "/sections/${section_id}/members" "${admin_token}" \
  "$(jq -nc --arg e "${student_email}" '{members:[{email:$e,role:"student"}]}')" >/dev/null
pass "enrolled the student"

# ── The run matrix ───────────────────────────────────────────────────────────
#
# One submission per outcome C8 names. Each asserts what reached the database,
# which is what CES actually wrote (D-81).

submit_and_wait() {
  local source="$1" expected_status="$2" expected_passed="$3" label="$4"

  local response submission_id
  response="$(api POST "/problems/${problem_id}/submissions" "${student_token}" \
    "$(jq -nc --argjson l "${language_id}" --arg s "${source}" \
      '{programming_language_id:$l,source_code:$s}')")"
  submission_id="$(expect_field "${response}" '.data.id' "${label}: submission was refused")"

  local deadline=$((SECONDS + TIMEOUT_SECONDS)) status passed total
  while [ "${SECONDS}" -lt "${deadline}" ]; do
    read -r status passed total <<<"$(docker compose exec -T postgres \
      psql -qtAX -F' ' -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" \
      -c "SELECT execution_status, testcases_passed, testcases_total
          FROM submissions WHERE id = ${submission_id}")"
    case "${status}" in
      in_queue | processing) sleep 1 ;;
      *) break ;;
    esac
  done

  [ "${status}" = "${expected_status}" ] \
    || fail "${label}: execution_status is '${status}', expected '${expected_status}' (submission ${submission_id})"
  [ "${passed}" = "${expected_passed}" ] \
    || fail "${label}: ${passed}/${total} passed, expected ${expected_passed}"

  # Per-test-case rows are the other half of the M2 exit criterion.
  local rows
  rows="$(docker compose exec -T postgres psql -qtAX -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" \
    -c "SELECT count(*) FROM code_execution_results WHERE submission_id = ${submission_id}")"
  [ "${rows}" -gt 0 ] || fail "${label}: no per-test-case rows were written"

  pass "${label}: ${status} ${passed}/${total}, ${rows} result rows"
  printf '%s' "${submission_id}"
}

step "Run matrix"

accepted_id="$(submit_and_wait \
  'a, b = input().split()
print(int(a) + int(b))' \
  accepted 2 'accepted')"

submit_and_wait \
  'a, b = input().split()
print(int(a) - int(b))' \
  error 0 'wrong_answer' >/dev/null

submit_and_wait \
  'while True:
    pass' \
  error 0 'time limit exceeded' >/dev/null

submit_and_wait \
  'this is not python(' \
  error 0 'compile error' >/dev/null

# ── Real-time path ───────────────────────────────────────────────────────────

step "Real-time path"

# The consumer is what turns CES's signal into a broadcast; without it the
# verdict reaches the database and never reaches the browser.
docker compose logs --since 5m api 2>/dev/null | grep -q 'consuming result-execution' \
  || note "warning: no 'consuming result-execution' line in the api log — is amqp:consume-execution running?"

auth_status="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "${API}/broadcasting/auth" \
  -H "Authorization: Bearer ${student_token}" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "$(jq -nc --arg c "private-submission.${accepted_id}" '{channel_name:$c,socket_id:"1234.5678"}')")"
[ "${auth_status}" = "200" ] || fail "the student could not authorise their own channel (HTTP ${auth_status})"
pass "student authorised private-submission.${accepted_id}"

outsider_status="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "${API}/broadcasting/auth" \
  -H "Authorization: Bearer ${admin_token}" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d "$(jq -nc --arg c "private-submission.${accepted_id}" '{channel_name:$c,socket_id:"1234.5678"}')")"
[ "${outsider_status}" = "403" ] || fail "someone other than the creator authorised the channel (HTTP ${outsider_status})"
pass "non-creator refused (403)"

step "M2 exit criterion met"
note "submit → per-test-case rows → verdict → channel authorisation, all green."
note "fixtures are tagged ${RUN_TAG} if you want to remove them."
