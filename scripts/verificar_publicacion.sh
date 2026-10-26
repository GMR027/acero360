#!/usr/bin/env bash
# ===========================================================================
# verificar_publicacion.sh — caza datos personales antes de subir a un
# repositorio público.
#
#   scripts/verificar_publicacion.sh            # revisa los archivos RASTREADOS
#   scripts/verificar_publicacion.sh --staged   # revisa sólo lo staged (git add)
#
# Se busca:
#   · RFC con estructura real que no sea un RFC de prueba (allowlist)
#   · Correos de proveedores gratuitos (personales)
#   · Hashes bcrypt dentro de volcados SQL (credenciales filtradas)
#   · Claves privadas PEM/OpenSSH
#   · Referencias a fotos de perfil subidas por usuarios
#
# Sale con código 1 si hay hallazgos. Para usarlo como hook de commit:
#   cp scripts/verificar_publicacion.sh .git/hooks/pre-commit
#   chmod +x .git/hooks/pre-commit
#   # y en el hook:  scripts/verificar_publicacion.sh --staged
# ===========================================================================
set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 1

MODO="${1:-}"

if [[ "$MODO" == "--staged" ]]; then
    mapfile -t archivos < <(git diff --cached --name-only --diff-filter=ACM)
else
    mapfile -t archivos < <(git ls-files)
fi

# RFCs de prueba que SÍ se permiten. Si añades un RFC nuevo a una prueba,
# agrégalo aquí: el objetivo es que ningún RFC real entre sin que se note.
allow_rfc='^(XAXX010101000|XEXX010101000|DEMO|SAT|EJE091119AA1|EJE970829AA2|GGA330101AA1|SSA330301CC3|TTA330201BB2|PPL1803057K3|JENO|OVEE|ZDUP|HERR|TEST|QQ[A-Z]|ZZ[A-Z]|AAA|BBB|CCC|DDD|EEE|FFF|GGG|HHH|III|JJJ|KKK|LLL|MMM|NNN|OOO|PPP|QQQ|RRR|SSS|TTT|UUU|VVV|WWW|XXX|YYY|ZZZ)'
# Archivos sembrados con datos ficticios documentados (identidades inventadas).
datos_ficticios='^mysql/datos_prueba\.sql$'
# Correos permitidos a propósito: autoría de los paquetes (decisión del dueño)
# y autores de librerías de terceros que aparecen en los lock files.
allow_mail='e\.gm27@outlook\.com|donatj@gmail\.com|goetas@gmail\.com|technosophos@gmail\.com|jimjag@gmail\.com'

# Extensión de archivos binarios que no se revisan.
binario='.*\.(png|jpg|jpeg|gif|webp|ico|woff|woff2|ttf|eot|svg|pdf|zip|deb|asar|pack)$'

fallas=0
for f in "${archivos[@]}"; do
    [[ -f "$f" ]] || continue
    [[ "$f" =~ ^($binario)$ ]] && continue
    # Volcado de datos de prueba con identidades inventadas (documentado en el archivo).
    [[ "$f" =~ $datos_ficticios ]] && continue

    # 1) RFCs reales
    while read -r rfc; do
        [[ -z "$rfc" ]] && continue
        echo "  ✗ RFC sospechoso en $f: $rfc"
        fallas=$((fallas+1))
    done < <(grep -ohE '[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}' "$f" 2>/dev/null | sort -u | grep -vE "$allow_rfc" || true)

    # 2) Correos personales
    while read -r mail; do
        [[ -z "$mail" ]] && continue
        echo "  ✗ Correo personal en $f: $mail"
        fallas=$((fallas+1))
    done < <(grep -ohE '[A-Za-z0-9._%+-]+@(gmail|hotmail|outlook|yahoo|icloud|live|msn|prodigy)\.[A-Za-z]{2,}' "$f" 2>/dev/null \
                | sort -u | grep -vE "$allow_mail" || true)

    # 3) Hashes bcrypt dentro de SQL: una contraseña no debe viajar en el repo
    if [[ "$f" == *.sql ]] && grep -qE '\$2y\$10\$' "$f" 2>/dev/null; then
        echo "  ✗ Hash bcrypt dentro de un SQL: $f"
        fallas=$((fallas+1))
    fi

    # 4) Claves privadas
    if grep -qE 'BEGIN (RSA |OPENSSH |EC |DSA )?PRIVATE KEY' "$f" 2>/dev/null; then
        echo "  ✗ Clave privada en $f"
        fallas=$((fallas+1))
    fi

    # 5) Fotos de perfil de usuarios
    if grep -qE 'perfil-[0-9a-f]{8,}\.(jpg|png)' "$f" 2>/dev/null; then
        echo "  ✗ Referencia a foto de perfil de usuario en $f"
        fallas=$((fallas+1))
    fi
done

if [[ $fallas -eq 0 ]]; then
    echo "OK: sin datos personales en ${#archivos[@]} archivos."
    exit 0
fi
echo
echo "$fallas hallazgo(s). Sanea antes de subir (RFC, correos, hashes o fotos de perfil)."
exit 1
