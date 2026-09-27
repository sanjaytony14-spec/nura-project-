# Nura Space

An original registration, login, and private profile application for the Nura software-development assignment. It uses **PHP, jQuery/AJAX, MySQL, MongoDB, Redis, Bootstrap, HTML, CSS, and JavaScript**. No framework substitutes, browser-only authentication, or mock databases.

## Run locally

Install Git and Docker Desktop, start Docker's **Linux container engine**, and clone this repository. PHP and the databases run in containers; separate installations are unnecessary.

```sh
git clone https://github.com/sanjaytony14-spec/nura-project-.git
cd nura-project-
```

1. Copy `.env.example` to `.env` (`Copy-Item .env.example .env` in PowerShell, or `cp .env.example .env` on Linux/macOS).
2. Replace **every** `replace-with-...` value with a different long random secret. Use alphanumeric values for the MongoDB password because it is embedded in a URI. To generate one secret in PowerShell 7: `[Convert]::ToHexString([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))`. On Linux/macOS: `openssl rand -hex 32`. Never commit `.env`.
3. Start the app:

```sh
docker compose up --build -d
docker compose ps
```

Open **http://localhost:8080**. Create an account, sign in, save a profile, sign out, and sign in again to verify persistence. There are no default or shared passwords. Initial image downloads and PHP-extension compilation can take several minutes. Database health checks delay app startup until storage is ready.

Stop with `docker compose down`. Named Docker volumes retain database data. Do **not** add `--volumes` unless you intentionally want to erase it. Credentials and database-init scripts apply on first initialization; changing `.env` later does not change existing database users.

## Project structure

The required application paths are preserved exactly. Supporting files are only for shared code, local execution, deployment, verification, and submission.

```text
assets/
  css/
    bootstrap.min.css         # required Bootstrap library, with upstream license header
    style.css                 # original design
  js/
    jquery.min.js             # required jQuery library, with upstream license header
    common.js                 # shared AJAX, CSRF, feedback and password visibility
    login.js
    profile.js
    register.js
php/
  bootstrap.php               # shared validation, database connections and sessions
  login.php
  profile.php
  register.php
database/
  mysql.sql                   # account table, unique constraints
  mongo.js                    # restricted app user, profile collection validator
deploy/
  apache.conf
  php.ini
  Caddyfile
  compose.production.yaml
output/pdf/
  application-flow.pdf
  database-schema.pdf
tests/
  integration.py              # real HTTP regression tests; Python standard library
index.html
login.html
profile.html
register.html
Dockerfile
compose.yaml
.dockerignore
.gitignore
.gitattributes
.env.example
README.md
```

`.env` is local-only. Docker stores downloaded images, dependencies, and database volumes outside this repository. No `node_modules`, application caches, credentials, build logs, or test screenshots belong in the submitted source.

## How it works

1. `index.html` links to registration and login.
2. Registration validates a username, email and 12–72-byte password, then stores a bcrypt hash in MySQL. It sends the user to login after success.
3. Login verifies the hash and rotates the server-side session ID and CSRF token. Redis stores the authenticated account ID; the browser receives only an opaque HttpOnly cookie.
4. The profile page loads account details from MySQL and additional fields from MongoDB through an authenticated API. Profile documents are created on first save, using the MySQL ID as their string `_id`.
5. Logout destroys the server-side session and removes its cookie.

The profile's HTML shell contains no personal information. Unauthenticated API calls return `401`, and the page redirects to login. MongoDB and MySQL do not share a transaction: registration deliberately writes only to MySQL, so a failed profile store cannot leave a partially completed registration.

## API

All responses are JSON and use `Cache-Control: no-store`. Mutations require `X-CSRF-Token`; JSON bodies use `Content-Type: application/json`.

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `php/login.php` | GET | Start/read session; return CSRF token and authentication status |
| `php/register.php` | POST | Register `{username, email, password}` |
| `php/login.php` | POST | Sign in with `{identifier, password}`; identifier is username or email |
| `php/login.php` | DELETE | Sign out and revoke the session |
| `php/profile.php` | GET | Return own account, own profile, and CSRF token |
| `php/profile.php` | PUT | Save `{name, age, bio, interests}`; age may be null, interests is an array |

Statuses: `200` success, `201` registration, `400` malformed JSON, `401` unauthenticated/invalid login, `403` CSRF/origin rejection, `405` wrong method, `409` duplicate account, `413` oversized body, `415` wrong content type, `422` invalid input, `429` rate limit, `503` unavailable service.

## Security and data handling

