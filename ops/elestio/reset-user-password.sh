#!/bin/bash
# reset-user-password.sh - Admin fallback to set a Hi.Events user's password
# directly, for when the email-based reset isn't deliverable (e.g. SES suppression).
# The new password works for normal login; the user can change it afterward in
# Profile (which requires the current password, no email). Run on the Elestio host.
#
# Usage: ./reset-user-password.sh <email> [password]
#   password omitted -> you'll be prompted (hidden, stays out of shell history).
set -euo pipefail

cd ~/elestio_app_directory/hi-events || { echo "app dir not found" >&2; exit 1; }

SERVICE="all-in-one"

email="${1:-}"
if [[ -z "$email" ]]; then
  echo "Usage: $0 <email> [password]" >&2
  exit 2
fi

password="${2:-}"
if [[ -z "$password" ]]; then
  read -r -s -p "New password for ${email}: " password; echo
  read -r -s -p "Confirm password: "          confirm;  echo
  [[ "$password" == "$confirm" ]] || { echo "Passwords do not match." >&2; exit 2; }
fi
[[ -n "$password" ]] || { echo "Password must not be empty." >&2; exit 2; }

cid="$(docker compose ps -q "$SERVICE")"
[[ -n "$cid" ]] || { echo "container for service '$SERVICE' is not running" >&2; exit 1; }

# Pass values through the environment (NOT as argv or a PHP string literal) so any
# characters in the password are safe and it never appears in ps or shell history.
export RESET_EMAIL="$email" RESET_PASSWORD="$password"

out="$(docker exec -e RESET_EMAIL -e RESET_PASSWORD "$cid" \
  php /app/backend/artisan tinker --execute='
    $email = getenv("RESET_EMAIL");
    $pass  = getenv("RESET_PASSWORD");
    $u = HiEvents\Models\User::where("email", $email)->first();
    if (!$u) { echo "RESET_ERR no active user with email {$email}\n"; return; }
    $u->password = Illuminate\Support\Facades\Hash::make($pass);
    $u->save();
    $ok = Illuminate\Support\Facades\Hash::check($pass, $u->fresh()->password);
    echo ($ok ? "RESET_OK" : "RESET_ERR hash-verify-failed")." email={$email} id={$u->id}\n";
  ' 2>&1)" || true

echo "$out" | grep -vE '^\s*$' >&2 || true

case "$out" in
  *RESET_OK*) echo "Password reset for ${email}." ; exit 0 ;;
  *)          echo "Password reset FAILED for ${email}." >&2 ; exit 1 ;;
esac
