# AjoPlus API

Laravel 12 JSON API for rotating savings groups. It uses MySQL, Sanctum bearer tokens, and integer kobo for every monetary field. The Flutter client is a separate project.

## Setup

1. Install PHP 8.2+, Composer, and MySQL 8+.
2. Run composer install, copy .env.example to .env, and set APP_KEY and the DB_* variables.
3. Set PAYSTACK_SECRET_KEY to a Paystack test secret in the backend .env. Never put it in Flutter, Git, or chat. Checkout is unavailable until this is set. The API rejects live keys in test mode.
4. Run php artisan migrate --force and php artisan serve.
5. Configure the Paystack test dashboard webhook URL as https://YOUR_API_HOST/api/v1/paystack/webhook. It must be publicly reachable over HTTPS.
6. Run the Laravel scheduler in deployment to prune expired Sanctum tokens and reconcile pending Paystack payments every five minutes.

The local .env is ignored by Git. Configure a real HTTPS APP_URL and TLS termination before exposing the API to devices.

## Authentication

Registration and login return a user, a 15-minute access token, and a 30-day rotating refresh token. Use the access token as Authorization: Bearer TOKEN for protected routes. Send the refresh token to /auth/refresh when access expires. Its old value is revoked on rotation. Logout revokes both tokens. Login, registration, and refresh are rate limited.

## API

All paths are under /api/v1. Send Accept: application/json. Protected routes require an access token.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | /auth/register | Register and issue tokens |
| POST | /auth/login | Issue tokens |
| POST | /auth/refresh | Rotate refresh and access tokens |
| GET | /auth/me | Current user |
| POST | /auth/logout | Revoke access and refresh tokens |
| GET, POST | /groups | Paginated memberships and group creation |
| POST | /groups/lookup | Preview an invite code |
| POST | /groups/join | Join a group with an invite code |
| GET | /groups/{group} | Group, members, current contributions, payouts |
| GET | /groups/{group}/schedule | Payout order and dates |
| GET | /groups/{group}/contributions | Paginated contribution history |
| POST | /groups/{group}/contributions/{contribution}/checkout | Paystack hosted checkout URL |
| POST | /groups/{group}/contributions/{contribution}/verify | Verify payment with Paystack |
| POST | /paystack/webhook | Signed provider event; no bearer token |
| POST | /groups/{group}/complete-cycle | Prepare pending payout after all contributions |
| POST | /groups/{group}/settle-payout | Organizer records completed manual transfer |
| GET | /transactions | Paginated group transactions |
| GET | /notifications | Paginated notifications |
| PATCH | /notifications/{notification}/read | Mark notification read |

Group creation requires a name, integer contribution_amount_kobo, frequency (daily, weekly, biweekly, or monthly), max_members (2-100), and start_date (YYYY-MM-DD). The creator holds payout position 1. The group opens for contributions when all positions fill.

Paystack checkout amount comes from the stored contribution, never from the client. Verification checks reference, exact integer kobo amount, NGN currency, test domain, and customer email before recording a paid contribution. Webhook signatures are HMAC SHA-512 checked, then the transaction is verified with Paystack again. Repeated verification is idempotent.

Payout preparation does not mark a transfer successful. The organizer must transfer funds manually and then provide an external transfer reference to settle-payout. This is a manual settlement record, not a provider-verified bank transfer.

## Verification

Run php artisan test and vendor/bin/pint --test. Feature tests exercise token rotation, authorization, kobo amounts, mocked Paystack verification responses, repeated verification, and payout progression. Tests use isolated SQLite; the configured application database is MySQL. A real Paystack test checkout and webhook require the account test secret and a public HTTPS webhook URL.
