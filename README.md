# datafront · backend

API de **datafront**, una bóveda personal de capturas de interfaces (*fronts*) que te gustan. Subes una captura, una IA que ya tienes instalada en tu máquina (**Claude Code** o **Codex**) la mira y la clasifica, y el catálogo resultante sirve de referencia para que otra IA genere los fronts de tus apps.

Este repo es solo el backend (Laravel). La interfaz está en el repo del frontend: **[Arzival/datafront](https://github.com/Arzival/datafront)**. **Necesitas los dos.** Sin backend, el frontend muestra datos de ejemplo y no guarda ni clasifica nada.

> **Todo corre en tu máquina.** No hay servidor central ni cuentas. La clasificación usa el CLI de IA que tú tengas instalado y con sesión iniciada, contra tu propia suscripción. No hace falta ninguna API key.

---

## Índice

- [Cómo encaja todo](#cómo-encaja-todo)
- [Requisitos](#requisitos)
- [Instalación paso a paso](#instalación-paso-a-paso)
- [Instrucciones para agentes de IA](#instrucciones-para-agentes-de-ia)
- [Uso diario](#uso-diario)
- [Configuración (.env)](#configuración-env)
- [API](#api)
- [Cómo se clasifica una captura](#cómo-se-clasifica-una-captura)
- [Dónde viven tus datos](#dónde-viven-tus-datos)
- [Seguridad](#seguridad)
- [Problemas frecuentes](#problemas-frecuentes)
- [Comandos y pruebas](#comandos-y-pruebas)

---

## Cómo encaja todo

```
┌──────────────────────┐   HTTP    ┌──────────────────────────┐
│ frontend (datafront) │ ────────▶ │ API Laravel (este repo)  │
│ React + Vite :5174   │           │ :8000 o Herd *.test      │
└──────────────────────┘           └────────────┬─────────────┘
                                                │ encola un trabajo
                                                ▼
                                   ┌──────────────────────────┐
                                   │ worker de la cola        │  ← sin esto no
                                   │ php artisan queue:work   │    se clasifica nada
                                   └────────────┬─────────────┘
                                                │ ejecuta
                                                ▼
                                   ┌──────────────────────────┐
                                   │ claude -p …  o  codex exec│
                                   │ (tu CLI, tu sesión)       │
                                   └──────────────────────────┘
```

Para que la app funcione tienen que estar vivas **tres** piezas: la **API**, el **worker** y el **frontend**. `./scripts/dev.sh` levanta las dos primeras juntas.

## Requisitos

| Qué | Versión | Cómo comprobarlo | Para qué |
|-----|---------|------------------|----------|
| PHP | 8.3 o superior | `php -v` | Laravel 13 |
| Composer | 2.x | `composer -V` | dependencias PHP |
| Extensión `pdo_sqlite` | — | `php -m \| grep -i sqlite` | la base de datos es un archivo SQLite |
| Python 3 + **Pillow** (con WebP) | Pillow 9+ | `python3 -c "from PIL import features; print(features.check('webp'))"` → `True` | miniaturas, copias reducidas y dHash |
| **Claude Code** y/o **Codex CLI** | — | `command -v claude` / `command -v codex` | la IA que clasifica. Basta con **uno** |
| Node.js | 20.19+ o 22.12+ | `node -v` | solo para el frontend (otro repo) |

Sistema: probado en **macOS**. En Linux debería funcionar igual, salvo los scripts de servicio, que usan `launchd`. En Windows, usa WSL.

## Instalación paso a paso

Los comandos se ejecutan desde la raíz de este repo.

### 1. Clonar e instalar dependencias

```bash
git clone https://github.com/Arzival/datafromback.git datafrontback
cd datafrontback
composer install
```

### 2. Crear el `.env`, la clave y la base de datos

```bash
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
```

### 3. Instalar Pillow (si el paso de requisitos dio error)

```bash
python3 -m pip install --user Pillow
```

Si `pip` no deja instalar en el Python del sistema (error *externally-managed-environment*), usa un entorno virtual y apunta `VAULT_PYTHON_BIN` a su Python:

```bash
python3 -m venv .venv-pillow && .venv-pillow/bin/pip install Pillow
# en el .env:  VAULT_PYTHON_BIN=/ruta/absoluta/a/datafrontback/.venv-pillow/bin/python
```

### 4. Decirle al backend dónde están tus binarios

```bash
command -v claude    # ej. /opt/homebrew/bin/claude
command -v codex     # ej. /opt/homebrew/bin/codex
command -v python3   # el que tenga Pillow
```

Copia esas **rutas absolutas** al `.env`:

```dotenv
VAULT_CLAUDE_BIN=/opt/homebrew/bin/claude
VAULT_CODEX_BIN=/opt/homebrew/bin/codex
VAULT_PYTHON_BIN=/usr/bin/python3
```

Tienen que ser absolutas porque el proceso PHP, sobre todo bajo Herd o launchd, no hereda el `PATH` de tu terminal.

### 5. Iniciar sesión en tu CLI de IA (una sola vez)

El backend ejecuta el CLI tal cual. Si no tiene sesión, la clasificación falla.

```bash
claude            # abre Claude Code; inicia sesión y sal
codex login       # para Codex
```

Comprueba que responden sin pedir nada:

```bash
claude -p "responde solo: ok"
codex exec --skip-git-repo-check "responde solo: ok"
```

### 6. Levantar la API y el worker

**Opción A — sin Herd (recomendada para empezar):**

```bash
./scripts/dev.sh               # API en http://127.0.0.1:8000 + worker
```

Déjalo corriendo en una terminal. Ctrl+C detiene los dos.

**Opción B — con [Laravel Herd](https://herd.laravel.com) (macOS/Windows):** Herd sirve la API en `http://<carpeta>.test`. Solo te falta el worker:

```bash
php artisan queue:work --tries=2 --timeout=300
# o, en macOS, déjalo como servicio que arranca solo:
./scripts/worker-servicio.sh instalar
```

Con Herd, pon en el `.env` `APP_URL=http://<carpeta>.test`, y en el frontend `VITE_API_URL=http://<carpeta>.test/api`.

> **No uses `php artisan serve`.** Arranca el servidor sin poder subir el límite de subida de PHP, que es de 2 MB, así que las capturas grandes fallan con *«The image failed to upload»*. `scripts/dev.sh` lo sube a 20 MB.

### 7. Verificar

```bash
curl -s http://127.0.0.1:8000/api/health
# {"status":"ok",...}

curl -s http://127.0.0.1:8000/api/providers
# cada proveedor con "available": true; si es false, "unavailableReason" dice por qué
```

Prueba completa con una imagen cualquiera:

```bash
curl -s -F image=@/ruta/a/una/captura.png -F provider_id=claude-cli -F model=sonnet \
  -H "Accept: application/json" http://127.0.0.1:8000/api/references
# → {"id":"1","status":"pending",...}

# Unos 30-90 s después:
curl -s http://127.0.0.1:8000/api/references/1 | python3 -m json.tool | head -20
# → "status": "ready", con título, resumen y tags
```

Si se queda en `pending` para siempre, el worker no está corriendo. Ver [Problemas frecuentes](#problemas-frecuentes).

### 8. Levantar el frontend

Sigue el README de **[Arzival/datafront](https://github.com/Arzival/datafront)**. En resumen: `npm install`, crear un `.env` con `VITE_API_URL=http://127.0.0.1:8000/api` y `npm run dev`. Después abre http://localhost:5174.

---

## Instrucciones para agentes de IA

Si eres un agente (Claude Code, Codex, Cursor…) y te pidieron «clona y deja lista esta app», haz exactamente esto:

1. **Clona los dos repos como carpetas hermanas:** `datafrontback` (este) y `datafront` (el frontend).
2. **Comprueba los requisitos** con los comandos de la tabla de [Requisitos](#requisitos). Si falta PHP, Composer, Node o Pillow, **díselo al usuario y pide permiso** antes de instalar nada a nivel de sistema.
3. **Sigue los pasos 1 a 4 de la instalación tal cual.** En el paso 4, rellena el `.env` con las rutas que devuelva `command -v`. **No las inventes.** Si `claude` o `codex` no existen, deja la variable como está: el backend marca ese proveedor como no disponible y la app sigue funcionando con el otro.
4. **El paso 5, iniciar sesión, es interactivo y lo hace el usuario.** No intentes iniciar sesión por él. Pídele que ejecute `claude` o `codex login` y que te avise.
5. **Levanta la API y el worker** con `./scripts/dev.sh` en segundo plano, o con `php artisan queue:work --tries=2 --timeout=300` si el usuario usa Herd.
6. **Verifica** con los `curl` del paso 7. La tarea está terminada cuando:
   - `GET /api/health` responde `"status":"ok"`;
   - `GET /api/providers` tiene al menos un proveedor con `"available": true`;
   - y, si el usuario ya inició sesión en su CLI, una captura de prueba llega a `"status": "ready"`.
7. **Prepara el frontend** según su README y comprueba que http://localhost:5174 **no** muestra el aviso «Modo de ejemplo».
8. **Ejecuta las pruebas** con `php artisan test`. Deben pasar todas. Usan una base en memoria y un disco falso, así que no tocan los datos del usuario.

Reglas para agentes:

- **No borres `storage/app/vault/` ni `database/database.sqlite`.** Ahí están las capturas y el catálogo del usuario. No hay otra copia.
- **No uses `php artisan migrate:fresh`, `migrate:reset` ni `db:wipe`** si la base ya tiene datos. Antes de cualquier migración sobre datos reales, haz copia: `sqlite3 database/database.sqlite ".backup storage/app/respaldos/antes-de-migrar.sqlite"`.
- **No expongas la API a internet** (`--host 0.0.0.0`, túneles, despliegues). No tiene autenticación. Ver [Seguridad](#seguridad).
- **No cambies los modelos de IA ni el vocabulario** (`config/vault.php`) salvo que el usuario lo pida.
- Para borrar referencias, usa la papelera (`DELETE /api/references/{id}`). El borrado definitivo (`/forever`) es irreversible: pide confirmación al usuario.

## Uso diario

| Quieres… | Haz… |
|----------|------|
| Arrancar todo en desarrollo | `./scripts/dev.sh` aquí + `npm run dev` en el frontend |
| Que el worker arranque solo al iniciar sesión (macOS) | `./scripts/worker-servicio.sh instalar` |
| Ver si el worker está vivo | `./scripts/worker-servicio.sh estado` |
| Ver qué está haciendo | `./scripts/worker-servicio.sh logs` (o `storage/logs/queue-worker.log`) |
| Reiniciarlo tras cambiar código | `./scripts/worker-servicio.sh reiniciar` |
| Quitar el servicio | `./scripts/worker-servicio.sh quitar` |

El frontend tiene su propio script de servicio. Consulta su README.

## Configuración (.env)

Además de las variables estándar de Laravel:

| Variable | Por defecto | Qué es |
|----------|-------------|--------|
| `APP_URL` | `http://127.0.0.1:8000` | URL de esta API. Con Herd, `http://<carpeta>.test` |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5174,…` | Orígenes del frontend autorizados. Añade el tuyo si cambias el puerto |
| `VAULT_CLAUDE_BIN` | `/opt/homebrew/bin/claude` | Ruta absoluta al CLI de Claude Code |
| `VAULT_CODEX_BIN` | `/opt/homebrew/bin/codex` | Ruta absoluta al CLI de Codex |
| `VAULT_PYTHON_BIN` | `/usr/bin/python3` | Python con Pillow |
| `VAULT_INBOX_PATH` | `$HOME/Sync/datafront-inbox` | *Opcional.* Carpeta de entrada (ver abajo) |
| `VAULT_INBOX_SETTLE` | `20` | Segundos que un archivo de la bandeja debe llevar quieto antes de poder importarlo |

**Modelos.** Están en `config/vault.php`, en `providers`:

- **Claude:** `opus`, `sonnet` (por defecto) y `haiku`.
- **Codex:** `gpt-6.1-sol` (por defecto) y `gpt-6-astra`. Si tu cuenta de Codex no tiene esos modelos, edita esa lista.

**Bandeja (opcional).** Es una carpeta a la que otra máquina deja capturas; en el proyecto original es un homelab que las sincroniza con Syncthing. Desde la vista «Bandeja» decides una por una si pasan a la bóveda o se rechazan. Los archivos siguen el formato `AAAAMMDD-HHMMSS__<hash>__pendiente.png`, y puede haber un `.txt` hermano con la URL en la línea 1 y el título en la 2. Si la carpeta no existe, la vista sale vacía y no pasa nada más.

## API

Todas bajo `/api`. Envía siempre `Accept: application/json`.

| Método | Ruta | Qué hace |
|--------|------|----------|
| GET | `/health` | Comprobación de vida |
| GET | `/providers` | Motores de IA, sus modelos y si están disponibles |
| GET | `/references` | Catálogo, sin lo que está en la papelera |
| POST | `/references` | Sube una captura (multipart): `image` (obligatorio; png, jpg, webp, avif o gif; máx. 20 MB), `provider_id` (`claude-cli` o `codex-cli`), `model`, `source_url`. Responde `201` y la encola |
| GET | `/references/{id}` | Una referencia |
| POST | `/references/{id}/reclassify` | Vuelve a clasificar (`provider_id`, `model`) |
| DELETE | `/references/{id}` | **Manda a la papelera.** No borra archivos ni etiquetas |
| GET | `/references/trash` | Lo que hay en la papelera |
| POST | `/references/{id}/restore` | Saca de la papelera |
| DELETE | `/references/{id}/forever` | Borrado definitivo con sus archivos. Solo funciona con lo que ya está en la papelera; si no, responde `409` |
| GET | `/references/{id}/image` · `/thumb` | Original y miniatura WebP |
| GET | `/inbox` | Capturas pendientes de la bandeja |
| POST | `/inbox/import` | Pasa una de la bandeja a la bóveda (`filename`, `provider_id`, `model`) |
| POST | `/inbox/reject` | La rechaza (`filename`, `reason`: `borrosa` o `irrelevante`) |

Cada referencia incluye `filePath`, la **ruta absoluta del original en disco**. Es lo que permite que el prompt que copias desde el frontend le diga a otra IA dónde abrir la imagen.

## Cómo se clasifica una captura

1. Al subirla, se calcula su sha256. Si ya existía, se devuelve la misma referencia, y si estaba en la papelera, se restaura. Después se guarda el original, se genera una miniatura WebP de 640 px con Pillow y se encola el trabajo.
2. El worker ejecuta el CLI elegido:
   - `claude -p <prompt> --output-format json --allowedTools Read --model <modelo>`
   - `codex exec -i <imagen> --sandbox read-only -m <modelo>`
3. Si la imagen mide más de 2560 px o pesa más de 4.5 MB, la IA recibe una **copia reducida** (`storage/app/vault/vision/`). El original no se toca.
4. La respuesta, un JSON, se normaliza contra un **vocabulario controlado** (`config/vault.php`). Así «modo oscuro» y «dark» acaban ambos en `oscuro` y los filtros del catálogo funcionan. Lo que no encaja se guarda aparte; `php artisan vault:vocab-gaps` lo lista.
5. Categorías y cuántos valores admite cada una:

   | Categoría | Valores |
   |-----------|---------|
   | `screen_type` | 1 |
   | `style` | 1–3 |
   | `palette` (en hex) | 2–5 |
   | `layout` | 1–2 |
   | `components` | 2–6 |
   | `density` | 1 |
   | `industry` | 1 |

Cada clasificación tarda entre 30 y 90 s según el modelo. El CLI tiene 180 s de límite.

## Dónde viven tus datos

| Qué | Dónde |
|-----|-------|
| Originales | `storage/app/vault/images/<sha256>.<ext>` |
| Miniaturas | `storage/app/vault/thumbs/` |
| Copias reducidas para la IA | `storage/app/vault/vision/` |
| Catálogo, etiquetas y papelera | `database/database.sqlite` |

Nada de esto está en Git. **Haz copia de seguridad tú.** Por ejemplo:

```bash
mkdir -p storage/app/respaldos
sqlite3 database/database.sqlite ".backup storage/app/respaldos/$(date +%Y%m%d).sqlite"
```

…y copia `storage/app/vault/` a otro disco. Borrar desde la app nunca elimina al momento: manda a la papelera, que conserva archivo y etiquetas hasta que la vacíes a mano.

## Seguridad

- **La API no tiene autenticación.** Está pensada para correr solo en `127.0.0.1`. Quien llegue a ella puede ver tu catálogo, borrarlo y lanzar clasificaciones con **tu** CLI y **tu** suscripción.
- No la publiques en internet ni la abras a tu red local (`--host 0.0.0.0`, túneles tipo ngrok, despliegues) sin añadir antes autenticación.
- El CLI de Claude se ejecuta con `--allowedTools Read`, y el de Codex con `--sandbox read-only`: pueden leer la imagen, pero no escribir ni ejecutar nada.

## Problemas frecuentes

| Síntoma | Causa | Solución |
|---------|-------|----------|
| Las referencias se quedan en «En cola» / `pending` para siempre, sin error | El worker no está corriendo | `./scripts/dev.sh`, o `php artisan queue:work --tries=2 --timeout=300` |
| `"available": false` en `/api/providers` | La ruta del binario en el `.env` está mal | `command -v claude` y pon esa ruta en `VAULT_CLAUDE_BIN` |
| Falla con un mensaje de login o autenticación | El CLI no tiene sesión | Ejecuta `claude` o `codex login` en una terminal |
| «The image failed to upload» con capturas grandes | Límite de 2 MB de PHP (`php artisan serve`) | Usa `./scripts/dev.sh` o Herd. Ambos admiten hasta 20 MB |
| Las referencias se crean sin miniatura | Falta Pillow o `VAULT_PYTHON_BIN` apunta a otro Python | Ver paso 3 de la instalación |
| El frontend muestra «Modo de ejemplo» | No llega a la API | Revisa `VITE_API_URL` en el frontend y que `/api/health` responda |
| Error de CORS en la consola del navegador | El origen del frontend no está autorizado | Añádelo a `CORS_ALLOWED_ORIGINS` y ejecuta `php artisan config:clear` |
| Cambié código y el worker sigue igual | El worker carga el código una vez | Reinícialo: Ctrl+C y otra vez, o `./scripts/worker-servicio.sh reiniciar` |

## Comandos y pruebas

| Comando | Qué hace |
|---------|----------|
| `php artisan vault:export` | Genera `storage/app/vault/CATALOG.md` y `catalog.json`, con rutas absolutas, para dárselos a una IA |
| `php artisan vault:vocab-gaps` | Lista lo que la IA propuso y el vocabulario rechazó |
| `php artisan vault:export-gusto [--backfill]` | Exporta etiquetas y dHash para el filtro de gusto de la bandeja, si la usas |
| `php artisan test` | Pruebas (Pest). Usan SQLite en memoria y disco falso; necesitan Pillow |
| `vendor/bin/pint` | Formatea el código |
