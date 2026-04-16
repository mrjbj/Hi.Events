# AWS SES/SNS Configuration for District11 Hi.Events

Last updated: 2026-04-11

## Overview

AWS SES sends email for two applications:
- **Hi.Events** — transactional emails (order confirmations, tickets) and organizer marketing messages
- **Sendy** — marketing newsletters and campaign emails (has its own bounce/unsubscribe management)

Both share the same SES account (438157282334, us-east-1) and the same SES configuration sets.

## Architecture

```
SES Account (438157282334, us-east-1)
│
├── Configuration Set: District11-Transaction (used by Hi.Events)
│   ├── District11-Hard  → Email-Hard-District11 (BOUNCE, COMPLAINT, SUBSCRIPTION)
│   ├── District11-Soft  → Email-Soft-District11 (DELIVERY_DELAY, REJECT, RENDERING_FAILURE)
│   └── HiEvents-Webhook → District11-HiEvents-Webhook (BOUNCE, COMPLAINT)
│
└── Configuration Set: District11-Marketing (used by Sendy)
    ├── District11-Hard  → Email-Hard-District11 (BOUNCE, COMPLAINT, SUBSCRIPTION)
    ├── District11-Soft  → Email-Soft-District11 (DELIVERY_DELAY, REJECT, RENDERING_FAILURE)
    └── HiEvents-Webhook → District11-HiEvents-Webhook (BOUNCE, COMPLAINT)

SNS Topics:
├── Email-Hard-District11 (original, email notifications only)
│   └── treasurer@gagop11.org (email) — human notification of hard bounces
│
├── Email-Soft-District11 (original, email notifications only)
│   └── treasurer@gagop11.org (email) — human notification of soft issues
│
└── District11-HiEvents-Webhook (new, dedicated to Hi.Events automation)
    └── https://events.district11ga.org/api/public/webhooks/ses (HTTPS)
```

## Design Decisions

### Why a separate SNS topic for Hi.Events?

The original topics (`Email-Hard-District11`, `Email-Soft-District11`) were named to reflect
their subscribers — email notifications. Adding an HTTPS webhook to `Email-Hard` would have
muddied that convention. A dedicated `District11-HiEvents-Webhook` topic:

- Keeps the original topics clean for their email notification purpose
- Can be independently managed (disable, add filters) without affecting treasurer notifications
- Makes it clear in the AWS console which topic feeds Hi.Events automation
- Avoids any risk of breaking Sendy's setup

### Why BOUNCE and COMPLAINT only (not soft events)?

The `Email-Soft` topic receives DELIVERY_DELAY, REJECT, and RENDERING_FAILURE. These are
sender-side issues (SES config problems, still retrying) not recipient problems. Suppressing
an email address because of a DELIVERY_DELAY would block emails that would have been delivered.

Our handler only processes:
- **Bounce** (notificationType: "Bounce") — address doesn't exist or is permanently undeliverable
- **Complaint** (notificationType: "Complaint") — recipient marked email as spam

### Sendy bounces flow through the same path

Both config sets (`District11-Transaction` and `District11-Marketing`) publish BOUNCE/COMPLAINT
to the Hi.Events webhook topic. This means Sendy marketing bounces also create suppression
records in Hi.Events. This is intentional:

- If an address hard-bounces from a Sendy campaign, it's a bad address regardless
- The Hi.Events handler stores these with `account_id = null` (global suppression)
- Sendy has its own bounce management — this is additive, not conflicting

## SNS Topic ARNs

| Topic | ARN |
|-------|-----|
| Email-Hard-District11 | `arn:aws:sns:us-east-1:438157282334:Email-Hard-District11` |
| Email-Soft-District11 | `arn:aws:sns:us-east-1:438157282334:Email-Soft-District11` |
| District11-HiEvents-Webhook | `arn:aws:sns:us-east-1:438157282334:District11-HiEvents-Webhook` |

## Hi.Events Environment Variables

Set in `docker-compose.yml` on Elestio:

```yaml
- SES_SUPPRESSION_ENABLED=true
- AWS_SNS_VERIFY_SIGNATURE=true
# Optional — restricts handler to only accept from this topic:
# - AWS_SNS_TOPIC_ARN=arn:aws:sns:us-east-1:438157282334:District11-HiEvents-Webhook
```

`AWS_SNS_TOPIC_ARN` is optional. When set, the handler silently ignores notifications from
any other topic. When unset, it processes all valid SNS notifications. Since only one topic
is subscribed to the webhook, this is defense-in-depth rather than required.

## CLI Commands for Managing This Setup

```bash
# List all SNS topics
aws sns list-topics --output table

# Check subscribers for a topic
aws sns list-subscriptions-by-topic \
  --topic-arn arn:aws:sns:us-east-1:438157282334:District11-HiEvents-Webhook \
  --output table

# Check event destinations for a config set
aws sesv2 get-configuration-set-event-destinations \
  --configuration-set-name District11-Transaction --output json

# Subscribe a new endpoint
aws sns subscribe \
  --topic-arn arn:aws:sns:us-east-1:438157282334:District11-HiEvents-Webhook \
  --protocol https \
  --notification-endpoint https://your-domain/api/public/webhooks/ses

# Remove a subscription
aws sns unsubscribe --subscription-arn <full-subscription-arn>

# Test: publish a fake bounce notification (bypasses SES, goes straight to SNS)
aws sns publish \
  --topic-arn arn:aws:sns:us-east-1:438157282334:District11-HiEvents-Webhook \
  --message '{"notificationType":"Bounce","bounce":{"bounceType":"Permanent","bounceSubType":"General","bouncedRecipients":[{"emailAddress":"test-bounce@example.com"}]}}'
```

## Verification

After setup, confirm the webhook is working:

1. Check admin UI: `https://events.district11ga.org/admin/email-suppressions`
2. Check container logs: `docker logs hi-events-all-in-one-1 --tail 50 | grep -i ses`
3. Check database directly:
   ```bash
   docker exec -w /app/backend hi-events-all-in-one-1 php artisan tinker \
     --execute="dump(\DB::table('email_suppressions')->get());"
   ```
