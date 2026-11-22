#!/usr/bin/env bash
#
# Builds the PHP engine the playground serves for a PHP version php-wasm does
# not publish: php-wasm's v0.2.0 sources with that version added, built the
# way php-wasm builds its php-wasm and php-wasm-mbstring packages (the
# "dynamic" configuration of its CI, the make targets of the web build, the
# sha1-named module of its compress step), inside php-wasm's own builder
# image made from the pinned Emscripten image.
#
#   build.sh <version> [--write-manifest | --check]
#
# Docker is the only requirement. The script builds the builder image from
# php-wasm's emscripten-builder.dockerfile at the pinned tag, then runs itself
# inside it (--inside), where it clones php-wasm, php-src and oniguruma at
# their tags, checks each commit, applies patches/php-wasm-<minor>.patch to
# php-wasm, hands patches/php<minor>.patch to php-wasm's own patch step and
# patches/vrzno-<minor>.patch to the vrzno commit php-wasm pins.
# The build tree stays inside the container; the served files land in build/
# (git-ignored), one directory per npm package php-wasm publishes:
#
#   build/php-wasm/            PhpWeb.mjs and the modules it imports, the
#                              loader php<minor>-web.mjs, the <sha1>.wasm
#                              module it fetches, LICENSE, LICENSE-GPL, NOTICE
#   build/php-wasm-mbstring/   index.mjs and its per-version modules,
#                              php<minor>-mbstring.so, libonig.so, LICENSE,
#                              LICENSE-GPL, NOTICE
#
# Each file's size and sha256 are printed. --write-manifest records them in
# manifest.json with the sources and the toolchain; --check exits 1 when a
# file differs from the manifest's. The date PHP stamps into its binary is
# the tag's commit date (SOURCE_DATE_EPOCH) and the uname it records is fixed,
# so a rebuild gives the same bytes.
#
# Local run (emulated amd64 on Apple silicon: the builder image takes about
# an hour, the engine about as long again):
#   wasm/php-wasm/build.sh 8.6.0 --write-manifest

set -euo pipefail

PHP_WASM_REPOSITORY=https://github.com/seanmorris/php-wasm
PHP_WASM_TAG=v0.2.0
PHP_WASM_COMMIT=1e1fb6703730759181e84c62bce35ee9129febb9
ONIGURUMA_TAG=v6.9.9
ONIGURUMA_COMMIT=e62a8e5ec123bb91651d297e6f72c84d53e3cff8
EMSCRIPTEN_IMAGE=emscripten/emsdk:6.0.6
EMSCRIPTEN_PLATFORM=linux/amd64
EMSCRIPTEN_DIGEST=sha256:e70c319784885fc4740761e3d473dbfa5bf092c5b55b6d08f41f7b2c9248c4a4
BUILDER_TAG=phpregex/php-wasm-builder:v0.2.0-emsdk-6.0.6

# The versions this recipe builds: the php-src tag's commit, the PCRE2
# release php-src bundles at that tag, and the tag commit's date.
usage() {
    echo "usage: build.sh <8.6.0> [--write-manifest | --check]" >&2
    exit 2
}
recipe() {
    case "$1" in
        8.6.0) PHP_MINOR=8.6 PHP_COMMIT=8aaada3f9605d81a38b8240f93c7389dfbc77ef9 PHP_PCRE2=10.44 SOURCE_DATE_EPOCH=1794925314 ;;
        *) usage ;;
    esac
}

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

inside=false
if [ "${1:-}" = "--inside" ]; then
    inside=true
    shift
fi
[ $# -ge 1 ] || usage
PHP_VERSION="$1"
shift
recipe "$PHP_VERSION"
mode="build"
while [ $# -gt 0 ]; do
    case "$1" in
        --write-manifest) mode="write-manifest"; shift ;;
        --check) mode="check"; shift ;;
        *) usage ;;
    esac
done

