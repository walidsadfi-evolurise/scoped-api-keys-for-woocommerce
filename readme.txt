=== Scoped API Keys for WooCommerce ===
Contributors: evolurise
Tags: woocommerce, rest api, security, api keys, integration
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give each WooCommerce REST API key a scope, an IP allowlist, an expiry date, a rate limit and a usage log. Stop handing out the keys to the whole store.

== Description ==

A WooCommerce REST API key is either *read*, *write* or *read/write* — on **everything**: orders, customers (names, emails, addresses), products, coupons, settings, webhooks, system tools. The shipping-label tool that only needs orders, the price-comparison feed that only needs products, the agency contractor, the AI agent you are experimenting with: they all get the same master key.

Scoped API Keys adds a policy on top of each key, without changing how keys are created:

* **Scopes** — per resource (orders, products, customers, coupons, reports, settings, shipping, taxes, payment gateways, webhooks, system status, data, analytics, admin) and per action (read or write). `products:read` cannot list customers; `orders:write` cannot touch settings.
* **IP allowlist** — single IPs or CIDR ranges, IPv4 and IPv6. Works behind Cloudflare and proxies once you set the client IP header.
* **Expiry date** — a key for a 3-week project stops working after 3 weeks.
* **Rate limit** — requests per minute per key; excess answers 429.
* **Disable** a key without deleting it.
* **Usage log** — route, method, status, outcome, IP, user agent per request, per key. See what an integration really uses before scoping it.
* **Monitor mode** — turn it on, add policies, watch the log for a week: violations are recorded as *would_block* and nothing breaks. Then switch to Enforce.
* **WP-CLI** — `wp wc scoped-keys set 12 --scopes=orders:read,products:read --ip=203.0.113.0/24 --expires=2027-01-31 --rate=120`.

A key with no policy behaves exactly as before, so installing the plugin changes nothing until you decide to.

= Why this matters now =

AI agents and automation platforms are the new API consumers. They work best with a narrow, revocable, observable credential: a key that can read orders and products, from one IP, for one month, with every call logged. That is what this plugin gives you, inside WooCommerce, with no external gateway.

= How enforcement works =

WooCommerce authenticates the consumer key and secret as usual. Just before the request reaches the controller, the plugin resolves the route to a resource (`/wc/v3/orders/12/refunds` → *orders*), the method to an action (GET → read, anything else → write), and checks the key's policy in this order: disabled → expired → IP → rate limit → scope. Violations answer a standard REST error (`wcsak_scope`, `wcsak_ip`, `wcsak_expired`, `wcsak_disabled`, `wcsak_rate_limit`).

Store API (`/wc/store/*`, public), cookie-authenticated admin requests and application passwords are never affected.

= Hooks =

* `wcsak_violation( $key, $reason, $request, $blocked )` — react to a violation (alert, ban…).

Compatible with High-Performance Order Storage.

== Installation ==

1. Install and activate. WooCommerce 8.0+ must be active.
2. Go to **WooCommerce → Scoped API Keys**. Every existing key is listed as *unrestricted*.
3. Optional but recommended: **Settings → Monitor mode** and **Log unmanaged keys** for a few days to see what each integration calls.
4. **Add policy** on a key: tick the resources it needs, paste the caller's IP, set an expiry.
5. Switch to **Enforce**.

== Frequently Asked Questions ==

= Does it replace WooCommerce's read/write permission? =

No. WooCommerce's permission is checked first; scopes can only narrow it.

= What about the Store API and the admin? =

Untouched. Only requests authenticated with a consumer key on `/wc/*`, `/wc-analytics/*` and `/wc-admin/*` are evaluated.

= My server is behind Cloudflare, the IP allowlist blocks everything =

Set the client IP header to `HTTP_CF_CONNECTING_IP` in Settings. Behind another proxy use `HTTP_X_FORWARDED_FOR` or `HTTP_X_REAL_IP`, only if the proxy overwrites the header.

= Is the rate limit precise? =

It is a per-minute fixed window stored in a transient (object cache if you have one). Good enough to stop a runaway script, not a replacement for a WAF.

== Screenshots ==

1. Keys with their scopes, allowlist, expiry, rate limit and block count.
2. Policy editor: resource × read/write grid, IP allowlist, expiry, rate limit.
3. Usage log with blocked and would-block outcomes.

== Changelog ==

= 1.0.0 =
* Initial release.