- PDO native prepared statements; database uniqueness also protects concurrent registrations.
- Bcrypt password hashing (cost 12), constant-cost dummy verification for unknown accounts, no plaintext password storage or logging. Passwords are not trimmed or silently truncated.
- Redis-backed sessions: 30-minute idle limit and 12-hour absolute limit. Session IDs rotate at login; cookies are HttpOnly and SameSite=Lax. HTTPS production cookies are Secure.
- CSRF tokens protect registration, login, profile updates and logout; supplied Origin headers must exactly match `APP_ORIGIN`.
- Fixed-window Redis limits: 10 registration attempts and 30 login attempts per IP per 15 minutes. Invalid attempts count too. Counter increments and expiry are atomic.
- Profile ownership comes exclusively from the session, never a client-supplied account ID.
- Server-side type, length and range validation, plus MongoDB schema validation. User-entered text is rendered using `.text()`/`.val()`, not interpreted as HTML. HTML-like content in a bio remains literal text.
- Restrictive content-security policy, frame denial, no directory listings, hidden PHP errors, generic service-failure responses, restricted database users, no public database ports.
- MySQL and MongoDB retain data in named volumes. Redis persists via AOF. Volumes are not backups or encryption at rest; configure server disk encryption, backups, patching and monitoring before accepting real personal data.

This assignment has no email verification, password-reset email, MFA, public profiles, or account deletion UI. Those are outside the supplied workflow and are not represented as implemented features.

## Verify

```sh
docker compose exec -T app php -l php/bootstrap.php
docker compose exec -T app php -l php/register.php
docker compose exec -T app php -l php/login.php
docker compose exec -T app php -l php/profile.php
python tests/integration.py
```

The integration script uses only standard Python libraries and targets localhost. It creates two uniquely named test accounts, printing only their usernames. Run on a disposable local environment, not production. Tests cover validation, CSRF, SQL injection, duplicates, session rotation, authorization, profile persistence, account isolation and logout. The intentional rate limits also apply to tests; repeated runs may require waiting 15 minutes. Optional JavaScript syntax checks: `node --check assets/js/common.js`, and repeat for the other three application scripts.

## Deploy with HTTPS

A public GitHub repository is the source submission; **GitHub Pages cannot execute this PHP backend**. Use a Linux Docker host with persistent storage and a hostname whose DNS points to it. No live deployment is claimed until a real host is configured and tested.

1. On the server, clone this repository. Create `.env` with new production secrets, restrict its permissions (`chmod 600 .env`), and add `DOMAIN=your-real-hostname`.
2. Allow inbound TCP 80 and 443 for HTTPS and certificate issuance. Keep database ports private. Restrict SSH to authorized access.
3. Run from the repository root:

```sh
docker compose -f compose.yaml -f deploy/compose.production.yaml up --build -d
```

The production overlay enables Secure cookies, sets the exact HTTPS origin, and starts Caddy for automatic HTTPS. Caddy receives the fixed private address `172.30.82.10`; Apache trusts forwarded IPs **only** from that address so rate limits use the actual client IP. If the subnet conflicts with another network, change both the overlay and Apache's trusted address together. For a different hosting proxy, adapt this trust configuration explicitly.

4. Visit `https://your-real-hostname` and manually verify registration, login, profile saving, refresh, logout, and a second browser's denied access to private data. Check Secure/HttpOnly/SameSite cookie flags and HTTP-to-HTTPS redirection.
5. Back up MySQL and MongoDB to an encrypted, separate location and test restoration. Keep the host and container images patched; monitor storage and service health.

Useful commands: `docker compose logs --tail 50 app`, `docker compose ps`, and `docker compose up --build -d` after local changes. The image copies source at build time, so edited files require a rebuild. Avoid sharing database logs or configuration output that could expose secrets.

## Submission checklist

- Public source repository: https://github.com/sanjaytony14-spec/nura-project-
- Live HTTPS URL: pending hosting setup and live verification.
- Application flow diagram: [PDF](output/pdf/application-flow.pdf).
- Database schema diagram: [PDF](output/pdf/database-schema.pdf).
- Resume: supplied separately by the applicant; excluded from public source.
- Employer submission email: sent by the applicant when all materials are ready.

Reference documentation: [PHP session security](https://www.php.net/manual/en/features.session.security.management.php), [Docker Compose deployment](https://docs.docker.com/compose/how-tos/production/), [MongoDB PHP driver](https://www.mongodb.com/docs/drivers/php/). Bootstrap 5.3.3 and jQuery 3.7.1 are vendored with their upstream license notices. The page layouts and application data model are original work for this implementation.