if ! $inside; then
    command -v docker >/dev/null || { echo "build.sh: Docker is required" >&2; exit 2; }
    context="$(mktemp -d)"
    trap 'rm -rf "$context"' EXIT
    git clone --quiet --depth 1 --branch "$PHP_WASM_TAG" "${PHP_WASM_REPOSITORY}.git" "$context/php-wasm"
    actual="$(git -C "$context/php-wasm" rev-parse HEAD)"
    [ "$actual" = "$PHP_WASM_COMMIT" ] || { echo "build.sh: php-wasm ${PHP_WASM_TAG} is ${actual}, not ${PHP_WASM_COMMIT}" >&2; exit 1; }
    # The image php-wasm's Makefile builds ("make image"), from the pinned
    # Emscripten image instead of the tag.
    docker build --platform "$EMSCRIPTEN_PLATFORM" --progress plain \
        --build-arg "EMSDK_VERSION=6.0.6@${EMSCRIPTEN_DIGEST}" \
        -f "$context/php-wasm/emscripten-builder.dockerfile" -t "$BUILDER_TAG" "$context/php-wasm"
    flag=""
    [ "$mode" = build ] || flag="--${mode}"
    docker run --rm --platform "$EMSCRIPTEN_PLATFORM" \
        -e "OWNER=$(id -u):$(id -g)" \
        -v "$HERE:/recipe" \
        "$BUILDER_TAG" bash /recipe/build.sh --inside "$PHP_VERSION" $flag
    exit
fi

# --- inside the builder image ------------------------------------------------

[ -d /emsdk ] && [ -d /recipe ] || { echo "build.sh: --inside runs in the builder image only" >&2; exit 2; }
emcc --version | head -n 1 | grep -q ' 6\.0\.6 ' || { echo "build.sh: the builder image does not run Emscripten 6.0.6" >&2; exit 2; }

clone() { # repository tag commit directory
    git clone --quiet --depth 1 --branch "$2" "$1" "$4"
    local actual
    actual="$(git -C "$4" rev-parse HEAD)"
    [ "$actual" = "$3" ] || { echo "build.sh: $1 $2 is ${actual}, not $3" >&2; exit 1; }
}

clone "${PHP_WASM_REPOSITORY}.git" "$PHP_WASM_TAG" "$PHP_WASM_COMMIT" /src
cd /src
git apply "/recipe/patches/php-wasm-${PHP_MINOR}.patch"
cp "/recipe/patches/php${PHP_MINOR}.patch" patch/
# The Makefile clones both by tag when they are missing; cloned here first,
# each commit is checked.
clone https://github.com/php/php-src.git "php-${PHP_VERSION}" "$PHP_COMMIT" "third_party/php${PHP_MINOR}-src"
clone https://github.com/kkos/oniguruma.git "$ONIGURUMA_TAG" "$ONIGURUMA_COMMIT" third_party/oniguruma

# vrzno, php-wasm's JavaScript bridge (PhpBase.mjs calls it), at the commit
# php-wasm pins, ported to PHP 8.6 by patches/vrzno-<minor>.patch: 8.6
# removed XtOffsetOf() and changed php_stream_wrapper_log_error()'s
# arguments. php-wasm imports it from a development checkout when
# VRZNO_DEV_PATH names one, which is how the port reaches the build.
vrzno_commit="$(sed -n 's/^VRZNO_REF?=//p' packages/vrzno/pre.mak)"
mkdir -p /usr/local/src/vrzno
git -C /usr/local/src/vrzno init --quiet
git -C /usr/local/src/vrzno fetch --quiet --depth 1 https://github.com/seanmorris/vrzno.git "$vrzno_commit"
git -C /usr/local/src/vrzno checkout --quiet FETCH_HEAD
git -C /usr/local/src/vrzno apply "/recipe/patches/vrzno-${PHP_MINOR}.patch"

# The configuration php-wasm's CI builds the published packages from.
sed "s/^PHP_VERSION=.*/PHP_VERSION=${PHP_MINOR}/" .github/.env_8.5.dynamic.ci > .env

# The date PHP stamps into its binary, and the uname it records.
export SOURCE_DATE_EPOCH
export PHP_UNAME="Emscripten ${EMSCRIPTEN_IMAGE} ${EMSCRIPTEN_PLATFORM}"

