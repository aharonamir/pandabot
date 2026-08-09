#!/usr/bin/env bash
#
# Builds pandabot.zip, optionally baking per-site values into the plugin.
#
#   ./build.sh                          # generic zip, no site values
#   ./build.sh pandabot.config.json     # zip with your values baked in
#   ./build.sh --bump 1.3.0             # set the version everywhere, then build
#
# With no argument it uses pandabot.config.json if that file exists, so the
# usual case is just ./build.sh. Values come from a gitignored config file so
# the repository itself never carries one clinic's phone number.
#
# The version lives in three places that must agree: the plugin header (the
# only one WordPress parses), the PANDABOT_VERSION constant (which cache-busts
# the CSS/JS URLs), and readme.txt's Stable tag. If they drift, the Plugins
# list shows the new version while browsers keep serving assets cached under
# the old one — an update that looks applied but visibly isn't. Every build
# checks them, and --bump writes all three at once.
#
# Baking config never touches the plugin source: that runs against a throwaway
# copy in a temp directory, so a wrong config can only produce a bad zip, never
# a dirty working tree. --bump is the one exception — it edits the tracked
# files on purpose, so review it with git diff like any other change.

set -euo pipefail

cd "$(dirname "$0")"

PLUGIN_DIR="pandabot"
OUT_ZIP="pandabot.zip"
BUMP=""
CONFIG=""

while [ $# -gt 0 ]; do
	case "$1" in
		--bump)   BUMP="${2:-}"; shift 2 ;;
		--bump=*) BUMP="${1#*=}"; shift ;;
		-h|--help)
			sed -n '2,25p' "$0" | sed 's/^# \{0,1\}//'
			exit 0
			;;
		-*) echo "unknown option: $1" >&2; exit 1 ;;
		*)  CONFIG="$1"; shift ;;
	esac
done

CONFIG="${CONFIG:-pandabot.config.json}"

command -v python3 >/dev/null || { echo "build.sh needs python3" >&2; exit 1; }
command -v zip     >/dev/null || { echo "build.sh needs zip" >&2; exit 1; }
[ -d "$PLUGIN_DIR" ] || { echo "no $PLUGIN_DIR/ directory here" >&2; exit 1; }

if [ -n "$BUMP" ]; then
	python3 - "$BUMP" "$PLUGIN_DIR/pandabot.php" "$PLUGIN_DIR/readme.txt" <<'PY'
import re, sys

version, plugin_file, readme_file = sys.argv[1], sys.argv[2], sys.argv[3]

if not re.fullmatch(r'\d+\.\d+\.\d+', version):
    sys.exit(f"--bump needs a version like 1.3.0, got '{version}'")

php = open(plugin_file, encoding='utf-8').read()
# Keep the header's column alignment: replace only the value.
php, n_header = re.subn(r'(^ \* Version: *)\S+$', r'\g<1>' + version, php, count=1, flags=re.M)
php, n_const = re.subn(r"(define\( 'PANDABOT_VERSION', ')[^']+(' \);)",
                       r'\g<1>' + version + r'\g<2>', php, count=1)
if n_header != 1 or n_const != 1:
    sys.exit(f"could not rewrite version in {plugin_file} "
             f"(header:{n_header} constant:{n_const}) — nothing changed")
open(plugin_file, 'w', encoding='utf-8').write(php)

readme = open(readme_file, encoding='utf-8').read()
readme, n_tag = re.subn(r'(^Stable tag: *)\S+$', r'\g<1>' + version, readme, count=1, flags=re.M)
if n_tag != 1:
    sys.exit(f"could not rewrite Stable tag in {readme_file} — plugin file already changed")
open(readme_file, 'w', encoding='utf-8').write(readme)

print(f"Bumped to {version} (header, PANDABOT_VERSION, Stable tag)")
PY
fi

# Run on every build, not just after --bump: the usual way these drift is a
# hand-edit that touched one of them.
python3 - "$PLUGIN_DIR/pandabot.php" "$PLUGIN_DIR/readme.txt" <<'PY'
import re, sys

plugin_file, readme_file = sys.argv[1], sys.argv[2]
php = open(plugin_file, encoding='utf-8').read()
readme = open(readme_file, encoding='utf-8').read()

def grab(pattern, text, label):
    m = re.search(pattern, text, re.M)
    if not m:
        sys.exit(f"could not find the {label}")
    return m.group(1)

found = {
    'plugin header':    grab(r'^ \* Version: *(\S+)$', php, 'plugin header version'),
    'PANDABOT_VERSION': grab(r"define\( 'PANDABOT_VERSION', '([^']+)' \);", php, 'PANDABOT_VERSION constant'),
    'readme Stable tag': grab(r'^Stable tag: *(\S+)$', readme, 'readme Stable tag'),
}

