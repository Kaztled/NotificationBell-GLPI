#!/usr/bin/env bash
# Install the optional NotificationBell SSE relay on an Apache/systemd host.
set -euo pipefail
umask 077

usage() {
    cat <<'HELP'
Usage: sudo bash service/install-sse-apache.sh [options]

Without options, the installer prompts for this installation's paths and URL.

  --glpi-root PATH       Absolute GLPI root (contains public/ and plugins/)
  --origin URL           Exact origin opened by the user, without a trailing slash
  --vhost FILE           Apache VirtualHost serving this installation
  --config-dir PATH      GLPI_CONFIG_DIR (default: ROOT/config)
  --files-dir PATH       GLPI_VAR_DIR (default: ROOT/files)
  --web-user USER        PHP/Apache user (apache or www-data)
  --apache-service NAME  systemd service (httpd or apache2)
  --port NUMBER          Local relay port (default: 8091)
  --dry-run              Validate and show the plan without writing anything
  --help                 Show this help

Example: sudo bash service/install-sse-apache.sh
HELP
}

die() { printf 'Error: %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || die "Missing command: $1."; }

prompt() {
    local name="$1" label="$2" default="${3:-}" answer=''
    [[ -n "${!name:-}" ]] && return
    [[ -t 0 ]] || die "Missing $label; provide it as an option or run in a terminal."
    if [[ -n "$default" ]]; then
        printf '%s [%s]: ' "$label" "$default" >&2
    else
        printf '%s: ' "$label" >&2
    fi
    read -r answer
    printf -v "$name" '%s' "${answer:-$default}"
    [[ -n "${!name}" ]] || die "$label is required."
}

glpi_root='' origin='' vhost='' config_dir='' files_dir='' web_user=''
apache_service='' port='' dry_run=0
while (($#)); do
    case "$1" in
        --glpi-root|--origin|--vhost|--config-dir|--files-dir|--web-user|--apache-service|--port)
            (($# >= 2)) || die "Missing value for $1."
            case "$1" in
                --glpi-root) glpi_root="$2" ;;
                --origin) origin="$2" ;;
                --vhost) vhost="$2" ;;
                --config-dir) config_dir="$2" ;;
                --files-dir) files_dir="$2" ;;
                --web-user) web_user="$2" ;;
                --apache-service) apache_service="$2" ;;
                --port) port="$2" ;;
            esac
            shift 2 ;;
        --dry-run) dry_run=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) die "Unknown option: $1" ;;
    esac
done