# php-wasm's Makefile runs each step as "docker compose run [-e K=V]...
# [-w DIR] emscripten-builder CMD...", a fresh container each time. Already
# inside that image, the step runs in place, in the directory it names
# (created when missing, as docker does), with the environment a fresh
# container has: the image's, the two variables
# above, the two php-wasm's docker-compose.yml sets and the step's own, and
# none of make's (a step's make inheriting the outer jobserver or flags
# would not be the step php-wasm runs). The configure cache the libraries'
# steps keep in /tmp, which a fresh container starts without, gets a fresh
# file per directory, so libraries configured side by side never read each
# other's answers.
env -u OWNER -u PWD -u OLDPWD -u SHLVL -u _ > /tmp/step-environment
printf '%s\n' EMCC_CORES=8 ENVIRONMENT=web >> /tmp/step-environment
cat > /usr/local/bin/compose-in-place <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
envs=()
dir=/src
while [ $# -gt 0 ]; do
    case "$1" in
        -p) shift 2 ;;
        run|-T|--rm) shift ;;
        -e) envs+=("$2"); shift 2 ;;
        -w) dir="$2"; shift 2 ;;
        emscripten-builder) shift; break ;;
        *) echo "compose-in-place: unexpected argument $1" >&2; exit 2 ;;
    esac
done
cache="/tmp/config-cache.$(printf '%s' "$dir" | tr -c 'A-Za-z0-9' '_')"
args=()
for arg in "$@"; do
    case "$arg" in
        *--cache-file=/tmp/config-cache*) rm -f "$cache"; args+=("${arg//\/tmp\/config-cache/$cache}") ;;
        *) args+=("$arg") ;;
    esac
done
mapfile -t base < /tmp/step-environment
mkdir -p "$dir"
cd "$dir"
exec env -i "${base[@]}" "${envs[@]}" "${args[@]}"
EOF
chmod +x /usr/local/bin/compose-in-place

# The web build's steps ("make web-mjs"), without the other extensions'
# shared objects it also builds: the libraries PHP's configure links
# against, the web loader and its modules, then mbstring and oniguruma.
cat > /tmp/engine.mk <<'EOF'
include Makefile
.PHONY: phpregex-engine
phpregex-engine:
	$(MAKE) -j$(CPU_COUNT) $(PHP_CONFIGURE_DEPS)
	$(MAKE) $(WEB_MJS)
	$(MAKE) -j$(CPU_COUNT) packages/mbstring/php$(PHP_VERSION)-mbstring.so packages/mbstring/libonig.so
EOF
make -f /tmp/engine.mk phpregex-engine ENV_FILE=.env MAKE_SHUFFLE= DOCKER_COMPOSE=/usr/local/bin/compose-in-place VRZNO_DEV_PATH=/usr/local/src/vrzno

# php-wasm's compress step: the module renamed after its sha1, the loader
# rewritten to ask for that name.
bash compress-package.sh packages/php-wasm

out=/recipe/build
rm -rf "$out"
mkdir -p "$out/php-wasm" "$out/php-wasm-mbstring"
for file in PhpBase.mjs PhpWebBase.mjs PhpWeb.mjs OutputBuffer.mjs fsOps.mjs resolveDependencies.mjs _Event.mjs webTransactions.mjs idbfsSync.mjs "php${PHP_MINOR}-web.mjs" LICENSE LICENSE-GPL NOTICE; do
    cp "packages/php-wasm/${file}" "$out/php-wasm/"
