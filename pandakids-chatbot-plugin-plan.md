# Build Plan: PandaBot — a self-hosted RAG chatbot plugin for WordPress

**Hand this file to Claude Code.** It is the complete spec for building a WordPress plugin that adds an
embedded, site-trained chat assistant to `https://pandakids-clinic.co.il/` — a Hebrew-language pediatric
clinic site (PANS/PANDAS, Tourette/tics, natural treatment) running on WordPress on Hostinger shared hosting.

The goal is a plugin roughly at the level of AI Engine (Meow Apps) for this **one specific use case**, but
self-built so there is no annual license. Scope is deliberately narrower than AI Engine: one chatbot, one
site, RAG over the site's own content, with guardrails, rate limiting, Hebrew/RTL, and an analytics dashboard.

---

## 0. Hard constraints (read first — these shape every decision)

1. **Hostinger shared hosting.** PHP + MySQL only. No Node, no Python, no VPS, no Redis, no external vector
   DB. Everything runs inside WordPress. Embeddings are stored in a **custom MySQL table** and similarity is
   computed in PHP. At this site's scale (~10s of pages, low hundreds of chunks) a brute-force cosine scan in
   PHP is fast enough — do NOT pull in Pinecone/Qdrant/Chroma.
2. **No streaming dependency.** LiteSpeed on shared hosting often breaks SSE. Build request/response with a
   typing indicator, not token streaming. (Leave a clean seam to add streaming later, but don't require it.)
3. **The API key never reaches the browser.** All LLM/embedding calls are made server-side from PHP. The key
   lives in `wp-config.php` (constant) or an options row, never enqueued into JS, never returned in any REST
   response.
4. **OpenAI-compatible provider, configurable.** The user will use an OpenAI-compatible endpoint
   (DeepSeek, OpenRouter, etc.). Do NOT hardcode `api.openai.com`. Base URL, chat model, embedding model, and
   API key are all settings. Default base URL empty; user fills it in.
5. **This is a medical site for children.** Guardrails are a functional requirement, not a nicety. See §6.
   Parents in distress will type symptoms. The bot explains site content and routes to a call — it never
   diagnoses, never gives dosages, never comments on medication changes.
6. **Hebrew-first, RTL.** All UI, all default strings, all prompts default to Hebrew. Widget must render RTL.

---

## 1. Site facts (already known — don't re-crawl to discover these)

- WordPress site, Hebrew (`he_IL`), RTL. Practitioner: ליאת אהרון, קלינית הרבליסטית.
- Primary nav / key pages:
  - Home `/`
  - About `/about-us/`
  - Tourette/tics treatment `/tourette-treatment/`
  - PANS/PANDAS treatment `/pandas-treatment/`
  - Partners & resources `/שותפים-ומשאבים/` (URL-encoded Hebrew slug)
  - Articles (blog) `/3-column-blog/`
  - Contact `/contact-us/`
  - Booking CTA `/make-appointments/` — **this is the primary conversion goal.**
- Contact facts the bot should know and surface: phone `054-6657207`, email `info@pandakids-clinic.co.il`,
  location בת-חן 31, מושב חרות; online follow-up sessions available; Facebook page exists.
- The site already states it does **not** replace the treating physician and never advises stopping/changing
  prescribed medication. The bot's guardrails must echo this exact stance.
- Theme note for styling: the site uses a clean clinical look — soft/rounded, generous whitespace, a teal/green
  accent, Hebrew serif-ish headings. The widget should feel native, not bolted on. **Confirm exact colors and
  font-family at build time** by reading the live site's computed styles / stylesheet rather than guessing
  (see §5, "theme matching").

---

## 2. Deliverable & structure

A single installable plugin directory `pandabot/`, zippable, WordPress.org-conventions compliant.

```
pandabot/
  pandabot.php                 # main plugin file: header, constants, activation/deactivation, bootstrap
  uninstall.php                # drops tables + options on uninstall (guard with a "delete data on uninstall" setting)
  includes/
    class-pandabot.php         # core singleton, hooks wiring
    class-activator.php        # dbDelta table creation, default options
    class-settings.php         # admin settings model + sanitization
    class-indexer.php          # content → chunks → embeddings → DB; save_post hook; bulk reindex
    class-chunker.php          # HTML/text → clean chunks (with overlap)
    class-embeddings.php       # provider embedding calls + local cosine search
    class-provider.php         # OpenAI-compatible HTTP client (chat + embeddings), provider-agnostic
    class-retriever.php        # given a query: embed, top-k, assemble context
    class-chat.php             # orchestration: build messages, guardrails, call provider, log
    class-guardrails.php       # pre- and post-checks (see §6)
    class-ratelimit.php        # per-IP + global caps (see §7)
    class-rest.php             # register_rest_route endpoints (public chat + admin)
    class-analytics.php        # aggregation queries for the dashboard (see §8)
    class-logger.php           # write conversations/messages/events to DB
  admin/
    class-admin.php            # menu, pages, asset enqueue
    views/
      dashboard.php            # analytics view
      settings.php             # settings form
      knowledge.php            # index status, reindex button, per-source list
      conversations.php        # browse logged conversations
    css/admin.css
    js/admin.js
  public/
    class-widget.php           # enqueue + render the floating widget markup/root
    css/pandabot.css           # RTL, theme-matched, scoped under #pandabot-root
    js/pandabot.js             # vanilla JS widget (no framework); calls REST; typing indicator
  languages/
    pandabot-he_IL.po/.mo      # Hebrew strings (default), plus pot template
  assets/                      # icons, avatar
  readme.txt
```

No build step, no npm. Plain PHP, vanilla JS, plain CSS. It must install by upload-and-activate on Hostinger.

---

## 3. Database schema (custom tables, `$wpdb->prefix . 'pandabot_*'`)

Create via `dbDelta` in the activator. All tables `utf8mb4` (Hebrew + emoji safe).

**`pandabot_embeddings`** — the local vector store
- `id` BIGINT PK auto
- `source_type` VARCHAR(20)  — 'post' | 'page' | 'manual'
- `source_id` BIGINT NULL     — WP post/page ID (NULL for manual entries)
- `source_url` TEXT
- `title` TEXT
- `chunk_index` INT
- `content` LONGTEXT          — the chunk text (returned as context)
- `embedding` LONGBLOB        — packed float32 vector (store as raw bytes via pack('g*', ...), or JSON if simpler; document choice)
- `token_estimate` INT
- `content_hash` CHAR(40)     — sha1 of chunk, to skip re-embedding unchanged chunks
- `lang` VARCHAR(10) DEFAULT 'he'
- `updated_at` DATETIME
- KEY on (source_type, source_id)

**`pandabot_conversations`**
- `id` BIGINT PK
- `session_id` CHAR(36)       — UUID generated client-side per visitor session (NOT a login)
- `ip_hash` CHAR(64)          — sha256(IP + salt). Never store raw IP. (privacy — see §9)
- `started_at` DATETIME
- `last_at` DATETIME
- `message_count` INT
- `resolved_flag` TINYINT     — heuristic: did convo reach a booking/contact CTA click? (nullable)
- `lang` VARCHAR(10)

**`pandabot_messages`**
- `id` BIGINT PK
- `conversation_id` BIGINT FK
- `role` ENUM('user','assistant','system')
- `content` LONGTEXT
- `tokens_prompt` INT NULL
- `tokens_completion` INT NULL
- `retrieved_ids` TEXT NULL   — comma list of embedding ids used as context (for "which article answered this")
- `guardrail_action` VARCHAR(30) NULL  — e.g. 'none' | 'blocked_medical' | 'refused_offtopic' | 'fallback_no_context'
- `latency_ms` INT NULL
- `created_at` DATETIME

**`pandabot_events`** — lightweight analytics events
- `id` BIGINT PK
- `session_id` CHAR(36)
- `event_type` VARCHAR(40)    — 'open' | 'first_message' | 'cta_click_booking' | 'cta_click_phone' | 'cta_click_whatsapp' | 'suggested_prompt_click' | 'rate_limited' | 'error'
- `meta` TEXT NULL
- `created_at` DATETIME

Add a "delete all data on uninstall" option (default off) that `uninstall.php` honors.

---

## 4. Indexing pipeline (`class-indexer` + `class-chunker` + `class-embeddings`)

**What to index:** published `post` and `page` content. Provide an admin setting to include/exclude post
types and to exclude specific IDs. Also allow a small set of **manual Q&A entries** (source_type 'manual')
the user types in the admin — perfect for the FAQ answers already on the homepage (insurance reimbursement,
who the treatment suits, process steps, contact info) so those answer crisply.

**Chunking (`class-chunker`):**
- Strip HTML to clean text; drop nav/footer/cookie-banner boilerplate. (The homepage fetch shows a cookie
  consent block and repeated nav — filter these; index main content only.)
- Chunk by ~500–800 tokens with ~15% overlap. Keep chunks paragraph-aligned where possible.
- Hebrew note: token estimates for Hebrew are rough — estimate conservatively (chars/3). Never split
  mid-sentence.
- Compute `content_hash` per chunk; skip embedding if a chunk with the same hash already exists (saves cost
  on reindex).

**Embedding (`class-embeddings` via `class-provider`):**
- Call the provider's embeddings endpoint (`/embeddings`, OpenAI-compatible) in batches.
- Handle providers that don't support embeddings gracefully: if the configured provider has no embedding
  model, allow a **separate embeddings provider** config (base URL + key + model) independent from the chat
  provider. (DeepSeek/OpenRouter differ here — some route embeddings, some don't. Make it two independent
  provider configs: "Chat provider" and "Embeddings provider," each with base URL / key / model. This is the
  single most important flexibility point — do not assume one provider does both.)
- Store the returned vector packed into `embedding`. Record the dimension in options; if the user changes
  embedding model (different dim), force a full reindex and warn.

**Triggers:**
- `save_post` (published transition): re-chunk + re-embed just that post (delete its old rows first).
- `wp_trash_post` / delete: remove its rows.
- Admin "Reindex all" button: batched via a paginated AJAX loop or WP-Cron chunks so it doesn't time out on
  shared hosting (process N posts per tick; show progress).
- Store index health: last run time, chunk count, failed items.

**Local similarity search (`class-embeddings::search`):**
- Load candidate vectors (all of them at this scale, or filtered by lang) and compute cosine similarity in
  PHP against the query embedding. Return top-k (k configurable, default 4–6) above a min-similarity floor.
- Optimization if needed later: pre-normalize stored vectors so similarity is a dot product; cache the
  unpacked vectors in a transient. Not required for launch scale.

---

## 5. The widget (`public/` — vanilla JS, no framework)

**Behavior:**
- Floating launcher button bottom-corner (RTL: bottom-left is natural for Hebrew, but make side configurable).
- Panel opens: header with clinic name/avatar, scrollable message list, input, send.
- **Suggested starter prompts** (configurable, default Hebrew), e.g.:
  - "מה זה PANS/PANDAS?"
  - "איך מתנהל תהליך הטיפול?"
  - "האם יש החזר מקופת החולים / ביטוח?"
  - "איך קובעים שיחת היכרות?"
- Typing indicator while awaiting response (no streaming).
- Every assistant answer that involves booking/contact renders **action buttons**: "לתיאום שיחה" → `/make-appointments/`,
  "התקשרו" → `tel:0546657207`, optional WhatsApp. Clicking fires an analytics event (§8) — this is how you
  measure conversions.
- Persist the session's messages in memory + `sessionStorage` (NOT localStorage long-term; privacy). Generate
  a `session_id` UUID once per session.
- Graceful states: loading, error ("שגיאה זמנית, נסו שוב או התקשרו…"), and rate-limited message.

**Theme matching (do this properly):**
- Scope ALL widget CSS under `#pandabot-root` so it can't leak into or inherit unpredictably from the theme.
- At build time, read the live site's actual palette and fonts (fetch the site / inspect the enqueued theme
  stylesheet) and set widget CSS variables to match — accent color, font-family, border-radius, shadow feel.
  Expose these as settings (accent color picker, radius, position, launcher icon) so the user can fine-tune
  without editing CSS.
