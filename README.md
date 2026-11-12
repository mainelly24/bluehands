# BlueHands (Tailwind CSS, Alpine.js, PHP)

## Run locally
Requires PHP 8.1+ with `pdo_sqlite`, `curl` and `fileinfo`.

    php -S localhost:8000 -t .
    # optional, for the AI interview:
    ANTHROPIC_API_KEY=your_key php -S localhost:8000 -t .

Open http://localhost:8000. The SQLite database and sample workers are created on first request.
To upload bigger videos, raise `upload_max_filesize` and `post_max_size` in php.ini (default code limit is 50 MB).

## Files
- `index.html`: Tailwind + Alpine front end (home, workers, AI interview, wallet, account).
- `api.php`: JSON API (auth, biometrics, workers, escrow wallet, AI interview).
- `db.php`: PDO connection, schema, sample data.

## Before going live
1. Serve over HTTPS (WebAuthn and secure cookies need it).
2. Payments are simulated. Replace `topup` and `withdraw` in `api.php` with your MTN MoMo / Orange Money API calls and credit balances from the provider's webhook.
3. Biometric sign-in uses the device's WebAuthn check to unlock a stored device token. For full assurance verify WebAuthn signatures on the server (e.g. web-auth/webauthn-framework).
4. Build Tailwind with the CLI instead of the CDN, and pin Alpine with an integrity hash.
5. Move to MySQL/PostgreSQL, add SMS one-time-code verification, rate limiting per IP, and admin tools for credential checks and moderation.
