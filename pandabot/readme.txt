=== PandaBot ===
Contributors: pandakidsclinic
Tags: chatbot, rag, hebrew, openai, chat
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-hosted RAG chatbot that answers only from your own site content, keeps
medical guardrails, and books calls instead of giving advice.

== Description ==

PandaBot indexes your pages and posts, embeds them, and answers visitor
questions using only what it retrieved from your own content. If it finds
nothing relevant it says so and offers to put the visitor in touch with you —
it does not improvise.

Built for a Hebrew-language clinic site on shared hosting, so the constraints
are deliberate:

* **PHP and MySQL only.** No Node, no Python, no external vector database, no
  build step. It installs by uploading a zip.
* **Your API key never reaches the browser.** All provider calls happen
  server-side. The public endpoint accepts only a session id and a message —
  the model, the system prompt and the retrieved context are decided entirely
  by the server, so a crafted request cannot override any of them.
* **Provider errors are never shown to visitors.** They are logged
  server-side; the visitor sees a generic message you control.
* **No third-party assets.** The widget loads no CDN scripts, no external
  fonts, no trackers.

= Medical guardrails =

The bot will not diagnose, will not give dosage information, and will not
advise starting, stopping or changing a medication or supplement. A keyword
watchlist short-circuits those questions before the model is ever called, and
a second pass scans the generated answer. When a question gets close to
clinical territory the bot says it cannot give medical advice, notes that the
treating physician remains responsible, and offers to arrange a call.

= Privacy =

Visitor IP addresses are never stored. Only a salted SHA-256 hash is kept, and
only for rate limiting; the salt can be regenerated at any time to sever the
link permanently. No names, emails or accounts are collected. A daily job
deletes conversations, messages and analytics events once they pass your
retention window (30 days by default). A consent line is always visible above
the chat input, and the plugin contributes suggested wording to WordPress's own
Privacy Policy Guide.

Deleting the plugin leaves your data alone unless you explicitly opt in first,
so an accidental delete cannot destroy your chat history or force you to pay to
re-embed your whole site.

= Analytics =

The dashboard reports the opens → first message → contact click funnel, volume
over time, token usage and estimated cost, and — most usefully — **content
gaps**: the real questions where retrieval found nothing. That list is a direct
answer to "what should I write next?".

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin** and activate.
2. Go to **PandaBot → Settings** and fill in the two provider blocks. Chat and
   embeddings are configured independently, because some OpenAI-compatible
   endpoints only offer one of the two. Use **Test connection** on each — the
   embeddings test also fills in the vector dimension for you.
3. Go to **PandaBot → Knowledge** and run a full reindex.
4. Use the chat tester on the same page to check a few real questions. It runs
   the identical pipeline the widget uses and shows every candidate chunk with
   its similarity score, so you can set the similarity floor from real numbers.
5. Set your contact details and accent colour under **Settings**, and the
   widget appears on the front end.

== Frequently Asked Questions ==

= The bot says it has no information about something that is on the site. =

Lower the similarity floor. Embedding similarity for genuinely related but
differently-worded Hebrew text often lands between 0.3 and 0.6, well below what
intuition suggests. The Knowledge page tester shows the actual scores for each
candidate chunk, including ones that did not clear the floor — set the floor
just under the scores you can see are correct matches.

= Answers get cut off mid-sentence. =

Raise **Max tokens per reply**. Hebrew uses more tokens per character than
English in OpenAI-style tokenizers, so a limit that is generous for English can
truncate Hebrew.

= The widget collides with another floating button. =

Set **Distance from bottom** and **Distance from side** under Widget
appearance. Back-to-top buttons, accessibility toolbars and cookie banners all
tend to claim the same corner. On phones, **Extra space under input on mobile**
pushes the send button clear of a floating overlay.

= Widget text is invisible or the wrong colour. =

Some themes style bare buttons with `!important`, which a normally-scoped
selector cannot override. The widget already restates the properties themes
most commonly hijack, but if your theme is unusually aggressive the fix belongs
in the theme's own CSS.

= Changing the embeddings model =

Any change to the embeddings model or its dimension requires a full reindex —
vectors from different models are not comparable.

= Automatic cleanup does not seem to run on time =

Cleanup runs on WP-Cron, which fires on site traffic rather than a system
clock, so a quiet site can lag by hours. You can run it by hand from Settings,
or point a real cron job at `wp-cron.php`.

== Changelog ==

= 1.2.3 =
* On phones the floating button now slides out of the way while you scroll
  down and returns when you stop or scroll up, and carries a small dismiss
  button to hide it for the rest of the session. Desktop is unchanged.
* Corrected the Hebrew label for the rate-limit message setting, which implied
  provider overload rather than the plugin's own request caps.

= 1.2.2 =
* Manual Q&A entries are now cited like any other source, labelled
  "מהשאלות הנפוצות" instead of carrying a link. Previously they were skipped,
  so an answer drawing on both a manual entry and a page credited the page for
  all of it.

= 1.2.1 =
* Fixed the source tooltip closing before you could click through to the page:
  the gap between the chip and the tooltip was inside neither element, so
  moving the pointer across it counted as leaving.

= 1.2.0 =
* Answers now cite their sources: numbered chips under the reply, each opening
  a tooltip with an excerpt of the retrieved text and a link to the page. Shown
  only when the answer was genuinely grounded in retrieval — never on a
  fallback or a guardrail response. Capped at three pages.

= 1.1.0 =
* Contact details are no longer hardcoded in the source. They can be supplied
  as wp-config.php constants, or baked into the zip at build time from a
  gitignored config file. Fields backed by a constant show as locked in the
  admin and are never written to the database. Empty contact channels are now
  omitted from the widget and from the system prompt rather than rendered blank.

= 1.0.0 =
* Hebrew admin translation, readme, and help-text pass.

= 0.9.0 =
* Retention cron with configurable window; manual cleanup, salt regeneration
  and delete-all-logs actions; delete-on-uninstall toggle; suggested privacy
  policy text.

= 0.8.0 =
* Analytics dashboard: conversion funnel, content gaps, answer outcomes, token
  usage and cost, plus a conversation browser with full transcripts. Charts are
  inline SVG with no chart library.

= 0.7.0 =
* Public chat widget with suggested prompts, contact CTAs and event tracking.

= 0.6.0 =
* Public REST endpoint, locked to a session id and a message.

= 0.5.0 =
* Guardrails and rate limiting.

= 0.4.0 =
* Retrieval and chat orchestration.

= 0.3.0 =
* Content indexer and embeddings storage.

= 0.2.0 =
* Provider client with connection testing.

= 0.1.0 =
* Initial scaffold, database schema and admin shell.
