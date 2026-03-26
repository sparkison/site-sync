#!/bin/bash
# generate-release-log.sh
#
# Generates release notes by finding version-bump commits on master via
# config/nativephp.php history (--first-parent). Does NOT rely on git tags.
#
# Usage:
#   ./tools/generate-release-log.sh              # auto-detect: unreleased changes since last version bump
#   ./tools/generate-release-log.sh v1.0.11      # reproduce: show what was IN this release
#   ./tools/generate-release-log.sh v1.0.10 v1.0.11  # explicit: range between two versions

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$(cd "$SCRIPT_DIR/.." && pwd)"

REMOTE_BRANCH="origin/master"
VERSION_KEY="'version'"

# ---------- Version-map helper ----------
#
# Scans config/nativephp.php history on master (first-parent only).
# Emits one line per distinct version: "<version> <oldest-commit-with-that-version>"
# Output is newest-version-first.
#
build_version_map() {
    local prev_ver="" last_hash="" hash ver

    while IFS= read -r hash; do
        ver=$(git show "${hash}:config/nativephp.php" 2>/dev/null \
              | grep "${VERSION_KEY}" \
              | grep -oE "[0-9]+\.[0-9]+\.[0-9]+[-a-z]*" | head -1 || true)
        [[ -z "$ver" ]] && continue

        if [[ "$ver" != "$prev_ver" ]]; then
            [[ -n "$prev_ver" && -n "$last_hash" ]] && echo "$prev_ver $last_hash"
            prev_ver="$ver"
        fi
        last_hash="$hash"
    done < <(git log "$REMOTE_BRANCH" --first-parent --format="%H" -- config/nativephp.php)

    [[ -n "$prev_ver" && -n "$last_hash" ]] && echo "$prev_ver $last_hash"
}

# lookup_version_commit <version_map_file> <version>
lookup_version_commit() {
    local map_file="$1" target="$2"
    grep "^${target} " "$map_file" | awk '{print $2}' | head -1
}

# ---------- Build the version map ----------

VERSION_MAP_FILE=$(mktemp)
SEEN_FILE=$(mktemp)
PREV_MSGS_FILE=$(mktemp)
trap 'rm -f "$VERSION_MAP_FILE" "$SEEN_FILE" "$PREV_MSGS_FILE"' EXIT

build_version_map > "$VERSION_MAP_FILE"

# ---------- Resolve PREV_REF / HEAD_REF / PREV_PREV_REF ----------

