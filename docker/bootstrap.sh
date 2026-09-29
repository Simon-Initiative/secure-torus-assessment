#!/bin/sh
# Prepare a sibling Moodle checkout and link this bundle's three components.
set -eu

workspace="${WORKSPACE_ROOT:-/workspace}"
bundle="$workspace/secure-assessment-plugin"
moodle="$workspace/moodle"
ref="${MOODLE_SOURCE_REF:-8eae8fc94d0e3932cbc64f1e81414903a1e2e83b}"
sourceurl="${MOODLE_SOURCE_URL:-https://github.com/moodle/moodle.git}"

test -d "$bundle/public/local/torussecure"
test -d "$bundle/public/availability/condition/torusseb"
test -d "$bundle/public/mod/lti/source/torussecure"

if [ ! -d "$moodle/.git" ]; then
    if [ -e "$moodle" ] && [ -n "$(ls -A "$moodle")" ]; then
        echo "Cannot clone Moodle: $moodle exists and is not a Git checkout." >&2
        exit 1
    fi
    git clone --no-checkout --filter=blob:none --depth 1 --branch main \
        "$sourceurl" "$moodle"
fi

if [ ! -f "$moodle/public/version.php" ]; then
    git -C "$moodle" fetch --depth 1 origin "$ref"
    git -C "$moodle" checkout --detach FETCH_HEAD
fi

backup=''
for relative in \
    local/torussecure \
    availability/condition/torusseb \
    mod/lti/source/torussecure; do
    source="$bundle/public/$relative"
    link="$moodle/public/$relative"
    mkdir -p "$(dirname "$link")"

    if [ -L "$link" ]; then
        if [ "$(realpath "$link")" != "$(realpath "$source")" ]; then
            echo "Unexpected plugin symlink: $link" >&2
            exit 1
        fi
        continue
    fi

    if [ -e "$link" ]; then
        if [ -z "$backup" ]; then
            mkdir -p "$bundle/.bootstrap-backup"
            backup="$(mktemp -d "$bundle/.bootstrap-backup/XXXXXX")"
        fi
        mkdir -p "$backup/public/$(dirname "$relative")"
        mv "$link" "$backup/public/$relative"
    fi

    case "$relative" in
        local/*) target="../../../secure-assessment-plugin/public/$relative" ;;
        availability/condition/*) target="../../../../secure-assessment-plugin/public/$relative" ;;
        mod/lti/source/*) target="../../../../../secure-assessment-plugin/public/$relative" ;;
    esac
    ln -s "$target" "$link"
    echo "Linked $relative"
done

if [ -n "$backup" ]; then
    echo "Preserved preexisting plugin directories in $backup"
fi
