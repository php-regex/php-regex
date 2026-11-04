#!/usr/bin/env bash
#
# Pins pcre2test's auto-callout trace of every case of the step debugger for
# one PCRE2 release:
#
#   pin-traces.sh <release> <pcre2test-binary>
#
# writes tests/Documentation/Fixtures/pcre2-trace/<release>.txt as the
# verbatim output of "pcre2test -q cases.txt" of that binary, after checking
# the binary is the release named. The shim test compares the shim's JSON
# with the file of the release the host links; a release with no file skips
# with the release named.

set -euo pipefail

if [ $# -ne 2 ]; then
    echo "usage: pin-traces.sh <release> <pcre2test-binary>" >&2
    exit 2
fi
release="$1"
pcre2test="$2"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
fixtures="${HERE}/../../tests/Documentation/Fixtures/pcre2-trace"
cases="${fixtures}/cases.txt"
[ -f "$cases" ] || { echo "pin-traces.sh: no ${cases}" >&2; exit 1; }

actual="$("$pcre2test" -C 2>/dev/null | sed -nE '1s/^PCRE2 version ([0-9]+\.[0-9]+).*/\1/p')"
if [ "$actual" != "$release" ]; then
    echo "pin-traces.sh: ${pcre2test} is PCRE2 ${actual:-unknown}, not ${release}" >&2
    exit 1
fi

target="${fixtures}/${release}.txt"
"$pcre2test" -q "$cases" > "$target"
echo "${target}: $(wc -l < "$target" | tr -d ' ') lines from PCRE2 ${release}"