if len(set(found.values())) != 1:
    print("Version mismatch — refusing to build:", file=sys.stderr)
    for where, value in found.items():
        print(f"  {where:<18} {value}", file=sys.stderr)
    print("\nFix them by hand, or run: ./build.sh --bump <version>", file=sys.stderr)
    sys.exit(1)
PY

BUILD_ROOT="$(mktemp -d)"
trap 'rm -rf "$BUILD_ROOT"' EXIT

cp -R "$PLUGIN_DIR" "$BUILD_ROOT/$PLUGIN_DIR"
# Never ship a stale site-config.php that happened to be lying around.
rm -f "$BUILD_ROOT/$PLUGIN_DIR/site-config.php"

if [ -f "$CONFIG" ]; then
	echo "Using config: $CONFIG"
	python3 - "$CONFIG" "$BUILD_ROOT/$PLUGIN_DIR/site-config.php" <<'PY'
import json, sys, datetime

config_path, out_path = sys.argv[1], sys.argv[2]

with open(config_path, encoding='utf-8') as fh:
    cfg = json.load(fh)

# config key -> PHP constant. Mirrors Pandabot_Settings::constant_map().
MAPPING = {
    ('contact', 'booking_url'):          'PANDABOT_CONTACT_BOOKING_URL',
    ('contact', 'phone'):                'PANDABOT_CONTACT_PHONE',
    ('contact', 'whatsapp'):             'PANDABOT_CONTACT_WHATSAPP',
    ('contact', 'email'):                'PANDABOT_CONTACT_EMAIL',
    ('contact', 'address'):              'PANDABOT_CONTACT_ADDRESS',
    ('contact', 'privacy_url'):          'PANDABOT_CONTACT_PRIVACY_URL',
    ('providers', 'chat_base_url'):       'PANDABOT_CHAT_BASE_URL',
    ('providers', 'chat_model'):          'PANDABOT_CHAT_MODEL',
    ('providers', 'chat_api_key'):        'PANDABOT_CHAT_API_KEY',
    ('providers', 'embeddings_base_url'): 'PANDABOT_EMBEDDINGS_BASE_URL',
    ('providers', 'embeddings_model'):    'PANDABOT_EMBEDDINGS_MODEL',
    ('providers', 'embeddings_api_key'):  'PANDABOT_EMBEDDINGS_API_KEY',
}

SECRET = {'PANDABOT_CHAT_API_KEY', 'PANDABOT_EMBEDDINGS_API_KEY'}

lines, baked, secrets = [], [], []
for (section, key), constant in MAPPING.items():
    value = cfg.get(section, {}).get(key, '')
    if not isinstance(value, str) or value.strip() == '':
        continue
    value = value.strip()
    if constant in SECRET:
        secrets.append(constant)
    # single-quoted PHP: only \ and ' are special
    escaped = value.replace('\\', '\\\\').replace("'", "\\'")
    lines.append(f"define( '{constant}', '{escaped}' );")
    baked.append(constant)

if not lines:
    print("  no values set — building a generic zip")
    sys.exit(0)

header = f"""<?php
/**
 * Generated by build.sh on {datetime.datetime.now().strftime('%Y-%m-%d %H:%M')} — do not edit by hand.
 * Regenerate by editing your config file and re-running ./build.sh.
 *
 * These constants override the matching settings at read time. The settings
 * screen shows each of them as locked, and they are never written into the
 * database.
 */

if ( ! defined( 'ABSPATH' ) ) {{
\texit;
}}

"""

with open(out_path, 'w', encoding='utf-8') as fh:
    fh.write(header + '\n'.join(lines) + '\n')

print(f"  baked in {len(baked)} value(s): {', '.join(sorted(baked))}")

if secrets:
    print()
    print("  !! WARNING: an API key is baked into this zip:")
    for s in secrets:
        print(f"       {s}")
    print("     The key is now in a PHP file inside the zip and inside your")
    print("     web root. Do not share this zip. Prefer wp-config.php")
    print("     constants or the admin screen instead.")
    print()
PY
else
	echo "No config file at $CONFIG — building a generic zip."
	echo "(Copy pandabot.config.example.json to pandabot.config.json to bake in your values.)"
fi

rm -f "$OUT_ZIP"
( cd "$BUILD_ROOT" && zip -rq "$OLDPWD/$OUT_ZIP" "$PLUGIN_DIR" -x "*.DS_Store" )

VERSION="$(grep -oP "define\( 'PANDABOT_VERSION', '\K[^']+" "$PLUGIN_DIR/pandabot.php")"
echo "Built $OUT_ZIP (v$VERSION, $(unzip -l "$OUT_ZIP" | tail -1 | awk '{print $2}') files)"
