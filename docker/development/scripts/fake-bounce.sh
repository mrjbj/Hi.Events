#!/usr/bin/env bash
#
# fake-bounce.sh — Inject a BOUNCED outgoing_messages row directly into the
# DB so the Resolve Delivery Issue UI can be exercised end-to-end without
# needing AWS SES or even a real send.
#
# COMPLEMENT TO sns-webhook-simulator.sh:
#   * sns-webhook-simulator.sh requires an existing SENT row, then runs the
#     real webhook path to mark it BOUNCED. Use that for testing the
#     bounce → suppression handler.
#   * fake-bounce.sh fabricates the bounce from scratch. Use it for testing
#     the Part 2 resolve-cascade (contact + attendees + suppression +
#     original_recipient + cross-event send-path).
#
# USAGE
#   ./fake-bounce.sh <event_id> [attendee_id]
#
#   If attendee_id is omitted, the script picks the first attendee in that
#   event that has a non-null contact_id (so the contact cascade has
#   something to update).
#
# OUTPUT
#   Prints the message_id, outgoing_messages.id, contact_id, and the URL
#   to the Message Tracking page so you can click Resolve immediately.
#

set -euo pipefail

DOCKER_COMPOSE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DOCKER_COMPOSE="docker compose -f $DOCKER_COMPOSE_DIR/docker-compose.dev.yml"
DB_NAME="${DB_DATABASE:-backend}"
DB_USER="${DB_USERNAME:-username}"

