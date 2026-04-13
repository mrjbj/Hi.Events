#!/usr/bin/env bash
#
# ses-test.sh — Simulate AWS SNS notifications against the local SES webhook.
#
# Requires: docker/development environment running with
#   AWS_SNS_VERIFY_SIGNATURE=false
#   SES_SUPPRESSION_ENABLED=true
#
# Usage:
#   ./jbj/ses-test.sh list                              # show recent outgoing messages
#   ./jbj/ses-test.sh list-suppressions                 # show email suppressions
#   ./jbj/ses-test.sh bounce <email> [ses_message_id]   # permanent bounce
#   ./jbj/ses-test.sh bounce-transient <email> [ses_msg_id]
#   ./jbj/ses-test.sh complaint <email> [ses_message_id]
#   ./jbj/ses-test.sh delivery <email> [ses_message_id] # unhandled type (log test)
#   ./jbj/ses-test.sh subscribe                         # subscription confirmation
#

set -euo pipefail

WEBHOOK_URL="${SES_TEST_URL:-https://localhost:8443/api/public/webhooks/ses}"
DOCKER_COMPOSE_DIR="$(cd "$(dirname "$0")/../docker/development" && pwd)"
DOCKER_COMPOSE="docker compose -f $DOCKER_COMPOSE_DIR/docker-compose.dev.yml"
DB_NAME="${DB_DATABASE:-backend}"
DB_USER="${DB_USERNAME:-postgres}"

# --- helpers ---

generate_uuid() {
    uuidgen 2>/dev/null || cat /proc/sys/kernel/random/uuid 2>/dev/null || python3 -c "import uuid; print(uuid.uuid4())"
}

timestamp() {
    date -u +"%Y-%m-%dT%H:%M:%S.000Z"
}

send_sns_payload() {
    local payload="$1"
    local description="$2"

    local http_code
    http_code=$(curl -sk -o /dev/null -w "%{http_code}" \
        -X POST "$WEBHOOK_URL" \
        -H "Content-Type: text/plain" \
        -d "$payload")

    if [ "$http_code" = "204" ]; then
        echo "OK ($http_code) — $description"
    else
        echo "FAILED ($http_code) — $description"
        echo "  URL: $WEBHOOK_URL"
    fi
}

build_sns_envelope() {
    local inner_message="$1"
    local sns_message_id
    sns_message_id=$(generate_uuid)

    # JSON-encode the inner message as a string value
    local escaped_message
    escaped_message=$(echo "$inner_message" | python3 -c "import sys,json; print(json.dumps(sys.stdin.read().strip()))")

    cat <<EOF
{
  "Type": "Notification",
  "MessageId": "$sns_message_id",
  "TopicArn": "arn:aws:sns:us-east-1:000000000000:test-ses-notifications",
  "Message": $escaped_message,
  "Timestamp": "$(timestamp)"
}
EOF
}

db_query() {
    local sql="$1"
    $DOCKER_COMPOSE exec -T pgsql psql -U "$DB_USER" -d "$DB_NAME" -c "$sql"
}

# --- subcommands ---

cmd_list() {
    echo "=== Recent Outgoing Messages ==="
    echo ""
    db_query "
        SELECT * FROM (
            SELECT
                'marketing' AS type,
                om.id,
                om.recipient,
                LEFT(om.subject, 40) AS subject,
                om.status,
                om.ses_message_id,
                om.created_at
            FROM outgoing_messages om
            WHERE om.deleted_at IS NULL
            ORDER BY om.created_at DESC
            LIMIT 10
        ) marketing
        UNION ALL
        SELECT * FROM (
            SELECT
                'transact' AS type,
                otm.id,
                otm.recipient,
                LEFT(otm.subject, 40) AS subject,
                otm.status,
                otm.ses_message_id,
                otm.created_at
            FROM outgoing_transaction_messages otm
            WHERE otm.deleted_at IS NULL
            ORDER BY otm.created_at DESC
            LIMIT 10
        ) transact
        ORDER BY created_at DESC
        LIMIT 20;
    "
}

cmd_list_suppressions() {
    echo "=== Email Suppressions ==="
    echo ""
    db_query "
        SELECT
            id,
            email,
            reason,
            bounce_type,
            bounce_sub_type,
            complaint_type,
            source,
            account_id,
            created_at
        FROM email_suppressions
        WHERE deleted_at IS NULL
        ORDER BY created_at DESC
        LIMIT 20;
    "
}

cmd_bounce() {
    local email="${1:?Usage: ses-test.sh bounce <email> [ses_message_id]}"
    local ses_msg_id="${2:-$(generate_uuid)}"

    local inner
    inner=$(cat <<EOF
{
  "notificationType": "Bounce",
  "bounce": {
    "bounceType": "Permanent",
    "bounceSubType": "General",
    "bouncedRecipients": [{"emailAddress": "$email"}],
    "timestamp": "$(timestamp)"
  },
  "mail": {
    "messageId": "$ses_msg_id",
    "timestamp": "$(timestamp)",
    "destination": ["$email"]
  }
}
EOF
)

    local payload
    payload=$(build_sns_envelope "$inner")
    send_sns_payload "$payload" "Permanent bounce for $email (ses_msg_id=$ses_msg_id)"
}

