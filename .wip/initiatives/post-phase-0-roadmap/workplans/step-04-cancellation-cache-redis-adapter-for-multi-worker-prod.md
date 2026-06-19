# Workplan — step-04 · Cancellation cache — Redis adapter for multi-worker prod

Swap the `cache.app` pool that backs `CacheTurnCancellation` (D7, `5db671b`)
from the dev-only filesystem adapter to a shared Redis adapter for dev + prod,
so a cancel POST and the streaming turn that land on different PHP workers share
one store. Pure config + compose + image — `CacheTurnCancellation` source is
untouched (the PSR-6 seam already absorbs the swap).

Started: 2026-06-19.

## Decisions (made here, feed later steps)

- **Env-name scoping, not a runtime DSN-emptiness check.** Base `cache.yaml`
  keeps `app: cache.adapter.filesystem` (so `test`/CI and any disk-only env work
  with zero Redis dependency); `when@dev` + `when@prod` override `app` to
  `cache.adapter.redis` with `default_redis_provider: '%env(REDIS_URL)%'`. This
  is the idiomatic Symfony pattern and keeps `make test` Redis-free.
- **`REDIS_URL` is the single DSN env var.** Committed default
  `redis://redis:6379` (resolves to the dev compose `redis` service and at image
  build time); prod overrides it to managed Redis via deploy env.
- **`doctrine.result_cache_pool` rides along.** It derives its adapter from
  `cache.app`, so in dev/prod it now lands on Redis too. Acceptable — we change
  only `cache.app`; we do not add a separate pool migration (honors the hard
  exclusion).
- **Cross-process proof is a shared-backing-store test, not a live Redis test.**
  Two `FilesystemAdapter` instances over one temp dir model "two workers, one
  store": instance A requests cancel, a fresh instance B sees the flag. Same
  PSR-6 contract Redis provides in prod — credential-free, deterministic, CI-safe.

## Chunks

1. **Image: add the `redis` PHP extension.** `docker/php/Dockerfile` base stage
   `install-php-extensions intl pdo_pgsql opcache redis`. Required for
   `cache.adapter.redis` in dev + prod images; `test`/CI host runs never touch it.
2. **Config: env-scoped Redis adapter.** `config/packages/cache.yaml` — base stays
   filesystem (rewrite the stale "promote when prod needs it" comment to state the
   split is done); add `when@dev`/`when@prod` Redis override via `REDIS_URL`.
3. **Compose + env: dev Redis service + DSN passthrough.** Add `REDIS_URL`
   passthrough to `compose.common.yaml` `x-app-env`; add a `redis` service to
   `compose.dev.yaml`; document `REDIS_URL` as a required prod var in
   `compose.prod.yaml`'s header; add `REDIS_URL` (with the cache rationale) to
   `.env`.
4. **Cross-process smoke test.** New credential-free test that proves a fresh
   pool instance (a "second worker") sees a cancel flag written by another
   instance over a shared store, and that `clear()` is likewise visible.

## Test strategy

- `make test` runs in `test` env → base filesystem adapter → no Redis needed; the
  existing `ChatRespondLoopCancellationTest` (uses `ControllableTurnCancellation`,
  not the cache) and `CacheTurnCancellationTest` (ArrayAdapter) are unaffected.
- New `CacheTurnCancellationCrossProcessTest` uses two `FilesystemAdapter`
  instances over one temp dir to model cross-worker visibility through a shared
  store — the invariant Redis guarantees in prod.
- `make lint` covers yamllint (`cache.yaml`, compose), php-cs-fixer/phpstan (new
  test), hadolint (Dockerfile).
- Live Redis path verified by config inspection (dev env resolves the adapter +
  `REDIS_URL`); a full multi-worker container run is the manual/optional check
  per the roadmap's "or via an isolated test that exercises the adapter directly".

## Definition of done

- `cache.app` resolves to Redis in dev + prod (env-scoped), filesystem in test/CI;
  `REDIS_URL` drives the DSN with the committed dev default + documented prod var.
- `redis` PHP ext in the image; `redis` service in dev compose.
- `cache.yaml`'s "promote to Redis when prod needs it" caveat removed/rewritten.
- `CacheTurnCancellation` source file unchanged (proves the abstraction holds).
- `make test` + `make lint` green; `ChatRespondLoopCancellationTest` still passes.
- Cross-process cancel proven by the new shared-store smoke test.

## Open questions to resolve during execution

- **Does `cache:warmup` (APP_ENV=prod, image build line 42) connect to Redis?**
  Lean: no — app-pool redis connections are created lazily on first pool fetch,
  and warmup touches `cache.system` (proxies/metadata), not `cache.app`. The
  committed `REDIS_URL` default keeps the env var resolvable at build either way.
  If a build surfaces an eager-connect, revisit with a lazy DSN flag.
