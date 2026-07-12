#!/bin/bash

# Resend the account-request email-confirmation mail to people who requested an
# account but never confirmed their address. Runs the ConfirmAccount reminder
# maintenance script for every language wiki (account_requests is per-wiki).
#
# Reminders are sent once per request, no earlier than 1h after signup; the
# script is idempotent (see acr_email_reminded), so running it often is safe.
# Intended to be invoked from cron every ~15 minutes.

set -euo pipefail

CONTAINER=hitchwiki-mediawiki
SCRIPT=/var/www/html/extensions/ConfirmAccount/maintenance/sendEmailReminders.php
LANGUAGES=(en bg de es fi fr he hr nl pl pt ro ru tr zh it lt uk)

for lang in "${LANGUAGES[@]}"; do
	docker exec "$CONTAINER" php /var/www/html/maintenance/run.php "$SCRIPT" --wiki="$lang"
done
