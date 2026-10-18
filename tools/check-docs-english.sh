#!/bin/sh
# English version of the site (mkdocs-static-i18n, page.md + page.en.md): every French page
# of docs/ has its translation and, given a base revision (pull request), a French page
# changed since then has its translation changed too.
#
# Usage: tools/check-docs-english.sh [base revision]

status=0
for page in $(find docs -name '*.md' ! -name '*.en.md' ! -name 'README.md' | sort); do
    if [ ! -f "${page%.md}.en.md" ]; then
        echo "::error file=$page::No English version: add ${page%.md}.en.md"
        status=1
    fi
done

if [ -n "$1" ]; then
    changed=$(git diff --name-only "$1"...HEAD -- docs)
    for page in $changed; do
        case "$page" in
            *.en.md) continue ;;
            *.md)
                if [ -f "$page" ] && ! printf '%s\n' "$changed" | grep -qx "${page%.md}.en.md"; then
                    echo "::error file=$page::Changed without its English version ${page%.md}.en.md"
                    status=1
                fi
                ;;
        esac
    done
fi
exit $status
