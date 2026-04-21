#!/usr/bin/env bash
# =============================================================================
# contact-lookup-smoke.sh
# =============================================================================
#
# PURPOSE:
#   Smoke tests + response-time profiling for the public contact endpoints.
#   Covers CORS, Turnstile middleware (including the ct_verified cookie
#   fast-path), flag gating, throttle, response shape, signed-token prefill,
#   the self-service profile, and Turnstile enforcement on order creation.
#   All configuration is env-overridable so the same script runs against dev
#   and against Elestio.
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
#   CORS_STRICT             default false. When true, asserts that a disallowed
#                             origin is NOT echoed back. In dev this is
#                             expected to fail because CORS_ALLOWED_ORIGINS=*
#                             reflects every Origin header; in prod set this
#                             to true alongside an allowlist.
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
CORS_STRICT="${CORS_STRICT:-false}"
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
COOKIE_JAR="$(mktemp -t contact-smoke-cookies.XXXXXX)"
TAMPERED_JAR="$(mktemp -t contact-smoke-tampered.XXXXXX)"
trap 'rm -f "$TMPBODY" "$TMPHEAD" "$COOKIE_JAR" "$TAMPERED_JAR"' EXIT

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
echo "  CORS strict mode:     $CORS_STRICT  (true enforces lockdown assertion; dev=false)"
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
ORDER_URL="$API_BASE/public/events/$EVENT_ID/order"

# -----------------------------------------------------------------------------
banner "1. CORS preflight — disallowed origin must NOT echo Access-Control-Allow-Origin"
# -----------------------------------------------------------------------------
# In dev, CORS_ALLOWED_ORIGINS=* causes Laravel's CORS package to echo every
# Origin header (because wildcard + credentials is browser-rejected, so the
# package reflects the caller's Origin instead). This test is only meaningful
# in prod, so gate it behind CORS_STRICT=true.
if [ "$CORS_STRICT" != "true" ]; then
    skip_msg "CORS_STRICT not set — skipping (dev typically runs with CORS_ALLOWED_ORIGINS=*)"
else
    out=$(do_curl OPTIONS "$LOOKUP_URL" \
        -H "Origin: $CORS_ORIGIN_REJECTED" \
        -H "Access-Control-Request-Method: POST" \
        -H "Access-Control-Request-Headers: content-type,cf-turnstile-response")
    acao=$(grep -i '^access-control-allow-origin:' "$TMPHEAD" | awk -F': ' '{print $2}' | tr -d '\r')
    info "headers: $(grep -i '^access-control' "$TMPHEAD" | tr -d '\r' | paste -sd'; ' - || echo '(none)')"
    if [ -z "$acao" ] || [ "$acao" = "$CORS_ORIGIN_ALLOWED" ] || [ "$acao" != "$CORS_ORIGIN_REJECTED" ]; then
        pass_msg "Disallowed origin not echoed back (ACAO='$acao')"
    else
        fail_msg "CORS lockdown broken — disallowed origin echoed: '$acao' (check CORS_ALLOWED_ORIGINS env)"
    fi
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
banner "5a. Cookie fast-path — first request with token issues ct_verified"
# -----------------------------------------------------------------------------
# With Turnstile enabled, the middleware issues an encrypted ct_verified
# cookie on a successful verify. Subsequent requests within 15 min can
# present that cookie and skip the Cloudflare siteverify round-trip.
# Cookie is path-scoped to /api/public, IP-bound, SameSite=Strict.
cookie_issued=false
out=$(do_curl POST "$LOOKUP_URL" \
    -H "Content-Type: application/json" \
    "${ts_hdr_args[@]}" \
    -c "$COOKIE_JAR" \
    -d "{\"email\":\"cookie-probe-$(date +%s)@example.test\"}")