cmd_bounce_transient() {
    local email="${1:?Usage: ses-test.sh bounce-transient <email> [ses_message_id]}"
    local ses_msg_id="${2:-$(generate_uuid)}"

    local inner
    inner=$(cat <<EOF
{
  "notificationType": "Bounce",
  "bounce": {
    "bounceType": "Transient",
    "bounceSubType": "General",
    "bouncedRecipients": [{"emailAddress": "$email"}],
    "timestamp": "$(timestamp)"
  },
  "mail": {
    "messageId": "$ses_msg_id",
    "timestamp": "$(timestamp)",
    "destination": ["$email"]
  }
}
EOF
)

    local payload
    payload=$(build_sns_envelope "$inner")
    send_sns_payload "$payload" "Transient bounce for $email (ses_msg_id=$ses_msg_id)"
}

cmd_complaint() {
    local email="${1:?Usage: ses-test.sh complaint <email> [ses_message_id]}"
    local ses_msg_id="${2:-$(generate_uuid)}"

    local inner
    inner=$(cat <<EOF
{
  "notificationType": "Complaint",
  "complaint": {
    "complaintFeedbackType": "abuse",
    "complainedRecipients": [{"emailAddress": "$email"}],
    "timestamp": "$(timestamp)"
  },
  "mail": {
    "messageId": "$ses_msg_id",
    "timestamp": "$(timestamp)",
    "destination": ["$email"]
  }
}
EOF
)

    local payload
    payload=$(build_sns_envelope "$inner")
    send_sns_payload "$payload" "Complaint (abuse) for $email (ses_msg_id=$ses_msg_id)"
}

cmd_delivery() {
    local email="${1:?Usage: ses-test.sh delivery <email> [ses_message_id]}"
    local ses_msg_id="${2:-$(generate_uuid)}"

    local inner
    inner=$(cat <<EOF
{
  "notificationType": "Delivery",
  "delivery": {
    "recipients": ["$email"],
    "timestamp": "$(timestamp)",
    "smtpResponse": "250 2.6.0 Message received"
  },
  "mail": {
    "messageId": "$ses_msg_id",
    "timestamp": "$(timestamp)",
    "destination": ["$email"]
  }
}
EOF
)

    local payload
    payload=$(build_sns_envelope "$inner")
    send_sns_payload "$payload" "Delivery notification for $email (unhandled type — check logs)"
}

cmd_subscribe() {
    local payload
    payload=$(cat <<EOF
{
  "Type": "SubscriptionConfirmation",
  "MessageId": "$(generate_uuid)",
  "TopicArn": "arn:aws:sns:us-east-1:000000000000:test-ses-notifications",
  "Message": "You have chosen to subscribe to the topic...",
  "SubscribeURL": "https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=test&Token=fake",
  "Timestamp": "$(timestamp)",
  "Token": "fake-confirmation-token"
}
EOF
)

    send_sns_payload "$payload" "SubscriptionConfirmation (will attempt to confirm fake URL — check logs)"
}

# --- main ---

case "${1:-help}" in
    list)
        cmd_list
        ;;
    list-suppressions|suppressions)
        cmd_list_suppressions
        ;;
    bounce)
        shift
        cmd_bounce "$@"
        ;;
    bounce-transient)
        shift
        cmd_bounce_transient "$@"
        ;;
    complaint)
        shift
        cmd_complaint "$@"
        ;;
    delivery)
        shift
        cmd_delivery "$@"
        ;;
    subscribe)
        cmd_subscribe
        ;;
    help|--help|-h|*)
        cat <<USAGE
Usage: ses-test.sh <command> [args]

Commands:
  list                              Show recent outgoing messages (marketing + transaction)
  list-suppressions                 Show email suppression records
  bounce <email> [ses_message_id]   Simulate a permanent (hard) bounce
  bounce-transient <email> [ses_id] Simulate a transient (soft) bounce
  complaint <email> [ses_message_id] Simulate an abuse complaint
  delivery <email> [ses_message_id] Simulate a delivery notification (unhandled type)
  subscribe                         Simulate SNS subscription confirmation

Environment:
  SES_TEST_URL    Webhook URL (default: https://localhost:8443/api/public/webhooks/ses)

Examples:
  ./jbj/ses-test.sh list
  ./jbj/ses-test.sh bounce user@example.com
  ./jbj/ses-test.sh bounce user@example.com 0100018e-abcd-1234-5678-abcdef123456
  ./jbj/ses-test.sh complaint user@example.com
  ./jbj/ses-test.sh list-suppressions

Requires docker/development environment running with:
  AWS_SNS_VERIFY_SIGNATURE=false
  SES_SUPPRESSION_ENABLED=true
USAGE
        ;;
esac
