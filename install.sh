#!/bin/sh
# Installs Tailscale on EL9 hosts missing /etc/os-release.
# Run as root, or with sudo: sudo sh install.sh

set -eu

TRACK="${TRACK:-stable}"
TAILSCALE_VERSION="${TAILSCALE_VERSION:-}"

case "$TRACK" in
	stable|unstable) ;;
	*)
		echo "TRACK must be 'stable' or 'unstable'." >&2
		exit 1
		;;
esac

run_as_root() {
	if [ "$(id -u)" -eq 0 ]; then
		"$@"
	elif command -v sudo >/dev/null 2>&1; then
		sudo "$@"
	else
		echo "Root access is required to create /etc/os-release. Run this script with sudo." >&2
		exit 1
	fi
}

ensure_el9_os_release() {
	[ -f /etc/os-release ] && return

	kernel_release="$(uname -r 2>/dev/null || true)"
	case "$kernel_release" in
		*.el9|*.el9.*)
			echo "No /etc/os-release found; creating minimal RHEL 9 compatibility metadata."
			run_as_root sh -c 'umask 022; cat > /etc/os-release <<"EOF"
NAME="Red Hat Enterprise Linux"
ID="rhel"
ID_LIKE="fedora"
VERSION_ID="9"
PLATFORM_ID="platform:el9"
PRETTY_NAME="Red Hat Enterprise Linux 9"
EOF'
			;;
		*)
			echo "No /etc/os-release found, but kernel '$kernel_release' is not recognized as EL9; leaving the system unchanged." >&2
			;;
	esac
}

download_and_run_tailscale_installer() {
	temp_file="$(mktemp "${TMPDIR:-/tmp}/tailscale-install.XXXXXX")"
	trap 'rm -f "$temp_file"' EXIT HUP INT TERM

	if command -v curl >/dev/null 2>&1; then
		curl -fsSL https://tailscale.com/install.sh -o "$temp_file"
	elif command -v wget >/dev/null 2>&1; then
		wget -qO "$temp_file" https://tailscale.com/install.sh
	else
		echo "This installer requires curl or wget to download the official Tailscale installer." >&2
		exit 1
	fi

	TRACK="$TRACK" TAILSCALE_VERSION="$TAILSCALE_VERSION" sh "$temp_file"
}

ensure_el9_os_release
download_and_run_tailscale_installer
