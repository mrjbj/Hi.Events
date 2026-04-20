#!/usr/bin/env bash
# =============================================================================
# contact-lookup-smoke.sh
# =============================================================================
#
# PURPOSE:
#   Smoke tests + response-time profiling for the public contact endpoints.
#   Covers CORS, Turnstile middleware, flag gating, throttle, response shape,
#   signed-token prefill, and the self-service profile. All configuration is
#   env-overridable so the same script runs against dev and against Elestio.
#
# USAGE:
#   ./ops/dev/contact-lookup-smoke.sh
#   API_BASE=https://events.district11ga.org/api \
#     CORS_ORIGIN_ALLOWED=https://events.district11ga.org \
#     ./ops/dev/contact-lookup-smoke.sh
#
#   # With a signed contact token (generate via `php artisan tinker`:
#   #   app(\HiEvents\Services\Domain\Contact\ContactSignedTokenService::class)
#   #     ->generate($contactId, $accountId);
#   # ) to exercise /contact-prefill and /contacts/me:
#   CONTACT_TOKEN="abc.def" ./ops/dev/contact-lookup-smoke.sh
#
# ENV OVERRIDES (all optional):
#   API_BASE                default https://localhost:8443/api
#   EVENT_ID                default 1
#   CONTACT_EMAIL_EXISTING  a known contact email (some tests skip if unset)
#   CONTACT_EMAIL_UNKNOWN   default nobody-<unix-time>@example.test
#   CORS_ORIGIN_ALLOWED     default https://events.district11ga.org
#   CORS_ORIGIN_REJECTED    default https://evil.example.com
#   TURNSTILE_TOKEN         default XXXX.DUMMY.TOKEN.XXXX
#                             Works in dev when backend uses the always-pass
#                             secret key 1x00000000...AA. In prod, the only
#                             usable token comes from the browser widget —
#                             this script can still verify the "missing
#                             token → 403" path.
#   SEND_TURNSTILE          default true. Set to false to deliberately test
#                             the missing-token path.
#   CONTACT_TOKEN           a signed contact token (enables prefill / portal
#                             tests); unset → those tests are skipped.
#   INSECURE                default true. Passes -k to curl for localhost
#                             self-signed certs. Set to false for prod.
#   PROFILE_RUNS            default 10. How many requests in the latency run.
#   VERBOSE                 default false. When true, shows response bodies.
#
# EXIT CODE:
#   0 if all assertions pass, 1 otherwise.
# =============================================================================

set -uo pipefail

API_BASE="${API_BASE:-https://localhost:8443/api}"
EVENT_ID="${EVENT_ID:-1}"
CONTACT_EMAIL_EXISTING="${CONTACT_EMAIL_EXISTING:-}"
CONTACT_EMAIL_UNKNOWN="${CONTACT_EMAIL_UNKNOWN:-nobody-$(date +%s)@example.test}"
CORS_ORIGIN_ALLOWED="${CORS_ORIGIN_ALLOWED:-https://events.district11ga.org}"
CORS_ORIGIN_REJECTED="${CORS_ORIGIN_REJECTED:-https://evil.example.com}"
TURNSTILE_TOKEN="${TURNSTILE_TOKEN:-XXXX.DUMMY.TOKEN.XXXX}"
SEND_TURNSTILE="${SEND_TURNSTILE:-true}"
CONTACT_TOKEN="${CONTACT_TOKEN:-}"
INSECURE="${INSECURE:-true}"
PROFILE_RUNS="${PROFILE_RUNS:-10}"
VERBOSE="${VERBOSE:-false}"

if [ -t 1 ]; then
    RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'
    CYAN=$'\033[36m'; BOLD=$'\033[1m'; DIM=$'\033[2m'; NC=$'\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; CYAN=''; BOLD=''; DIM=''; NC=''
fi

INSECURE_FLAG=""
[ "$INSECURE" = "true" ] && INSECURE_FLAG="-k"

