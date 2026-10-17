#!/usr/bin/env bash
#
# Deja el worker de la cola como servicio de macOS (launchd): arranca solo al
# iniciar sesión y se relanza si se cae. Sin worker no se clasifica nada.
#
#   ./scripts/worker-servicio.sh instalar   crea y arranca el servicio
#   ./scripts/worker-servicio.sh quitar     lo detiene y lo elimina
#   ./scripts/worker-servicio.sh reiniciar  lo relanza (tras cambiar código)
#   ./scripts/worker-servicio.sh estado     dice si está vivo
#   ./scripts/worker-servicio.sh logs       sigue el log en directo
#
# Solo macOS. En Linux usa un servicio de systemd con
# `php artisan queue:work --tries=2 --timeout=300` en este directorio.

set -euo pipefail

LABEL="${DATAFRONT_WORKER_LABEL:-com.arzival.datafront.queue}"
PROYECTO="$(cd "$(dirname "$0")/.." && pwd)"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
LOG="$PROYECTO/storage/logs/queue-worker.log"
DOMINIO="gui/$(id -u)"

if [[ "$(uname)" != "Darwin" ]]; then
  echo "Este script es solo para macOS." >&2
  exit 1
fi

# launchd no hereda el PATH de la terminal: hay que dárselo explícito.
PHP="$(command -v php || true)"
if [[ -z "$PHP" ]]; then
  echo "No encuentro php en el PATH." >&2
  exit 1
fi

instalar() {
  mkdir -p "$(dirname "$PLIST")" "$(dirname "$LOG")"

  cat > "$PLIST" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>$LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>$PHP</string>
        <string>artisan</string>
        <string>queue:work</string>
        <string>--tries=2</string>
        <string>--timeout=300</string>
        <string>--max-time=3600</string>
    </array>
    <key>WorkingDirectory</key>
    <string>$PROYECTO</string>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>$(dirname "$PHP"):/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin</string>
    </dict>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>$LOG</string>
    <key>StandardErrorPath</key>
    <string>$LOG</string>
</dict>
</plist>
XML

  launchctl bootout "$DOMINIO/$LABEL" 2>/dev/null || true
  launchctl bootstrap "$DOMINIO" "$PLIST"
  echo "Worker instalado ($LABEL). Log: $LOG"
}

quitar() {
  launchctl bootout "$DOMINIO/$LABEL" 2>/dev/null || true
  rm -f "$PLIST"
  echo "Worker detenido y eliminado ($LABEL)."
}

reiniciar() {
  launchctl kickstart -k "$DOMINIO/$LABEL"
  echo "Worker reiniciado."
}

estado() {
  if launchctl print "$DOMINIO/$LABEL" >/dev/null 2>&1; then
    launchctl print "$DOMINIO/$LABEL" | grep -E $'^\t(state|pid) =' || true
  else
    echo "No está instalado. Usa: $0 instalar"
    exit 1
  fi
}

case "${1:-}" in
  instalar) instalar ;;
  quitar) quitar ;;
  reiniciar) reiniciar ;;
  estado) estado ;;
  logs) tail -f "$LOG" ;;
  *) sed -n '3,13p' "$0"; exit 1 ;;
esac
