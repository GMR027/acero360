<?php

namespace Model;

/**
 * Carpeta virtual de la herramienta Archivo (árbol de hasta varios niveles).
 *
 * Compartida entre administradores, como el resto de herramientas personales.
 * Al borrar una carpeta MySQL empuja su contenido a la raíz (carpeta_id NULL):
 * no se pierde ningún archivo.
 */
class ArchivoCarpeta extends ActiveRecord
{
    protected static $tabla = 'archivo_carpetas';
    protected static $columnasDB = ['id', 'nombre', 'color', 'icono', 'carpeta_padre_id', 'creado_por'];

    public $id, $nombre, $color, $icono, $carpeta_padre_id, $creado_por;
    public $creado, $actualizado;   // los pone la BD

    /** Profundidad máxima admitida (raíz + 4 niveles). */
    public const PROFUNDIDAD_MAX = 4;

    /** Color con el que se pinta una carpeta sin color propio. */
    public const COLOR_POR_DEFECTO = '#38648c';

    /** Icono con el que se identifica una carpeta sin icono propio. */
    public const ICONO_POR_DEFECTO = 'folder';

    /**
     * Paleta cerrada de colores para las carpetas. Todos son oscuros a
     * propósito: el icono central se pinta en blanco y debe conservar el
     * contraste (WCAG, mínimo 3:1) en cualquier tema.
     */
    public const COLORES = [
        '#38648c' => 'Azul acero',
        '#2563eb' => 'Azul',
        '#4f46e5' => 'Índigo',
        '#7c3aed' => 'Violeta',
        '#c026d3' => 'Fucsia',
        '#db2777' => 'Rosa',
        '#dc2626' => 'Rojo',
        '#ea580c' => 'Naranja',
        '#b45309' => 'Ámbar',
        '#15803d' => 'Verde',
        '#0f766e' => 'Turquesa',
        '#475569' => 'Pizarra',
    ];

