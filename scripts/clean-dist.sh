#!/bin/bash

# Clean-in-place: strips dev-only files (everything listed in .distignore)
# from a directory the plugin was installed into via `git clone`, instead of
# via the compiled release ZIP. Run this FROM the plugin directory itself,
# after cloning it into wp-content/plugins/.
#
# Usage:
#   bash scripts/clean-dist.sh              # dry run: show what would be removed
#   bash scripts/clean-dist.sh --yes        # remove, skipping the confirmation prompt
#   bash scripts/clean-dist.sh --force      # also bypass the "clean working tree" safety check
#   bash scripts/clean-dist.sh --force --yes
#
# Safety: this is destructive (it deletes .git among other things), so by
# default it refuses to run against a git working tree that has uncommitted
# changes or unpushed commits -- that's the signature of an active dev
# workspace, not a fresh site install. --force overrides that check for
# people who really mean it.

set -e

PLUGIN_SLUG="wphaven-connect"
MAIN_FILE="wphaven.php"
DISTIGNORE=".distignore"

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

FORCE=0
ASSUME_YES=0
DRY_RUN=0
for arg in "$@"; do
	case "$arg" in
	--force) FORCE=1 ;;
	--yes) ASSUME_YES=1 ;;
	--dry-run) DRY_RUN=1 ;;
	*)
		echo -e "${RED}❌ Unknown option: $arg${NC}"
		exit 1
		;;
	esac
done

# --------------------------------------------------------------------------
# Guard: make sure we're actually inside the plugin directory, not somewhere
# a stray --force could do real damage.
# --------------------------------------------------------------------------
if [ ! -f "$MAIN_FILE" ] || ! grep -q "^ \* Plugin Name:" "$MAIN_FILE"; then
	echo -e "${RED}❌ This doesn't look like the ${PLUGIN_SLUG} directory (no ${MAIN_FILE} with a plugin header found).${NC}"
	echo "   Run this script from the plugin's own root directory."
	exit 1
fi

if [ ! -f "$DISTIGNORE" ]; then
	echo -e "${RED}❌ ${DISTIGNORE} not found. Nothing to clean.${NC}"
	exit 1
fi

# --------------------------------------------------------------------------
# Guard: refuse to touch what looks like an active dev workspace.
# A fresh `git clone` has a clean tree and nothing unpushed -- anything else
# means someone has been working here.
# --------------------------------------------------------------------------
if [ -d .git ] && [ "$FORCE" != "1" ]; then
	if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
		echo -e "${RED}❌ Refusing to clean: this git working tree has uncommitted changes.${NC}"
		echo "   This looks like a development checkout, not a fresh site install."
		echo "   Commit/stash your work, or re-run with --force if you're sure."
		exit 1
	fi

	UPSTREAM=$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)
	if [ -n "$UPSTREAM" ]; then
		UNPUSHED=$(git rev-list "@{u}.." --count 2>/dev/null || echo 0)
		if [ "$UNPUSHED" != "0" ]; then
			echo -e "${RED}❌ Refusing to clean: this branch has ${UNPUSHED} unpushed commit(s).${NC}"
			echo "   This looks like a development checkout, not a fresh site install."
			echo "   Push your work, or re-run with --force if you're sure."
			exit 1
		fi
	fi
fi

# --------------------------------------------------------------------------
# Build the list of paths to remove from .distignore (top-level entries only
# -- every current entry in the file is a repo-root name or glob, and
# keeping this shallow keeps the script's behavior easy to audit).
# --------------------------------------------------------------------------
TARGETS=()
while IFS= read -r pattern || [ -n "$pattern" ]; do
	[[ -z "$pattern" || "$pattern" =~ ^# ]] && continue
	pattern="${pattern%/}"

	shopt -s nullglob
	for match in $pattern; do
		[ -e "$match" ] && TARGETS+=("$match")
	done
	shopt -u nullglob
done <"$DISTIGNORE"

if [ ${#TARGETS[@]} -eq 0 ]; then
	echo -e "${GREEN}✅ Nothing to clean -- no .distignore entries matched.${NC}"
	exit 0
fi

echo -e "${BLUE}The following will be permanently deleted from $(pwd):${NC}"
printf '  %s\n' "${TARGETS[@]}"

if [ "$DRY_RUN" = "1" ]; then
	echo -e "${YELLOW}(dry run -- nothing was deleted)${NC}"
	exit 0
fi

if [ "$ASSUME_YES" != "1" ]; then
	read -r -p "Type '${PLUGIN_SLUG}' to confirm deletion: " CONFIRMATION
	if [ "$CONFIRMATION" != "$PLUGIN_SLUG" ]; then
		echo -e "${YELLOW}Aborted -- no changes made.${NC}"
		exit 1
	fi
fi

for target in "${TARGETS[@]}"; do
	rm -rf -- "$target"
done

echo -e "${GREEN}✅ Cleaned. This directory now contains only production files.${NC}"