- `direction: rtl` on the root; text-align start; mirror the launcher and bubble tails. Test with the actual
  Hebrew strings, not lorem ipsum — Hebrew + punctuation + Latin acronyms (PANS/PANDAS/OCD) mixed in one line
  is where RTL bugs show up.
- Respect `prefers-reduced-motion`. Keep it light — no heavy libraries, keep pages fast.

---

## 6. Guardrails (`class-guardrails`) — functional requirement

Layered: system prompt + pre-checks + post-checks. Log every guardrail action to `pandabot_messages.guardrail_action`.

**System prompt (Hebrew, stored as an editable setting with a sane default).** Must instruct the model to:
- Answer ONLY from the provided site context. If the context doesn't contain the answer, say so plainly and
  direct to a call/contact — do not improvise medical content.
- Never diagnose, never assess whether a specific child "has" a condition, never give dosages, never advise
  starting/stopping/changing any medication or supplement, never interpret lab results.
- State, when clinical territory is approached, that it's an assistant for information about the clinic and
  cannot give medical advice; the treating physician remains responsible; encourage booking an intro call.
- Stay on-topic (the clinic, its approach, PANS/PANDAS, tics/Tourette, process, contact/booking). Politely
  decline unrelated requests.
- Answer in the user's language, defaulting to Hebrew; keep answers short and warm.

