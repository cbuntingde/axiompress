# Experimental invocation authority and read broker

Trusted supervisor infrastructure, not a WordPress plugin or OS sandbox. Load the protocol classes plus BrokerError.php, InvocationRegistry.php and ContentBroker.php in the future supervisor bootstrap. Run `composer test:broker` without WordPress.

## Adapter contract

Only the supervisor may issue/revoke handles or close channels. Channel identity comes from the actual attached transport, never plugin JSON. issue receives trusted installation/site/actor and allowed content IDs; it does not authenticate a user. Handles use 32 cryptographically random bytes and are retained only by SHA-256 hash.

The generation callback receives installation/site and returns an integer only when that installation exists, is enabled and has content.read permission; return null otherwise. Its source must persist monotonically increasing generations across grant changes, disable and re-enable. Never reuse an earlier generation. The registry itself is in-memory and single-threaded.

canRead(actor, site, content ID) must return literal true for current resource authority. read(site, content ID) must perform a site-bound lookup and return matching content_id, revision, title and text. Extra fields are stripped. Both callbacks are trusted core adapters, not plugin callbacks. Tests use explicit fixtures; real core integration remains pending.

## Enforced rules

- Five-second monotonic lifetime; exact deadline is expired.
- Four active entries globally; one per installation/site and channel; at most 100 scoped IDs.
- Eight unique read attempts and one in-flight read. Denied reads consume IDs; repeats conflict.
- Channel, handle, expiry and current grant generation checked on use. Actor policy checked before read and again before returning data; generation rechecked before return.
- No private response cache. Responses pass strict protocol validation.
- Adapter errors release the in-flight slot. Internal failures become internal_error with previous exception for trusted diagnostics only. Never serialize exception chains, stacks or adapter messages to plugins.

closeConnection and revoke invalidate authority but do not stop processes. Expired metadata is pruned on admission. The supervisor must independently terminate/reap workers, enforce deadlines on blocked adapters, bound queues/output and reconcile workers after restart. A fresh registry accepts no old handles. No queue, persistence, write API, manifest loader, network service or container launcher is implemented.

Protocol violations remain terminal ProtocolError. BrokerError reason codes must be converted by the future supervisor into fixed safe response envelopes; no exception renderer belongs on the worker channel.

## Checks

```powershell
composer test:broker
composer test:protocol
docker compose --env-file .env exec -T php php tests/axiompress/broker-test.php
php vendor/bin/phpcs --standard=PSR12 tools/axiompress/broker tests/axiompress/broker-test.php
```

Tests use a fake clock with no sleeps and cover forgery, copied handles, cross-site/actor denial, quotas, replay, expiry, grant changes, disable, revoke, disconnect, restart, reentrancy, failures and revocation during reads. This verifies policy orchestration, not correctness of an eventual WordPress adapter or sandbox containment.

## Transitional real-core adapter

WordPressContentAdapter now supplies real core policy/data callbacks after a trusted WordPress CLI or test bootstrap. IDs are canonical `site:N`, `post:N`, `user:N`; anonymous is explicit. Only the currently bootstrapped site is allowed; the adapter never switches blogs. Only posts/pages in publish/private/draft/pending/future states are eligible. Private reads require read_post; unpublished reads require edit_post; password-protected content requires edit_post. Anonymous access permits only publicly viewable content without a password. Missing users, revisions, attachments, trash, wrong sites and malformed/overflow IDs are denied. No global user impersonation is performed.

Reads return stripped, entity-decoded source text without applying the_content, rendering blocks or executing shortcodes. This is text for analysis, not trusted HTML; the eventual renderer must escape it. Raw titles/content are limited to 4096/131072 bytes, and protocol validation also bounds serialized output. Oversize content is rejected, not silently truncated. Revision fingerprints cover the returned source and its status/modified time; they are read tokens, not optimistic-write support.

The adapter uses inherited WP_Post/WP_User APIs and their caches/filters. It does not guarantee a transactional snapshot across concurrent external database changes; the new kernel must define that consistency model. It also does not remove privileged legacy plugins from the existing core bootstrap. No isolated worker is ever loaded by this adapter.

Operator-only local probe (post 9, existing admin 1):

```powershell
docker compose --env-file .env exec -T --user wp_php cli wp eval-file tools/axiompress/broker/cli-read.php 9 1 --path=/var/www/src
```

Use user ID 0 for anonymous. The CLI operator explicitly chooses identity; this is not a public authentication endpoint. The probe issues one local scoped read grant, not a production persistent grant store. It closes invocation state on success/denial and returns only safe broker codes for policy failures. Test database integration is in tests/phpunit/tests/plugins/axiompress-content-adapter.php.
