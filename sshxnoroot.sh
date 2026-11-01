#!/bin/sh
# Installs the SSHX x86_64 musl binary for the current user, without root.

set -eu

URL="https://s3.amazonaws.com/sshx/sshx-x86_64-unknown-linux-musl.tar.gz"

# Some restricted SSH sessions omit HOME. Resolve it before using user paths.
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

case "$HOME" in
	/) ;;
	*) HOME="${HOME%/}" ;;
esac

BIN_DIR="${XDG_BIN_HOME:-$HOME/.local/bin}"

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

main() {
	if [ "$(id -u)" -eq 0 ]; then
		echo "Run this script as the non-root user that will use SSHX." >&2
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
			echo "This archive supports x86_64/amd64 only; detected $(uname -m)." >&2
			exit 1
			;;
	esac

	if ! command -v tar >/dev/null 2>&1; then
		echo "This installer requires tar." >&2
		exit 1
	fi

	temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/sshx.XXXXXX")"
	trap 'rm -rf "$temporary_directory"' EXIT HUP INT TERM
	archive="$temporary_directory/sshx.tar.gz"

	echo "Downloading SSHX binary..."
	download "$archive"
	tar -xzf "$archive" -C "$temporary_directory"
	sshx_binary="$(find "$temporary_directory" -type f -name sshx -print -quit)"

	if [ -z "$sshx_binary" ]; then
		echo "Downloaded archive does not contain the expected 'sshx' binary." >&2
		exit 1
	fi

	mkdir -p "$BIN_DIR"
	install -m 0755 "$sshx_binary" "$BIN_DIR/sshx"

	echo "SSHX installed to $BIN_DIR/sshx"
	echo "Run it with:"
	echo "  $BIN_DIR/sshx"
	echo "Add $BIN_DIR to PATH to run 'sshx' without its full path."
}

main "$@"
