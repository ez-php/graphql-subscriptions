# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `check_module_deps.php` — fails when a package's code imports an ez-php package its `composer.json` does not declare (module `src`: `require`/`suggest`; tests: `require`/`require-dev` and their dependencies), or requires one it never uses
4. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
5. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
6. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
composer module-deps:check   # undeclared / unused ez-php package dependencies only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis --extensions=gmp
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` (each `-`-separated word upper-cased) unless `--namespace=`
overrides it. Existing exceptions the guess gets wrong: `asyncapi` → `AsyncApi`,
`bignum` → `BigNum`, `dataloader` → `DataLoader`, `dotenv` → `Env`, `graphql` → `GraphQL`, `graphql-subscriptions` → `GraphQLSubscriptions`, `oauth` → `OAuth`,
`opcache` → `OPCache`, `openapi` → `OpenApi`, `qrcode` → `QrCode`, `swagger-ui` → `SwaggerUI`, `webauthn` → `WebAuthn` and
`websocket` → `WebSocket`; `websocket-client` → `WebsocketClient`, `websocket-tls` → `WebsocketTls`,
`webauthn-metadata` → `WebauthnMetadata` and `metrics-statsd` → `MetricsStatsd` are
intentional lower-case-word namespaces, and `testing-application` shares `EzPhp\Testing\`
with `testing`).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services`/`--extensions` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4` **and** the shared
`autoload-dev` `Tests\` directory list), `phpstan.neon`, `phpunit.xml` (test suite
**and** coverage source), and `packages.sh` (alphabetical position) — in both
generated and `--repo` mode.

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — claim the "next free" row by
  editing the table in `CODING_GUIDELINES.md` (never in a `CLAUDE.md` copy) and run
  `composer guidelines:sync` in the same change. Editing it drifts every `CLAUDE.md`
  until the sync runs, which is why the generator only reminds you instead of doing
  it. Skipping the edit leaves "next free" stale, so the next module collides.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders (`docker/db/`, the MySQL init script, only with `--services=mysql`). Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch/Memcached/Mailpit service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
vendor/bin/docker-init --services=memcached,mailpit
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`, `memcached`, `apcu` (with `apc.enable_cli=1`):

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
vendor/bin/docker-init --extensions=memcached,apcu
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| `ez-php/broadcast` | — | 6384 (`REDIS_HOST_PORT`) | — |
| `ez-php/feature-flags` | — | 6385 (`REDIS_HOST_PORT`) | — |
| `ez-php/scheduler` | — | 6386 (`REDIS_HOST_PORT`) | — |
| `ez-php/session` | — | 6387 (`REDIS_HOST_PORT`) | — |
| `ez-php/metrics` | — | 6388 (`REDIS_HOST_PORT`) | — |
| `ez-php/event-store-snapshots` | 3312 | — | — |
| **next free** | **3313** | **6389** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. Every module row maps it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT`, `HEALTH_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. Mailpit is the one other service with published host ports: SMTP `1025` and web UI `8025`. `ez-php/mail` maps them through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above, documented in `modules/mail/.env.example`); the root project and the `ez-php/` template each run their own Mailpit on the same defaults (`MAIL_PORT`/`MAIL_WEB_PORT`), so **these three stacks cannot run at the same time** without overriding those variables. It isn't a table column because no module beyond those three runs Mailpit — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here. Services reached only over the Compose network publish no host port and need no entry at all: Memcached (`memcached:11211` in the root stack and `ez-php/cache`) and the opt-in Elasticsearch/Typesense backends in `modules/search/docker-compose.ci.yml`.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Every multi-package script sources it — `update_all.sh`, `fullcheck.sh`, `bump_version.sh` and the `git_*_all.sh` scripts (`git_push_all.sh`, `git_pull_all.sh`, `git_tag_all.sh`, `git_delete_all_tags.sh`) — so the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/graphql-subscriptions

GraphQL subscriptions for ez-php: a `graphql-transport-ws` protocol handler for
`ez-php/websocket`, a registry mapping Subscription fields to `ez-php/broadcast` channels,
and a broadcast driver that runs the matching subscriptions when an event is published in
the WebSocket server process.

---

## Source Structure

