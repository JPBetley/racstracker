---
name: last-war-api
description: 'ACTIVATE for any code that talks to api.lastwar.tools (the Last War API toolkit) — alliance rosters, VS rankings and seasons, alliance power rankings, session keys, or the request queue. Activate when the user mentions the Last War API, lastwar.tools, session keys, the Capture Tool, X-API-Key, or references app/LastWar/, config/services.php lastwar credentials, or any import that pulls live game data instead of reading screenshots. Covers: authentication and session-key lifecycle, endpoint params and response shapes, the shared-queue vs session-key execution model, error and rate-limit handling, and how API data maps onto this app Member and Score models. Do NOT activate for the screenshot OCR import path (app/Imports/Ocr/, the Laravel AI SDK vision reader) — that is a separate, non-API pipeline.'
---

# Last War API Integration

`api.lastwar.tools` is a third-party REST API exposing live Last War: Survival game data. It returns
the two datasets this app currently reconstructs from screenshots: the alliance roster (with a stable
player `uid`) and per-player VS scores.

Full endpoint params and response schemas: [`references/endpoints.md`](references/endpoints.md).

## Connection basics

- **Base URL**: `https://api.lastwar.tools`
- **Spec**: `https://api.lastwar.tools/openapi.json` (OpenAPI 3.1.0, v1.0.0). The `/docs` page is
  JS-rendered and returns no useful content to a fetch — **always read `/openapi.json` instead.**
- **Auth**: an `X-API-Key` header on every request. A missing key returns `401` with a
  `www-authenticate: ApiKey` header.
- **Credentials**: `config/services.php` under a `lastwar` key, reading `env('LASTWAR_API_KEY')` and
  `env('LASTWAR_SESSION_KEY')`. Add both to `.env.example` **with empty values** — never commit a real
  key or session key.

## The two execution models

This is the most important concept in the whole API, and the easiest thing to get wrong.

| | With `session_key` | Without `session_key` |
| --- | --- | --- |
| Credentials | Your own game account | A shared pool of connections |
| Timing | Processed **immediately** | **Queued** behind other users' requests |
| Latency | Roughly a normal HTTP call | Unbounded — depends on queue depth |

`session_key` is a **query parameter**, not a header. Every `/vs/*` endpoint requires it and has no
queue fallback; `/alliance/*` and `/rankings/*` accept it optionally.

**Architectural consequence: any call that can hit the queue belongs in a queued job, never in a web
request.** Even session-keyed calls proxy through to a live game server, so treat all of them as slow
and failure-prone. Check `GET /queue/stats` (no key needed) for current depth.

For explicit lifecycle control: `POST /queue/submit` → `GET /queue/status/{request_id}` →
`GET /queue/{request_id}`, with SSE at `/queue/stream/{request_id}` and `DELETE /queue/{request_id}`
to cancel. Otherwise just omit `session_key` and let the API queue transparently.

## Session-key lifecycle

Session keys are tied to an API key, and one API key can hold several — typically one per game account
or server. To obtain one:

