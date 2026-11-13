# Scoped API Keys for WooCommerce

**Give each WooCommerce REST API key a scope, an IP allowlist, an expiry date, a rate limit and a usage log.**

[![CI](https://github.com/walidsadfi-evolurise/scoped-api-keys-for-woocommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/walidsadfi-evolurise/scoped-api-keys-for-woocommerce/actions/workflows/ci.yml)
![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-blue)
![WooCommerce 8.0+](https://img.shields.io/badge/WooCommerce-8.0%2B-96588a)
![License GPLv2](https://img.shields.io/badge/license-GPLv2-green)

## The problem

A WooCommerce API key is *read*, *write* or *read/write* on **the whole store**. The label printer that needs orders, the product feed that needs products, the contractor, the AI agent — all of them get a master key that can also list every customer's email and address, change prices, or create webhooks.

## What it does

```bash
wp wc scoped-keys set 12 \
  --scopes=orders:read,products:read \
  --ip=203.0.113.0/24 \
  --expires=2027-01-31 \
  --rate=120
```

From then on key #12:

| request | answer |
|---|---|
| `GET /wc/v3/orders/4821` from 203.0.113.7 | 200 |
| `GET /wc/v3/customers` | 403 `wcsak_scope` |
| `PUT /wc/v3/products/9` | 403 `wcsak_scope` |
| anything from 198.51.100.4 | 403 `wcsak_ip` |
| anything after 2027-01-31 | 403 `wcsak_expired` |
| 121st request in a minute | 429 `wcsak_rate_limit` |

Every request is logged (route, method, status, outcome, IP, user agent). **Monitor mode** records what *would* be blocked without blocking, so you can scope existing integrations safely. A key with no policy behaves exactly as before.

Resources: `orders` (incl. refunds, notes) · `products` (incl. variations, categories, tags, attributes, reviews) · `customers` · `coupons` · `reports` · `settings` · `shipping` · `taxes` · `payment_gateways` · `webhooks` · `system_status` · `data` · `analytics` · `admin` · `other` · `*`. Actions: `read` (GET/HEAD) or `write` (everything else; implies read).

## Why

AI agents and automation platforms are the new API consumers. They should get a narrow, revocable, observable credential — inside WooCommerce, with no gateway in front. This plugin is the WooCommerce half of a pair; [woocommerce-mcp-server](https://github.com/walidsadfi-evolurise/woocommerce-mcp-server) is the agent half (reads with PII masked, writes as proposals a human approves).

## Install

Requires WordPress 6.4+, PHP 7.4+, WooCommerce 8.0+.

```bash
wp plugin install https://github.com/walidsadfi-evolurise/scoped-api-keys-for-woocommerce/archive/refs/heads/main.zip --activate
```

Then **WooCommerce → Scoped API Keys**. Recommended rollout: Settings → *Monitor* + *Log unmanaged keys* for a few days → add policies → *Enforce*.

Behind Cloudflare or a reverse proxy, set the client IP header (`HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`…) in Settings before using IP allowlists.

## How it works

```
determine_current_user ─ WooCommerce validates consumer key + secret (unchanged)
rest_pre_dispatch @10  ─ WooCommerce checks read / write permission (unchanged)
rest_pre_dispatch @20  ─ WCSAK_Enforcer:
                           route  → resource   (/wc/v3/orders/12/refunds → orders)
                           method → action     (GET → read, else write)
                           policy → disabled? expired? IP? rate? scope?  → WP_Error 403/429
rest_post_dispatch     ─ usage log
```

Store API (`/wc/store/*`), cookie-authenticated admin requests and application passwords are never evaluated.

## Development

```bash
composer install && composer test && composer lint
php tests/run-without-phpunit.php      # unit tests, zero dependencies
```

Integration tests (CI) create a real consumer key, dispatch REST requests through `WP_REST_Server` with Basic auth and assert 200/403/429 and the log for scopes, IP, expiry, disabled keys, rate limit and monitor mode.

## License

GPLv2 or later. Built by [Walid Sadfi](https://walidsadfi.com) — Forward Deployed Engineer, WooCommerce & AI agents.
