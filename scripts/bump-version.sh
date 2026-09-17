#!/usr/bin/env bash
#
# Bump the plugin version everywhere it is written down.
#
# Usage:
#   scripts/bump-version.sh patch|minor|major
#
# Kept out of the release zip: .gitattributes export-ignores scripts/.

set -euo pipefail
cd "$(dirname "$0")/.."

part="${1:-}"
case "$part" in
    patch | minor | major) ;;
    *)
        echo "usage: $0 patch|minor|major" >&2
        exit 2
        ;;
esac

plugin='ai-provider-for-command-code.php'
config='src/Util/CommandCodeConfig.php'
selfcheck='scripts/selfcheck.php'

current=$(perl -ne 'print $1 if /^ \* Version:\s+([\d.]+)/' "$plugin")
[ -n "$current" ] || {
    echo "no Version header in $plugin" >&2
    exit 1
}

IFS=. read -r major minor patch <<<"$current"
case "$part" in
    major) major=$((major + 1)); minor=0; patch=0 ;;
    minor) minor=$((minor + 1)); patch=0 ;;
    patch) patch=$((patch + 1)) ;;
esac
next="$major.$minor.$patch"

perl -pi -e "s/^ \* Version:\s+\K[\d.]+/$next/" "$plugin"
perl -pi -e "s/^Stable tag:\s+\K[\d.]+/$next/" readme.txt
perl -pi -e "s/(const VERSION = ')[\d.]+(?=')/\${1}$next/" "$config"
perl -pi -e "s/(ai-provider-for-command-code\/)[\d.]+/\${1}$next/" "$selfcheck"

# Fail loudly if a spot was missed, rather than shipping a half-bumped version.
for file in "$plugin" readme.txt "$config" "$selfcheck"; do
    grep -q "$next" "$file" || {
        echo "$file still not at $next" >&2
        exit 1
    }
done

cat <<EOF
$current -> $next

Next:
  1. Add "= $next =" entries to the Changelog and Upgrade Notice sections of readme.txt.
  2. git commit -am "Release $next"
  3. git tag v$next && git push origin HEAD v$next
EOF
