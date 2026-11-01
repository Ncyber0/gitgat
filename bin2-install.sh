#!/bin/sh
# Installs Tailscale 1.104.4 for the current user without root access.
# This uses userspace networking, so it does not create a system VPN interface.

set -eu

VERSION="1.104.4"
ARCH="amd64"
URL="https://pkgs.tailscale.com/stable/tailscale_${VERSION}_${ARCH}.tgz"

# Some restricted SSH sessions omit HOME. Resolve it from the account entry
# before enabling nounset-dependent path expansions below.
if [ -z "${HOME-}" ]; then
	current_uid="$(id -u)"
	if command -v getent >/dev/null 2>&1; then
		HOME="$(getent passwd "$current_uid" | awk -F: 'NR == 1 { print $6 }')"
	else
		HOME="$(awk -F: -v uid="$current_uid" '$3 == uid { print $6; exit }' /etc/passwd)"
	fi
fi

if [ -z "${HOME-}" ] || [ ! -d "$HOME" ]; then
	echo "Cannot determine a valid home directory for the current user. Set HOME and run again." >&2
	exit 1
fi

export HOME
case "$HOME" in
	/) ;;
	*) HOME="${HOME%/}" ;;
esac

BASE_DIR="${XDG_DATA_HOME:-$HOME/.local/share}/tailscale"
BIN_DIR="${XDG_BIN_HOME:-$HOME/.local/bin}"
SOCKET="$BASE_DIR/tailscaled.sock"
STATE="$BASE_DIR/tailscaled.state"
SOCKS5_SERVER="127.0.0.1:1055"
SERVICE_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"
SERVICE_FILE="$SERVICE_DIR/tailscaled.service"

download() {
	destination="$1"
	if command -v curl >/dev/null 2>&1; then
		curl --fail --location --silent --show-error "$URL" -o "$destination"
	elif command -v wget >/dev/null 2>&1; then
		wget -qO "$destination" "$URL"
	else
		echo "This installer requires curl or wget." >&2
		exit 1
	fi
}

install_user_service() {
	command -v systemctl >/dev/null 2>&1 || return
	systemctl --user show-environment >/dev/null 2>&1 || return

	mkdir -p "$SERVICE_DIR"
	cat > "$SERVICE_FILE" <<EOF
[Unit]
Description=Tailscale node agent (userspace networking)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=$BIN_DIR/tailscaled --tun=userspace-networking --socks5-server=$SOCKS5_SERVER --state=$STATE --socket=$SOCKET
Restart=on-failure

[Install]
WantedBy=default.target
EOF
	systemctl --user daemon-reload
	systemctl --user enable --now tailscaled
}

main() {
	if [ "$(id -u)" -eq 0 ]; then
		echo "Run this script as the non-root user that will use Tailscale." >&2
		exit 1
	fi

	case "$(uname -s)" in
		Linux) ;;
		*)
			echo "This script only supports Linux." >&2
			exit 1
			;;
	esac

	case "$(uname -m)" in
		x86_64|amd64) ;;
		*)
			echo "This archive supports amd64/x86_64 only; detected $(uname -m)." >&2
			exit 1
			;;
	esac

	if ! command -v tar >/dev/null 2>&1; then
		echo "This installer requires tar." >&2
		exit 1
	fi

	temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/tailscale-user.XXXXXX")"
	trap 'rm -rf "$temporary_directory"' EXIT HUP INT TERM
	archive="$temporary_directory/tailscale.tgz"

	echo "Downloading Tailscale ${VERSION} userspace binaries..."
	download "$archive"
	tar -xzf "$archive" -C "$temporary_directory"
	tailscale_binary="$(find "$temporary_directory" -type f -name tailscale -print -quit)"
	tailscaled_binary="$(find "$temporary_directory" -type f -name tailscaled -print -quit)"

	if [ -z "$tailscale_binary" ] || [ -z "$tailscaled_binary" ]; then
		echo "Downloaded archive does not contain the expected Tailscale binaries." >&2
		exit 1
	fi

	mkdir -p "$BIN_DIR" "$BASE_DIR"
	install -m 0755 "$tailscale_binary" "$BIN_DIR/tailscale"
	install -m 0755 "$tailscaled_binary" "$BIN_DIR/tailscaled"

	if install_user_service; then
		echo "Tailscaled user service is running."
	else
		echo "A systemd user session was not detected. Start the daemon manually with:"
		echo "  $BIN_DIR/tailscaled --tun=userspace-networking --socks5-server=$SOCKS5_SERVER --state=$STATE --socket=$SOCKET &"
	fi

	echo "Connect this user account with:"
	echo "  $BIN_DIR/tailscale --socket=$SOCKET up"
	echo "Applications can use the Tailscale network through SOCKS5 at $SOCKS5_SERVER."
	echo "Add $BIN_DIR to PATH to run 'tailscale' without its full path."
}

main "$@"