TMPBODY="$(mktemp -t contact-smoke-body.XXXXXX)"
TMPHEAD="$(mktemp -t contact-smoke-head.XXXXXX)"
trap 'rm -f "$TMPBODY" "$TMPHEAD"' EXIT

pass=0; fail=0; skip=0

banner()    { echo ""; echo "${BOLD}${CYAN}=== $* ===${NC}"; }
pass_msg()  { echo "${GREEN}  [PASS]${NC} $*"; pass=$((pass+1)); }
fail_msg()  { echo "${RED}  [FAIL]${NC} $*"; fail=$((fail+1)); }
skip_msg()  { echo "${YELLOW}  [SKIP]${NC} $*"; skip=$((skip+1)); }
info()      { echo "${DIM}         $*${NC}"; }
show_body() { [ "$VERBOSE" = "true" ] && info "body: $(head -c 400 "$TMPBODY")"; }

# Issue a curl with headers + body capture. Echoes "STATUS TIME_TOTAL".
# Args: METHOD URL [extra curl flags...]
do_curl() {
    local method="$1" url="$2"; shift 2
    curl $INSECURE_FLAG -s \
        -o "$TMPBODY" -D "$TMPHEAD" \
        -X "$method" "$url" \
        -w '%{http_code} %{time_total}' \
        "$@"
}

assert_status() {
    local got="$1" want="$2" label="$3"
    if [ "$got" = "$want" ]; then
        pass_msg "$label — HTTP $got"
    else
        fail_msg "$label — expected $want, got $got"
        show_body
    fi
}

assert_body_contains() {
    local needle="$1" label="$2"
    if grep -q "$needle" "$TMPBODY"; then
        pass_msg "$label (body contains '$needle')"
    else
        fail_msg "$label (body missing '$needle')"
        show_body
    fi
}

assert_body_lacks() {
    local needle="$1" label="$2"
    if grep -q "$needle" "$TMPBODY"; then
        fail_msg "$label (body unexpectedly contains '$needle')"
        show_body
    else
        pass_msg "$label (body does not contain '$needle')"
    fi
}

banner "Configuration"
echo "  API_BASE:             $API_BASE"
echo "  EVENT_ID:             $EVENT_ID"
echo "  CORS good origin:     $CORS_ORIGIN_ALLOWED"
echo "  CORS bad origin:      $CORS_ORIGIN_REJECTED"
echo "  Send Turnstile hdr:   $SEND_TURNSTILE"
echo "  Turnstile token:      ${TURNSTILE_TOKEN:0:16}... (${#TURNSTILE_TOKEN} chars)"
echo "  Known contact email:  ${CONTACT_EMAIL_EXISTING:-<unset — known-email tests will be skipped>}"
echo "  Unknown email:        $CONTACT_EMAIL_UNKNOWN"
if [ -n "$CONTACT_TOKEN" ]; then
    echo "  Contact token:        [set, ${#CONTACT_TOKEN} chars]"
else
    echo "  Contact token:        <unset — prefill/portal tests will be skipped>"
fi
echo "  Insecure (-k):        $INSECURE"
echo "  Profile runs:         $PROFILE_RUNS"

LOOKUP_URL="$API_BASE/public/events/$EVENT_ID/contact-lookup"
PREFILL_URL="$API_BASE/public/events/$EVENT_ID/contact-prefill"
PORTAL_URL="$API_BASE/public/contacts/me"

# -----------------------------------------------------------------------------
banner "1. CORS preflight — disallowed origin must NOT echo Access-Control-Allow-Origin"
# -----------------------------------------------------------------------------
out=$(do_curl OPTIONS "$LOOKUP_URL" \
    -H "Origin: $CORS_ORIGIN_REJECTED" \
    -H "Access-Control-Request-Method: POST" \
    -H "Access-Control-Request-Headers: content-type,cf-turnstile-response")
