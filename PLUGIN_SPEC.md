# PLUGIN_SPEC — Kiyoh WooCommerce

**Shape:** A + B (WordPress/WooCommerce plugin and Kiyoh / Kiyoh-Connect / Kiyoh Mobile connector)
**Owner:** Kiyoh plugins (KP-58, KP-86)   **Version:** 1.4.3

## Host
- Platform + supported versions: WordPress 5.8+ (tested to 7.1.3), WooCommerce 5.0+ (tested to 11.2.1). High-Performance Order Storage and Cart/Checkout blocks are declared compatible.
- Runtime: PHP 8.2+.

## Contract
- Inputs: WooCommerce order status changes, product saves, admin settings saves, daily WP-Cron, and the merchant clicking Connect or Test connection. OAuth return: `GET https://{shop-host}/wp-json/kiyoh/v1/oauth/callback?code&state`.
- Outputs: Kiyoh invite and product APIs, Kiyoh Mobile `POST /api/integrations/health`, Kiyoh-Connect register / authorize / token / credentials / refresh / revoke / shop revoke. Admin settings UI.
- Sample payloads: `tests/fixtures/kiyoh-connect/`.

## External API
- Kiyoh / Klantenvertellen publication and invite API: `https://www.kiyoh.com` or `https://www.klantenvertellen.nl`. Header `X-Publication-Api-Token`, location id in the body or query. Overridable with `kiyoh_api_base_url` for a mock only.
- Kiyoh-Connect 1.2.0 (KVMicroServices/Kiyoh-Connect PR #1). Self-hosted client `kiyoh-woocommerce` is public. There is no HMAC key and no client secret.
  - Issuers: `https://connect.kiyoh.com` (tenant 98), `https://connect.klantenvertellen.nl` (tenant 99).
  - Scope `integration:connect`. PKCE S256 plus `state`.
  - Per shop, return address `https://{shop-host}/wp-json/kiyoh/v1/oauth/callback`.
  - `POST {issuer}/shops/register` as form or JSON: `client_id`, `shop_url` (https origin, no path), `redirect_uri`. No signature. HTTP 200 once, `Cache-Control: no-store`: `client_id`, `shop_origin`, `redirect_uri`, `shop_id`, `shop_secret`, `status` `active` or `pending`. A non-200 body is not stored.
  - The plugin stores `shop_id` and `shop_secret` encrypted. The same issuer and return address skip register and reuse them. A later register returns `pending`; that secret is used for the in-flight code exchange and becomes the only stored secret after the exchange succeeds. A failed pending exchange leaves the previous secret in place. `401 invalid_client` on a reused secret clears it so the next click registers again.
  - Popup `GET {issuer}/authorize?response_type=code&client_id&redirect_uri&scope=integration:connect&state&code_challenge&code_challenge_method=S256`. No shop secret and no verifier on this URL.
  - Callback reads `code` and `state`.
  - `POST {issuer}/token` form: `grant_type=authorization_code`, `client_id`, `shop_id`, `shop_secret`, `code`, `code_verifier`, `redirect_uri`. A bad secret is HTTP 401 `invalid_client`.
  - `GET {issuer}/integration/credentials` with `Authorization: Bearer` returns `locationId`, `tenantId`, `locationName`, `inviteApiKey`. Those become the plugin Location ID and API key.
  - Refresh form: `grant_type=refresh_token`, `refresh_token`, `client_id`, `shop_id`, `shop_secret`. A new refresh token replaces the stored one.
  - `POST {issuer}/revoke` form: `token`, `client_id`, `shop_id`, `shop_secret`.
  - `POST {issuer}/shops/revoke` JSON: `client_id`, `shop_id`, `shop_secret`. Disconnect and uninstall both send this after the token revoke. If the shop secret cannot be decrypted, the remote revoke is skipped and local data is still deleted.
- Kiyoh Mobile health: `KIYOH_MOBILE_URLS` (default `https://kiyoh-mobile.com` only; testers set staging in the field), overridden by the setting, overridden by the `kiyoh_mobile_urls` filter. `POST /api/integrations/health`. Body includes `webhooks.lastReceivedAt` (null; this plugin has no webhook receiver).
- Auth: Connect is OAuth 2.1 authorization code + PKCE with a per-shop secret. The publication API uses the invite key from Connect, or a manually pasted key.
- Rate limits: Connect and Mobile 429 honour `Retry-After`, else `retryAfterSeconds`. Interactive Connect calls stop after 3 attempts or when the wait is over 8 seconds. Backoff cap is 60 seconds. A register 429 tells the merchant to wait. Publication calls are not given a 5-attempt hang inside checkout.
- Webhooks: none. This plugin does not receive webhooks.

## Behaviour
- Trigger: merchant clicks Connect (popup). Registration runs only when this issuer and return address have no reusable shop secret. After the callback, the popup tells the settings tab and closes; the tab reloads Connected. With no opener, the popup window opens the settings page. Daily health cron, plus health on Connect credential save, settings save, invite, and product sync. Invites on order status.
- Failure policy: Connect errors stay on the settings screen. An active secret is saved before the popup, because Connect shows it once. A pending secret is saved only after the token call succeeds. A publication 401 refreshes Connect once when the shop is connected, then retries that call once. Manual keys are not overwritten by a refresh. Health failures never stop checkout or invites.
- Idempotency: the same return address reuses `shop_id` and `shop_secret` and does not call register. The OAuth `state` transient is single-use and lasts 15 minutes. Location id and invite key are written only after both the token and the credentials call succeed. A second callback with the same state does not write again. Switching the platform (Kiyoh vs Klantenvertellen) does not reuse the other issuer's secret.

## Permissions requested (and why)
- `manage_woocommerce`: open WooCommerce → Kiyoh, save settings, start Connect, disconnect, test the API, sync products, and sync ratings. Shoppers and authors do not get these actions.
- The OAuth callback is a logged-out REST GET because WordPress strips the auth cookie when the Connect redirect has no REST nonce. The unguessable single-use `state` is the CSRF check. The callback does not accept a client-supplied location id or API key.

## Data stored, retention, uninstall behaviour
- Option `kiyoh_woocommerce_settings`: enabled, platform, location id, API key, credential source (`connect` or `manual`), mobile URLs, product sync, invitations, ratings.
- Option `kiyoh_connect_account` (schema 2, autoload off): issuer, shop origin, redirect URI, location name, tenant id, shop status, and `shop_id`, `shop_secret`, refresh token, and access token encrypted with AES-256-GCM. The key is `hash('sha256', wp_salt('auth'), true)`. Plaintext secrets are not stored.
- Option `kiyoh_mobile_health`: last report digest and 429 block. Options `kiyoh_woocommerce_version`, `kiyoh_ratings_sync_state`, `kiyoh_ratings_sync_job`.
- Tables `{prefix}kiyoh_product_sync`, `{prefix}kiyoh_invitations`, `{prefix}kiyoh_review_cache`.
- Transients whose names start with `kiyoh_`, including the 15-minute OAuth state (encrypted verifier, shop id, and shop secret, plus whether the secret must be promoted).
- Cron: `kiyoh_process_scheduled_invitations`, `kiyoh_cleanup_cache`, `kiyoh_sync_product_ratings`, `kiyoh_process_rating_sync`, `kiyoh_report_mobile_health`, `kiyoh_send_scheduled_invitation`.
- Deactivate clears cron and the review cache only. Settings, Connect tokens, the shop secret, invitations, and product-sync rows stay.
- Uninstall (`uninstall.php`, checks `WP_UNINSTALL_PLUGIN`) revokes the OAuth token and the shop registration with the stored shop secret, then deletes the options, tables, cron, and `kiyoh_` transients on each site of a multisite.

## Config precedence
- Connect has no wp-config secret. `shop_url` is the https origin of the shop. The issuer is chosen only by the platform setting. It is not a free-text URL.
- Mobile URLs: `kiyoh_mobile_urls` filter, else a non-blank setting, else `KIYOH_MOBILE_URLS`, else `https://kiyoh-mobile.com`.

## Security checklist
- Secrets: no shared HMAC key and no client secret in the zip, JavaScript, logs, or authorize URL. The per-shop secret and the refresh and access tokens are encrypted. Editing the Location ID or API key clears the refresh and access tokens and stops automatic overwrite; the shop secret stays so the same return address can connect again.
- Least privilege: `manage_woocommerce` only, scope `integration:connect` only.
- Input: callback code and state are charset-checked. Registration requires `shop_id`, a 16–256 character `shop_secret`, and status `active` or `pending`. Echoed `client_id`, `shop_origin`, and `redirect_uri` must match what was sent. Credentials require `locationId`, tenant 98 or 99, and `inviteApiKey`. Return addresses must be the exact callback path.
- Output: settings page escapes text. Callback HTML escapes the message and refuses a next URL on another host. The popup postMessages `{source:kiyoh-connect}` only to the stored shop origin (never `*`). The settings tab checks `event.origin` and `event.source` (the window it opened), replies with `{source:kiyoh-connect-ack}` to that same callback origin, closes the popup, and reloads. With no opener, the callback window navigates to the settings URL. `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, and `Cross-Origin-Opener-Policy: unsafe-none` on the callback document.
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
- Errors: merchants see a short message. Tokens and the shop secret are not in that message.
- Uninstall: listed above.
- REST: `permission_callback` is not `__return_true`. It rejects a callback that lacks a plausible code and state.
- ABSPATH / `WP_UNINSTALL_PLUGIN` guards are on the new entry points.
- HPOS: already declared. No new checkout markup.

## Acceptance test
1. On an HTTPS shop with pretty permalinks, click **Connect with Kiyoh / Klantenvertellen** with no Connect values in `wp-config.php`. Finish login on the matching Connect host. The popup closes. The original WooCommerce → Kiyoh tab reloads and shows Connected, and the location id and invite key are saved. **Test API Connection** succeeds. If the browser drops `window.opener`, that same popup window opens the Kiyoh settings page instead of staying on the callback.
2. Click Connect again for the same return address. The plugin does not call `/shops/register` again. The saved location is replaced only after a new successful login. A replay of the old callback does not write a second set of credentials.
3. Changing the site URL so the callback changes causes a new registration. The pending secret replaces the stored secret only after the code exchange succeeds. Closing the popup before that leaves the previous secret in place.
4. Stopping the popup before login on a first-time `active` registration keeps the secret so the next click can reuse it. Starting again after 15 minutes requires a new popup.
5. An HTTP shop shows the HTTPS message and does not change the manual key. A rejected shop secret (`401 invalid_client`) asks the merchant to connect again.
6. A Connect 429 fixture waits on `Retry-After` or `retryAfterSeconds` and does not spin inside the browser past 8 seconds (`tests/kiyoh-connect-test.php`).
7. Editing the Location ID or API key switches the shop to the manual key. A later publication 401 does not overwrite that key.
8. Disconnect clears the location and API key when they came from Connect, and leaves invitation settings. Deactivate keeps settings. Delete runs `uninstall.php`, revokes the shop with the stored secret, and removes options, tables, and cron.
9. `python3 scripts/preflight.py kiyoh-woocommerce-plugin/kiyoh-woocommerce --fail-on low` reports 0 findings. Raw `$_GET` / `$_POST` reads that remain are nonce or capability checked, or sanitized and not printed raw (`preflight: ignore` on those lines).

## Open questions
- Subdirectory WordPress installs and Plain permalinks do not match `https://{host}/wp-json/kiyoh/v1/oauth/callback`. Those shops keep the manual key.