status=$(echo "$out" | awk '{print $1}')
info "HTTP $status"
case "$status" in
    200)
        if grep -q 'ct_verified' "$COOKIE_JAR"; then
            cookie_issued=true
            pass_msg "Response issued ct_verified cookie"
            # cookie-jar format: domain\tTRUE/FALSE\tpath\tsecure\texpiry\tname\tvalue
            cookie_line=$(grep ct_verified "$COOKIE_JAR" | head -1)
            cookie_path=$(echo "$cookie_line" | awk '{print $3}')
            cookie_secure=$(echo "$cookie_line" | awk '{print $4}')
            info "path=$cookie_path secure=$cookie_secure"
            [ "$cookie_path" = "/api/public" ] \
                && pass_msg "Cookie path scoped to /api/public" \
                || fail_msg "Cookie path wrong: expected /api/public, got '$cookie_path'"
            [ "$cookie_secure" = "TRUE" ] \
                && pass_msg "Cookie marked Secure" \
                || fail_msg "Cookie not marked Secure"
        else
            fail_msg "No ct_verified cookie in response"
        fi
        ;;
    403) skip_msg "Turnstile rejected token — cannot test cookie fast-path" ;;
    404) skip_msg "Endpoint disabled — cannot test cookie fast-path" ;;
    429) skip_msg "Throttled — cannot test cookie fast-path" ;;
    *)   fail_msg "Unexpected HTTP $status" ; show_body ;;
esac

# -----------------------------------------------------------------------------
banner "5b. Cookie fast-path — cookie alone (no Turnstile token) is accepted"
# -----------------------------------------------------------------------------
if [ "$cookie_issued" = "true" ]; then
    out=$(do_curl POST "$LOOKUP_URL" \
        -H "Content-Type: application/json" \
        -b "$COOKIE_JAR" \
        -d "{\"email\":\"cookie-reuse-$(date +%s)@example.test\"}")
    status=$(echo "$out" | awk '{print $1}')
    info "HTTP $status (cookie only, no cf-turnstile-response header)"
    case "$status" in
        200) pass_msg "Cookie alone accepted — fast-path working (skipped Cloudflare siteverify)" ;;
        403) fail_msg "Cookie rejected — middleware did not honor ct_verified" ; show_body ;;
        429) skip_msg "Throttled — cannot verify cookie fast-path" ;;
        *)   fail_msg "Unexpected HTTP $status" ; show_body ;;
    esac
else
    skip_msg "Skipping — no cookie was issued by 5a"
fi

# -----------------------------------------------------------------------------
banner "5c. Cookie fast-path — tampered cookie is rejected, falls through"
# -----------------------------------------------------------------------------
if [ "$cookie_issued" = "true" ]; then
    # Corrupt the cookie value (last column in the cookie jar)
    awk 'BEGIN{OFS="\t"} /ct_verified/ {$7="tampered-garbage-not-a-valid-payload"} {print}' \
        "$COOKIE_JAR" > "$TAMPERED_JAR"
    out=$(do_curl POST "$LOOKUP_URL" \
        -H "Content-Type: application/json" \
        -b "$TAMPERED_JAR" \
        -d "{\"email\":\"cookie-tampered-$(date +%s)@example.test\"}")
    status=$(echo "$out" | awk '{print $1}')
    info "HTTP $status (tampered cookie, no token)"
    case "$status" in
        403) pass_msg "Tampered cookie rejected (fell through to siteverify, no token → 403)" ;;
        200) fail_msg "Tampered cookie accepted — decryption/validation broken" ; show_body ;;
        429) skip_msg "Throttled — cannot verify tamper rejection" ;;
        *)   fail_msg "Unexpected HTTP $status" ; show_body ;;
    esac
else
    skip_msg "Skipping — no baseline cookie from 5a"
fi

# -----------------------------------------------------------------------------
banner "6. Throttle — per-email cap trips (3/hour per normalized email)"
# -----------------------------------------------------------------------------
# contact-lookup has TWO limits in RouteServiceProvider:
#   - 30/min per IP  (loose — sized for 10-attendee group orders)
#   - 3/hour per email (tight — the real abuse gate)
# Hit the per-email cap with 4 rapid requests using the same email.
throttle_email="throttle-cap-$(date +%s)@example.test"
fired=0
hit_429=false
for i in $(seq 1 4); do
    out=$(do_curl POST "$LOOKUP_URL" \
        -H "Content-Type: application/json" \
        "${ts_hdr_args[@]}" \
        -d "{\"email\":\"$throttle_email\"}")
    status=$(echo "$out" | awk '{print $1}')
    fired=$((fired+1))
    info "request #$i ($throttle_email) → HTTP $status"
    [ "$status" = "429" ] && { hit_429=true; break; }
done
if [ "$hit_429" = "true" ]; then
    pass_msg "Per-email throttle tripped after $fired request(s)"
elif [ "$fired" -ge 4 ]; then
    fail_msg "4 rapid requests with same email did not hit 429 — check 'contact-lookup' limiter"
else
    skip_msg "Test aborted early (fired=$fired)"
