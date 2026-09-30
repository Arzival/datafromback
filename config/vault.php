<?php

declare(strict_types=1);

/**
 * Configuración de la bóveda de referencias.
 *
 * El vocabulario controlado es lo que mantiene el catálogo filtrable: los
 * modelos de IA describen la misma pantalla con sinónimos distintos ("oscuro"
 * y "modo oscuro"), así que solo se aceptan valores de estas listas. Lo que
 * no encaje se guarda aparte para poder ampliar el vocabulario con datos
 * reales (`php artisan vault:vocab-gaps`).
 */
return [

    'disk' => 'vault',

    'thumbnail' => [
        'width' => 640,
        'quality' => 82,
    ],

    /** Segundos que se le dan a un CLI antes de darlo por colgado. */
    'classifier_timeout' => 180,

    /**
     * Cuántos valores se aceptan por categoría. La IA recibe estos límites en
     * el prompt y el backend los vuelve a aplicar al guardar.
     */
    'limits' => [
        'screen_type' => ['min' => 1, 'max' => 1],
        'style' => ['min' => 1, 'max' => 3],
        'palette' => ['min' => 2, 'max' => 5],
        'layout' => ['min' => 1, 'max' => 2],
        'components' => ['min' => 2, 'max' => 6],
        'density' => ['min' => 1, 'max' => 1],
        'industry' => ['min' => 1, 'max' => 1],
    ],

    /**
     * Vocabulario permitido. `palette` no aparece: acepta cualquier color hex.
     */
    'vocabulary' => [

        'screen_type' => [
            'dashboard', 'landing', 'checkout', 'formulario', 'listado', 'detalle',
            'ajustes', 'autenticación', 'onboarding', 'perfil', 'chat', 'calendario',
            'precios', 'feed', 'buscador', 'reproductor', 'mapa', 'panel de administración',
            'email', 'estado vacío', 'error', 'selector', 'checkout de suscripción',
        ],

        'style' => [
            'oscuro', 'claro', 'minimalista', 'editorial', 'brutalista', 'neumorfismo',
            'glassmorphism', 'retro', 'corporativo', 'lúdico', 'técnico', 'lujo',
            'degradados', 'alto contraste', 'monocromo', 'ilustrado', '3d', 'denso',
        ],

        'layout' => [
            'columna única', 'dos columnas', 'tres columnas', 'sidebar fijo',
            'sidebar colapsable', 'bento grid', 'grid de tarjetas', 'lista vertical',
            'secciones apiladas', 'split view', 'pantalla completa', 'sticky lateral',
            'tabs', 'wizard por pasos', 'asimétrico',
        ],

        'components' => [
            'navbar', 'sidebar', 'bottom nav', 'tabs', 'breadcrumbs', 'hero',
            'stat cards', 'data table', 'lista', 'tarjetas', 'formulario', 'input',
            'botón principal', 'botón secundario', 'modal', 'drawer', 'dropdown',
            'toast', 'badge', 'avatar', 'gráfica', 'progress ring', 'barra de progreso',
            'timeline', 'acordeón', 'carrusel', 'buscador', 'filtros', 'paginación',
            'footer', 'toggle', 'slider', 'calendario', 'mapa', 'chat', 'reproductor',
            'skeleton', 'tooltip', 'indicador de estado', 'icono de cierre',
        ],

        'density' => ['alta', 'media', 'baja'],

        'industry' => [
            'saas', 'fintech', 'salud', 'fitness', 'productividad', 'ecommerce',
            'educación', 'viajes', 'inmobiliaria', 'medios', 'redes sociales',
            'música', 'gaming', 'ia', 'logística', 'energía', 'gobierno',
            'sin sector',
        ],
    ],

    /**
     * Sinónimos que se traducen en lugar de rechazarse. Se comparan en
     * minúsculas y sin acentos, así que basta una entrada por concepto.
     */
    'aliases' => [
        'modo oscuro' => 'oscuro',
        'dark' => 'oscuro',
        'dark mode' => 'oscuro',
        'tema oscuro' => 'oscuro',
        'modo claro' => 'claro',
        'light' => 'claro',
        'minimal' => 'minimalista',
        'minimalismo' => 'minimalista',
        'limpio' => 'minimalista',
        'clean' => 'minimalista',
        'clean corporativo' => 'corporativo',
        'una columna' => 'columna única',
        'single column' => 'columna única',
        'lista vertical de tarjetas' => 'lista vertical',
        'apilado' => 'secciones apiladas',
        'card' => 'tarjetas',
        'cards' => 'tarjetas',
        'tarjetas de selección' => 'tarjetas',
        'tarjetas de dispositivos' => 'tarjetas',
        'lista de actividades' => 'lista',
        'botón cta' => 'botón principal',
        'cta' => 'botón principal',
        'boton principal' => 'botón principal',
        'primary button' => 'botón principal',
        'indicadores de conexión' => 'indicador de estado',
        'lectura de frecuencia cardíaca' => 'indicador de estado',
        'botón de cierre' => 'icono de cierre',
        'salud y fitness' => 'salud',
        'salud y bienestar' => 'salud',
        'bienestar' => 'salud',
        'health' => 'salud',
        'selección de actividad' => 'selector',
        'navegación inferior' => 'bottom nav',
        'tabla' => 'data table',
        'grafica' => 'gráfica',
        'chart' => 'gráfica',
        'form' => 'formulario',
        'order summary' => 'tarjetas',
    ],

    /**
     * Bandeja de entrada: carpeta que Syncthing comparte con el homelab.
     *
     * Los archivos llegan como `AAAAMMDD-HHMMSS__<hash>__pendiente.png`. Solo
     * se procesan los que están en estado `pendiente`; al decidir qué hacer con
     * ellos se renombra el estado, y ese nombre viaja de vuelta al homelab por
     * Syncthing. Es la única señal de que la referencia ya se trabajó, así que
     * nunca se borran ni se les cambia la fecha o el hash.
     */
    'inbox' => [

        'path' => env('VAULT_INBOX_PATH', env('HOME').'/Sync/datafront-inbox'),

        /**
         * Segundos que un archivo debe llevar sin cambios antes de dejar
         * tocarlo. Syncthing escribe a un temporal y renombra al terminar,
         * pero este margen evita leer una copia a medias.
         */
        'settle_seconds' => (int) env('VAULT_INBOX_SETTLE', 20),

        /** Extensiones que se aceptan de la bandeja. */
        'extensions' => ['png', 'jpg', 'jpeg', 'webp', 'avif'],

        /**
         * Motivos de rechazo. Viajan en el nombre (`__rechazada-borrosa`) para
         * que el homelab distinga un fallo de captura, que puede reintentar, de
         * una fuente mala, que debería dejar de visitar.
         */
        'reject_reasons' => [
            'borrosa' => 'Borrosa o ilegible',
            'irrelevante' => 'No me sirve como referencia',
        ],
    ],

    /**
     * Motores disponibles. `bin` se resuelve contra el PATH del proceso PHP,
     * que bajo Herd no incluye /opt/homebrew/bin, de ahí las rutas absolutas.
     */
    'providers' => [

        'claude-cli' => [
            'label' => 'Claude Code (CLI local)',
            'kind' => 'cli',
            'description' => 'Usa el binario `claude` instalado en tu máquina. No consume API key: va contra tu suscripción.',
            'bin' => env('VAULT_CLAUDE_BIN', '/opt/homebrew/bin/claude'),
            'default_model' => 'sonnet',
            'allow_custom_model' => false,
            'models' => [
                ['id' => 'opus', 'label' => 'Opus', 'hint' => 'El más capaz, más lento'],
                ['id' => 'sonnet', 'label' => 'Sonnet', 'hint' => 'Equilibrado, el recomendado'],
                ['id' => 'haiku', 'label' => 'Haiku', 'hint' => 'El más rápido'],
            ],
        ],

        'codex-cli' => [
            'label' => 'Codex (CLI local)',
            'kind' => 'cli',
            'description' => 'Usa el binario `codex` con `codex exec -i`. Tampoco consume API key propia.',
            'bin' => env('VAULT_CODEX_BIN', '/opt/homebrew/bin/codex'),
            'default_model' => 'gpt-6.1-sol',
            'allow_custom_model' => false,
            'models' => [
                ['id' => 'gpt-6.1-sol', 'label' => 'Sol', 'hint' => 'gpt-6.1-sol'],
                ['id' => 'gpt-6-astra', 'label' => 'Astra', 'hint' => 'gpt-6-astra'],
            ],
        ],
    ],
];
