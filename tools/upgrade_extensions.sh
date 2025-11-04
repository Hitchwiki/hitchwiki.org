#!/bin/bash

# MediaWiki Extensions/Skins Upgrade Script
# Usage: ./upgrade_extensions.sh <mediawiki_version> [install_dir]
# Example: ./upgrade_extensions.sh 1.39.0 /var/www/mediawiki

# Color codes for output
RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

# Print colored messages
print_message() {
  local message=$1
  local color=${2:-$NC}
  echo -e "${color}${message}${NC}"
}

# Check if MediaWiki version is provided
if [ $# -eq 0 ]; then
  print_message "Usage: $0 <mediawiki_version> [install_dir]" $RED
  print_message "Example: $0 1.39.0 /var/www/mediawiki" $YELLOW
  exit 1
fi

MEDIAWIKI_VERSION=$1
INSTALL_DIR=${2:-$(pwd)}
MAJOR_MINOR_VERSION=$(echo "$MEDIAWIKI_VERSION" | cut -d. -f1,2)

print_message "Starting extension/skin upgrade for MediaWiki $MEDIAWIKI_VERSION" $GREEN
print_message "Install directory: $INSTALL_DIR" 

# Handle local skins and extensions
handle_git_repo() {
  local repo_dir=$1
  local repo_name=$(basename "$repo_dir")
  print_message "Checking if extension '$repo_name' is a git repository..." 

  # Check if the directory is a git repository using git rev-parse
  if git -C "$repo_dir" rev-parse --is-inside-work-tree &>/dev/null; then
    print_message "Extension '$repo_name' is loaded from Git. Checking out the correct branch or tag..." 
    cd "$repo_dir" || exit
    git fetch --all

    # Construct the branch/tag name dynamically based on the version
    REL_BRANCH_TAG="REL${MAJOR_MINOR_VERSION//./_}"

    # Try to checkout the REL branch/tag first
    if git checkout -f "$REL_BRANCH_TAG" 2>/dev/null; then
      print_message "Checked out branch/tag $REL_BRANCH_TAG..." 
      # Only pull if it's a branch (not a tag)
      if git show-ref --verify --quiet "refs/heads/$REL_BRANCH_TAG"; then
      git pull
      fi
    # Fallback to main if REL branch/tag checkout failed
    elif git checkout -f main 2>/dev/null; then
      print_message "Checked out branch main..." 
      git pull
    # Fallback to master if main checkout failed
    elif git checkout -f master 2>/dev/null; then
      print_message "Checked out branch master..." 
      git pull
    else
      print_message "No suitable branch or tag found. Skipping extension '$repo_name'." $RED
    fi

    # Check extension.json for version requirements
    if [ -f "extension.json" ]; then
      print_message "Checking the version requirements in extension.json for '$repo_name'..." 
      required_version=$(jq -r '.requires."MediaWiki"' extension.json 2>/dev/null)
      
      if [ -n "$required_version" ] && [ "$required_version" != "null" ]; then
        # Compare version numerically using sort
        if [[ "$(echo -e "$required_version\n$MEDIAWIKI_VERSION" | sort -V | head -n 1)" != "$MEDIAWIKI_VERSION" ]]; then
          print_message "Warning: Extension '$repo_name' requires MediaWiki version >= $required_version. Proceeding with caution." $YELLOW
        fi
      fi
    fi

    cd - || exit
  else
    print_message "Extension '$repo_name' is not a git repository, skipping." $RED
  fi
}

# Check skins for git repositories and update them
print_message "Checking skins..." 
if [ -d "$INSTALL_DIR/skins" ]; then
  for dir in "$INSTALL_DIR/skins"/*; do
    if [ -d "$dir" ]; then
      handle_git_repo "$dir"
    fi
  done
else
  print_message "No skins directory found." $YELLOW
fi

# Check extensions for git repositories and update them
print_message "Checking extensions..." 
if [ -d "$INSTALL_DIR/extensions" ]; then
  for dir in "$INSTALL_DIR/extensions"/*; do
    if [ -d "$dir" ]; then
      handle_git_repo "$dir"
    fi
  done
else
  print_message "No extensions directory found." $YELLOW
fi

print_message "Extension/skin upgrade process completed!" $GREEN