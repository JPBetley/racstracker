# Last War API — Endpoint Reference

Derived from `https://api.lastwar.tools/openapi.json` (OpenAPI 3.1.0, "Last War API" v1.0.0).
Every endpoint below requires the `X-API-Key` header. Re-fetch the spec if anything here looks stale.

---

## Alliance

### `GET /alliance/{alliance_id}/members`

Members of an alliance. Works without a session key (falls back to the shared queue).

| Param | In | Required | Notes |
| --- | --- | --- | --- |
| `alliance_id` | path | yes | 32-character hex string |
| `sort_by` | query | no | `power` \| `name` \| `rank` — default `power` |
| `descending` | query | no | bool, default `true` |
| `session_key` | query | no | bypasses the queue |

**200 — `AllianceMembersResponse`**

```
alliance_id             string
member_count            int
members_with_positions  int
total_power             int
total_power_formatted   string    e.g. "1.2B"
members                 MemberResponse[]
```

**`MemberResponse`** (all fields required, though `x`/`y` are nullable)

```
uid                string   Player unique ID — the stable identity
name               string   Display name
hq_level           int      HQ level (1-30+)
power              int
power_formatted    string   Power with M/B suffix
server_id          int      Home server
current_server_id  int      Current server
point_id           int      Encoded position (y*1000+x); 0 when not visible
x, y               int|null Coordinates; null when not visible
rank               int      Alliance rank — see the rank warning in SKILL.md
online             bool
join_time          int      Unix timestamp when they joined the alliance
army_kill          int      Troops killed
career_type        int
career_level       int
offline_time       int      Last offline timestamp
```

> `x`/`y` are `null` and `point_id` is `0` for any alliance other than your own.

Errors: `401`, `404`, `422`.

---

## VS (Alliance Duel)

Every VS endpoint **requires** `session_key` as a query param. Without one they fail — there is no
queue fallback. All of them share the `success` / `response_size` / `message` envelope.

### `GET /vs/rankings/daily`

| Param | Required | Notes |
| --- | --- | --- |
| `session_key` | yes | |
| `day` | no | int 1–6, default `1` — day of the VS week |

**200 — `VSRankingsResponse`**

```
success        bool
rankings       VSPlayerRanking[]
player_count   int       number of ranked players, default 0
day            int|null  1-6 for daily rankings
rank_type      string|null  'daily' or 'season'
response_size  int
message        string
```

**`VSPlayerRanking`** (only `rank` and `uid` are guaranteed — everything else is nullable)

```
rank            int
uid             string
name            string|null
score           int|null
alliance_name   string|null
alliance_abbr   string|null
server_id       int|null
alliance_id     string|null
```

Errors: `400`, `401`, `403`, `422`.

### `GET /vs/rankings/season`

Cumulative player scores for the whole season. Params: `session_key` only.
Same `VSRankingsResponse` shape; `rank_type` is `'season'` and `day` is null.

### `GET /vs/season`

Current season info. Params: `session_key` only.

```
success   bool
current   VSDuelSeason|null
previous  VSDuelSeason|null
```

**`VSDuelSeason`** — every field nullable:

```
rank_type     int|null     Tier: 1=Gold, 2=Silver, 3=Bronze
position      int|null     Position within group
group         string|null  Group identifier
round_result  string|null  Round results string
```

### `GET /vs/schedule`

Params: `session_key` only.

```
success     bool
schedule    VSDaySchedule[]
start_time  int|null   Week start timestamp (milliseconds)
```

**`VSDaySchedule`**: `day` (int), `event_id`, `description_id`, `score_multiplier`, `is_win`,
`mvp` (a `VSMVPInfo`: `uid`, `name`, `score`, `alliance_name`, `alliance_abbr`, `server_id`) — all
nullable except `day`.

### `GET /vs/group`

Params: `session_key` only. Returns `standings` — `VSGroupEntry[]`:

```
position      int
alliance_id   string|null
name          string|null
abbr          string|null
server_id     int|null
rank_type     int|null
round_result  string|null
group         string|null
```

### `GET /vs/matchups`