if [[ $# -eq 2 ]]; then
    # Explicit range: two version strings
    prev_ver="${1#v}"
    curr_ver="${2#v}"
    PREV_REF=$(lookup_version_commit "$VERSION_MAP_FILE" "$prev_ver")
    HEAD_REF=$(lookup_version_commit "$VERSION_MAP_FILE" "$curr_ver")
    PREV_PREV_REF=$(awk -v target="$prev_ver" '
        found { print $2; exit }
        $1 == target { found=1 }
    ' "$VERSION_MAP_FILE")

    if [[ -z "$PREV_REF" || -z "$HEAD_REF" ]]; then
        echo "Error: could not find commit for one or both versions in master history." >&2
        echo "Available versions:" >&2
        awk '{print "  v"$1}' "$VERSION_MAP_FILE" >&2
        exit 1
    fi

elif [[ $# -eq 1 ]]; then
    # Reproduce a specific release
    curr_ver="${1#v}"
    HEAD_REF=$(lookup_version_commit "$VERSION_MAP_FILE" "$curr_ver")

    if [[ -z "$HEAD_REF" ]]; then
        echo "Error: version $curr_ver not found in master history." >&2
        echo "Available versions:" >&2
        awk '{print "  v"$1}' "$VERSION_MAP_FILE" >&2
        exit 1
    fi

    PREV_REF=$(awk -v target="$curr_ver" '
        found { print $2; exit }
        $1 == target { found=1 }
    ' "$VERSION_MAP_FILE")
    [[ -z "$PREV_REF" ]] && PREV_REF=$(git rev-list --max-parents=0 HEAD)

    prev_ver=$(awk -v target="$curr_ver" '
        found { print $1; exit }
        $1 == target { found=1 }
    ' "$VERSION_MAP_FILE")
    PREV_PREV_REF=$(awk -v target="$prev_ver" '
        found { print $2; exit }
        $1 == target { found=1 }
    ' "$VERSION_MAP_FILE")

else
    # Auto-detect: unreleased changes since the most recent version bump
    curr_ver=$(awk 'NR==1{print $1}' "$VERSION_MAP_FILE")
    HEAD_REF="HEAD"
    PREV_REF=$(lookup_version_commit "$VERSION_MAP_FILE" "$curr_ver")
    [[ -z "$PREV_REF" ]] && PREV_REF=$(git rev-list --max-parents=0 HEAD)

    prev_ver=$(awk 'NR==2{print $1}' "$VERSION_MAP_FILE")
    PREV_PREV_REF=$(lookup_version_commit "$VERSION_MAP_FILE" "$prev_ver")
fi

echo "Branch      : master"
echo "Range       : ${PREV_REF:0:12}..${HEAD_REF:0:12}"
echo "Dedup from  : ${PREV_PREV_REF:0:12}"
echo ""

# ---------- Build previous-release message set for cross-release dedup ----------

normalize() {
    echo "$1" | sed 's/^[^[:alnum:]]*//' | tr '[:upper:]' '[:lower:]' | sed 's/^[[:space:]]*//'
}

if [[ -n "${PREV_PREV_REF:-}" ]]; then
    while IFS= read -r msg; do
        norm=$(normalize "$msg")
        echo "$norm" >> "$PREV_MSGS_FILE"
    done < <(git log "${PREV_PREV_REF}..${PREV_REF}" --no-merges --pretty=tformat:"%s")
fi

# ---------- Process commits in range ----------

features="" fixes="" perf="" refactor="" docs="" chores="" other=""

while IFS='|' read -r msg hash; do
    [[ "$msg" =~ ^[Vv]ersion\ bump ]] && continue
    [[ -z "$msg" ]] && continue

    norm=$(normalize "$msg")

    grep -qxF "$norm" "$SEEN_FILE"      && continue  # within-range duplicate
    grep -qxF "$norm" "$PREV_MSGS_FILE" && continue  # already in previous release

    echo "$norm" >> "$SEEN_FILE"

    line="- ${msg} (\`${hash}\`)"

    if [[ "$norm" =~ ^feat ]];       then features+="${line}"$'\n'
    elif [[ "$norm" =~ ^fix ]];      then fixes+="${line}"$'\n'
    elif [[ "$norm" =~ ^perf ]];     then perf+="${line}"$'\n'
    elif [[ "$norm" =~ ^refactor ]]; then refactor+="${line}"$'\n'
    elif [[ "$norm" =~ ^docs ]];     then docs+="${line}"$'\n'
    elif [[ "$norm" =~ ^chore ]];    then chores+="${line}"$'\n'
    else                                  other+="${line}"$'\n'
    fi
done < <(git log "${PREV_REF}..${HEAD_REF}" --no-merges --pretty=tformat:"%s|%h")

# ---------- Output ----------

echo "## What's Changed"
echo ""

if [[ -n "$features" ]];  then echo "### Features";       echo -n "$features";  echo ""; fi
if [[ -n "$fixes" ]];     then echo "### Bug Fixes";      echo -n "$fixes";     echo ""; fi
if [[ -n "$perf" ]];      then echo "### Performance";    echo -n "$perf";      echo ""; fi
if [[ -n "$refactor" ]];  then echo "### Refactoring";    echo -n "$refactor";  echo ""; fi
if [[ -n "$docs" ]];      then echo "### Documentation";  echo -n "$docs";      echo ""; fi
if [[ -n "$chores" ]];    then echo "### Maintenance";    echo -n "$chores";    echo ""; fi
if [[ -n "$other" ]];     then echo "### Other Changes";  echo -n "$other";     echo ""; fi

if [[ -z "$features$fixes$perf$refactor$docs$chores$other" ]]; then
    echo "_No new changes found in range ${PREV_REF:0:12}..${HEAD_REF}_"
fi