done
cp packages/php-wasm/[0-9a-f]*.wasm "$out/php-wasm/"
cp packages/mbstring/*.mjs "packages/mbstring/php${PHP_MINOR}-mbstring.so" packages/mbstring/libonig.so packages/mbstring/LICENSE packages/mbstring/LICENSE-GPL packages/mbstring/NOTICE "$out/php-wasm-mbstring/"
[ "$(ls "$out"/php-wasm/*.wasm | wc -l)" = 1 ] || { echo "build.sh: expected one module in packages/php-wasm" >&2; exit 1; }
chown -R "${OWNER:-0:0}" "$out"

echo "Toolchain: $(emcc --version | head -n 1); $(bison --version | head -n 1); $(re2c --version); $(autoconf --version | head -n 1)"
(cd "$out" && find . -type f | sed 's|^\./||' | LC_ALL=C sort | while read -r file; do
    printf '%s  %9d  %s\n' "$(sha256sum "$file" | cut -d' ' -f1)" "$(stat -c %s "$file")" "$file"
done)

MANIFEST=/recipe/manifest.json
listing() {
    node - "$out" <<'EOF'
const fs = require("fs"), path = require("path"), crypto = require("crypto");
const out = process.argv[2], files = {};
for (const dir of ["php-wasm", "php-wasm-mbstring"]) {
    for (const name of fs.readdirSync(path.join(out, dir)).sort()) {
        const bytes = fs.readFileSync(path.join(out, dir, name));
        files[`${dir}/${name}`] = { bytes: bytes.length, sha256: crypto.createHash("sha256").update(bytes).digest("hex") };
    }
}
process.stdout.write(JSON.stringify(files));
EOF
}
case "$mode" in
    write-manifest)
        node - "$MANIFEST" "$(listing)" "$PHP_VERSION" "$PHP_COMMIT" "$PHP_PCRE2" "$PHP_WASM_REPOSITORY" "$PHP_WASM_TAG" "$PHP_WASM_COMMIT" "$PHP_MINOR" "$EMSCRIPTEN_IMAGE" "$EMSCRIPTEN_PLATFORM" "$EMSCRIPTEN_DIGEST" "$SOURCE_DATE_EPOCH" <<'EOF'
const fs = require("fs"), crypto = require("crypto");
const [manifest, files, version, commit, pcre2, repository, tag, wasmCommit, minor, image, platform, digest, epoch] = process.argv.slice(2);
const patches = {};
for (const patch of [`php${minor}.patch`, `php-wasm-${minor}.patch`, `vrzno-${minor}.patch`]) {
    patches[patch] = crypto.createHash("sha256").update(fs.readFileSync(`/recipe/patches/${patch}`)).digest("hex");
}
const entry = {
    php: { version, tag: `php-${version}`, commit, pcre2 },
    phpWasm: { repository, tag, commit: wasmCommit },
    patches,
    emscripten: { image, platform, digest },
    sourceDateEpoch: Number(epoch),
    files: JSON.parse(files),
};
fs.writeFileSync(manifest, JSON.stringify(entry, null, 4) + "\n");
EOF
        echo "manifest.json written"
        ;;
    check)
        [ -f "$MANIFEST" ] || { echo "build.sh: no manifest.json to check against" >&2; exit 1; }
        node - "$MANIFEST" "$(listing)" "$PHP_VERSION" <<'EOF'
const fs = require("fs");
const [manifest, listing, version] = process.argv.slice(2);
const pinned = JSON.parse(fs.readFileSync(manifest, "utf8"));
const built = JSON.parse(listing);
const problems = [];
if (pinned.php.version !== version) problems.push(`manifest.json pins PHP ${pinned.php.version}, not ${version}`);
for (const file of new Set([...Object.keys(pinned.files), ...Object.keys(built)])) {
    const want = pinned.files[file], got = built[file];
    if (!want) problems.push(`${file} is built but not pinned`);
    else if (!got) problems.push(`${file} is pinned but not built`);
    else if (want.sha256 !== got.sha256 || want.bytes !== got.bytes) problems.push(`${file}: ${got.bytes} bytes, sha256 ${got.sha256}, against ${want.bytes} bytes, sha256 ${want.sha256}`);
}
if (problems.length) {
    process.stderr.write(problems.map((problem) => `build.sh: ${problem}`).join("\n") + "\n");
    process.exit(1);
}
process.stdout.write(`Every file matches manifest.json (${Object.keys(built).length} files)\n`);
EOF
        ;;
esac