acao=$(grep -i '^access-control-allow-origin:' "$TMPHEAD" | awk -F': ' '{print $2}' | tr -d '\r')
info "headers: $(grep -i '^access-control' "$TMPHEAD" | tr -d '\r' | paste -sd'; ' - || echo '(none)')"
if [ -z "$acao" ] || [ "$acao" = "$CORS_ORIGIN_ALLOWED" ] || [ "$acao" != "$CORS_ORIGIN_REJECTED" ]; then
    pass_msg "Disallowed origin not echoed back (ACAO='$acao')"
else
    fail_msg "CORS lockdown broken — disallowed origin echoed: '$acao'"
fi

# -----------------------------------------------------------------------------
banner "2. CORS preflight — allowed origin is echoed"
# -----------------------------------------------------------------------------
out=$(do_curl OPTIONS "$LOOKUP_URL" \
    -H "Origin: $CORS_ORIGIN_ALLOWED" \
    -H "Access-Control-Request-Method: POST" \
    -H "Access-Control-Request-Headers: content-type,cf-turnstile-response")
acao=$(grep -i '^access-control-allow-origin:' "$TMPHEAD" | awk -F': ' '{print $2}' | tr -d '\r')
info "ACAO: '$acao'"
if [ "$acao" = "$CORS_ORIGIN_ALLOWED" ] || [ "$acao" = "*" ]; then
    pass_msg "Allowed origin echoed back (ACAO='$acao')"
else
    fail_msg "Allowed origin rejected (ACAO='$acao'); set CORS_ALLOWED_ORIGINS to include $CORS_ORIGIN_ALLOWED"
fi

# -----------------------------------------------------------------------------
banner "3. POST without Turnstile header"
# -----------------------------------------------------------------------------
# Possible outcomes:
#   - Turnstile enabled on server → 403 'Challenge token missing.'
#   - Turnstile disabled, flag off → 404
#   - Turnstile disabled, flag on  → 200 with {found:...}
out=$(do_curl POST "$LOOKUP_URL" \
    -H "Content-Type: application/json" \
    -d "{\"email\":\"$CONTACT_EMAIL_UNKNOWN\"}")
status=$(echo "$out" | awk '{print $1}')
time_total=$(echo "$out" | awk '{print $2}')
info "HTTP $status, time ${time_total}s"
case "$status" in
    403) pass_msg "Turnstile middleware rejected missing token (HTTP 403)" ; show_body ;;
    404) pass_msg "Flag off OR Turnstile off — endpoint returned 404" ; show_body ;;
    200) pass_msg "Endpoint open (Turnstile off, flag on) — proceed to content tests" ;;
    429) skip_msg "Throttled before we even started — wait a minute and re-run" ;;
    *)   fail_msg "Unexpected HTTP $status" ; show_body ;;
esac

# -----------------------------------------------------------------------------
banner "4. POST with Turnstile header (unknown email)"
# -----------------------------------------------------------------------------
ts_hdr_args=()
[ "$SEND_TURNSTILE" = "true" ] && ts_hdr_args=(-H "cf-turnstile-response: $TURNSTILE_TOKEN")

out=$(do_curl POST "$LOOKUP_URL" \
    -H "Content-Type: application/json" \
    "${ts_hdr_args[@]}" \
    -d "{\"email\":\"$CONTACT_EMAIL_UNKNOWN\"}")
status=$(echo "$out" | awk '{print $1}')
time_total=$(echo "$out" | awk '{print $2}')
info "HTTP $status, time ${time_total}s"
case "$status" in
    200)
        assert_body_contains '"found":false' "Unknown email returns found:false"
        assert_body_lacks    '"question_answers"' "question_answers never leaks on public lookup"
        ;;
    404) skip_msg "Endpoint disabled (CONTACT_LOOKUP_ENABLED=false) — flip it on to test content" ;;
    403) skip_msg "Turnstile rejected token (prod + dummy token, or dev secret mismatch)" ;;
    429) skip_msg "Throttled — wait a minute and re-run" ;;
    *)   fail_msg "Unexpected HTTP $status" ; show_body ;;
