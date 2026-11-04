#!/usr/bin/env bash
#
# Builds the PCRE2 trace module (pcre2-<release>.wasm) from the pinned PCRE2
# release and trace.c, inside the pinned Emscripten container only.
#
#   build.sh [--source <pcre2-checkout>] [--write-manifest | --check]
#
# Without --source the PCRE2 tag is cloned into a temporary build directory
# outside this tree; with it the checkout is copied there, so the source tree
# given is never written to. The commit is verified against the manifest's
# before anything compiles. The module lands in build/ (git-ignored); its
# size and sha256 are printed. --write-manifest records the build in
# manifest.json; --check exits 1 when the sha256 differs from the manifest's.
#
# Local run (emulated amd64, about a minute):
#   docker run --rm --platform linux/amd64 -v "$PWD:/work" -w /work \
#     emscripten/emsdk@sha256:27bc6267cb285223b8aebb7627bfebae7cb3ad2aaa0d5923b8aa5321793033e8 \
#     wasm/pcre2-trace/build.sh --write-manifest

set -euo pipefail

PCRE2_RELEASE=10.44
PCRE2_TAG=pcre2-10.44
PCRE2_COMMIT=6ae58beca071f13ccfed31d03b3f479ab520639b
PCRE2_REPOSITORY=https://github.com/PCRE2Project/pcre2
EMSCRIPTEN_VERSION=4.0.15
EMSCRIPTEN_IMAGE=emscripten/emsdk:4.0.15
EMSCRIPTEN_DIGEST=sha256:27bc6267cb285223b8aebb7627bfebae7cb3ad2aaa0d5923b8aa5321793033e8

# The 8-bit, non-JIT, non-DFA sources of the library.
PCRE2_SOURCES="pcre2_auto_possess pcre2_chartables pcre2_chkdint pcre2_compile pcre2_config pcre2_context pcre2_error pcre2_extuni pcre2_find_bracket pcre2_maketables pcre2_match pcre2_match_data pcre2_newline pcre2_ord2utf pcre2_pattern_info pcre2_script_run pcre2_string_utils pcre2_study pcre2_substring pcre2_tables pcre2_ucd pcre2_valid_utf pcre2_xclass"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE="pcre2-${PCRE2_RELEASE}.wasm"
OUT_DIR="${HERE}/build"
OUT="${OUT_DIR}/${MODULE}"
MANIFEST="${HERE}/manifest.json"

source_dir=""
mode="build"
while [ $# -gt 0 ]; do
    case "$1" in
        --source)
            [ $# -ge 2 ] || { echo "build.sh: --source needs a directory" >&2; exit 2; }
            source_dir="$2"
            shift 2
            ;;
        --write-manifest) mode="write-manifest"; shift ;;
        --check) mode="check"; shift ;;
        *) echo "usage: build.sh [--source <pcre2-checkout>] [--write-manifest | --check]" >&2; exit 2 ;;
    esac
done

if [ -z "${EMSDK:-}" ]; then
    echo "build.sh: EMSDK is not set: this build runs only inside the pinned container (${EMSCRIPTEN_IMAGE}, ${EMSCRIPTEN_DIGEST})." >&2
    echo "  docker run --rm --platform linux/amd64 -v \"\$PWD:/work\" -w /work emscripten/emsdk@${EMSCRIPTEN_DIGEST} wasm/pcre2-trace/build.sh" >&2
    exit 1
fi
for tool in emcc git node sha256sum; do
    command -v "$tool" >/dev/null 2>&1 || { echo "build.sh: $tool is not on the PATH" >&2; exit 1; }
done
emcc_version="$(emcc --version | head -n 1 | sed -E 's/^emcc \([^)]*\) ([0-9.]+).*/\1/')"
if [ "$emcc_version" != "$EMSCRIPTEN_VERSION" ]; then
    echo "build.sh: emcc ${emcc_version} is not the pinned ${EMSCRIPTEN_VERSION}" >&2
    exit 1
fi

# The build directory lives outside every source tree and goes away with
# the script.
build_dir="$(mktemp -d "${TMPDIR:-/tmp}/pcre2-trace-build.XXXXXX")"
trap 'rm -rf "$build_dir"' EXIT
pcre2_dir="${build_dir}/pcre2"

if [ -n "$source_dir" ]; then
    [ -d "$source_dir/src" ] || { echo "build.sh: $source_dir is not a PCRE2 checkout (no src/)" >&2; exit 1; }
    cp -R "$source_dir" "$pcre2_dir"
