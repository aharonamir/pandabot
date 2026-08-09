<?php
/**
 * PandaBot — optional wp-config.php constants.
 *
 * THIS FILE IS NOT LOADED BY THE PLUGIN. It is documentation. Copy the block
 * below into your site's wp-config.php, above the line that reads
 * "That's all, stop editing! Happy publishing."
 *
 * Anything you define here wins over the value stored in the database, and the
 * settings screen shows the field as locked rather than editable. Leave a
 * constant out (or set it to an empty string) and the database value is used
 * as normal — so you can define only the ones you care about.
 *
 * WHY wp-config.php AND NOT A .env FILE
 *
 * A .env inside wp-content/plugins/pandabot/ is a bad place for a secret:
 *
 *   1. It is inside the web root. Unless your webserver explicitly blocks
 *      dotfiles, https://your-site.com/wp-content/plugins/pandabot/.env
 *      returns your API key as plain text to anyone who asks. Apache and
 *      LiteSpeed do not all block dotfiles by default.
 *   2. Updating the plugin replaces the whole folder, taking the file with it.
 *   3. Reading it needs a parser that runs on every request, for no benefit.
 *
 * wp-config.php has none of those problems: it is outside the plugin folder,
 * it is never served as text, and WordPress has already loaded it before any
 * plugin runs.
 *
 * WHAT THIS IS AND ISN'T FOR
 *
 * Use it to keep real credentials out of source control and out of database
 * exports — a DB backup handed to a developer no longer carries your API key.
 * It does not encrypt anything: someone who can read wp-config.php can read
 * the key, but that person already has your database credentials too.
 *
 * @package PandaBot
 */

exit; // Never executed as part of the plugin; guards against direct access.

/*
--------------------------------------------------------------------------
Copy from here into wp-config.php
--------------------------------------------------------------------------

// --- PandaBot: chat provider ---
define( 'PANDABOT_CHAT_BASE_URL', 'https://api.openai.com/v1' );
define( 'PANDABOT_CHAT_API_KEY',  'sk-your-chat-key-here' );
define( 'PANDABOT_CHAT_MODEL',    'gpt-4o-mini' );

// --- PandaBot: embeddings provider (configured separately on purpose —
//     some OpenAI-compatible endpoints only offer one of the two) ---
define( 'PANDABOT_EMBEDDINGS_BASE_URL', 'https://api.openai.com/v1' );
define( 'PANDABOT_EMBEDDINGS_API_KEY',  'sk-your-embeddings-key-here' );
define( 'PANDABOT_EMBEDDINGS_MODEL',    'text-embedding-3-small' );

// --- PandaBot: contact details ---
// Not secret, but per-site. Define them here to keep one clinic's real phone
// number out of a shared or public copy of the plugin source.
define( 'PANDABOT_CONTACT_BOOKING_URL', '/make-appointments/' );
define( 'PANDABOT_CONTACT_PHONE',       '054-0000000' );
define( 'PANDABOT_CONTACT_WHATSAPP',    '972540000000' );  // digits only, no + or dashes
define( 'PANDABOT_CONTACT_EMAIL',       'info@example.com' );
define( 'PANDABOT_CONTACT_ADDRESS',     'Street 1, Town' );
define( 'PANDABOT_CONTACT_PRIVACY_URL', '/privacy/' );

--------------------------------------------------------------------------
Stop copying here
--------------------------------------------------------------------------

NOTE ON CHANGING THE EMBEDDINGS MODEL

Vectors from different embedding models are not comparable. If you change
PANDABOT_EMBEDDINGS_MODEL you must run a full reindex from PandaBot →
Knowledge, or retrieval will silently return nonsense.
*/
