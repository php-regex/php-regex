#!/usr/bin/env bash
#
# Builds the PCRE2 trace module of one release (pcre2-<release>.wasm) from
# that pinned PCRE2 release and trace.c, inside the pinned Emscripten
# container only. One module per PCRE2 release a playground engine bundles.
#
#   build.sh <release> [--source <pcre2-checkout>] [--write-manifest | --check]
#
# Without --source the PCRE2 tag is cloned into a temporary build directory
# outside this tree; with it the checkout is copied there, so the source tree
# given is never written to. The commit is verified against the release's
# before anything compiles, and the patches php-src applies to its bundled
# copy of that release are applied on top (patches/). The module lands in
# build/ (git-ignored); its size and sha256 are printed. --write-manifest
# records the build under its release in manifest.json; --check exits 1 when
# the sha256 differs from the manifest's for that release.
#
# Local run (emulated amd64, about a minute per release):
#   docker run --rm --platform linux/amd64 -v "$PWD:/work" -w /work \
#     emscripten/emsdk@sha256:27bc6267cb285223b8aebb7627bfebae7cb3ad2aaa0d5923b8aa5321793033e8 \
#     wasm/pcre2-trace/build.sh 10.44 --write-manifest

set -euo pipefail

PCRE2_REPOSITORY=https://github.com/PCRE2Project/pcre2
EMSCRIPTEN_VERSION=4.0.15
EMSCRIPTEN_IMAGE=emscripten/emsdk:4.0.15
EMSCRIPTEN_DIGEST=sha256:27bc6267cb285223b8aebb7627bfebae7cb3ad2aaa0d5923b8aa5321793033e8

# The 8-bit, non-JIT, non-DFA sources of the library, in the order they are
# compiled (the order is part of the bytes); pcre2_chkdint joined in 10.43.
PCRE2_SOURCES="pcre2_auto_possess pcre2_chartables pcre2_chkdint pcre2_compile pcre2_config pcre2_context pcre2_error pcre2_extuni pcre2_find_bracket pcre2_maketables pcre2_match pcre2_match_data pcre2_newline pcre2_ord2utf pcre2_pattern_info pcre2_script_run pcre2_string_utils pcre2_study pcre2_substring pcre2_tables pcre2_ucd pcre2_valid_utf pcre2_xclass"

# The releases the playground's engines bundle, by php-src tag:
#   10.35 php-8.0.30   10.39 php-8.1.28   10.40 php-8.2.11
#   10.42 php-8.3.11   10.44 php-8.4.1, php-8.5.2, php-8.6.0
# Each pins the tag's commit, the patches php-src carries in its bundled
# copy at those tags (the patched sources equal php-src's byte for byte),
# and the name-size limit of the release's generic configuration, which
# php-src's bundled config.h repeats (32 up to 10.42, 128 from 10.44).
usage() {
    echo "usage: build.sh <10.35|10.39|10.40|10.42|10.44> [--source <pcre2-checkout>] [--write-manifest | --check]" >&2
    exit 2
}
[ $# -ge 1 ] || usage
PCRE2_RELEASE="$1"
shift
case "$PCRE2_RELEASE" in
    10.35) PCRE2_COMMIT=0c4141b78bdaff0bd704508edeca48573ec1e0b2 PATCHES="" MAX_NAME_SIZE=32 ;;
    10.39) PCRE2_COMMIT=35fee4193b852cb504892352bd0155de10809889 PATCHES="gh-11374" MAX_NAME_SIZE=32 ;;
    10.40) PCRE2_COMMIT=3103b8f20a3b9944b177e812fde29fbfb8b90558 PATCHES="" MAX_NAME_SIZE=32 ;;
    10.42) PCRE2_COMMIT=52c08847921a324c804cabf2814549f50bce1265 PATCHES="gh-11374" MAX_NAME_SIZE=32 ;;
    10.44) PCRE2_COMMIT=6ae58beca071f13ccfed31d03b3f479ab520639b PATCHES="" MAX_NAME_SIZE=128 ;;
    *) usage ;;