1. Download the [Capture Tool](https://github.com/LastWarTools/Capture-Tool).
2. Run it while launching the **PC version** of Last War. It captures `handshake.bin`, `auth.bin`, and
   `login.bin`.
3. `POST /auth/credentials/upload` with those files and your API key.
4. `GET /auth/sessions` to read the resulting session key.

`GET /auth/validate` verifies a key **without deducting tokens** — the right call for a health check
or a setup wizard. Do not burn a real data endpoint just to test connectivity.

Session keys can expire when the underlying game credentials go stale. Treat a `403` on a `/vs/*`
endpoint as "re-run the Capture Tool", not as a bug in the calling code.

## Endpoints that matter here

| Endpoint | Session key | Gives us |
| --- | --- | --- |
| `GET /alliance/{alliance_id}/members` | optional | The roster: `uid`, `name`, `rank`, `power`, `hq_level`, `army_kill`, `join_time` |
| `GET /vs/rankings/daily` | **required** | Per-player VS score for one day (1–6) |
| `GET /vs/rankings/season` | **required** | Cumulative per-player season score |
| `GET /vs/season` | **required** | Tier, position, group |
| `GET /vs/matchups`, `/vs/schedule`, `/vs/group` | **required** | Weekly matchups, day schedule, standings |
| `GET /rankings/{server_id}/alliances` | optional | Alliance power rankings — **how you find your own 32-char hex `alliance_id`** |
| `GET /auth/validate`, `/auth/sessions` | — | Key validation, session discovery |

Also available but not used by this app: `/world/*`, `/chat/*`, `/actions/*`, `/detect/players`,
`/mail/system`, `/warzone/*`, `/kingdom/{server_id}/positions`, `/pool/stats`. See the spec.

> **Never call `/actions/*`** — those endpoints mutate the linked game account (claiming stamina,
> dispatching trucks). Nothing in a tracking app should be taking game actions.

## Quirks — read before writing a parser

These are verified against the live API and are the main reason this skill exists.

**Error shape contradicts the spec.** The spec declares `ErrorResponse` as `{error, detail}`, but a
live `401` returns only `{"detail": "Missing API key. Include 'X-API-Key' header."}` — no `error` key
at all. Treat both fields as optional; never assume `error` is present.

**`422` uses a different shape entirely** — FastAPI's validation format,
`{"detail": [{loc, msg, type, input, ctx}]}`, where `detail` is an *array of objects* rather than a
string. Code that reads `$body['detail']` as a string will break on validation errors.

**The spec is not exhaustive.** `POST /queue/submit` is documented in the spec's prose and is live
(an empty body returns `422`), but it is absent from the spec's `paths` object. Probe before assuming
an endpoint doesn't exist.

**Rate limits are claimed but unobservable.** The spec says API keys have hourly and burst limits and
to "check response headers for current usage", yet no `X-RateLimit-*` headers appear on responses.
Handle `429` defensively, but do not build logic that depends on those headers existing.

**`x`/`y` are null for other alliances.** `MemberResponse.point_id` is `0` and coordinates are `null`
unless you are querying your own alliance.

**Most VS fields are nullable.** In `VSPlayerRanking` only `rank` and `uid` are guaranteed — `name`
and `score` can both be null. Match on `uid`, and never assume a name is present.

**⚠ `rank` semantics are unconfirmed.** `MemberResponse.rank` is documented as
"Alliance rank (1=R1 Leader)", but in game R5 is the leader, and this app's `MemberPosition` treats R5
as the top rank (capped at 1 per team). The API's numbering may be inverted relative to ours.
**Verify against a roster with a known leader before writing the mapping** — getting this backwards
silently corrupts every member's position, and nothing will throw.

## How API code should be written in this app

Mirror the existing `RosterScreenshotReader` pattern — contract, single implementation, container
binding, faked in tests.

```
app/LastWar/Contracts/LastWarApi.php   interface with array-shape PHPDoc returns
app/LastWar/HttpLastWarApi.php         Http::-based implementation
```

- **Bind in `AppServiceProvider::register()`**, next to the existing `RosterScreenshotReader` binding.
  The contract is what tests fake — depend on the interface everywhere else.
- **Use the framework's `Http` facade.** Do not add Saloon or any other HTTP package; new dependencies
  need approval per `CLAUDE.md`.
- **Read `config()` at call time**, not in the constructor — see `AiVisionRosterScreenshotReader`.
- **Return typed arrays with array-shape PHPDoc**, not DTO classes. This app has no DTOs for import
  data; `RosterScreenshotReader` returns `array<int, array{name: string, position: string}>`.
- **Let errors bubble.** The `Import` model already records failures through `markFailed()`; don't
  swallow exceptions in the client.
- **Ingest belongs in the existing import pipeline**: a new `ImportType` case with its own
  `ImportWorkflow`, reusing `Import`, `DispatchImportWorkflow`, and the status machinery. Because API
  data is authoritative (unlike OCR guesses), it can skip the `awaiting_review` step.
- **Test with `Http::fake()`** and stored fixtures. Never hit the live API from the test suite — it
  costs tokens, needs real credentials, and depends on a shared queue.

## Schema gaps to raise before ingesting

The existing tables were designed around screenshot data and don't yet fit the API. Flag these rather
than working around them silently:

- **`members` has no column for the API's `uid`.** Identity is currently the name string, which is why
  `RosterNameMatcher` does fuzzy matching. The API provides a stable ID that would make matching exact
  — but that needs a migration.
- **`scores` has no metric-type column.** It stores one `points` integer per member per day, while the
  API offers daily VS score, season VS score, power, and army kills. Don't overload `points` with
  different metrics; propose a migration instead.