    /**
     * Iconos de Material Symbols que puede llevar una carpeta, agrupados por
     * tema para que la lista sea fácil de recorrer.
     */
    public const GRUPOS_ICONOS = [
        'General' => [
            'folder'      => 'Carpeta',
            'star'        => 'Destacado',
            'bookmark'    => 'Marcador',
            'home_work'   => 'Oficina',
            'business'    => 'Empresa',
            'public'      => 'Sitio web',
            'category'    => 'Categoría',
            'label'       => 'Etiqueta',
        ],
        'Documentos' => [
            'description'    => 'Documentos',
            'picture_as_pdf' => 'PDF',
            'table_chart'    => 'Hojas de cálculo',
            'slideshow'      => 'Presentaciones',
            'article'        => 'Artículos',
            'receipt_long'   => 'Recibos',
            'request_quote'  => 'Cotizaciones',
            'contract'       => 'Contratos',
            'fact_check'     => 'Revisión',
            'gavel'          => 'Legal',
        ],
        'Diseño y obra' => [
            'architecture'            => 'Planos',
            'view_in_ar'              => 'Modelos 3D',
            'engineering'             => 'Ingeniería',
            'design_services'         => 'Diseño',
            'precision_manufacturing' => 'Fabricación',
            'construction'            => 'Construcción',
            'straighten'              => 'Medidas',
            'calculate'               => 'Cálculos',
        ],
        'Multimedia' => [
            'imagesmode'    => 'Imágenes',
            'photo_library' => 'Galería',
            'photo_camera'  => 'Fotos',
            'videocam'      => 'Videos',
            'movie'         => 'Películas',
            'audio_file'    => 'Audio',
            'palette'       => 'Diseño gráfico',
        ],
        'Comercial' => [
            'payments'         => 'Pagos',
            'sell'             => 'Ventas',
            'local_offer'      => 'Ofertas',
            'inventory_2'      => 'Inventario',
            'local_shipping'   => 'Envíos',
            'storefront'       => 'Tienda',
            'groups'           => 'Clientes',
            'person'           => 'Contactos',
            'handshake'        => 'Acuerdos',
        ],
        'Sistema' => [
            'shield'        => 'Seguridad',
            'key'           => 'Accesos',
            'fingerprint'   => 'Identidad',
            'verified_user' => 'Permisos',
            'cloud'         => 'Nube',
            'dns'           => 'Servidores',
            'archive'       => 'Respaldos',
        ],
    ];

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->nombre = self::limpiarNombre($args['nombre'] ?? '');
        $this->color = self::limpiarColor($args['color'] ?? '');
        $this->icono = self::limpiarIcono($args['icono'] ?? '');
        $this->carpeta_padre_id = isset($args['carpeta_padre_id']) && (int) $args['carpeta_padre_id'] > 0
            ? (int) $args['carpeta_padre_id']
            : null;
        $this->creado_por = $args['creado_por'] ?? null;
    }

    /** Color válido de la paleta; si no lo es, el color por defecto. */
    public static function limpiarColor($color): string
    {
        $color = strtolower(trim((string) $color));
        return isset(self::COLORES[$color]) ? $color : self::COLOR_POR_DEFECTO;
    }

    /** Icono válido de la lista; si no lo es, el icono por defecto. */
    public static function limpiarIcono($icono): string
    {
        $icono = trim((string) $icono);
        return isset(self::iconosValidos()[$icono]) ? $icono : self::ICONO_POR_DEFECTO;
    }

    /** @return array<string, string> Todos los iconos permitidos (icono => etiqueta). */
    public static function iconosValidos(): array
    {
        $validos = [];
        foreach (self::GRUPOS_ICONOS as $grupo) {
            $validos += $grupo;
        }
        return $validos;
    }

    /** Color con el que pintar la carpeta (siempre un hex de la paleta). */
    public function colorHex(): string
    {
        return self::limpiarColor($this->color ?? '');
    }

    /** Icono central con el que identificar la carpeta. */
    public function iconoNombre(): string
    {
        return self::limpiarIcono($this->icono ?? '');
    }

    /** Nombre apto para guardar: sin controles, espacios colapsados, 100 caracteres. */
    public static function limpiarNombre($nombre): string
    {
        $nombre = preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) $nombre);
        $nombre = trim(preg_replace('/\s+/u', ' ', (string) $nombre));
        // Caracteres que encajan mal en rutas, URLs y nombres de archivo.
        $nombre = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', (string) $nombre);
        return mb_substr($nombre, 0, 100);
    }

    public function validar(): array
    {
        self::$alertas = [];
        if ($this->nombre === '') {
            self::$alertas['error'][] = 'El nombre de la carpeta es obligatorio';
        }
        if (mb_strlen($this->nombre) > 100) {
            self::$alertas['error'][] = 'El nombre es demasiado largo (máx. 100)';
        }
        if ($this->carpeta_padre_id) {
            $padre = self::find($this->carpeta_padre_id);
            if (!$padre) {
                self::$alertas['error'][] = 'La carpeta contenedora ya no existe';
            } elseif (count($padre->ruta()) >= self::PROFUNDIDAD_MAX) {
                self::$alertas['error'][] = 'Se alcanzó la profundidad máxima de carpetas';
            }
            if ((int) $this->id === (int) $this->carpeta_padre_id) {
                self::$alertas['error'][] = 'Una carpeta no puede contenerse a sí misma';
            }
        }
        return self::$alertas;
    }

    /** Carpetas de un nivel concreto (null = raíz), ordenadas por nombre. */
    public static function enNivel(?int $padreId): array
    {
        $donde = $padreId ? 'carpeta_padre_id = ' . (int) $padreId : 'carpeta_padre_id IS NULL';
        return self::consultarSQL("SELECT * FROM archivo_carpetas WHERE $donde ORDER BY nombre ASC");
    }

    /** @return array<int, array{id: int, nombre: string}> Ruta desde la raíz hasta esta carpeta. */
    public function ruta(): array
    {
        $ruta = [];
        $actual = $this;
        $giros = 0;
        while ($actual && $giros <= self::PROFUNDIDAD_MAX + 2) {
            array_unshift($ruta, ['id' => (int) $actual->id, 'nombre' => (string) $actual->nombre]);
            if (!$actual->carpeta_padre_id) {
                break;
            }
            $actual = self::find((int) $actual->carpeta_padre_id);
            $giros++;
        }
        return $ruta;
    }

    /** @return array<int, int> Ids de esta carpeta y todas sus descendientes. */
    public function idsConDescendientes(): array
    {
        $ids = [(int) $this->id];
        $pendientes = [(int) $this->id];
        $giros = 0;
        while ($pendientes && $giros < 500) {
            $siguientes = [];
            foreach ($pendientes as $padre) {
                foreach (self::enNivel($padre) as $hija) {
                    $ids[] = (int) $hija->id;
                    $siguientes[] = (int) $hija->id;
                }
            }
            $pendientes = $siguientes;
            $giros++;
        }
        return $ids;
    }

    /** Total de archivos guardados en esta carpeta y sus descendientes. */
    public function totalArchivos(): int
    {
        $ids = implode(',', $this->idsConDescendientes());
        $r = self::$db->query("SELECT COUNT(*) FROM archivo_archivos WHERE carpeta_id IN ($ids)");
        $fila = $r ? $r->fetch_array() : null;
        return $fila ? (int) array_shift($fila) : 0;
    }

    /** ¿Existe ya una hermana con este nombre? (evita duplicados en un nivel) */
    public function nombreUsado(string $nombre, int $padreId): bool
    {
        $n = self::escapar(mb_strtolower(self::limpiarNombre($nombre)));
        $p = $padreId ? (int) $padreId : 0;
        $donde = $padreId ? "carpeta_padre_id = $p" : 'carpeta_padre_id IS NULL';
        $r = self::$db->query("SELECT COUNT(*) FROM archivo_carpetas WHERE $donde AND LOWER(nombre) = '$n' AND id <> " . (int) $this->id);
        $fila = $r ? $r->fetch_array() : null;
        return $fila && (int) array_shift($fila) > 0;
    }
}
