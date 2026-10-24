# PLUGIN_SPEC — Kiyoh WooCommerce

**Shape:** A + B (WordPress/WooCommerce plugin and Kiyoh / Kiyoh-Connect / Kiyoh Mobile connector)
**Owner:** Kiyoh plugins (KP-58, KP-86)   **Version:** 1.4.0

## Host
- Platform + supported versions: WordPress 5.8+ (tested to 7.1.3), WooCommerce 5.0+ (tested to 11.2.1). High-Performance Order Storage and Cart/Checkout blocks are declared compatible.
- Runtime: PHP 8.2+.

## Contract
- Inputs: WooCommerce order status changes, product saves, admin settings saves, daily WP-Cron, and the merchant clicking Connect or Test connection. OAuth return: `GET https://{shop-host}/wp-json/kiyoh/v1/oauth/callback?code&state`.
- Outputs: Kiyoh invite and product APIs, Kiyoh Mobile `POST /api/integrations/health`, Kiyoh-Connect register / authorize / token / credentials / revoke. Admin settings UI.
- Sample payloads: `tests/fixtures/kiyoh-connect/`.

## External API
- Kiyoh / Klantenvertellen publication and invite API: `https://www.kiyoh.com` or `https://www.klantenvertellen.nl`. Header `X-Publication-Api-Token`, location id in the body or query. Overridable with `kiyoh_api_base_url` for a mock only.
- Kiyoh-Connect 1.1.0 (KVMicroServices/Kiyoh-Connect PR #1, branch `cursor/per-shop-redirect-clients-64d3`):
  - Issuers: `https://connect.kiyoh.com` (tenant 98), `https://connect.klantenvertellen.nl` (tenant 99).
  - Client id `kiyoh-woocommerce`. Scope `integration:connect`. PKCE S256 plus `state`.
  - Per shop, return address `https://{shop-host}/wp-json/kiyoh/v1/oauth/callback`.
  - `POST {issuer}/shops/register` with `client_id`, `shop_url` (the https origin), `redirect_uri`, `issued_at`, and `signature`.
  - Signature is unpadded base64url HMAC-SHA256 over LF-joined lines: `v1`, `register`, client id, shop origin, redirect URI, unix `issued_at`. Uninstall and disconnect use the verb `revoke` on `POST {issuer}/shops/revoke`.
  - Popup `GET {issuer}/authorize`. Callback reads `code` and `state`. `POST {issuer}/token` is form-encoded (`authorization_code`, `client_id`, `client_secret`, `code`, `code_verifier`, `redirect_uri`). Refresh uses `grant_type=refresh_token`.
  - `GET {issuer}/integration/credentials` with `Authorization: Bearer` returns `locationId`, `tenantId`, `locationName`, `inviteApiKey`. Those become the plugin Location ID and API key.
  - `POST {issuer}/revoke` drops the OAuth token.
- Kiyoh Mobile health: `KIYOH_MOBILE_URLS` (default `https://kiyoh-mobile.com`), overridden by the setting, overridden by the `kiyoh_mobile_urls` filter. `POST /api/integrations/health`.
- Auth: Connect is OAuth 2.1 authorization code + PKCE. The publication API uses the invite key from Connect, or a manually pasted key.
- Rate limits: Connect and Mobile 429 honour `Retry-After` or `retryAfterSeconds`. Interactive Connect calls stop after 3 attempts or when the wait is over 8 seconds. Backoff cap is 60 seconds. Publication calls are not given a 5-attempt hang inside checkout.
- Webhooks: none. This plugin does not receive webhooks.
- The private Connect README was not readable with this agent's GitHub token (repository 404). This spec follows the 1.1.0 contract in the WooCommerce task: register, popup authorize, REST callback, token with client secret, credentials, refresh, and shop revoke.

## Behaviour
- Trigger: merchant clicks Connect (popup). Registration runs on each attempt and treats HTTP 409 as already registered. Daily health cron, plus health on settings save, invite, and product sync. Invites on order status.
- Failure policy: Connect errors stay on the settings screen. A publication 401 refreshes Connect once when the shop is connected, then retries that call once. Manual keys are not overwritten by a refresh. Health failures never stop checkout or invites.
- Idempotency: registering the same shop again is accepted. The OAuth `state` transient is single-use and lasts 15 minutes. Credentials are written only after both the token and the credentials call succeed. A second callback with the same state does not write again.

## Permissions requested (and why)
- `manage_woocommerce`: open WooCommerce → Kiyoh, save settings, start Connect, disconnect, test the API, sync products, and sync ratings. Shoppers and authors do not get these actions.
- The OAuth callback is a logged-out REST GET because WordPress strips the auth cookie when the Connect redirect has no REST nonce. The unguessable single-use `state` is the CSRF check. The callback does not accept a client-supplied location id or API key.

## Data stored, retention, uninstall behaviour
- Option `kiyoh_woocommerce_settings`: enabled, platform, location id, API key, credential source (`connect` or `manual`), mobile URLs, product sync, invitations, ratings.
- Option `kiyoh_connect_account`: issuer, shop origin, redirect URI, location name, tenant id, and the refresh and access tokens encrypted with AES-256-GCM. The key is `wp_salt('auth')`. Plaintext tokens are not stored. Autoload is off.
- Option `kiyoh_mobile_health`: last report digest and 429 block. Options `kiyoh_woocommerce_version`, `kiyoh_ratings_sync_state`, `kiyoh_ratings_sync_job`.
- Tables `{prefix}kiyoh_product_sync`, `{prefix}kiyoh_invitations`, `{prefix}kiyoh_review_cache`.
- Transients whose names start with `kiyoh_`, including the 15-minute OAuth state.
- Cron: `kiyoh_process_scheduled_invitations`, `kiyoh_cleanup_cache`, `kiyoh_sync_product_ratings`, `kiyoh_process_rating_sync`, `kiyoh_report_mobile_health`, `kiyoh_send_scheduled_invitation`.
- Deactivate clears cron and the review cache only. Settings, Connect tokens, invitations, and product-sync rows stay.
- Uninstall (`uninstall.php`, checks `WP_UNINSTALL_PLUGIN`) revokes the OAuth token and the shop registration, then deletes the options, tables, cron, and `kiyoh_` transients on each site of a multisite.

## Config precedence
- Connect HMAC key and client secret: `KIYOH_CONNECT_HMAC_KEY` / `KIYOH_CONNECT_CLIENT_SECRET` constants, else the same environment variables. There is no default. The plugin zip does not contain either value.
- Mobile URLs: `kiyoh_mobile_urls` filter, else a non-blank setting, else `KIYOH_MOBILE_URLS`, else `https://kiyoh-mobile.com`.
- Connect issuer is chosen only by the platform setting. It is not a free-text URL.

## Security checklist
- Secrets: HMAC key and client secret are not in the repo, JavaScript, or logs. Refresh and access tokens are encrypted. The authorize URL does not carry the verifier or the client secret. **Shipping either secret inside the distributed plugin would let any copy sign shop registrations and exchange codes. That is rejected. Each shop must receive the values out of band (wp-config or environment).**
- Least privilege: `manage_woocommerce` only, scope `integration:connect` only.
- Input: callback code and state are charset-checked. Credentials require `locationId`, tenant 98 or 99, and `inviteApiKey`. Return addresses must be the exact callback path.
- Output: settings page escapes text. Callback HTML escapes the message and refuses a next URL on another host.
- SQL: table names are whitelisted identifiers. Values use `$wpdb->prepare`. WordPress 5.8 has no `%i` placeholder.
- AuthZ: start and disconnect check the capability and a nonce. The callback is bound to the state created by that admin.
- CSRF: settings use the WordPress settings nonce. Connect start/disconnect use their own nonces. The external redirect uses `state`.
- SSRF: the issuer is one of the two Connect hosts. The return address must be public HTTPS. Private, loopback, link-local, and unresolved hosts are rejected before register.
- Files: no uploads.
- TLS: `wp_remote_*` keeps certificate verification on. Redirects are not followed.
- Webhooks: N/A. This plugin has no webhook receiver.
- Rate limit: Connect interactive cap above. The callback is not a public write; a missing or unknown state does not change credentials.
- Logging: Connect request bodies are not logged. The pre-existing invite debug log still runs when `WP_DEBUG` is on.
- Dependencies: no Composer or npm lockfile in this plugin. N/A for `composer audit` / `npm audit`.
- Errors: merchants see a short message. Tokens are not in that message.
- Uninstall: listed above.
- REST: `permission_callback` is not `__return_true`. It rejects a callback that lacks a plausible code and state.
- ABSPATH / `WP_UNINSTALL_PLUGIN` guards are on the new entry points.
- HPOS: already declared. No new checkout markup.

## Acceptance test
1. On an HTTPS shop with pretty permalinks, set the two secrets in `wp-config.php`, click **Connect with Kiyoh / Klantenvertellen**, finish login on the matching Connect host, and see the location id and invite key saved. **Test API Connection** succeeds.
2. Click Connect again for the same shop. Registration is accepted again and the saved location is replaced only after a new successful login. A replay of the old callback does not write a second set of credentials.
3. Stopping the popup before login leaves the previous key in place. Starting again after 15 minutes requires a new popup.
4. An HTTP shop, a missing secret, or a rejected client secret shows an admin message and does not change the manual key.
5. A Connect 429 fixture waits on `retryAfterSeconds` and does not spin inside the browser past 8 seconds (`tests/kiyoh-connect-test.php`).
6. Deactivate keeps settings. Delete runs `uninstall.php` and removes options, tables, and cron.
7. `python3 scripts/preflight.py kiyoh-woocommerce-plugin/kiyoh-woocommerce --fail-on low` reports 0 findings. Raw `$_GET` / `$_POST` reads that remain are nonce or capability checked, or sanitized and not printed raw (`preflight: ignore` on those lines).

## Open questions
- The Connect GitHub README could not be fetched (token 404). `shop_url` is sent as the https origin, which is the same value signed on the canonical line. If Connect instead wants the full home URL, including a path, registration will fail until that field is changed.
- How a merchant shop is given `KIYOH_CONNECT_HMAC_KEY` and `KIYOH_CONNECT_CLIENT_SECRET` is an operations decision. The plugin will not embed them.
- Subdirectory WordPress installs and Plain permalinks do not match `https://{host}/wp-json/kiyoh/v1/oauth/callback`. Those shops keep the manual key.
