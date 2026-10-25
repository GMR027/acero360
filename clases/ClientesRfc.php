<?php

namespace App;

/**
 * ClientesRfc — registro de RFCs de facturación por cliente (FASE 9).
 *
 * Un cliente puede tener varios RFC (p. ej. su persona física y su empresa).
 * `clientes.rfc` sigue siendo el RFC ACTIVO con el que se factura; esta clase
 * administra todos los RFC conocidos del cliente, cada uno con su razón social
 * y sus datos fiscales, para que:
 *   · la facturación ofrezca elegir cuál usar (y cargue su CP, régimen y uso);
 *   · la venta inteligente y el alta rápida reconozcan a un cliente por
 *     CUALQUIERA de sus RFC, en vez de "no encontrado";
 *   · al capturar un RFC que ya pertenece a alguien no se duplique la ficha.
 *
 * Nada se inventa: agregar exige RFC con formato válido y LIBRE (que no esté
 * en esta tabla ni como clientes.rfc de nadie). Todo queda auditado.
 */
class ClientesRfc
{
    /** @var ?\mysqli */
    private static $db = null;

    /** @var string[] */
    public static array $errores = [];

    public static function setDB($database): void
    {
        self::$db = $database;
    }

    /** @return string[] */
    public static function getErrores(): array
    {
        return self::$errores;
    }

    /* ---------------------------------------------------------------- *
     * CONSULTAS
     * ---------------------------------------------------------------- */

    /** RFCs de un cliente (el principal primero). @return array<int, array<string, mixed>> */
    public static function de(int $clienteId): array
    {
        $q = self::$db->prepare(
            'SELECT id, cliente_id, rfc, razon_social, codigo_postal, regimen_fiscal, uso_cfdi, es_principal
               FROM clientes_rfc WHERE cliente_id = ? ORDER BY es_principal DESC, id'
        );
        $q->bind_param('i', $clienteId);
        $q->execute();
        $filas = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        return $filas;
    }

    /**
     * ¿Qué cliente tiene este RFC (en su registro de RFCs)? Busca con la misma
     * normalización del resto de la plataforma. @return array<string, mixed>|null
     */
    public static function buscarClientePorRfc(string $rfc, int $excluirId = 0): ?array
    {
        $normalizado = VentasFiscal::normalizarRfc($rfc);
        if ($normalizado === '') {
            return null;
        }
        $q = self::$db->prepare(
            'SELECT c.id, c.nombre, c.nombre_empresa, r.rfc, r.codigo_postal, r.regimen_fiscal, r.uso_cfdi
               FROM clientes_rfc r JOIN clientes c ON c.id = r.cliente_id
              WHERE UPPER(REPLACE(REPLACE(REPLACE(r.rfc, "-", ""), " ", ""), ".", "")) = ?
              LIMIT 1'
        );
        $q->bind_param('s', $normalizado);
        $q->execute();
        $fila = $q->get_result()->fetch_assoc();
        $q->close();

        if ($fila && $excluirId > 0 && (int) $fila['id'] === $excluirId) {
            return null;   // es el mismo cliente que se está editando
        }

        return $fila ?: null;
    }

    /** Ficha de clientes que PARECEN la misma persona (mismo nombre o correo) que la
     *  que se está capturando: es la detección de «el mismo cliente con otro RFC».
     *  Nunca bloquea: sólo informa para revisar antes de duplicar la ficha.
     *  @return array<int, array<string, mixed>> */
    public static function posiblesDuplicados(string $nombre, string $nombreEmpresa, string $email, int $excluirId = 0): array
    {
        $norm = static fn (string $t): string => mb_strtoupper(trim(preg_replace('/\s+/', ' ', $t) ?? ''));
        $nombre = $norm($nombre);
        $empresa = $norm($nombreEmpresa);
        $correo = mb_strtolower(trim($email));
        $candidatos = [];
        $q = self::$db->prepare(
            'SELECT id, nombre, nombre_empresa, alias, rfc, email FROM clientes ORDER BY id'
        );
        $q->execute();
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
            if ((int) $c['id'] === $excluirId) {
                continue;
            }
            $coincide = ($nombre !== '' && $norm((string) $c['nombre']) === $nombre)
                || ($empresa !== '' && $norm((string) $c['nombre_empresa']) === $empresa);
            $mismoCorreo = $correo !== '' && mb_strtolower(trim((string) $c['email'])) === $correo;
            if ($coincide || $mismoCorreo) {
                $candidatos[] = $c + ['por' => $mismoCorreo ? 'correo' : 'nombre'];
            }
        }
        $q->close();