**Pre-checks (before calling the model), cheap and deterministic:**
- Input length cap (e.g. 1000 chars) → reject politely.
- Optional keyword watchlist for high-risk phrasings (dosage/self-harm/emergency). On match, short-circuit to
  a safe canned response (e.g. emergency → advise contacting emergency services / treating physician; don't
  send to the LLM). Keep this list editable and conservative — it's a safety net, not the main mechanism.

**Post-checks (after model returns, before showing):**
- If the retriever returned no context above the similarity floor, force the "I don't have that on the site,
  here's how to reach the clinic" fallback rather than a model guess. Log as `fallback_no_context`.
- Basic scan to catch the model slipping into dosage/diagnosis despite instructions; if detected, replace with
  the safe redirect and log `blocked_medical`. (Heuristic/regex is fine; don't over-engineer.)

**Grounding technique:** pass retrieved chunks as clearly delimited context and instruct: use only this; cite
nothing you weren't given; if unsure, say so. Include the clinic contact facts in every system prompt so
routing answers are always correct.

---

## 7. Rate limiting (`class-ratelimit`)

Because a public endpoint calling a paid API is the real abuse surface. Enforce **before** any provider call.

- **Per-IP (hashed):** e.g. max 8 messages / minute and 40 / hour. Use WP transients keyed by ip_hash as
  counters with TTL. On breach → return a friendly rate-limit message, log `rate_limited` event, do NOT call
  the provider.
- **Global daily cap:** a hard ceiling on total messages/day across the whole site (default e.g. 500),
  configurable. Protects against a distributed hammering running up the API bill. On breach, widget shows a
  "busy, please call" message.
- **Per-session soft cap:** e.g. 30 messages/session.
- **Token ceiling per reply:** cap `max_tokens` on the completion (e.g. 400) and cap assembled context size.
- All limits are settings with the above as defaults. Surface current usage on the dashboard.
- Also: nonce on the REST endpoint for same-origin calls, and an origin check. It's a public endpoint so nonce
  isn't airtight, but combined with rate limits it's enough at this scale.

---

## 8. Analytics dashboard (`class-analytics` + `admin/views/dashboard.php`)

The point (as discussed): the bot is a lead-capture tool that happens to answer questions, and the transcripts
tell you which article to write next. Dashboard should answer: is it converting, what are people asking, and
is it costing/misbehaving?

**Cards / charts (date-range filterable, default last 30 days):**
- Conversations, unique sessions, total messages, avg messages/conversation.
- **Conversion funnel:** opens → first message → CTA click (booking/phone/whatsapp). This is the headline metric.
- Booking-CTA clicks over time (from `pandabot_events`).
- **Top questions / themes:** list recent user messages; simple frequency of terms; optionally cluster later.
  This is the "which article to write next" signal — make it easy to skim.
- Fallback rate: % of answers that hit `fallback_no_context` (content gaps — pages the bot couldn't answer
  from). List the questions that triggered fallbacks. **This is gold for the clinic** — it's literally the
  list of things parents want to know that the site doesn't yet cover.
- Guardrail actions breakdown (how often medical/off-topic blocks fire).
- Rate-limit hits and error count.
- Estimated API cost: sum tokens × configurable per-token price (chat + embeddings), shown as a running
  monthly figure. Include a spend-cap warning banner if near the global cap.
- Token usage over time.

**Conversations browser (`conversations.php`):** paginated, click a conversation to read the full transcript
(user + assistant + which chunks were retrieved + guardrail action). No raw IPs shown.

Keep all aggregation as plain `$wpdb` queries; render charts with a tiny dependency-free approach (inline SVG
or a single small chart lib loaded locally — no external CDN calls from admin).

---

## 9. Privacy & compliance (Israel; sensitive health data about minors)

- **Never store raw IPs.** Hash with a per-site salt (generated at activation, stored in options).
- Store only what analytics needs. Provide a **retention setting**: auto-delete conversations/messages older
  than N days (default 30) via a daily WP-Cron job.
- Provide a visible consent line in the widget's first-open state (short, Hebrew) noting it's an automated
  assistant, that chats may be stored to improve service, and a link to the privacy policy. Fire the `open`
  event only after that.
- Add a settings note reminding the owner to (a) mention the chatbot in the site privacy policy and cookie
  banner, and (b) not paste child health data anywhere it shouldn't go. The plugin should make the
  privacy-preserving choice the default everywhere.
- No third-party trackers, no external fonts/CDNs loaded by the widget. All assets local.

---

## 10. Settings screen (`admin/views/settings.php`) — full list

- **Chat provider:** base URL, API key, model name. (OpenAI-compatible.)
- **Embeddings provider:** base URL, API key, model name, dimension. (Independent from chat — see §4.)
- Reindex controls + index health readout (chunk count, last run, failures).
- Post types to index; excluded IDs; manual Q&A entries editor.
- System prompt (Hebrew default provided); suggested prompts; fallback message; rate-limit message;
  consent text.
- Guardrail keyword watchlist; similarity floor; top-k.
- Rate limits (per-IP/min, per-IP/hour, per-session, global/day); max_tokens; max context size; input cap.
- Appearance: accent color, radius, position, launcher icon, avatar, header title, greeting.
- Privacy: retention days, hash salt (regenerate), "delete data on uninstall" toggle.
- Cost estimation: per-1K-token price for chat in/out and embeddings (for the dashboard cost figure).

Sanitize/validate everything. Never echo the API key back into the DOM (mask it).

---

## 11. Provider client (`class-provider`) — the OpenAI-compatible layer

- One method for chat completions (`POST {base_url}/chat/completions`), one for embeddings
  (`POST {base_url}/embeddings`). Use `wp_remote_post` with a sane timeout (e.g. 30s) and retry-once on
  transient failure.
- Send `Authorization: Bearer {key}`. Body is standard OpenAI shape: `model`, `messages`, `max_tokens`,
  `temperature` (low, ~0.2–0.4 for grounded answers).
- Parse usage tokens from the response when present (for analytics); tolerate providers that omit them.
- Never leak provider error bodies to the public endpoint — log server-side, return a generic message to the
  widget. (AI Engine had a CVE-class bug here; don't repeat it: public endpoint must not trust or reflect
  client-supplied model/instructions/context, and must not expose internal errors.)
- **Security musts** (learn from AI Engine's fixes): the public chat endpoint must NOT accept client-supplied
  model, system instructions, or arbitrary context IDs. The server decides the model, the prompt, and does its
  own retrieval. The client sends only: session_id and the user's message. Anything else is ignored.

---

## 12. Build order (suggested milestones for Claude Code)

1. **Scaffold + activation:** plugin header, constants, activator with all tables via dbDelta, uninstall,
   settings model with defaults, admin menu shell. Verify clean activate/deactivate/uninstall on a test WP.
2. **Provider client:** chat + embeddings against the configured OpenAI-compatible endpoint; a "Test
   connection" button in settings for each provider.
3. **Indexer:** chunker → embeddings → DB; save_post hook; batched "reindex all" with progress; index-health
   readout. Confirm chunks land for the real pages in §1.
4. **Retriever + chat orchestration:** embed query → local cosine top-k → assemble grounded prompt → provider
   → log. No widget yet; test via a REST call / WP-CLI-ish admin tester.
5. **Guardrails + rate limiting:** wire pre/post checks and all caps; verify medical/off-topic/fallback paths
   and that limits short-circuit before the provider call.
6. **Public REST endpoint:** locked-down per §11; nonce + origin + rate limit; returns answer + any CTAs.
7. **Widget:** vanilla JS/CSS, RTL, theme-matched, suggested prompts, typing indicator, CTA buttons + events,
   consent first-open. Test on the live theme with real Hebrew strings.
8. **Analytics dashboard + conversations browser.**
9. **Privacy pass:** IP hashing, retention cron, consent text, no external assets. Final security review of the
   public endpoint.
10. **Polish:** Hebrew `.po/.mo`, readme.txt, settings help text, zip.

---

## 13. Acceptance checklist (definition of done)

- [ ] Installs by zip upload on Hostinger shared hosting; activates with no errors; no Node/VPS needed.
- [ ] Chat + embeddings work against a DeepSeek/OpenRouter-style OpenAI-compatible endpoint; providers are
      independently configurable; API key never appears in any browser payload.
- [ ] Reindex completes on the real site content without PHP timeouts; save_post keeps the index fresh.
- [ ] Answers are grounded in site content; off-context questions hit the fallback; no dosage/diagnosis output;
      guardrail actions are logged.
- [ ] Rate limits and a global daily cap demonstrably block before any paid call; rate-limit UX is friendly.
- [ ] Widget matches the site's look, renders correctly RTL with real Hebrew + Latin acronyms, offers booking/
      phone CTAs that log conversion events.
- [ ] Dashboard shows the open→message→CTA funnel, top questions, fallback-gap list, guardrail/rate/error
      counts, and an estimated API cost.
- [ ] No raw IPs stored; retention auto-deletes old data; consent shown; no external CDNs/fonts/trackers.

---

## 14. Notes to the human (you), not Claude Code

- You already have the `pandakids-clinic.co.il` MCP indexed — that's a separate thing from this plugin. It's
  handy for *you* to spot-check what content exists, but the plugin does its own indexing from the WordPress DB.
- Pick your embeddings provider deliberately: some OpenAI-compatible chat providers (e.g. certain DeepSeek/
  OpenRouter routes) don't serve embeddings. That's exactly why the plan makes chat and embeddings two separate
  provider configs. A cheap, reliable embeddings model + a cheap chat model is the sweet spot here.
- Set the global daily cap and a hard spend limit in your provider's billing console before going live. The
  realistic failure mode is someone looping your endpoint, not organic traffic.
- Read the fallback-gap list monthly — it's your content roadmap.