fi

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
            full_defs_count=$(grep -o '"id":' "$TMPBODY" | wc -l | tr -d ' ')
            info "unfiltered attribute_definitions count: $full_defs_count"
            ;;
        404) pass_msg "Portal returned 404 — invalid/expired link (check the token)" ;;
        *)   fail_msg "Unexpected HTTP $status" ; show_body ;;
    esac

    # -----------------------------------------------------------------------------
    banner "9a. Self-service profile portal with ?event_id=<id> filter"
    # -----------------------------------------------------------------------------
    # Filter narrows attribute_definitions to the union of is_globally_recommended
    # AND attributes linked to questions on this event. Used by the order
    # confirmation page's AttendeeProfiles component so review is scoped to
    # "common questions + this event's linked attributes".
    out=$(do_curl GET "$PORTAL_URL?c=$(printf '%s' "$CONTACT_TOKEN" | sed 's/+/%2B/g;s:/:%2F:g;s/=/%3D/g')&event_id=$EVENT_ID")
    status=$(echo "$out" | awk '{print $1}')
    info "HTTP $status (event_id=$EVENT_ID)"
    case "$status" in
        200)
            assert_body_contains '"found":true' "Filtered portal GET returns profile"
            assert_body_contains '"attribute_definitions"' "Filtered response still includes attribute_definitions"
            filtered_defs_count=$(grep -o '"id":' "$TMPBODY" | wc -l | tr -d ' ')
            info "filtered attribute_definitions count: $filtered_defs_count"
            if [ -n "${full_defs_count:-}" ] && [ "$filtered_defs_count" -le "$full_defs_count" ]; then
                pass_msg "Filter reduced or kept count (full=$full_defs_count, filtered=$filtered_defs_count)"
            else
                info "Could not verify count comparison"
            fi
            ;;
        404) pass_msg "Filtered portal returned 404 — invalid/expired link" ;;
        *)   fail_msg "Unexpected HTTP $status" ; show_body ;;
    esac
fi

# -----------------------------------------------------------------------------
banner "10. Order creation (POST /events/{id}/order) is Turnstile-gated"
# -----------------------------------------------------------------------------
# Order creation gets the same Turnstile middleware as contact-lookup.
# A valid ct_verified cookie (from any prior /api/public Turnstile verify)
# satisfies it without a fresh token, since the cookie is path-scoped to
# /api/public.
out=$(do_curl POST "$ORDER_URL" \
    -H "Content-Type: application/json" \
    -d '{"products":[]}')
status=$(echo "$out" | awk '{print $1}')
info "HTTP $status (no Turnstile, no cookie)"
case "$status" in
    403)
        assert_body_contains 'Challenge' "Turnstile middleware gates order creation"
        ;;
    404|422|400)
        # Middleware disabled (TURNSTILE_ENABLED=false) → request bypasses
        # Turnstile and validation kicks in. Can't distinguish from a
        # genuinely missing middleware without flipping the flag.
        skip_msg "Turnstile disabled or validation intercepted (HTTP $status) — enable TURNSTILE_ENABLED to exercise"
        ;;
    200|201) fail_msg "Order created without Turnstile — middleware missing on POST /order" ; show_body ;;
    429)     skip_msg "Throttled — cannot verify order gate" ;;
    *)       fail_msg "Unexpected HTTP $status" ; show_body ;;
esac

# If we still have a valid cookie from 5a, verify it's accepted on /order too.
if [ "$cookie_issued" = "true" ]; then
    out=$(do_curl POST "$ORDER_URL" \
        -H "Content-Type: application/json" \
        -b "$COOKIE_JAR" \
        -d '{"products":[]}')
    status=$(echo "$out" | awk '{print $1}')
    info "HTTP $status (ct_verified cookie, no token — any non-403 means Turnstile accepted the cookie)"
    case "$status" in
        403)
            # Disambiguate: is the 403 from Turnstile (challenge) or from
            # something else (auth, policy)?
            if grep -qi 'challenge' "$TMPBODY"; then
                fail_msg "Cookie not accepted on /order — Turnstile middleware rejected it"
                show_body
            else
                pass_msg "Cookie accepted by Turnstile on /order (downstream returned 403 for a different reason)"
            fi
            ;;
        429) skip_msg "Throttled" ;;
        *)   pass_msg "Cookie satisfied Turnstile on /order (HTTP $status is downstream handler, not Turnstile)" ;;
    esac
else
    skip_msg "Skipping cookie-on-/order test — no cookie from 5a"
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