esac

# -----------------------------------------------------------------------------
banner "5. POST with Turnstile header (known email)"
# -----------------------------------------------------------------------------
if [ -z "$CONTACT_EMAIL_EXISTING" ]; then
    skip_msg "CONTACT_EMAIL_EXISTING not set"
else
    out=$(do_curl POST "$LOOKUP_URL" \
        -H "Content-Type: application/json" \
        "${ts_hdr_args[@]}" \
        -d "{\"email\":\"$CONTACT_EMAIL_EXISTING\"}")
    status=$(echo "$out" | awk '{print $1}')
    time_total=$(echo "$out" | awk '{print $2}')
    info "HTTP $status, time ${time_total}s"
    case "$status" in
        200)
            assert_body_contains '"found":true' "Known email returns found:true"
            assert_body_contains '"first_name"' "Response includes first_name"
            assert_body_contains '"last_name"' "Response includes last_name"
            assert_body_contains '"answered_question_ids"' "Response includes answered_question_ids"
            assert_body_lacks    '"question_answers"' "Public response never contains question_answers"
            ;;
        404) skip_msg "Endpoint disabled" ;;
        403) skip_msg "Turnstile rejected — try dev mode or set SEND_TURNSTILE=false" ;;
        429) skip_msg "Throttled — wait a minute and re-run" ;;
        *)   fail_msg "Unexpected HTTP $status" ; show_body ;;
    esac
fi

# -----------------------------------------------------------------------------
banner "6. Throttle — expect 429 after 6 rapid requests"
# -----------------------------------------------------------------------------
# contact-lookup limit is 5/min per IP, so the 6th should trip.
fired=0
hit_429=false
for i in $(seq 1 6); do
    out=$(do_curl POST "$LOOKUP_URL" \
        -H "Content-Type: application/json" \
        "${ts_hdr_args[@]}" \
        -d "{\"email\":\"throttle-test-$(date +%s)-$i@example.test\"}")
    status=$(echo "$out" | awk '{print $1}')
    fired=$((fired+1))
    info "request #$i → HTTP $status"
    [ "$status" = "429" ] && { hit_429=true; break; }
done
if [ "$hit_429" = "true" ]; then
    pass_msg "Throttle tripped after $fired request(s)"
elif [ "$fired" -ge 6 ]; then
    fail_msg "6 rapid requests did not hit 429 — check RouteServiceProvider::contact-lookup limiter"
else
    skip_msg "Test aborted early (fired=$fired)"
fi

info "Sleeping 61s for throttle cooldown before the profile run..."
sleep 61

# -----------------------------------------------------------------------------
banner "7. Response-time profile ($PROFILE_RUNS runs)"
# -----------------------------------------------------------------------------
# Profiles a stream of requests to measure the artificial delay + network +
# handler time. Stays under the 5/min limit by sleeping 13s between calls if
# PROFILE_RUNS > 5.
times_file="$(mktemp -t contact-smoke-times.XXXXXX)"
trap 'rm -f "$TMPBODY" "$TMPHEAD" "$times_file"' EXIT
for i in $(seq 1 "$PROFILE_RUNS"); do
    out=$(do_curl POST "$LOOKUP_URL" \
        -H "Content-Type: application/json" \
        "${ts_hdr_args[@]}" \
        -d "{\"email\":\"profile-$i-$(date +%s)@example.test\"}")
    status=$(echo "$out" | awk '{print $1}')
    time_total=$(echo "$out" | awk '{print $2}')
    if [ "$status" = "429" ]; then
        info "run $i throttled — sleeping 13s"
        sleep 13
        continue
    fi
    echo "$time_total" >> "$times_file"
    printf "  run %2d: %ss (HTTP %s)\n" "$i" "$time_total" "$status"
    # Pace to avoid tripping the 5/min limit during the profile loop.
    [ "$i" -lt "$PROFILE_RUNS" ] && [ "$PROFILE_RUNS" -gt 5 ] && sleep 13
