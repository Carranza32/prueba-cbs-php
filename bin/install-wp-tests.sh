#!/usr/bin/env bash
# Install the WordPress test library and a temporary test database.
#
# Usage:
#   bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Example (Local by Flywheel defaults):
#   bash bin/install-wp-tests.sh wordpress_test root root localhost latest
#
# The script installs:
#   /tmp/wordpress/          — a copy of WordPress core
#   /tmp/wordpress-tests-lib — the WP PHPUnit test stubs
#
# After running this once, `composer test` will work.

set -e

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}

download() {
    if command -v curl &> /dev/null; then
        curl -s "$1" > "$2"
    elif command -v wget &> /dev/null; then
        wget -nv -O "$2" "$1"
    fi
}

if [[ $WP_VERSION == 'latest' ]]; then
    local_version=$(download https://api.wordpress.org/core/version-check/1.7/ - | grep -oE '"version":"[^"]+"' | head -1 | sed 's/"version":"//;s/"//')
    WP_VERSION=${local_version:-latest}
fi

WP_TESTS_TAG="tags/${WP_VERSION}"
if [[ $WP_VERSION == 'latest' ]]; then
    WP_TESTS_TAG="trunk"
fi

set +e
svn_installed=$(command -v svn)
set -e
if [[ -z $svn_installed ]]; then
    echo "Error: svn is required. Install it with: brew install subversion"
    exit 1
fi

# ── WordPress core ────────────────────────────────────────────────────────────
if [[ ! -d $WP_CORE_DIR/src ]]; then
    mkdir -p "$WP_CORE_DIR"
    svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/src/" "$WP_CORE_DIR/src"
else
    svn up --quiet "$WP_CORE_DIR/src"
fi

# ── WordPress test library ────────────────────────────────────────────────────
if [[ ! -d $WP_TESTS_DIR/includes ]]; then
    mkdir -p "$WP_TESTS_DIR"
    svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
    svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/"     "$WP_TESTS_DIR/data"
else
    svn up --quiet "$WP_TESTS_DIR/includes"
    svn up --quiet "$WP_TESTS_DIR/data"
fi

# ── wp-tests-config.php ───────────────────────────────────────────────────────
if [[ ! -f $WP_TESTS_DIR/wp-tests-config.php ]]; then
    download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/src/'|" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|youremptytestdbnamehere|${DB_NAME}|" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|yourusernamehere|${DB_USER}|"         "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|yourpasswordhere|${DB_PASS}|"         "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|localhost|${DB_HOST}|"                "$WP_TESTS_DIR/wp-tests-config.php"
fi

# ── Create test database ──────────────────────────────────────────────────────
mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true

echo "Done. WP test library installed at: ${WP_TESTS_DIR}"
echo "Run tests with: composer test"
