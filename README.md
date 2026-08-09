# PandaBot

A self-hosted RAG chatbot for WordPress that answers **only** from your own site
content, holds medical guardrails, and offers to book a call instead of giving
advice.

Built for a Hebrew-language pediatric clinic on shared hosting, which drove
every architectural decision here.

## Why it looks like this

The hard constraint was **PHP and MySQL only** — no Node, no Python, no
external vector database, no build step. It has to install by uploading a zip
to Hostinger shared hosting. That rules out the entire standard RAG stack, so:

- **Embeddings are packed `float32` blobs** in a MySQL `longblob`, and
  similarity is brute-force cosine in PHP. At a clinic site's scale (hundreds of
  chunks) this is fast enough, and it needs no extension beyond core PHP.
- **Chunking is paragraph- and sentence-aligned** at roughly 650 tokens with 15%
  overlap, using `chars/3` as the Hebrew token estimate — Hebrew runs
  substantially more tokens per character than English in OpenAI-style
  tokenizers.
- **Charts on the dashboard are hand-rolled inline SVG.** No chart library, no
  CDN, no JavaScript — hover text is a native `<title>` element.

## Security model

- **The API key never reaches the browser.** All provider calls are server-side.
- **The public endpoint accepts only a session id and a message.** The model,
  the system prompt and the retrieved context are decided entirely server-side,
  so a crafted request has nothing to override. Access is gated by a dedicated
  nonce plus an Origin/Referer check.
- **Provider errors never reach visitors.** They're logged server-side; the
  visitor sees a generic message you control.
- **No third-party assets.** The widget loads no CDN scripts, fonts or trackers,
  and reads its config from server-rendered `data-*` attributes rather than an
  inline `<script>`, so it survives a strict CSP.

## Privacy

IP addresses are never stored — only a salted SHA-256 hash, used solely for rate
limiting, with a UI to regenerate the salt and sever the link permanently. No
names, emails or accounts are collected. A daily cron deletes conversations,
messages and analytics events past a configurable retention window (30 days by
default). Deleting the plugin leaves your data intact unless you explicitly opt
in first.

## Medical guardrails

No diagnosis, no dosage, no advice to start, stop or change a medication or
supplement. A keyword watchlist short-circuits those questions before the model
is called at all, and a second pass scans the generated answer. When retrieval
finds nothing relevant, the bot says so and offers contact details rather than
improvising — the failure mode for a clinical site has to be silence, not a
confident guess.

## The most useful feature

The dashboard's **content gaps** table: every real question where retrieval found
nothing above the similarity floor, each linking to its transcript. It's a direct,
evidence-based answer to "what should I write next?"

## Installation

See [`pandabot/readme.txt`](pandabot/readme.txt) for install steps, tuning advice
and an FAQ covering the problems that actually came up during this build.

## Building

```bash
./build.sh
```

That produces `pandabot.zip`, ready to upload via **Plugins → Add New → Upload
Plugin**.

### Keeping per-site values out of the repository

No phone number, address or email is hardcoded in the source — those are per-site
details, not product defaults, and shipping one clinic's contact info to every
install would be wrong. Instead:

```bash
cp pandabot.config.example.json pandabot.config.json
$EDITOR pandabot.config.json   # your real values
./build.sh
```

`pandabot.config.json` is gitignored. `build.sh` reads it and generates
`pandabot/site-config.php` **into a throwaway copy** of the plugin, so the zip
carries your values while your working tree and the repository stay clean. Run
`./build.sh` with no config file and you get a perfectly functional generic zip
— everything is still editable in the WordPress admin.

Those generated constants win over the database at read time, and the settings
screen shows each affected field as locked with the constant's name, so there's
never a box that looks editable but isn't.

### A note on API keys

`build.sh` will bake in an API key if you insist, and warns loudly when you do.
Don't. A key baked into the zip lives in a PHP file inside your web root and
travels with every copy of that zip. Put keys in the WordPress admin (they go to
the database, never to the browser) or in `wp-config.php` — see
[`pandabot/config-sample.php`](pandabot/config-sample.php) for the constants.

**A `.env` file inside the plugin folder is the one option to avoid.** It sits in
the web root, and unless your webserver explicitly blocks dotfiles,
`https://your-site.com/wp-content/plugins/pandabot/.env` hands out your key as
plain text. It also gets deleted by the next plugin update. `wp-config.php` has
neither problem.

## Repository layout

| Path | What it is |
|---|---|
| `pandabot/` | The plugin |
| `build.sh` | Builds the zip, optionally baking in per-site values |
| `pandabot.config.example.json` | Template for your gitignored `pandabot.config.json` |
| `pandakids-chatbot-plugin-plan.md` | Architecture plan — the spec the code was built against |
| `pandabot-widget-mockup.html` | Standalone visual mockup and the source of the widget's Hebrew copy |

## License

GPL v2 or later.
