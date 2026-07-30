#!/usr/bin/env bash
# ============================================================
#  Ejecuta el test de render de todas las pantallas.
#
#  Corre cada pantalla en su propio proceso PHP, con una sesión de
#  administrador falsa, y verifica que no reviente ni devuelva una página vacía.
#  Es lo que atrapa las funciones inexistentes y las variables que una vista
#  espera y nadie le pasó — cosas que `php -l` no puede ver.
#
#  Uso:  bash test/render.sh [/ruta/al/webroot]
# ============================================================
set -u

WEB="${1:-/home/u566451014/domains/intellihelp.tech/public_html/crm}"
AQUI="$(cd "$(dirname "$0")" && pwd)"
OK=0
MAL=0
FALLOS=""

echo "== Render de pantallas (sesión de administrador) =="

# Las claves no tienen espacios, así que un for sobre la salida alcanza y evita
# la sustitución de procesos, que no está disponible en todos los hostings.
for clave in $(php "$AQUI/render_test.php" --rutas); do
    salida="$(php "$AQUI/render_test.php" "$clave" "$WEB" 2>&1)"
    if [[ "$salida" == ok\ * ]]; then
        printf "  [OK] %-22s %s bytes\n" "$clave" "${salida#ok }"
        OK=$((OK + 1))
    else
        printf "  [XX] %-22s %s\n" "$clave" "$(echo "$salida" | tr '\n' ' ' | cut -c1-260)"
        MAL=$((MAL + 1))
        FALLOS="$FALLOS $clave"
    fi
done

php "$AQUI/render_test.php" --limpiar "$WEB" >/dev/null 2>&1

echo
echo "========================================================"
printf "  %d pantallas · %d correctas · %d fallidas\n" "$((OK + MAL))" "$OK" "$MAL"
[ -n "$FALLOS" ] && echo "  Fallaron:$FALLOS"
echo "========================================================"
[ "$MAL" -eq 0 ] || exit 1
