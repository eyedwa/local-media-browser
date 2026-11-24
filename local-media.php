<?php
/**
 * Plugin Name: Local Media Browser
 * Description: Browse and select files directly from local server folders in the WordPress Media Library and ACF, without copying or duplicating them into wp-content/uploads.
 * Version: 1.0.0
 * Author: ivanvsanz
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Text Domain: local-media-browser
 */

if (!defined('ABSPATH')) {
    exit;
}

class LocalMedia
{
    const VERSION = '1.0.0';
    const OPTION = 'localmedia_settings';

    // MIME LIST ALLOWED 
    const TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'zip' => 'application/zip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
    ];


    private $folder_cache = null;
    private $protected_deletions = [];

    public function __construct()
    {
        // Menu and resources
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_media', [$this, 'enqueue_media_assets']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);

        // REST API 
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_post_localmedia_file', [$this, 'serve_file']);
        add_action('admin_post_nopriv_localmedia_file', [$this, 'serve_file']);

        // WordPress
        add_filter('get_attached_file', [$this, 'filter_attached_file'], 20, 2);
        add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 20, 2);
        add_filter('image_downsize', [$this, 'filter_image_downsize'], 20, 3);
        add_filter('wp_prepare_attachment_for_js', [$this, 'filter_prepare_js'], 20, 3);
        add_action('delete_attachment', [$this, 'before_delete_attachment'], 1, 2);
        add_filter('wp_delete_file', [$this, 'protect_original_file'], PHP_INT_MAX);
    }

    // Language

    public function get_lang()
    {
        $config = $this->config();
        $lang = $config['lang'] ?? 'auto';
        if (in_array($lang, ['es', 'en'], true)) {
            return $lang;
        }
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        return 0 === strpos(strtolower($locale), 'es') ? 'es' : 'en';
    }

    public function strings()
    {
        $es = [
            'plugin_title' => 'Local Media Browser',
            'menu_title' => 'Local Media',
            'settings_link' => 'Ajustes',
            'tab_title' => 'Archivos locales',
            'intro' => 'Explora archivos del servidor directamente desde el selector de WordPress y ACF. Solo se registra un adjunto al confirmar la selección; el archivo original nunca se copia ni se mueve.',
            'lang_label' => 'Idioma de la interfaz',
            'lang_auto' => 'Automático (según WordPress)',
            'base_label' => 'Carpeta base por defecto',
            'base_desc' => 'Ruta absoluta en el servidor donde se resolverán las carpetas relativas (ej. /var/www/media o C:/media).',
            'folders_label' => 'Carpetas permitidas (una por línea)',
            'folders_desc' => 'Usa rutas relativas (ej. ./documentos, ./imagenes) dentro de la carpeta base, o rutas absolutas existentes. Las subcarpetas se muestran automáticamente.',
            'add_folder_btn' => 'Añadir carpeta',
            'add_folder_prompt' => 'Nombre de la carpeta dentro de la base (ej. ./documentos):',
            'save_btn' => 'Guardar configuración',
            'security_notice' => 'Las carpetas relativas nuevas se crean solo dentro de la carpeta base. Guarda aquí únicamente archivos públicos, nunca documentos privados ni scripts.',
            'configured_paths' => 'Ver rutas configuradas activas',
            'browse_heading' => 'Explorar archivos (modo lectura)',
            'configure_link' => 'Configurar carpetas',
            'search_label' => 'Buscar en esta carpeta',
            'search_placeholder' => 'Nombre del archivo...',
            'type_label' => 'Tipo',
            'type_all' => 'Todos',
            'type_pdf' => 'PDF',
            'type_image' => 'Imágenes',
            'type_audio' => 'Audio',
            'type_video' => 'Vídeo',
            'refresh_btn' => 'Actualizar',
            'up_btn' => 'Subir',
            'prev_btn' => 'Anterior',
            'next_btn' => 'Siguiente',
            'page_info' => 'Página %1$d de %2$d (%3$d elementos)',
            'loading' => 'Cargando archivos...',
            'no_folders' => 'No hay carpetas configuradas. Añádelas en Medios > Local Media.',
            'empty_folder' => 'No hay archivos que coincidan en esta carpeta.',
            'status_inspect' => 'Abre una carpeta o pulsa un archivo para ver sus detalles.',
            'status_select' => 'Abre una carpeta o selecciona uno o más archivos.',
            'status_selected' => '%d archivo(s) seleccionado(s). Pulsa el botón inferior para confirmar.',
            'max_selection' => 'Puedes seleccionar un máximo de 50 archivos a la vez.',
            'not_allowed_field' => 'Tipo de archivo no admitido en este campo.',
            'details_title' => 'Detalles del archivo',
            'details_empty' => 'Los archivos se leen desde sus carpetas originales. Explorar no crea adjuntos en WordPress.',
            'file_url' => 'URL del archivo',
            'copy_link' => 'Copiar enlace',
            'link_copied' => 'Enlace copiado al portapapeles.',
            'link_selected' => 'Enlace seleccionado. Cópialo con Ctrl+C.',
            'open_file' => 'Abrir archivo',
            'no_copy_note' => 'El archivo no se copia a uploads. Su ubicación original no cambia.',
            'preparing' => 'Registrando archivos seleccionados...',
            'select_btn' => 'Seleccionar',
            'err_request' => 'No se pudo completar la petición. Revisa la conexión.',
            'err_base_invalid' => 'La carpeta base debe ser una ruta absoluta válida y no puede ser la raíz del sistema.',
            'err_path_invalid' => 'Ruta no válida (no se permiten ".." ni carpetas ocultas): ',
            'err_abs_unreadable' => 'La carpeta absoluta no existe o no se puede leer: ',
            'err_base_create' => 'No se pudo crear la carpeta base. Revisa los permisos del servidor.',
            'err_base_read' => 'WordPress no tiene permisos de lectura en la carpeta base.',
            'err_symlink' => 'La carpeta sale de la base mediante un enlace simbólico: ',
            'err_dir_create' => 'No se pudo crear la carpeta: ',
            'err_dir_unavail' => 'Carpeta no disponible: ',
            'err_folder_read' => 'No se pudo leer la carpeta solicitada.',
            'err_permission' => 'No tienes permiso para realizar esta acción.',
            'err_selection_cnt' => 'Selecciona entre 1 y 50 archivos.',
            'err_post_perm' => 'No tienes permiso para adjuntar archivos a esta entrada.',
            'err_acf_field' => 'El campo ACF no está disponible o no admite archivos.',
            'err_file_missing' => 'El archivo ya no existe o no se puede leer: ',
            'err_mime_denied' => 'WordPress no permite este tipo de archivo para tu usuario: ',
            'err_image_invalid' => 'La imagen no es válida o su extensión no coincide: ',
            'err_acf_images' => 'Este campo ACF solo admite imágenes.',
            'err_acf_parent' => 'Este archivo ya pertenece a otra entrada.',
            'err_lock' => 'Otro proceso está registrando este archivo. Inténtalo de nuevo.',
            'err_custom_toolbar' => 'Este selector usa una acción personalizada incompatible.',
        ];

        $en = [
            'plugin_title' => 'Local Media Browser',
            'menu_title' => 'Local Media',
            'settings_link' => 'Settings',
            'tab_title' => 'Local Files',
            'intro' => 'Browse and select files directly from local server folders inside the WordPress Media Modal and ACF, without copying or duplicating them into wp-content/uploads.',
            'lang_label' => 'Interface language',
            'lang_auto' => 'Automatic (WordPress default)',
            'base_label' => 'Default base folder',
            'base_desc' => 'Absolute server path where relative folders will be resolved (e.g., /var/www/media or C:/media).',
            'folders_label' => 'Allowed folders (one per line)',
            'folders_desc' => 'Use relative paths (e.g., ./documents, ./images) inside the base folder, or existing absolute paths. Subfolders are listed automatically.',
            'add_folder_btn' => 'Add folder',
            'add_folder_prompt' => 'Folder name inside the base directory (e.g., ./documents):',
            'save_btn' => 'Save settings',
            'security_notice' => 'New relative folders are created only inside the base folder. Store only public files here—never private documents or executable scripts.',
            'configured_paths' => 'View active configured paths',
            'browse_heading' => 'Browse files (read-only)',
            'configure_link' => 'Configure folders',
            'search_label' => 'Search in this folder',
            'search_placeholder' => 'File name...',
            'type_label' => 'Type',
            'type_all' => 'All',
            'type_pdf' => 'PDF',
            'type_image' => 'Images',
            'type_audio' => 'Audio',
            'type_video' => 'Video',
            'refresh_btn' => 'Refresh',
            'up_btn' => 'Up',
            'prev_btn' => 'Previous',
            'next_btn' => 'Next',
            'page_info' => 'Page %1$d of %2$d (%3$d items)',
            'loading' => 'Loading files...',
            'no_folders' => 'No folders configured. Set them up in Media > Local Media.',
            'empty_folder' => 'No matching files in this folder.',
            'status_inspect' => 'Open a folder or click a file to inspect its details.',
            'status_select' => 'Open a folder or select one or more files.',
            'status_selected' => '%d file(s) selected. Click the bottom button to confirm.',
            'max_selection' => 'You can select up to 50 files at once.',
            'not_allowed_field' => 'File type not allowed in this field.',
            'details_title' => 'File details',
            'details_empty' => 'Files are read from their original folders. Browsing does not create WordPress attachments.',
            'file_url' => 'File URL',
            'copy_link' => 'Copy link',
            'link_copied' => 'Link copied to clipboard.',
            'link_selected' => 'Link selected. Press Ctrl+C to copy.',
            'open_file' => 'Open file',
            'no_copy_note' => 'The file is not copied to uploads. Its original location remains unchanged.',
            'preparing' => 'Registering selected files...',
            'select_btn' => 'Select',
            'err_request' => 'Request failed. Please check your connection and try again.',
            'err_base_invalid' => 'The base folder must be a valid absolute path and cannot be the system root.',
            'err_path_invalid' => 'Invalid path (".." and hidden folders are not allowed): ',
            'err_abs_unreadable' => 'Absolute folder does not exist or is not readable: ',
            'err_base_create' => 'Could not create the base folder. Check server permissions.',
            'err_base_read' => 'WordPress does not have read permissions for the base folder.',
            'err_symlink' => 'Folder escapes the base directory via symlink: ',
            'err_dir_create' => 'Could not create folder: ',
            'err_dir_unavail' => 'Folder unavailable: ',
            'err_folder_read' => 'Could not read the requested folder.',
            'err_permission' => 'You do not have permission to perform this action.',
            'err_selection_cnt' => 'Please select between 1 and 50 files.',
            'err_post_perm' => 'You do not have permission to attach files to this post.',
            'err_acf_field' => 'ACF field is unavailable or does not accept files.',
            'err_file_missing' => 'File no longer exists or is unreadable: ',
            'err_mime_denied' => 'WordPress does not allow this file type for your user: ',
            'err_image_invalid' => 'Invalid image or mismatched file extension: ',
            'err_acf_images' => 'This ACF field only accepts images.',
            'err_acf_parent' => 'This file already belongs to another post.',
            'err_lock' => 'Another process is registering this file. Please try again.',
            'err_custom_toolbar' => 'This media frame uses an incompatible custom action.',
        ];

        return 'es' === $this->get_lang() ? $es : $en;
    }

    public function t($key)
    {
        $strings = $this->strings();
        return $strings[$key] ?? $key;
    }

    // CONFIG AND PATHs

    public function config()
    {
        $saved = get_option(self::OPTION, null);
        if (is_array($saved) && isset($saved['base'], $saved['folders'])) {
            return wp_parse_args($saved, ['lang' => 'auto']);
        }
        return [
            'lang' => 'auto',
            'base' => wp_normalize_path(trailingslashit(ABSPATH) . 'media'),
            'folders' => '',
        ];
    }

    public function register_settings()
    {
        register_setting('localmedia_settings_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_config'],
        ]);
    }

    public static function is_absolute($path)
    {
        return is_string($path) && (0 === strpos($path, '/') || (bool) preg_match('~^[a-zA-Z]:[/\\\\]~', $path));
    }

    public static function path_key($path)
    {
        $path = rtrim(wp_normalize_path($path), '/');
        return '\\' === DIRECTORY_SEPARATOR ? strtolower($path) : $path;
    }

    public static function is_within($path, $base)
    {
        $path = self::path_key($path);
        $base = self::path_key($base);
        return $path === $base || 0 === strpos($path, $base . '/');
    }

    public static function is_safe_relative($relative, $allow_empty = true)
    {
        if (!is_string($relative) || preg_match('/[\x00-\x1f\x7f\\\\:]/', $relative)) {
            return false;
        }
        if ('' === $relative) {
            return $allow_empty;
        }
        foreach (explode('/', $relative) as $part) {
            if ('' === $part || '.' === $part[0]) {
                return false;
            }
        }
        return true;
    }

    private function resolve_candidate($entry, $base)
    {
        $entry = wp_normalize_path(trim($entry));
        if (self::is_absolute($entry)) {
            return $entry;
        }
        $relative = 0 === strpos($entry, './') ? substr($entry, 2) : $entry;
        return self::is_safe_relative($relative, false) ? rtrim($base, '/\\') . '/' . $relative : false;
    }

    public function sanitize_config($input)
    {
        $old = $this->config();
        if (!current_user_can('manage_options') || !is_array($input)) {
            return $old;
        }

        $lang = isset($input['lang']) && in_array($input['lang'], ['auto', 'es', 'en'], true) ? $input['lang'] : 'auto';
        $base = isset($input['base']) && is_string($input['base']) ? trim(wp_normalize_path($input['base'])) : '';
        $lines = isset($input['folders']) && is_string($input['folders']) ? trim($input['folders']) : '';

        $is_system_root = ('/' === $base || (bool) preg_match('~^[a-zA-Z]:/?$~', $base));
        if (!self::is_absolute($base) || $is_system_root || preg_match('/[\x00-\x1f]/', $base)) {
            return $this->settings_error($this->t('err_base_invalid'), $old);
        }

        $entries = [];
        foreach (preg_split('/\R/', $lines) as $entry) {
            $entry = trim($entry);
            if ('' === $entry) {
                continue;
            }
            $candidate = $this->resolve_candidate($entry, $base);
            if (false === $candidate || preg_match('/[\x00-\x1f]/', $candidate)) {
                return $this->settings_error($this->t('err_path_invalid') . $entry, $old);
            }
            if (self::is_absolute(wp_normalize_path($entry)) && (!is_dir($candidate) || !is_readable($candidate))) {
                return $this->settings_error($this->t('err_abs_unreadable') . $entry, $old);
            }
            $entries[] = $entry;
        }

        if (!is_dir($base) && !wp_mkdir_p($base)) {
            return $this->settings_error($this->t('err_base_create'), $old);
        }
        $real_base = realpath($base);
        if (false === $real_base || !is_readable($real_base)) {
            return $this->settings_error($this->t('err_base_read'), $old);
        }

        $seen = [];
        $saved = [];
        foreach ($entries as $entry) {
            $candidate = $this->resolve_candidate($entry, $real_base);
            $relative = !self::is_absolute(wp_normalize_path($entry));

            $ancestor = $candidate;
            while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
                $ancestor = dirname($ancestor);
            }
            $real_ancestor = realpath($ancestor);
            if ($relative && (false === $real_ancestor || !self::is_within($real_ancestor, $real_base))) {
                return $this->settings_error($this->t('err_symlink') . $entry, $old);
            }

            if (!is_dir($candidate) && (!$relative || !wp_mkdir_p($candidate))) {
                return $this->settings_error($this->t('err_dir_create') . $entry, $old);
            }
            $real = realpath($candidate);
            if (false === $real || !is_dir($real) || !is_readable($real) || ($relative && !self::is_within($real, $real_base))) {
                return $this->settings_error($this->t('err_dir_unavail') . $entry, $old);
            }

            $key = self::path_key($real);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $saved[] = $relative ? './' . ltrim(wp_normalize_path($entry), './') : wp_normalize_path($real);
            }
        }

        $this->folder_cache = null;
        return [
            'lang' => $lang,
            'base' => wp_normalize_path($real_base),
            'folders' => implode("\n", $saved),
        ];
    }

    private function settings_error($message, $old)
    {
        add_settings_error(self::OPTION, 'localmedia_invalid', $message);
        return $old;
    }

    // Return folders
    public function folders()
    {
        if (null !== $this->folder_cache) {
            return $this->folder_cache;
        }
        $this->folder_cache = [];
        $config = $this->config();
        foreach (preg_split('/\R/', $config['folders']) as $entry) {
            if ('' === trim($entry)) {
                continue;
            }
            $candidate = $this->resolve_candidate($entry, $config['base']);
            $real = $candidate ? realpath($candidate) : false;
            if (false === $real || !is_dir($real) || !is_readable($real)) {
                continue;
            }
            if (!self::is_absolute(wp_normalize_path(trim($entry)))) {
                $base = realpath($config['base']);
                if (false === $base || !self::is_within($real, $base)) {
                    continue;
                }
            }
            $id = substr(hash('sha256', $real), 0, 16);
            $this->folder_cache[$id] = [
                'id' => $id,
                'name' => wp_basename($real),
                'path' => $real,
            ];
        }
        return $this->folder_cache;
    }

    // Resolve relative path safely
    public function resolve($relative, $directory = false, $root = '')
    {
        $folders = $this->folders();
        $selected = '' === $root ? reset($folders) : (is_string($root) && isset($folders[$root]) ? $folders[$root] : false);
        if (!$selected || !self::is_safe_relative($relative)) {
            return false;
        }
        $path = realpath($selected['path'] . ('' !== $relative ? '/' . $relative : ''));
        if (false === $path || !self::is_within($path, $selected['path']) || !is_readable($path)) {
            return false;
        }
        if ($directory) {
            return is_dir($path) ? $path : false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return is_file($path) && isset(self::TYPES[$ext]) ? $path : false;
    }

    // Generate direct URL
    public function file_url($relative, $path, $root)
    {
        $wordpress = realpath(ABSPATH);
        if ($wordpress && self::is_within($path, $wordpress)) {
            $local = ltrim(substr(wp_normalize_path($path), strlen(rtrim(wp_normalize_path($wordpress), '/'))), '/');
            return site_url(implode('/', array_map('rawurlencode', explode('/', $local))));
        }
        return add_query_arg(
            [
                'action' => 'localmedia_file',
                'file' => $relative,
                'root' => $root,
            ],
            admin_url('admin-post.php')
        );
    }


    /* ==============
        REST API
       ==============
    */

    public function can_browse()
    {
        return current_user_can('upload_files');
    }

    public function register_routes()
    {
        register_rest_route('lmb/v1', '/files', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'can_browse'],
            'callback' => [$this, 'list_files'],
            'args' => [
                'root' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'dir' => ['type' => 'string', 'default' => ''],
                'search' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'type' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 80, 'minimum' => 1, 'maximum' => 200],
            ],
        ]);

        register_rest_route('lmb/v1', '/register', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'can_browse'],
            'callback' => [$this, 'register_files'],
        ]);
    }

    public function list_files($request)
    {
        $dir = $request->get_param('dir');
        $root = $request->get_param('root');
        $path = $this->resolve($dir, true, $root);
        if (false === $path) {
            return new WP_Error('localmedia_folder', $this->t('err_dir_unavail'), ['status' => 400]);
        }

        $search = strtolower(remove_accents($request->get_param('search')));
        $type = $request->get_param('type');
        $items = [];

        try {
            $entries = new DirectoryIterator($path);
            foreach ($entries as $entry) {
                $name = $entry->getFilename();
                if ($entry->isDot() || '.' === $name[0]) {
                    continue;
                }
                $relative = '' === $dir ? $name : $dir . '/' . $name;
                if ($entry->isDir() && false !== $this->resolve($relative, true, $root)) {
                    $items[] = [
                        'name' => $name,
                        'path' => $relative,
                        'kind' => 'folder',
                        'root' => $root,
                    ];
                    continue;
                }
                if ('' !== $search && false === strpos(strtolower(remove_accents($name)), $search)) {
                    continue;
                }
                $file = $this->resolve($relative, false, $root);
                if (false === $file) {
                    continue;
                }
                $mime = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))];
                if ('' !== $type && $type !== $mime && 0 !== strpos($mime, $type . '/')) {
                    continue;
                }
                $items[] = [
                    'name' => $name,
                    'path' => $relative,
                    'root' => $root,
                    'kind' => 'file',
                    'mime' => $mime,
                    '_file' => $file,
                ];
            }
        } catch (UnexpectedValueException $e) {
            return new WP_Error('localmedia_folder', $this->t('err_folder_read'), ['status' => 400]);
        }

        usort($items, static function ($a, $b) {
            if ($a['kind'] !== $b['kind']) {
                return 'folder' === $a['kind'] ? -1 : 1;
            }
            return strnatcasecmp($a['name'], $b['name']);
        });

        $total = count($items);
        $per_page = (int) $request->get_param('per_page');
        $pages = max(1, (int) ceil($total / $per_page));
        $page = min((int) $request->get_param('page'), $pages);
        $visible = array_slice($items, ($page - 1) * $per_page, $per_page);
        $date_fmt = get_option('date_format', 'Y-m-d');

        foreach ($visible as &$item) {
            if ('file' !== $item['kind']) {
                continue;
            }
            $file = $item['_file'];
            unset($item['_file']); // Nunca exponer rutas absolutas del servidor en la API.
            $bytes = @filesize($file);
            $modified = @filemtime($file);
            $item['url'] = $this->file_url($item['path'], $file, $root);
            $item['size'] = false === $bytes ? 0 : $bytes;
            $item['sizeText'] = false === $bytes ? '' : size_format($bytes, 1);
            $item['date'] = $modified ? wp_date($date_fmt, $modified) : '';
        }
        unset($item);

        return rest_ensure_response([
            'items' => $visible,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    // Attachment Logging and Protection
    public function filter_attached_file($file, $id)
    {
        $local = get_post_meta($id, '_localmedia_file', true);
        return $local ? $local : $file;
    }

    public function filter_attachment_url($url, $id)
    {
        $local = get_post_meta($id, '_localmedia_url', true);
        return $local ? $local : $url;
    }

    public function filter_image_downsize($result, $id, $size)
    {
        if (!get_post_meta($id, '_localmedia_file', true) || !wp_attachment_is_image($id)) {
            return $result;
        }
        $meta = wp_get_attachment_metadata($id);
        if (empty($meta['width']) || empty($meta['height'])) {
            return $result;
        }
        list($width, $height) = image_constrain_size_for_editor($meta['width'], $meta['height'], $size);
        return [wp_get_attachment_url($id), $width, $height, false];
    }

    public function filter_prepare_js($response, $attachment, $meta)
    {
        $file = get_post_meta($attachment->ID, '_localmedia_file', true);
        if (!$file || !is_array($response)) {
            return $response;
        }
        $response['url'] = wp_get_attachment_url($attachment->ID);
        $response['filename'] = wp_basename($file);
        $response['lmbLocal'] = true;
        $response['editLink'] = false;
        $response['nonces']['edit'] = false; // Evita recortar o modificar el archivo original.
        $bytes = is_file($file) ? filesize($file) : false;
        if (false !== $bytes) {
            $response['filesizeInBytes'] = $bytes;
            $response['filesizeHumanReadable'] = size_format($bytes, 1);
        }
        if (!empty($meta['width']) && !empty($meta['height'])) {
            $response['width'] = $meta['width'];
            $response['height'] = $meta['height'];
            $response['orientation'] = $meta['width'] >= $meta['height'] ? 'landscape' : 'portrait';
            $response['sizes'] = [
                'full' => [
                    'url' => $response['url'],
                    'width' => $meta['width'],
                    'height' => $meta['height'],
                    'orientation' => $response['orientation'],
                ],
            ];
        }
        return $response;
    }

    // If the attachment is deleted in WordPress, it prevents unlink() from removing the original physical file.
    public function before_delete_attachment($id, $post = null)
    {
        $file = get_post_meta($id, '_localmedia_file', true);
        if ($file) {
            $this->protected_deletions[self::path_key($file)] = true;
        }
    }

    public function protect_original_file($file)
    {
        return isset($this->protected_deletions[self::path_key($file)]) ? '' : $file;
    }

    public function register_files($request)
    {
        if (!current_user_can('upload_files')) {
            return new WP_Error('localmedia_perm', $this->t('err_permission'), ['status' => 403]);
        }

        $files = $request->get_param('files');
        if (!is_array($files) || empty($files) || count($files) > 50) {
            return new WP_Error('localmedia_count', $this->t('err_selection_cnt'), ['status' => 400]);
        }

        $parent = absint($request->get_param('post_id'));
        if ($parent && (!get_post($parent) || !current_user_can('edit_post', $parent))) {
            return new WP_Error('localmedia_parent', $this->t('err_post_perm'), ['status' => 403]);
        }

        $field_key = $request->get_param('acf_field');
        $field = false;
        if (is_string($field_key) && '' !== $field_key && function_exists('acf_get_field')) {
            $field = acf_get_field($field_key);
            if (!$field || !in_array($field['type'], ['file', 'image', 'gallery'], true)) {
                return new WP_Error('localmedia_acf', $this->t('err_acf_field'), ['status' => 400]);
            }
        }

        $validated = [];
        foreach ($files as $item) {
            if (!is_array($item) || !isset($item['root'], $item['path']) || !is_string($item['root']) || !is_string($item['path'])) {
                return new WP_Error('localmedia_item', $this->t('err_file_missing'), ['status' => 400]);
            }
            $path = $this->resolve($item['path'], false, $item['root']);
            if (false === $path) {
                return new WP_Error('localmedia_missing', $this->t('err_file_missing') . $item['path'], ['status' => 400]);
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = self::TYPES[$ext];
            $allowed = wp_check_filetype(wp_basename($path), get_allowed_mime_types());
            if (empty($allowed['type'])) {
                return new WP_Error('localmedia_mime', $this->t('err_mime_denied') . $item['path'], ['status' => 403]);
            }

            $size = filesize($path);
            $meta = ['file' => wp_basename($path), 'filesize' => $size, 'sizes' => []];

            if (0 === strpos($mime, 'image/')) {
                $dimensions = function_exists('wp_getimagesize') ? wp_getimagesize($path) : @getimagesize($path);
                if (!$dimensions || empty($dimensions['mime']) || $mime !== $dimensions['mime']) {
                    return new WP_Error('localmedia_img', $this->t('err_image_invalid') . $item['path'], ['status' => 400]);
                }
                $meta['width'] = (int) $dimensions[0];
                $meta['height'] = (int) $dimensions[1];
            }

            $url = $this->file_url($item['path'], $path, $item['root']);
            $key = hash('sha256', self::path_key($path));
            $existing = $this->find_existing_attachment($key);

            if ($field && in_array($field['type'], ['image', 'gallery'], true) && 0 !== strpos($mime, 'image/')) {
                return new WP_Error('localmedia_acf_img', $this->t('err_acf_images'), ['status' => 400]);
            }
            if ($field && function_exists('acf_validate_attachment')) {
                $preview = [
                    'id' => $existing,
                    'ID' => $existing,
                    'filename' => wp_basename($path),
                    'name' => wp_basename($path),
                    'url' => $url,
                    'mime' => $mime,
                    'type' => strtok($mime, '/'),
                    'filesizeInBytes' => $size,
                    'width' => $meta['width'] ?? 0,
                    'height' => $meta['height'] ?? 0,
                ];
                $errors = acf_validate_attachment($preview, $field, 'prepare');
                if ($errors) {
                    return new WP_Error('localmedia_acf_val', wp_basename($path) . ': ' . implode(' ', array_map('wp_strip_all_tags', $errors)), ['status' => 400]);
                }
            }
            if ($field && isset($field['library']) && 'uploadedTo' === $field['library'] && $existing) {
                $post = get_post($existing);
                if ((int) $post->post_parent !== $parent) {
                    return new WP_Error('localmedia_acf_parent', $this->t('err_acf_parent'), ['status' => 400]);
                }
            }

            $validated[$key] = compact('key', 'path', 'url', 'mime', 'meta', 'item');
        }

        $result = [];
        foreach ($validated as $file) {
            $id = $this->register_single_attachment($file, $parent);
            if (is_wp_error($id)) {
                return $id;
            }
            $prepared = wp_prepare_attachment_for_js($id);
            if (is_array($prepared)) {
                $result[] = $prepared;
            }
        }

        return rest_ensure_response(['attachments' => $result]);
    }

    private function find_existing_attachment($key)
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => ['inherit', 'publish', 'private'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_localmedia_key',
            'meta_value' => $key,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);
        return $ids ? (int) $ids[0] : 0;
    }

    private function register_single_attachment($file, $parent)
    {
        $lock = '_localmedia_lock_' . $file['key'];
        $token = time() . ':' . wp_generate_password(12, false);
        $previous = get_option($lock, '');

        if (is_string($previous) && '' !== $previous) {
            $parts = explode(':', $previous);
            if ((int) $parts[0] < time() - 120) {
                delete_option($lock);
            }
        }

        if (!add_option($lock, $token, '', false)) {
            return new WP_Error('localmedia_lock', $this->t('err_lock'), ['status' => 409]);
        }

        try {
            $id = $this->find_existing_attachment($file['key']);
            if ($id) {
                if (current_user_can('edit_post', $id)) {
                    wp_update_attachment_metadata($id, $file['meta']);
                }
                return $id;
            }

            $id = wp_insert_attachment(wp_slash([
                'post_title' => pathinfo(wp_basename($file['path']), PATHINFO_FILENAME),
                'post_status' => 'inherit',
                'post_mime_type' => $file['mime'],
                'post_author' => get_current_user_id(),
                'post_parent' => $parent,
                'guid' => $file['url'],
                'meta_input' => [
                    '_localmedia_key' => $file['key'],
                    '_localmedia_file' => $file['path'],
                    '_localmedia_url' => $file['url'],
                    '_localmedia_root' => $file['item']['root'],
                    '_localmedia_relative' => $file['item']['path'],
                ],
            ]), false, $parent, true);

            if (!is_wp_error($id)) {
                wp_update_attachment_metadata($id, $file['meta']);
            }
            return $id;
        } finally {
            if ($token === get_option($lock, '')) {
                delete_option($lock);
            }
        }
    }

    // Admin Interfaces and Assets

    public function add_settings_link($links)
    {
        if (!current_user_can('upload_files')) {
            return $links;
        }
        $url = admin_url('upload.php?page=local-media-browser');
        $link = '<a href="' . esc_url($url) . '">' . esc_html($this->t('settings_link')) . '</a>';
        array_unshift($links, $link);
        return $links;
    }

    public function register_menu()
    {
        add_submenu_page(
            'upload.php',
            $this->t('plugin_title'),
            $this->t('menu_title'),
            'upload_files',
            'local-media-browser',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_admin_assets($hook)
    {
        if ('media_page_local-media-browser' === $hook) {
            wp_enqueue_media();
            $this->enqueue_media_assets();
        }
    }

    public function enqueue_media_assets()
    {
        if (!current_user_can('upload_files') || wp_script_is('lm-browser', 'enqueued')) {
            return;
        }

        $css_ver = self::VERSION . '-' . substr(md5_file(__DIR__ . '/assets/css/browser.css'), 0, 8);
        $js_ver = self::VERSION . '-' . substr(md5_file(__DIR__ . '/assets/js/browser.js'), 0, 8);

        wp_enqueue_style('lm-browser', plugins_url('assets/css/browser.css', __FILE__), ['dashicons'], $css_ver);
        wp_enqueue_script('lm-browser', plugins_url('assets/js/browser.js', __FILE__), ['jquery', 'media-views', 'media-editor'], $js_ver, true);

        $roots = [];
        foreach ($this->folders() as $folder) {
            $roots[] = ['id' => $folder['id'], 'name' => $folder['name']];
        }

        wp_localize_script('lm-browser', 'LMB', [
            'api' => rest_url('lmb/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'roots' => $roots,
            'settings' => current_user_can('manage_options') ? admin_url('upload.php?page=local-media-browser') : '',
            'i18n' => $this->strings(),
        ]);
    }

    public function render_admin_page()
    {
        if (!current_user_can('upload_files')) {
            return;
        }
        $config = $this->config();
        ?>
        <div class="wrap lm-settings">
            <h1><?php echo esc_html($this->t('plugin_title')); ?> <small><?php echo esc_html(self::VERSION); ?></small>
            </h1>
            <p><?php echo esc_html($this->t('intro')); ?></p>

            <?php if (current_user_can('manage_options')): ?>
                <?php settings_errors(self::OPTION); ?>
                <form method="post" action="options.php">
                    <?php settings_fields('localmedia_settings_group'); ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="lm-lang"><?php echo esc_html($this->t('lang_label')); ?></label>
                                </th>
                                <td>
                                    <select id="lm-lang" name="<?php echo esc_attr(self::OPTION); ?>[lang]">
                                        <option value="auto" <?php selected($config['lang'], 'auto'); ?>>
                                            <?php echo esc_html($this->t('lang_auto')); ?>
                                        </option>
                                        <option value="es" <?php selected($config['lang'], 'es'); ?>>Español</option>
                                        <option value="en" <?php selected($config['lang'], 'en'); ?>>English</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="lm-base"><?php echo esc_html($this->t('base_label')); ?></label>
                                </th>
                                <td>
                                    <input class="large-text code" id="lm-base" name="<?php echo esc_attr(self::OPTION); ?>[base]"
                                        value="<?php echo esc_attr($config['base']); ?>" required>
                                    <p class="description"><?php echo esc_html($this->t('base_desc')); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="lm-folders"><?php echo esc_html($this->t('folders_label')); ?></label>
                                </th>
                                <td>
                                    <textarea class="large-text code" rows="6" id="lm-folders"
                                        name="<?php echo esc_attr(self::OPTION); ?>[folders]"
                                        placeholder="./documents&#10;./images"><?php echo esc_textarea($config['folders']); ?></textarea>
                                    <p><button type="button" class="button"
                                            id="lm-add-folder"><?php echo esc_html($this->t('add_folder_btn')); ?></button></p>
                                    <p class="description"><?php echo esc_html($this->t('folders_desc')); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button($this->t('save_btn')); ?>
                </form>

                <div class="notice notice-info inline">
                    <p><?php echo esc_html($this->t('security_notice')); ?></p>
                </div>

                <?php if ($this->folders()): ?>
                    <details>
                        <summary><?php echo esc_html($this->t('configured_paths')); ?></summary>
                        <ul>
                            <?php foreach ($this->folders() as $folder): ?>
                                <li><strong><?php echo esc_html($folder['name']); ?></strong>:
                                    <code><?php echo esc_html($folder['path']); ?></code>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            <?php endif; ?>

            <h2><?php echo esc_html($this->t('browse_heading')); ?></h2>
            <div id="lm-browser"></div>
        </div>
        <?php
    }

    // File Delivery outside ABSPATH (with range and cache support)
    public function serve_file()
    {
        $relative = isset($_GET['file']) && is_string($_GET['file']) ? wp_unslash($_GET['file']) : '';
        $root = isset($_GET['root']) && is_string($_GET['root']) ? wp_unslash($_GET['root']) : '';
        $path = $this->resolve($relative, false, $root);

        if (false === $path || false === ($size = filesize($path))) {
            status_header(404);
            exit;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            status_header(405);
            header('Allow: GET, HEAD');
            exit;
        }

        // ETag validation to save bandwidth and PHP load
        $mtime = filemtime($path);
        $etag = '"' . md5($path . '|' . $size . '|' . $mtime) . '"';
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            status_header(304);
            exit;
        }

        $start = 0;
        $end = max(0, $size - 1);
        if (isset($_SERVER['HTTP_RANGE'])) {
            $range = self::parse_byte_range($_SERVER['HTTP_RANGE'], $size);
            if (false === $range) {
                status_header(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            list($start, $end) = $range;
            status_header(206);
            header("Content-Range: bytes $start-$end/$size");
        }

        $stream = fopen($path, 'rb');
        if (false === $stream) {
            status_header(404);
            exit;
        }

        $mime = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))];
        $disposition = ('application/pdf' === $mime || preg_match('~^(image|audio|video)/~', $mime)) ? 'inline' : 'attachment';
        $filename = str_replace(['"', "\r", "\n", '\\'], '_', wp_basename($path));

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        header('ETag: ' . $etag);
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode(wp_basename($path)));
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($size ? $end - $start + 1 : 0));

        if ('HEAD' !== $method && $size) {
            fseek($stream, $start);
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !feof($stream) && !connection_aborted()) {
                $chunk = fread($stream, min(65536, $remaining));
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $remaining -= strlen($chunk);
            }
        }
        fclose($stream);
        exit;
    }

    public static function parse_byte_range($header, $size)
    {
        if ($size <= 0 || !is_string($header) || !preg_match('/^bytes=(\d*)-(\d*)$/D', trim($header), $m) || ('' === $m[1] && '' === $m[2])) {
            return false;
        }
        if ('' === $m[1]) {
            return (int) $m[2] > 0 ? [max(0, $size - (int) $m[2]), $size - 1] : false;
        }
        $start = (int) $m[1];
        $end = '' === $m[2] ? $size - 1 : min((int) $m[2], $size - 1);
        return $start < $size && $end >= $start ? [$start, $end] : false;
    }
}

new LocalMedia();