        return $candidatos;
    }

    /* ---------------------------------------------------------------- *
     * ESCRITURA (con auditoría)
     * ---------------------------------------------------------------- */

    /**
     * Agrega un RFC a un cliente. Exige formato válido y RFC LIBRE (que no
     * pertenezca a ningún cliente, ni como RFC activo ni en este registro).
     *
     * @param array{rfc?:string, razon_social?:string, codigo_postal?:string,
     *               regimen_fiscal?:string, uso_cfdi?:string} $datos
     * @return array{ok: bool, rfc_id?: int, errores: string[]}
     */
    public static function agregar(int $clienteId, array $datos, int $usuarioId): array
    {
        self::$errores = [];
        $cliente = Clientes::buscarID($clienteId);
        if (!$cliente) {
            return ['ok' => false, 'errores' => ['El cliente ya no existe.']];
        }

        $rfc = VentasFiscal::normalizarRfc((string) ($datos['rfc'] ?? ''));
        if ($rfc === '' || !VentasFiscal::rfcConFormato($rfc)) {
            return ['ok' => false, 'errores' => ['El RFC no tiene el formato de un RFC.']];
        }

        $dueno = VentasFiscal::buscarClientePorRfc($rfc);
        if ($dueno !== null) {
            if ((int) $dueno['id'] === $clienteId) {
                return ['ok' => false, 'errores' => ['Ese RFC ya está registrado a este cliente (revisa su lista de RFCs).']];
            }
            $nombre = trim((string) ($dueno['nombre_empresa'] ?: $dueno['nombre']));
            return ['ok' => false, 'errores' => [
                'El RFC "' . $rfc . '" ya pertenece al cliente «' . $nombre . '» (ficha #' . (int) $dueno['id']
                . '). Si es el mismo cliente, revísalo en su ficha; no se duplica.',
            ]];
        }

        $cp = trim((string) ($datos['codigo_postal'] ?? ''));
        if ($cp !== '' && preg_match('/^\d{5}$/', $cp) !== 1) {
            return ['ok' => false, 'errores' => ['El código postal debe tener 5 dígitos.']];
        }
        $regimen = mb_strtoupper(trim((string) ($datos['regimen_fiscal'] ?? '')));
        if ($regimen !== '' && preg_match('/^[0-9A-Z]{3,4}$/', $regimen) !== 1) {
            return ['ok' => false, 'errores' => ['El régimen fiscal no es una clave del catálogo SAT.']];
        }
        $uso = mb_strtoupper(trim((string) ($datos['uso_cfdi'] ?? '')));
        if ($uso !== '' && preg_match('/^[0-9A-Z]{3,4}$/', $uso) !== 1) {
            return ['ok' => false, 'errores' => ['El uso del CFDI no es una clave del catálogo SAT.']];
        }

        $razon = trim((string) ($datos['razon_social'] ?? '')) ?: trim((string) ($cliente->nombre_empresa ?: $cliente->nombre));
        $insertar = self::$db->prepare(
            'INSERT INTO clientes_rfc (cliente_id, rfc, razon_social, codigo_postal, regimen_fiscal, uso_cfdi)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $cpNulo = $cp !== '' ? $cp : null;
        $regNulo = $regimen !== '' ? $regimen : null;
        $usoNulo = $uso !== '' ? $uso : null;
        $insertar->bind_param('isssss', $clienteId, $rfc, $razon, $cpNulo, $regNulo, $usoNulo);
        $insertar->execute();
        $rfcId = (int) self::$db->insert_id;
        $insertar->close();

        Auditoria::registrar('cliente_rfc', $rfcId, 'agregar', $usuarioId,
            'RFC adicional para la ficha del cliente', ['cliente' => $clienteId, 'rfc' => $rfc]);

        return ['ok' => true, 'rfc_id' => $rfcId, 'errores' => []];
    }

    /** Quita un RFC del registro. El RFC ACTIVO (principal) no se puede quitar:
     *  primero se factura con otro para cambiar el activo. */
    public static function quitar(int $rfcId, int $usuarioId): bool
    {
        self::$errores = [];
        $q = self::$db->prepare('SELECT id, cliente_id, rfc, es_principal FROM clientes_rfc WHERE id = ?');
        $q->bind_param('i', $rfcId);
        $q->execute();
        $fila = $q->get_result()->fetch_assoc();
        $q->close();

        if (!$fila) {
            self::$errores[] = 'Ese RFC ya no está en la ficha.';
            return false;
        }
        if ((int) $fila['es_principal'] === 1) {
            self::$errores[] = 'El RFC "' . $fila['rfc'] . '" es el activo para facturar: no se quita '
                . '(factura con otro RFC para cambiar el activo).';
            return false;
        }

        $borrar = self::$db->prepare('DELETE FROM clientes_rfc WHERE id = ?');
        $borrar->bind_param('i', $rfcId);
        $borrar->execute();
        $borrar->close();

        Auditoria::registrar('cliente_rfc', $rfcId, 'cancelar', $usuarioId, 'RFC quitado de la ficha', [
            'cliente' => (int) $fila['cliente_id'], 'rfc' => (string) $fila['rfc'],
        ]);

        return true;
    }

    /**
     * Sincroniza el registro con el RFC ACTIVO de la ficha (lo usa la revisión
     * previa a facturar): si el RFC usado ya estaba registrado, actualiza sus
     * datos fiscales; si es nuevo, se agrega. Y queda marcado como principal
     * (los demás pasan a secundarios). Es lo que mantiene coherentes
     * clientes.rfc (activo) y clientes_rfc (registro).
     *
     * @param array{codigo_postal?:string, regimen_fiscal?:string, uso_cfdi?:string} $datos
     */
    public static function sincronizarPrincipal(int $clienteId, string $rfc, array $datos, int $usuarioId): void
    {
        $rfc = VentasFiscal::normalizarRfc($rfc);
        if ($rfc === '') {
            return;
        }
        $cliente = Clientes::buscarID($clienteId);
        if (!$cliente) {
            return;
        }

        $razon = trim((string) ($cliente->nombre_empresa ?: $cliente->nombre));
        $cp = trim((string) ($datos['codigo_postal'] ?? '')) ?: null;
        $regimen = mb_strtoupper(trim((string) ($datos['regimen_fiscal'] ?? ''))) ?: null;
        $uso = mb_strtoupper(trim((string) ($datos['uso_cfdi'] ?? ''))) ?: null;

        $q = self::$db->prepare('SELECT id FROM clientes_rfc WHERE cliente_id = ? AND rfc = ? LIMIT 1');
        $q->bind_param('is', $clienteId, $rfc);
        $q->execute();
        $existe = $q->get_result()->fetch_assoc();
        $q->close();

        if ($existe) {
            $upd = self::$db->prepare(
                'UPDATE clientes_rfc SET razon_social = ?, codigo_postal = ?, regimen_fiscal = ?, uso_cfdi = ? WHERE id = ?'
            );
            $id = (int) $existe['id'];
            $upd->bind_param('ssssi', $razon, $cp, $regimen, $uso, $id);
            $upd->execute();
            $upd->close();
        } else {
            $ins = self::$db->prepare(
                'INSERT INTO clientes_rfc (cliente_id, rfc, razon_social, codigo_postal, regimen_fiscal, uso_cfdi, es_principal)
                 VALUES (?, ?, ?, ?, ?, ?, 0)'
            );
            $ins->bind_param('isssss', $clienteId, $rfc, $razon, $cp, $regimen, $uso);
            $ins->execute();
            $ins->close();
        }

        // El RFC con el que se acaba de facturar queda como el ACTIVO del registro.
        $marca = self::$db->prepare(
            'UPDATE clientes_rfc SET es_principal = (rfc = ?) WHERE cliente_id = ?'
        );
        $marca->bind_param('si', $rfc, $clienteId);
        $marca->execute();
        $marca->close();
    }
}
