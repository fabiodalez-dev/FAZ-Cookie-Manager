#!/usr/bin/env bash
# Build and destroy an isolated WordPress multisite around the scanner E2E.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ -z "${WP_PATH:-}" ] || [ ! -f "${WP_PATH}/wp-config.php" ]; then
	echo 'WP_PATH must point to a working reference WordPress installation.' >&2
	exit 2
fi
SOURCE_WP="${WP_PATH}"
RUN_DIR="$(mktemp -d /private/tmp/faz-multisite-e2e.XXXXXX)"
WP_DIR="${RUN_DIR}/wordpress"
DB_NAME="faz_multisite_e2e_$(date +%s)_${RANDOM}"
PORT="${FAZ_MULTISITE_PORT:-10001}"
MAIN_URL="http://127.0.0.1:${PORT}"
CHILD_URL="${MAIN_URL}/child"
SERVER_PID=""

cleanup() {
	if [ -n "${SERVER_PID}" ]; then
		kill "${SERVER_PID}" >/dev/null 2>&1 || true
		wait "${SERVER_PID}" >/dev/null 2>&1 || true
	fi
	wp --path="${SOURCE_WP}" db query "DROP DATABASE IF EXISTS \`${DB_NAME}\`" >/dev/null 2>&1 || true
	case "${RUN_DIR}" in
		/private/tmp/faz-multisite-e2e.*) rm -rf -- "${RUN_DIR}" ;;
		*) echo "Refusing to remove unexpected multisite temp path: ${RUN_DIR}" >&2 ;;
	esac
}
trap cleanup EXIT INT TERM

mkdir -p "${WP_DIR}"
rsync -a \
	--exclude='wp-content/plugins' \
	--exclude='wp-content/uploads' \
	--exclude='wp-content/cache' \
	--exclude='wp-content/debug.log' \
	"${SOURCE_WP}/" "${WP_DIR}/"
mkdir -p "${WP_DIR}/wp-content/plugins"

wp --path="${SOURCE_WP}" db query "CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
wp --path="${WP_DIR}" config set DB_NAME "${DB_NAME}" --type=constant --quiet
wp --path="${WP_DIR}" config delete MULTISITE --quiet >/dev/null 2>&1 || true
wp --path="${WP_DIR}" config delete SUBDOMAIN_INSTALL --quiet >/dev/null 2>&1 || true
wp --path="${WP_DIR}" config delete DOMAIN_CURRENT_SITE --quiet >/dev/null 2>&1 || true
wp --path="${WP_DIR}" config delete PATH_CURRENT_SITE --quiet >/dev/null 2>&1 || true
wp --path="${WP_DIR}" config delete SITE_ID_CURRENT_SITE --quiet >/dev/null 2>&1 || true
wp --path="${WP_DIR}" config delete BLOG_ID_CURRENT_SITE --quiet >/dev/null 2>&1 || true

wp --path="${WP_DIR}" core install \
	--url="${MAIN_URL}" \
	--title='FAZ isolated multisite' \
	--admin_user=admin \
	--admin_password=admin \
	--admin_email='admin@example.test' \
	--skip-email
wp --path="${WP_DIR}" config set WP_DEBUG true --raw --quiet
wp --path="${WP_DIR}" option update permalink_structure '/%postname%/'

FAZ_DEPLOY_TARGET="${WP_DIR}/wp-content/plugins/faz-cookie-manager/" bash "${REPO_DIR}/scripts/deploy-test.sh"
rsync -a \
	"${REPO_DIR}/tests/e2e/fixtures/plugins/faz-e2e-scan-lab/" \
	"${WP_DIR}/wp-content/plugins/faz-e2e-scan-lab/"

# Omitting --subdomains is the WP-CLI spelling for a subdirectory network.
# Passing --subdomains=false still counts as "flag present" and silently builds
# child.127.0.0.1 instead of /child.
wp --path="${WP_DIR}" core multisite-convert --title='FAZ isolated network'
wp --path="${WP_DIR}" plugin activate faz-cookie-manager --network
wp --path="${WP_DIR}" plugin activate faz-e2e-scan-lab --network
wp --path="${WP_DIR}" site create --slug=child --title='FAZ child scanner' --email='admin@example.test'
wp --path="${WP_DIR}" --url="${CHILD_URL}" post create \
	--post_type=page \
	--post_status=publish \
	--post_name=faz-lab-ajax-httponly \
	--post_title='FAZ Ajax HttpOnly Lab' \
	--post_content='[faz_e2e_scan_lab scenario="ajax-httponly"]' >/dev/null
wp --path="${WP_DIR}" --url="${CHILD_URL}" rewrite flush >/dev/null

php -S "127.0.0.1:${PORT}" -t "${WP_DIR}" \
	"${REPO_DIR}/tests/e2e/fixtures/multisite-router.php" >"${RUN_DIR}/server.log" 2>&1 &
SERVER_PID=$!
for _attempt in $(seq 1 60); do
	if curl -fsS "${CHILD_URL}/wp-login.php" >/dev/null 2>&1; then
		break
	fi
	sleep 0.25
done
curl -fsS "${CHILD_URL}/wp-login.php" >/dev/null

cd "${REPO_DIR}"
WP_BASE_URL="${CHILD_URL}" \
FAZ_MULTISITE_MAIN_URL="${MAIN_URL}" \
WP_ADMIN_USER=admin \
WP_ADMIN_PASS=admin \
WP_PATH="${WP_DIR}" \
npx playwright test -c tests/e2e/playwright.multisite.config.ts