```
src/
├── GraphQLTransportWsHandler.php   — websocket HandlerInterface speaking graphql-transport-ws (init/ack, subscribe, complete, ping)
├── SubscriptionRegistry.php        — Subscription field → broadcast channel (fixed name or closure over the field args) + optional event filter
├── ChannelRoute.php                — readonly (channel, ?event) a subscription listens on; matches()
├── Subscription.php                — readonly active subscription: connection, id, document, variables, route, context
├── SubscriptionManager.php         — active subscriptions per connection; publish() executes matches and sends `next`
├── SubscriptionBroadcastDriver.php — BroadcastDriverInterface feeding the manager, optionally decorating an inner driver
├── Protocol.php                    — subprotocol name, 44xx close codes (incl. 4408 init timeout), JSON message encoding
├── Redis/
│   ├── RedisSubscriber.php         — RESP Pub/Sub consumer on a plain socket (PSUBSCRIBE, AUTH, split-frame buffering); no ext-redis
│   └── RedisBroadcastRelay.php     — broadcast RedisDriver JSON → SubscriptionManager::publish(); attach() registers it with LoopHooks
├── Sse/
│   ├── SseSubscriptionEndpoint.php — SSE fallback route handler: one operation per request over the WS handler, Redis-fed, ping/maxSeconds
│   └── BufferedConnection.php      — @internal in-memory ConnectionInterface collecting the handler's protocol messages
├── MessageType.php                 — enum of graphql-transport-ws message types
└── SubscriptionException.php       — unroutable subscription field / invalid variables

tests/
├── TestCase.php                         — Base PHPUnit test case (identical in every package)
├── Support/SubscriptionFakeConnection.php — recording ConnectionInterface (messages, close code, subprotocol)
├── Support/SubscriptionTestSchema.php   — schema fixture with Query/Mutation/Subscription fields
├── GraphQLTransportWsHandlerTest.php    — protocol flow: ack, 4400/4401/4403/4406/4409/4429, single-result ops, routing, context, per-connection limit
├── SubscriptionManagerTest.php          — event matching, payload as root value, errors in `next`, dropping closed connections
├── SubscriptionRegistryTest.php         — fixed/closure channels, event filter, unknown field
├── SubscriptionBroadcastDriverTest.php  — Broadcaster → driver → subscribers, inner driver still receives events
├── Support/SubscriptionFakeRedisServer.php — loopback TCP server playing Redis (records commands, writes RESP pushes)
├── Redis/SubscriptionRedisSubscriberTest.php — RESP framing, split frames, error replies, closed connection, AUTH (child-process fake), real Redis when reachable
├── Redis/SubscriptionRedisRelayTest.php — relayed events reach a WS subscriber through LoopHooks; foreign messages skipped; decode()
└── Sse/SubscriptionSseEndpointTest.php — query answers once, relayed subscription events, 4403 / validation errors as error events, GET/POST parsing
```

---

## Key Classes and Responsibilities

| Class | Responsibility |
|---|---|
| `GraphQLTransportWsHandler` | Protocol state machine per connection. `connection_init` → optional `$authorize(conn, payload)` → `connection_ack`; `$context(conn, payload)` builds the resolver context once. `subscribe`: validates via `GraphQLExecutor::validate()`; a query/mutation runs once (`next` + `complete`); a subscription is routed and registered. Violations close with `Protocol::CLOSE_*`. |
| `SubscriptionRegistry` | Which broadcast channel feeds which Subscription field. A closure channel receives the field's **coerced** arguments, so `orderUpdated(id: $id)` with variables routes to `orders.42`. |
| `SubscriptionManager` | Holds subscriptions keyed by connection and operation id. `publish()` re-executes each matching document with the event payload as root value through `GraphQLExecutor::execute()`, so depth/complexity limits and debug handling are the graphql module's. |
| `SubscriptionBroadcastDriver` | Glue to ez-php/broadcast: install it as the broadcast driver in the WebSocket process; an optional inner driver (e.g. `WebSocketDriver`, `LogDriver`) still receives every event. |

Typical wiring (one long-running CLI process):

```php
$schema   = SchemaBuilder::create()->query([...])->mutation([...])->subscription([...])->build();
$executor = new GraphQLExecutor($schema);
$registry = (new SubscriptionRegistry())->channel('orderUpdated', fn (array $a): string => 'orders.' . $a['id']);
$manager  = new SubscriptionManager($executor);

Broadcast::setBroadcaster(new Broadcaster(new SubscriptionBroadcastDriver($manager)));

(new Server('0.0.0.0', 8080, subprotocols: [Protocol::SUBPROTOCOL]))
    ->run(new GraphQLTransportWsHandler($schema, $executor, $registry, $manager));
```

---

## Design Decisions and Constraints

- **Two delivery paths, one format.** Inside the WebSocket process, `SubscriptionBroadcastDriver` feeds
  `Broadcast::to()` calls straight into the manager. From other processes (the HTTP app, workers), events
  travel through Redis: they publish with `ez-php/broadcast`'s `RedisDriver` (JSON `{"event","payload"}`), and
  `Redis\RedisBroadcastRelay` consumes that format and calls `SubscriptionManager::publish()` — no new
  pub/sub layer. `ez-php/broadcast` drivers only publish, so the relay brings its own subscriber.