| Param | Required | Notes |
| --- | --- | --- |
| `session_key` | yes | |
| `week` | no | int ≥ 1, default `1` |

Returns `matchups` — `VSMatchup[]`, each `{alliance_1, alliance_2, decided}` where both alliances are
`VSAllianceMatchup`: `alliance_id`, `name`, `abbr`, `server_id`, `wins`, `win_score`, `total_score`
(all nullable).

---

## Rankings

### `GET /rankings/{server_id}/alliances`

Alliance power rankings for a server, sorted by total power descending. No session key required.

| Param | In | Required | Notes |
| --- | --- | --- | --- |
| `server_id` | path | yes | int |
| `limit` | query | no | 1–200, default `50` |
| `session_key` | query | no | |

**200 — `AllianceRankingResponse[]`** (a bare array, not an envelope)

```
rank                 int     Position in rankings
id                   string  Alliance 32-char hex ID
abbr                 string  Alliance tag
name                 string
power                int
power_formatted      string
server_id            int
member_count         int
max_member_count     int
leader               string
leader_uid           string
country              string
icon                 string
army_kill            int
army_kill_formatted  string
```

This is the endpoint to use to discover your own `alliance_id` for `/alliance/{id}/members`.

---

## Auth

### `GET /auth/validate`

Validates an API key **without deducting tokens**. Returns basic user info. Use for health checks.
The spec declares no response schema.

### `GET /auth/sessions`

Lists active game sessions for the authenticated key, with their session keys. One API key may hold
several sessions (different game accounts or servers). The spec declares no response schema — inspect
the live response before depending on field names.

### `POST /auth/credentials/upload`

Uploads the three files produced by the Capture Tool (`handshake.bin`, `auth.bin`, `login.bin`) as
multipart form data. Run this once per game account, then read the resulting key from
`GET /auth/sessions`.

### `POST /auth/email/request-code` / `POST /auth/email/verify`

Email-based auth flow. Not required for the API-key + session-key path this app uses.

---

## Queue

Used implicitly by any request that omits `session_key`. These endpoints exist if you want explicit
control over the request lifecycle.

- **`POST /queue/submit`** — submit a request to the queue. **Not present in the spec's `paths`**, but
  it is documented in the spec's prose and is live (an empty body returns `422`). Inspect it directly
  before use.
- **`GET /queue/status/{request_id}`** — poll. Returns `QueueStatusResponse`:
  `request_id`, `endpoint`, `status`, `position`, `total_queue_size`, `result` (object|null),
  `error` (string|null), `token_cost`, `queued_at` (float), `completed_at` (float|null).
- **`GET /queue/stream/{request_id}`** — server-sent events for real-time updates.
- **`GET /queue/{request_id}`** — fetch the completed result.
- **`DELETE /queue/{request_id}`** — cancel a queued request.
- **`GET /queue/stats`** — queue depth and pool health. Callable without a key. Shape:
  `{queue_length, total_tracked, subscribers, pool: {total_connections, ready_connections, connections: {...}}}`.
- **`GET /pool/stats`** — `PoolStatsResponse`: `total_connections`, `ready_connections`,
  `connections` (map of name → `ConnectionStatsResponse`).

---

## Not used by this app

Documented here only so you know they exist. Fetch `/openapi.json` for their full shapes.

| Endpoint | Purpose |
| --- | --- |
| `GET /kingdom/{server_id}/positions` | Kingdom title/position holders |
| `GET /detect/players` | Radar — recently detected players |
| `GET /world/block`, `/world/scan`, `/world/find-player` | World map blocks, region scans, player lookup |
| `POST /chat/share-coordinates`, `/chat/share-coordinates-auto` | Post coordinates to in-game chat |
| `POST /actions/claim-stamina`, `/actions/collect-visitors`, `/actions/collect-idle-rewards`, `/actions/dispatch-trade-truck` | Automated account actions |
| `GET /mail/system` | System mail |
| `GET /warzone/current`, `/warzone/all-rounds` | Warzone rounds |

> The `/actions/*` endpoints mutate your game account. Do not call them from this app.