else
    echo "cloning ${PCRE2_REPOSITORY} at ${PCRE2_TAG}"
    git clone --quiet --depth 1 --branch "$PCRE2_TAG" "$PCRE2_REPOSITORY" "$pcre2_dir"
fi
git config --global --add safe.directory "$pcre2_dir" >/dev/null 2>&1 || true
commit="$(git -C "$pcre2_dir" rev-parse HEAD)"
if [ "$commit" != "$PCRE2_COMMIT" ]; then
    echo "build.sh: the PCRE2 checkout is at ${commit}, not the pinned ${PCRE2_COMMIT} (${PCRE2_TAG})" >&2
    exit 1
fi

# The generic configuration is the one php-src's bundled copy ships: the
# limits below are asserted so a PCRE2 release that moves one is noticed.
src="${pcre2_dir}/src"
for pair in "HEAP_LIMIT 20000000" "LINK_SIZE 2" "MATCH_LIMIT 10000000" "MAX_NAME_SIZE 128" "NEWLINE_DEFAULT 2" "PARENS_NEST_LIMIT 250"; do
    name="${pair% *}"
    value="${pair#* }"
    if ! grep -Eq "^#define ${name} ${value}\$" "${src}/config.h.generic"; then
        echo "build.sh: config.h.generic does not define ${name} ${value}" >&2
        exit 1
    fi
done
cp "${src}/config.h.generic" "${src}/config.h"
cp "${src}/pcre2.h.generic" "${src}/pcre2.h"
cp "${src}/pcre2_chartables.c.dist" "${src}/pcre2_chartables.c"

sources=""
for name in $PCRE2_SOURCES; do
    sources="${sources} ${src}/${name}.c"
done

mkdir -p "$OUT_DIR"
echo "compiling ${MODULE} with emcc ${emcc_version}"
# shellcheck disable=SC2086
emcc -Oz -DHAVE_CONFIG_H -DPCRE2_CODE_UNIT_WIDTH=8 -DPCRE2_STATIC -DSUPPORT_UNICODE -DSUPPORT_PCRE2_8 \
    -I"$src" -I"$HERE" \
    $sources "${HERE}/trace.c" \
    -sSTANDALONE_WASM --no-entry \
    -sALLOW_MEMORY_GROWTH=1 -sMAXIMUM_MEMORY=1GB \
    -sEXPORTED_FUNCTIONS=_pcre2_trace_run,_pcre2_trace_free,_pcre2_trace_version,_malloc,_free \
    -o "$OUT"

bytes="$(wc -c < "$OUT" | tr -d ' ')"
sha256="$(sha256sum "$OUT" | cut -d ' ' -f 1)"
echo "${OUT}: ${bytes} bytes, sha256 ${sha256}"

# The module's interface, read from the bytes: the page relies on one import
# (Emscripten's memory-growth notice, answered by a no-op)
# and on these exports.
interface="$(node -e '
const fs = require("node:fs");
const module = new WebAssembly.Module(fs.readFileSync(process.argv[1]));
process.stdout.write(JSON.stringify({
    exports: WebAssembly.Module.exports(module).map((e) => e.name),
    imports: WebAssembly.Module.imports(module).map((i) => i.module + "." + i.name),
}));
' "$OUT")"

case "$mode" in
    write-manifest)
        node -e '
const fs = require("node:fs");
const [manifest, release, tag, commit, image, digest, module, sha256, bytes, interface] = process.argv.slice(1);
const { exports, imports } = JSON.parse(interface);
fs.writeFileSync(manifest, JSON.stringify({
    release, pcre2: { tag, commit }, emscripten: { image, digest }, module, sha256, bytes: Number(bytes), exports, imports,
}, null, 4) + "\n");
' "$MANIFEST" "$PCRE2_RELEASE" "$PCRE2_TAG" "$PCRE2_COMMIT" "$EMSCRIPTEN_IMAGE" "$EMSCRIPTEN_DIGEST" "$MODULE" "$sha256" "$bytes" "$interface"
        echo "${MANIFEST} written"
        ;;
    check)
        [ -f "$MANIFEST" ] || { echo "build.sh: no manifest.json to check against" >&2; exit 1; }
        expected="$(node -e 'process.stdout.write(JSON.parse(require("node:fs").readFileSync(process.argv[1], "utf8")).sha256)' "$MANIFEST")"
        if [ "$sha256" != "$expected" ]; then
            echo "build.sh: the module's sha256 ${sha256} differs from the manifest's ${expected}" >&2
            exit 1
        fi
        echo "the module matches manifest.json"
        ;;
    build) ;;
esac