- **`RedisSubscriber` speaks RESP over a plain socket instead of using ext-redis.** phpredis'
  `subscribe()` blocks the process inside a callback, which an event loop cannot multiplex. The socket is
  registered with `ez-php/websocket`'s `LoopHooks` (`$server->hooks()->addReadStream()`, added to both
  `Server` and `TlsServer` for this), and `read($timeout)` parses complete frames (split frames stay
  buffered). Only `PSUBSCRIBE`/`AUTH` and the push frames are implemented — it is a consumer, not a client.
- **The SSE fallback reuses the WebSocket handler.** `Sse\SseSubscriptionEndpoint` drives
  `GraphQLTransportWsHandler` over an in-memory `Sse\BufferedConnection` (connection_init with the request's
  `Authorization` header as payload, then one `subscribe`), so validation, routing, authorization, context and
  limits are the same code. Each streaming request opens its own `RedisSubscriber` (`PSUBSCRIBE *`; the manager
  does the routing), sends `ping` after `heartbeatSeconds` of silence and ends after `maxSeconds` (clients
  reconnect). Queries and mutations answer once and never open Redis.
- **Execute per event, re-parse per event.** webonyx executes a `subscription` operation like a query
  with a root value; each event runs the stored document with the payload as root. The document is
  parsed again per event (via `GraphQLExecutor::execute()`), trading some CPU for reusing the graphql
  module's limits and error handling unchanged. Validation runs once at subscribe time.
- **Subscription resolvers read the payload.** The broadcast payload is the root value — a field's
  `resolve` receives it as its first argument. Without a `resolve`, webonyx's default resolver looks up
  `$payload[<fieldName>]`.
- **Errors per event stay in-band.** Execution errors are sent inside the `next` payload (`errors`
  key), as the protocol specifies; the subscription stays active. Validation / routing failures at
  subscribe time are an `error` message; only protocol violations close the socket.
- **Routing errors are client-visible.** `SubscriptionException` messages (unregistered field,
  invalid variables) are sent in the `error` message — they describe the request, not server internals.
- **Optional per-connection subscription limit.** `GraphQLTransportWsHandler`'s last constructor argument,
  `maxSubscriptionsPerConnection` (null = unlimited), caps how many subscriptions one socket may hold. Each
  one is re-executed on every matching broadcast event, so an authorised client could otherwise register
  unbounded work. A subscribe beyond the cap gets an `error` message, not a close: the protocol reserves
  closes for violations, and the client may `complete` one and retry. Query size/depth stay with
  `ez-php/graphql`'s executor limits.
- **Connection-init timeout (4408) via the loop's tick.** With `initTimeoutSeconds` set, `onOpen()` records
  the connection and `tick()` — registered with `$server->hooks()->onTick($handler->tick(...))` — closes it
  with 4408 once the timeout passes without `connection_init`. Without the argument (or without registering
  `tick()`), nothing times out; unacknowledged connections still cannot subscribe (4401).
- **Needs ez-php/websocket's subprotocol support.** The handler closes with 4406 unless the handshake
  negotiated `graphql-transport-ws`; the `Server` (or `websocket-tls`'s `TlsServer`) must be created
  with `subprotocols: [Protocol::SUBPROTOCOL]`. `closeWith()`/`subprotocol()` on `ConnectionInterface`
  were added to ez-php/websocket for this module.

---

## Testing Approach

- Redis is replaced by `Support\SubscriptionFakeRedisServer`, a loopback TCP server writing RESP pushes (AUTH,
  whose reply the constructor waits for, uses a one-shot child-process fake); one test also talks to a real
  Redis at `REDIS_HOST`/`REDIS_PORT` and skips when there is none.
- No other infrastructure. `Support\SubscriptionFakeConnection` replaces the socket and records
  every message (JSON-decoded) plus the close code; the handler is driven by feeding `Frame`s to
  `onMessage()`. Execution uses a real `GraphQLExecutor` over `Support\SubscriptionTestSchema`.
- Test classes live in the shared `Tests\` namespace but must be uniquely named
  across the whole monorepo — the root `phpunit.xml` loads every package in one
  process, so a duplicate name is a fatal error, not a test failure. Prefix with
  `GraphQLSubscriptions` (or `Subscription`) when the obvious name is already taken.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Schema building, query execution, depth/complexity limits | `ez-php/graphql` (`SchemaBuilder::subscription()`, `GraphQLExecutor`) |
| WebSocket transport, handshake, subprotocol negotiation | `ez-php/websocket` / `ez-php/websocket-tls` |
| Event publishing, channel authorization | `ez-php/broadcast` |
| A general-purpose Redis client (commands beyond PSUBSCRIBE/AUTH) | ext-redis / `ez-php/cache`'s `RedisDriver` |
| The legacy `subscriptions-transport-ws` (Apollo) protocol | Not supported — deprecated upstream |
| Running the server process (supervisor, signals) | Application / deployment |
