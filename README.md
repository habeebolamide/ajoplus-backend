# AjoPlus API

Laravel 12 JSON API for AjoPlus, a Nigerian rotating-savings (ajo/esusu) application. It manages authentication, savings circles, memberships, contributions, payout cycles, transactions, notifications, and Paystack payment verification.

The companion Flutter client is available at [habeebolamide/ajoplus](https://github.com/habeebolamide/ajoplus). Mobile clients authenticate with Sanctum bearer tokens. All monetary values are **integer kobo**, in requests, responses, and MySQL columns. For example, ₦1,250.50 is `125050` kobo. No float, decimal naira, or currency conversion is used in the backend.

> Payment checkout and verification routes are present, but production payment handling requires configured Paystack credentials, verified webhooks, reconciliation, and deployment security review.

## Setup

1. Install PHP 8.2+, Composer, Node.js/npm, and MySQL 8+.
2. Run `composer install`.
3. Copy `.env.example` to `.env` and set `APP_KEY`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`. Create the configured MySQL database first.
4. Run `php artisan key:generate` if this is a new `.env`.
5. Run `php artisan migrate`.
6. Start the API with `php artisan serve`, or use `composer run dev` to run the API, queue worker, logs, and Vite together.

The local `.env` is ignored by Git. The API is served at `/api/v1`.

## API

Send `Accept: application/json`. Except for registration and login, send `Authorization: Bearer <token>`.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/auth/register` | Create account and token |
| POST | `/auth/login` | Create token |
| POST | `/auth/refresh` | Refresh an access token |
| GET | `/auth/me` | Current user |
| POST | `/auth/logout` | Revoke current token |
| GET, POST | `/groups` | List my groups, create a group |
| POST | `/groups/join` | Join with `invite_code` |
| POST | `/groups/lookup` | Look up a group by invite code |
| GET | `/groups/{group}` | Group, members, current contributions, payouts |
| GET | `/groups/{group}/schedule` | Fixed payout order and dates |
| GET | `/groups/{group}/contributions` | Contribution history |
| POST | `/groups/{group}/contributions/{contribution}/checkout` | Start a Paystack checkout |
| POST | `/groups/{group}/contributions/{contribution}/verify` | Verify a contribution payment |
| POST | `/groups/{group}/complete-cycle` | Creator records payout after everyone paid |
| POST | `/groups/{group}/settle-payout` | Settle a completed payout |
| GET | `/transactions?type=all\|contribution\|payout` | Transactions for my groups |
| GET | `/notifications` | My notifications |
| PATCH | `/notifications/{notification}/read` | Mark my notification read |
| POST | `/paystack/webhook` | Paystack webhook receiver |

### Examples

Registration requires `name`, `email`, `password`, and `password_confirmation`; `phone` is optional. Login requires `email` and `password`.

Create group:

```json
{
  "name": "Market circle",
  "description": "Monthly savings",
  "contribution_amount_kobo": 125050,
  "frequency": "monthly",
  "max_members": 5,
  "start_date": "2026-10-01"
}
```

The creator gets payout position 1. Members join with `{"invite_code":"CODE"}` and receive the next position. The group becomes active when it is full. For a simulated contribution, send `{"successful":true}` or `{"successful":false}`. The amount always comes from the stored group contribution amount, never from the payment request. Only a member can record their own contribution. The creator can complete a cycle once every member's contribution is paid. The payout is the sum of those integer kobo amounts; the next cycle starts with pending contributions.

## Security and financial rules

- Keep `.env`, Paystack secret keys, database dumps, certificates, and user data out of Git.
- Verify Paystack webhook signatures; never treat client-reported payment status as authoritative.
- Apply authorization and group-membership checks to every state-changing request.
- Use database transactions, idempotency checks, and audit records around payment and payout state changes.
- Do not log passwords, access tokens, bank details, or full provider payloads.

## Tests and formatting

Run the checks before committing:

```sh
php artisan test
vendor/bin/pint --test
```

Feature tests use an in-memory SQLite database for fast isolation; the configured application database is MySQL.