esac
PCRE2_TAG="pcre2-${PCRE2_RELEASE}"
if [ "$(printf '%s\n' "$PCRE2_RELEASE" 10.43 | sort -V | head -n 1)" != 10.43 ]; then
    PCRE2_SOURCES="${PCRE2_SOURCES/ pcre2_chkdint/}"
fi
# Before 10.42 pcre2_code_free() calls PRIV(jit_free) without a SUPPORT_JIT
# guard; pcre2_jit_compile.c, built without SUPPORT_JIT, provides it as a
# stub (no pattern ever holds JIT code here), as php-src builds it.
if [ "$(printf '%s\n' "$PCRE2_RELEASE" 10.42 | sort -V | head -n 1)" != 10.42 ]; then
    PCRE2_SOURCES="${PCRE2_SOURCES} pcre2_jit_compile"
fi

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
        *) usage ;;
    esac
done

if [ -z "${EMSDK:-}" ]; then
    echo "build.sh: EMSDK is not set: this build runs only inside the pinned container (${EMSCRIPTEN_IMAGE}, ${EMSCRIPTEN_DIGEST})." >&2
    echo "  docker run --rm --platform linux/amd64 -v \"\$PWD:/work\" -w /work emscripten/emsdk@${EMSCRIPTEN_DIGEST} wasm/pcre2-trace/build.sh ${PCRE2_RELEASE}" >&2
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

# php-src's patches to its bundled copy of this release, in order.
patch_list="[]"
for name in $PATCHES; do
    git -C "$pcre2_dir" apply "${HERE}/patches/${name}.patch"
    echo "applied patches/${name}.patch"
done
if [ -n "$PATCHES" ]; then
    patch_list="$(printf '%s\n' $PATCHES | node -e 'process.stdout.write(JSON.stringify(require("node:fs").readFileSync(0, "utf8").trim().split("\n")))')"
fi

# The generic configuration is the one php-src's bundled copy ships: the
# limits below are asserted so a PCRE2 release that moves one is noticed.
src="${pcre2_dir}/src"
for pair in "HEAP_LIMIT 20000000" "LINK_SIZE 2" "MATCH_LIMIT 10000000" "MAX_NAME_SIZE ${MAX_NAME_SIZE}" "NEWLINE_DEFAULT 2" "PARENS_NEST_LIMIT 250"; do
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
const [manifest, release, tag, commit, patches, image, digest, module, sha256, bytes, interface] = process.argv.slice(1);
const { exports, imports } = JSON.parse(interface);
const all = fs.existsSync(manifest) ? JSON.parse(fs.readFileSync(manifest, "utf8")) : {};
all[release] = {
    release, pcre2: { tag, commit }, patches: JSON.parse(patches), emscripten: { image, digest }, module, sha256, bytes: Number(bytes), exports, imports,
};
const sorted = {};
Object.keys(all).sort((a, b) => a.localeCompare(b, "en", { numeric: true })).forEach((key) => { sorted[key] = all[key]; });
fs.writeFileSync(manifest, JSON.stringify(sorted, null, 4) + "\n");
' "$MANIFEST" "$PCRE2_RELEASE" "$PCRE2_TAG" "$PCRE2_COMMIT" "$patch_list" "$EMSCRIPTEN_IMAGE" "$EMSCRIPTEN_DIGEST" "$MODULE" "$sha256" "$bytes" "$interface"
        echo "${MANIFEST} written (${PCRE2_RELEASE})"
        ;;
    check)
        [ -f "$MANIFEST" ] || { echo "build.sh: no manifest.json to check against" >&2; exit 1; }
        expected="$(node -e 'const entry = JSON.parse(require("node:fs").readFileSync(process.argv[1], "utf8"))[process.argv[2]]; process.stdout.write(entry ? entry.sha256 : "")' "$MANIFEST" "$PCRE2_RELEASE")"
        if [ -z "$expected" ]; then
            echo "build.sh: manifest.json pins no module for PCRE2 ${PCRE2_RELEASE}" >&2
            exit 1
        fi
        if [ "$sha256" != "$expected" ]; then
            echo "build.sh: the ${MODULE} sha256 ${sha256} differs from the manifest's ${expected}" >&2
            exit 1
        fi
        echo "${MODULE} matches manifest.json"
        ;;
    build) ;;
esac