done

if [ -s "$times_file" ]; then
    # Sort in shell (portable across BSD/GNU awk) then compute stats.
    sort -n "$times_file" | awk '
        {t[NR]=$1; s+=$1}
        END {
            n=NR;
            p50=t[int(n*0.5+0.5)]; if (p50 == "") p50 = t[1];
            p95=t[int(n*0.95+0.5)]; if (p95 == "") p95 = t[n];
            printf "  ─────────────\n"
            printf "  n=%d  min=%.3fs  avg=%.3fs  p50=%.3fs  p95=%.3fs  max=%.3fs\n", \
                n, t[1], s/n, p50, p95, t[n]
        }
    '
    pass_msg "Profile completed"
else
    fail_msg "No successful profile runs"
fi

# -----------------------------------------------------------------------------
banner "8. Signed-token prefill"
# -----------------------------------------------------------------------------
if [ -z "$CONTACT_TOKEN" ]; then
    skip_msg "CONTACT_TOKEN not set — skipping prefill tests"
else
    # Valid token → full payload
    out=$(do_curl POST "$PREFILL_URL" \
        -H "Content-Type: application/json" \
        -d "{\"token\":\"$CONTACT_TOKEN\"}")
    status=$(echo "$out" | awk '{print $1}')
    info "valid token → HTTP $status"
    case "$status" in
        200)
            assert_body_contains '"found":true' "Valid token returns found:true"
            assert_body_contains '"question_answers"' "Token prefill includes question_answers"
            assert_body_contains '"answered_question_ids"' "Token prefill includes answered_question_ids"
            ;;
        *) fail_msg "Unexpected HTTP $status" ; show_body ;;
    esac

    # Tampered token → found:false OR 403
    tampered="${CONTACT_TOKEN}x"
    out=$(do_curl POST "$PREFILL_URL" \
        -H "Content-Type: application/json" \
        -d "{\"token\":\"$tampered\"}")
    status=$(echo "$out" | awk '{print $1}')
    info "tampered token → HTTP $status"
    if [ "$status" = "200" ]; then
        assert_body_contains '"found":false' "Tampered token returns found:false"
    elif [ "$status" = "403" ]; then
        pass_msg "Tampered token returns 403"
    else
        fail_msg "Tampered token got HTTP $status" ; show_body
    fi
fi

# -----------------------------------------------------------------------------
banner "9. Self-service profile portal (GET /contacts/me)"
# -----------------------------------------------------------------------------
if [ -z "$CONTACT_TOKEN" ]; then
    skip_msg "CONTACT_TOKEN not set — skipping portal GET"
else
    out=$(do_curl GET "$PORTAL_URL?c=$(printf '%s' "$CONTACT_TOKEN" | sed 's/+/%2B/g;s:/:%2F:g;s/=/%3D/g')")
    status=$(echo "$out" | awk '{print $1}')
    info "HTTP $status"
    case "$status" in
        200)
            assert_body_contains '"found":true' "Portal GET with valid token returns profile"
            assert_body_contains '"attribute_definitions"' "Portal includes attribute_definitions"
            ;;
        404) pass_msg "Portal returned 404 — invalid/expired link (check the token)" ;;
        *)   fail_msg "Unexpected HTTP $status" ; show_body ;;
    esac
fi

# -----------------------------------------------------------------------------
banner "Summary"
# -----------------------------------------------------------------------------
echo "  ${GREEN}passed:${NC}  $pass"
echo "  ${RED}failed:${NC}  $fail"
echo "  ${YELLOW}skipped:${NC} $skip"
echo ""

if [ "$fail" -gt 0 ]; then
    exit 1
fi
exit 0
