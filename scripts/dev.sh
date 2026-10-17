#!/usr/bin/env bash
#
# Levanta la API y el worker de la cola juntos, en primer plano.
# Ctrl+C detiene los dos.
#
#   ./scripts/dev.sh            API en http://127.0.0.1:8000
#   PORT=8080 ./scripts/dev.sh  en otro puerto
#
# No hace falta si usas Laravel Herd (que ya sirve la API): en ese caso solo
# necesitas el worker (`php artisan queue:work --timeout=300`).
#
# Por qué no `php artisan serve`: lanza el servidor en un proceso hijo sin
# las opciones -d ni PHP_INI_SCAN_DIR, así que se queda con el límite de
# subida por defecto de PHP (2 MB) y las capturas grandes fallan con
# «The image failed to upload».

set -euo pipefail

PROYECTO="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${PORT:-8000}"
HOST="${HOST:-127.0.0.1}"
PHP="${PHP:-php}"

if ! command -v "$PHP" >/dev/null 2>&1; then
  echo "No encuentro php en el PATH (o en \$PHP)." >&2
  exit 1
fi

cd "$PROYECTO"

if [[ ! -f .env ]]; then
  echo "Falta el .env. Sigue los pasos de instalación del README." >&2
  exit 1
fi

pids=()
terminar() {
  # ${pids[@]+...}: con set -u, bash 3.2 (el de macOS) falla con arrays vacíos.
  for pid in ${pids[@]+"${pids[@]}"}; do kill "$pid" 2>/dev/null || true; done
}
trap terminar EXIT INT TERM

# Worker: sin él las referencias se quedan en «pending» sin ningún error.
"$PHP" artisan queue:work --tries=2 --timeout=300 &
pids+=($!)

# API. El servidor de PHP necesita arrancar desde public/.
(
  cd public
  exec "$PHP" \
    -d upload_max_filesize=20M \
    -d post_max_size=21M \
    -S "$HOST:$PORT" \
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
) &
pids+=($!)

echo "API:    http://$HOST:$PORT/api/health"
echo "Worker: escuchando la cola (Ctrl+C para detener todo)"

# Si cualquiera de los dos se cae, se detiene todo. (Sin `wait -n`: no
# existe en el bash 3.2 que trae macOS.)
while kill -0 "${pids[0]}" 2>/dev/null && kill -0 "${pids[1]}" 2>/dev/null; do
  sleep 1
done
echo "Uno de los procesos terminó; deteniendo el otro." >&2
exit 1