if [ $# -lt 1 ]; then
    echo "USAGE: $0 <event_id> [attendee_id]" >&2
    exit 1
fi

EVENT_ARG="$1"
ATTENDEE_ID="${2:-}"

db_value() {
    # head -n1 strips the trailing "INSERT 0 1" status line emitted by RETURNING.
    $DOCKER_COMPOSE exec -T pgsql psql -U "$DB_USER" -d "$DB_NAME" -t -A -c "$1" | head -n 1 | tr -d '[:space:]'
}

db_run() {
    $DOCKER_COMPOSE exec -T pgsql psql -U "$DB_USER" -d "$DB_NAME" -c "$1"
}

sql_escape() {
    printf '%s' "$1" | sed "s/'/''/g"
}

# Resolve event by numeric id, or fall back to case-insensitive title match.
if [[ "$EVENT_ARG" =~ ^[0-9]+$ ]]; then
    EVENT_ID="$EVENT_ARG"
    ACCOUNT_ID=$(db_value "SELECT account_id FROM events WHERE id = $EVENT_ID AND deleted_at IS NULL")
else
    SAFE_TITLE=$(sql_escape "$EVENT_ARG")
    MATCH_COUNT=$(db_value "SELECT COUNT(*) FROM events WHERE LOWER(title) = LOWER('$SAFE_TITLE') AND deleted_at IS NULL")
    if [ "$MATCH_COUNT" = "0" ]; then
        echo "ERROR: No event matches title '$EVENT_ARG'. Use the numeric id instead:" >&2
        db_run "SELECT id, title, account_id FROM events WHERE deleted_at IS NULL ORDER BY id;" >&2
        exit 1
    fi
    if [ "$MATCH_COUNT" != "1" ]; then
        echo "ERROR: Title '$EVENT_ARG' matches $MATCH_COUNT events — pass the numeric id instead." >&2
        exit 1
    fi
    EVENT_ID=$(db_value "SELECT id FROM events WHERE LOWER(title) = LOWER('$SAFE_TITLE') AND deleted_at IS NULL LIMIT 1")
    ACCOUNT_ID=$(db_value "SELECT account_id FROM events WHERE id = $EVENT_ID")
fi

if [ -z "$ACCOUNT_ID" ]; then
    echo "ERROR: Event $EVENT_ARG not found" >&2
    exit 1
fi
echo "Event $EVENT_ID belongs to account $ACCOUNT_ID"

# Pick attendee (with a contact, so the cascade has something to touch).
if [ -z "$ATTENDEE_ID" ]; then
    ATTENDEE_ID=$(db_value "SELECT id FROM attendees WHERE event_id = $EVENT_ID AND contact_id IS NOT NULL AND deleted_at IS NULL ORDER BY id LIMIT 1")
    if [ -z "$ATTENDEE_ID" ]; then
        echo "ERROR: no attendees in event $EVENT_ID have contact_id set. Pass one explicitly or seed one first." >&2
        exit 1
    fi
fi

ATTENDEE_INFO=$(db_value "SELECT email || '|' || COALESCE(contact_id::text, '') FROM attendees WHERE id = $ATTENDEE_ID")
ATTENDEE_EMAIL=$(echo "$ATTENDEE_INFO" | cut -d'|' -f1)
CONTACT_ID=$(echo "$ATTENDEE_INFO" | cut -d'|' -f2)
echo "Using attendee $ATTENDEE_ID ($ATTENDEE_EMAIL), contact_id=$CONTACT_ID"

# Find any user in the account to attribute the message to.
USER_ID=$(db_value "SELECT u.id FROM users u JOIN account_users au ON au.user_id = u.id WHERE au.account_id = $ACCOUNT_ID ORDER BY u.id LIMIT 1")
if [ -z "$USER_ID" ]; then
    echo "ERROR: no users found in account $ACCOUNT_ID" >&2
    exit 1
fi

# Create a synthetic announcement row to anchor the outgoing message.
MESSAGE_ID=$(db_value "INSERT INTO messages (event_id, subject, message, type, status, sent_at, sent_by_user_id, created_at, updated_at) VALUES ($EVENT_ID, '[fake-bounce] Test bounce ' || NOW(), '<p>fake bounce body</p>', 'ALL_ATTENDEES', 'SENT', NOW(), $USER_ID, NOW(), NOW()) RETURNING id")
echo "Created messages row id=$MESSAGE_ID"

# Inject the BOUNCED outgoing_messages row referencing it.
OM_ID=$(db_value "INSERT INTO outgoing_messages (message_id, event_id, status, recipient, subject, created_at, updated_at) VALUES ($MESSAGE_ID, $EVENT_ID, 'BOUNCED', '$ATTENDEE_EMAIL', '[fake-bounce] Test bounce', NOW(), NOW()) RETURNING id")
echo "Created BOUNCED outgoing_messages row id=$OM_ID"

echo
echo "─── State snapshot ───"
db_run "SELECT id, recipient, status, original_recipient, resolved_at FROM outgoing_messages WHERE id = $OM_ID;"
db_run "SELECT id, email, contact_id FROM attendees WHERE contact_id = $CONTACT_ID;"
db_run "SELECT id, email FROM contacts WHERE id = $CONTACT_ID;"

echo
echo "─── Next steps ───"
echo "1. Open: https://localhost:8443/manage/event/$EVENT_ID/messages"
echo "2. Click the 'Delivery Issues' tab → click Resolve on the new row"
echo "3. Enter a corrected email (e.g. fixed-$(date +%s)@example.com) and submit"
echo "4. Re-run these to verify the cascade:"
echo
echo "   SELECT id, email, attributes_history FROM contacts WHERE id = $CONTACT_ID;"
echo "   SELECT id, email, contact_id FROM attendees WHERE contact_id = $CONTACT_ID;"
echo "   SELECT email, source, reason FROM email_suppressions WHERE email = '$ATTENDEE_EMAIL';"
echo "   SELECT recipient, original_recipient, status, retry_for_id FROM outgoing_messages WHERE retry_for_id = $OM_ID;"
echo
echo "5. To exercise the cross-event send-path rule:"
echo "   - From event $EVENT_ID, open Compose → set 'Relating to' to a DIFFERENT event"
echo "   - Send to ALL_ATTENDEES → check mailpit (http://localhost:8025);"
echo "     the recipient header should be the corrected email (proves contact-read path)."