need realpath
need php
need awk
need grep
need curl
need install
need systemctl
need runuser

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
suggested_root="$(realpath "$script_dir/../../..")"
if [[ ! -f "$suggested_root/public/index.php" ]]; then suggested_root=''; fi
prompt glpi_root 'Absolute GLPI path' "$suggested_root"
[[ "$glpi_root" == /* && -d "$glpi_root" ]] || die 'The GLPI root must be an existing absolute directory.'
glpi_root="$(realpath "$glpi_root")"
[[ -f "$glpi_root/public/index.php" && -f "$glpi_root/plugins/notificationbell/setup.php" \
    && -f "$glpi_root/plugins/notificationbell/service/relay.php" ]] \
    || die 'The path does not contain GLPI with notificationbell installed in plugins/.'

prompt origin 'GLPI origin URL (example: https://glpi.company.com)'
php -r '$u = parse_url($argv[1]); if (!is_array($u) || !in_array($u["scheme"] ?? null, ["http", "https"], true) || empty($u["host"]) || preg_match("~^https?://[A-Za-z0-9._\\[\\]:-]+$~D", $argv[1]) !== 1) exit(1);' "$origin" \
    || die 'The origin must be an exact http(s) URL, without a path or trailing slash.'

prompt vhost 'Apache VirtualHost file for this installation'
[[ "$vhost" == /* && -f "$vhost" ]] || die 'The VirtualHost must be an existing file with an absolute path.'
vhost="$(realpath "$vhost")"
prompt config_dir 'GLPI_CONFIG_DIR' "$glpi_root/config"
prompt files_dir 'GLPI files directory' "$glpi_root/files"
for path in "$glpi_root" "$vhost" "$config_dir" "$files_dir"; do
    [[ "$path" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "The path contains unsupported characters: $path"
done
[[ -d "$config_dir" && -d "$files_dir" ]] || die 'The config and files directories must exist.'
config_dir="$(realpath "$config_dir")"
files_dir="$(realpath "$files_dir")"
for path in "$glpi_root" "$vhost" "$config_dir" "$files_dir"; do
    [[ "$path" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "The resolved path contains unsupported characters: $path"
done
[[ "$config_dir" != "$glpi_root/public" && "$config_dir" != "$glpi_root/public/"* \
    && "$files_dir" != "$glpi_root/public" && "$files_dir" != "$glpi_root/public/"* ]] \
    || die 'Config and files must be outside public/.'
state_dir="$files_dir/_plugins/notificationbell/revisions"

default_user='www-data'
id apache >/dev/null 2>&1 && default_user='apache'
prompt web_user 'PHP/Apache user' "$default_user"
id "$web_user" >/dev/null 2>&1 || die "User $web_user does not exist."
[[ "$web_user" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]] || die 'Invalid username.'
web_group="$(id -gn "$web_user")"
runuser -u "$web_user" -- test -x "$config_dir" || die "$web_user cannot access $config_dir."
runuser -u "$web_user" -- test -w "$files_dir" || die "$web_user cannot write to $files_dir."
runuser -u "$web_user" -- test -r "$glpi_root/plugins/notificationbell/service/relay.php" \
    || die "$web_user cannot read the NotificationBell relay."

default_service='apache2'
command -v httpd >/dev/null 2>&1 && default_service='httpd'
prompt apache_service 'Apache systemd service' "$default_service"
[[ "$apache_service" =~ ^[A-Za-z0-9_.@-]+$ ]] || die 'Invalid service name.'
prompt port 'Local relay port' '8091'
[[ "$port" =~ ^[0-9]+$ ]] && ((port >= 1 && port <= 65535)) || die 'The port must be between 1 and 65535.'

php_bin="$(command -v php)"
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die 'The relay requires PHP CLI 8.2 or later.'
php -l "$glpi_root/plugins/notificationbell/service/relay.php" >/dev/null
apachectl_bin="$(command -v apachectl || command -v apache2ctl || true)"
[[ -n "$apachectl_bin" ]] || die 'Missing apachectl or apache2ctl.'
modules="$("$apachectl_bin" -M 2>/dev/null || true)"
if [[ -z "$modules" ]]; then
    if command -v httpd >/dev/null 2>&1; then
        modules="$(httpd -M 2>/dev/null || true)"
    elif command -v apache2 >/dev/null 2>&1; then
        modules="$(apache2 -M 2>/dev/null || true)"
    fi
fi
[[ -n "$modules" ]] || die 'Could not retrieve the Apache module list.'
for module in proxy_module proxy_http_module headers_module setenvif_module env_module; do
    grep -q "$module" <<< "$modules" || die "Apache requires $module to be enabled."
done

[[ "$(grep -Ec '^[[:space:]]*<VirtualHost[[:space:]]' "$vhost")" == 1 \
    && "$(grep -Ec '^[[:space:]]*</VirtualHost>[[:space:]]*$' "$vhost")" == 1 ]] \
    || die 'The file must contain exactly one VirtualHost.'
# Apache configs copied from Windows can end each line with CRLF.
docroot="$(awk 'tolower($1)=="documentroot" {gsub(/[\r"]/, "", $2); print $2}' "$vhost")"
[[ -n "$docroot" && -d "$docroot" && "$(realpath "$docroot")" == "$glpi_root/public" ]] \
    || die 'The VirtualHost DocumentRoot does not match the GLPI public/ path.'
[[ "$(grep -Ec '^[[:space:]]*CustomLog[[:space:]]' "$vhost")" == 1 ]] \
    || die 'The VirtualHost must have a single CustomLog to exclude SSE capabilities from the log.'
if grep -Eq '^[[:space:]]*(TransferLog|CustomLog.*(env=|expr=|#))' "$vhost"; then
    die 'The VirtualHost already uses a custom access log rule; edit SSE logging manually.'
fi
if grep -q 'notificationbell-sse.inc' "$vhost"; then
    die 'The VirtualHost already contains the NotificationBell SSE configuration.'
fi

snippet="$(dirname "$vhost")/notificationbell-sse.inc"
env_dir='/etc/notificationbell'
env_file="$env_dir/relay.env"
php_config="$config_dir/notificationbell.php"
unit='/etc/systemd/system/notificationbell.service'
for file in "$snippet" "$env_file" "$php_config" "$unit"; do
    [[ ! -e "$file" ]] || die "$file already exists; it will not be overwritten."
done

if command -v getenforce >/dev/null 2>&1 && [[ "$(getenforce)" == Enforcing ]]; then
    if command -v getsebool >/dev/null 2>&1 \
        && ! getsebool httpd_can_network_connect 2>/dev/null | grep -q -- '--> on'; then
        die 'SELinux blocks the proxy: an administrator must enable httpd_can_network_connect.'
    fi
fi

printf 'GLPI: %s\nOrigin: %s\nVirtualHost: %s\nConfig: %s\nRevisions: %s\nWeb user: %s\nApache: %s\nLocal port: %s\n' \
    "$glpi_root" "$origin" "$vhost" "$php_config" "$state_dir" "$web_user" "$apache_service" "$port"
if ((dry_run)); then
    printf 'Validation complete. No files were modified.\n'
    exit 0
fi
((EUID == 0)) || die 'Run the installer with sudo/as root.'

stage="$(mktemp -d)"
backup="$vhost.notificationbell-$(date +%Y%m%d-%H%M%S).bak"
applied=0
cleanup() {
    local status=$?
    trap - EXIT
    if ((status != 0 && applied)); then
        printf 'Installation failed; restoring Apache and removing the new files.\n' >&2
        systemctl disable --now notificationbell.service >/dev/null 2>&1 || true
        if [[ -f "$backup" ]]; then cp -a "$backup" "$vhost" || true; fi
        rm -f -- "$snippet" "$env_file" "$php_config" "$unit"
        systemctl daemon-reload >/dev/null 2>&1 || true
        "$apachectl_bin" -t >/dev/null 2>&1 && systemctl reload "$apache_service" >/dev/null 2>&1 || true
    fi
    [[ -z "${stage:-}" ]] || rm -rf -- "$stage"
    exit "$status"
}
trap cleanup EXIT

secret="$(php -r 'echo bin2hex(random_bytes(32));')"
[[ "$secret" =~ ^[0-9a-f]{64}$ ]] || die 'Could not generate the SSE secret.'
cat > "$stage/relay.env" <<EOF
NB_STATE_DIR=$state_dir
NB_ORIGIN=$origin
NB_PORT=$port
NB_SECRET=$secret
EOF
cat > "$stage/notificationbell.php" <<EOF
<?php
// NotificationBell SSE: keep this file outside public/.
return [
    'state_dir' => '$state_dir',
    'url' => '/notificationbell-live/events',
    'secret' => '$secret',
];
EOF
cat > "$stage/notificationbell.service" <<EOF
[Unit]
Description=NotificationBell realtime relay
After=network.target

[Service]
Type=simple
User=$web_user
Group=$web_group
EnvironmentFile=$env_file
ExecStart=$php_bin $glpi_root/plugins/notificationbell/service/relay.php
Restart=always
RestartSec=3
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=read-only
PrivateTmp=true
UMask=0027

[Install]
WantedBy=multi-user.target
EOF
cat > "$stage/notificationbell-sse.inc" <<EOF
# NotificationBell SSE: include only from GLPI's VirtualHost.
ProxyPass "/notificationbell-live/events" "http://127.0.0.1:$port/events" retry=0 timeout=180 flushpackets=on
ProxyPassReverse "/notificationbell-live/events" "http://127.0.0.1:$port/events"
SetEnvIf Request_URI "^/notificationbell-live/events" NB_SSE=1
<Location "/notificationbell-live/events">
    Header always set Cache-Control "no-store"
    Header always set X-Content-Type-Options "nosniff"
    SetEnv no-gzip 1
</Location>
EOF
awk -v snippet="$snippet" '
    {
        eol = sub(/\r$/, "", $0) ? "\r" : ""
        if ($0 ~ /^[[:space:]]*CustomLog[[:space:]]/) {
            $0 = $0 " env=!NB_SSE"
        }
        if ($0 ~ /^[[:space:]]*<\/VirtualHost>[[:space:]]*$/) {
            printf "    Include %s%s\n", snippet, eol
        }
        printf "%s%s\n", $0, eol
    }
' "$vhost" > "$stage/vhost"

cp -a -- "$vhost" "$backup"
applied=1
install -d -m 0750 -o "$web_user" -g "$web_group" "$state_dir"
install -d -m 0700 -o root -g root "$env_dir"
install -m 0600 -o root -g root "$stage/relay.env" "$env_file"
install -m 0640 -o root -g "$web_group" "$stage/notificationbell.php" "$php_config"
install -m 0644 -o root -g root "$stage/notificationbell.service" "$unit"
install -m 0644 -o root -g root "$stage/notificationbell-sse.inc" "$snippet"
cp -- "$stage/vhost" "$vhost"
if command -v restorecon >/dev/null 2>&1; then
    restorecon -R "$state_dir" "$php_config" "$unit" "$snippet" "$vhost" >/dev/null
fi
php -l "$php_config" >/dev/null
"$apachectl_bin" -t
systemctl daemon-reload
systemctl enable --now notificationbell.service
systemctl is-active --quiet notificationbell.service || die 'The relay did not start; check journalctl.'
systemctl reload "$apache_service"

# An invalid capability must return 401 when Apache reaches the correct relay.
vhost_port="$(awk '/^[[:space:]]*<VirtualHost[[:space:]]/ { if (match($0, /:[0-9]+/)) print substr($0, RSTART + 1, RLENGTH - 1) }' "$vhost")"
server_name="$(awk 'tolower($1)=="servername" {gsub(/\r/, "", $2); print $2}' "$vhost")"
[[ "$vhost_port" =~ ^[0-9]+$ && -n "$server_name" ]] || die 'Could not verify the Apache VirtualHost.'
scheme='http'
grep -Eiq '^[[:space:]]*SSLEngine[[:space:]]+on' "$vhost" && scheme='https'
http_code="$(curl --noproxy '*' -ksS --connect-timeout 3 --max-time 5 -o /dev/null -w '%{http_code}' \
    -H "Host: $server_name" -H "Origin: $origin" \
    "$scheme://127.0.0.1:$vhost_port/notificationbell-live/events?token=invalid")"
[[ "$http_code" == 401 ]] || die "The SSE proxy returned $http_code instead of 401."

printf '\nSSE is active. VirtualHost backup: %s\n' "$backup"
printf 'Reload a GLPI page: the bell indicator should show "Live".\n'
printf 'Check: systemctl status notificationbell --no-pager\n'
