#!/usr/bin/env bash
# ============================================================
#  Ejecuta el test de render de todas las pantallas.
#
#  Corre cada pantalla en su propio proceso PHP, con una sesión de
#  administrador falsa, y verifica que no reviente ni devuelva una página vacía.
#  Es lo que atrapa las funciones inexistentes y las variables que una vista
#  espera y nadie le pasó — cosas que `php -l` no puede ver.
#
#  Uso:  bash test/render.sh [--produccion] [/ruta/al/webroot]
#
#  Contra qué instalación: la del webroot del argumento; sin argumento, la
#  de PRUEBAS (public_html/crm-pruebas). Producción sólo con --produccion
#  (sin ese argumento, render_test.php rechaza el webroot de producción con
#  código 9). Se corre en el servidor (necesita la configuración y la base
#  de esa instalación); `deploy.py test` no lo lanza.
#
#  Escribe en la base: render_test.php crea el usuario administrador
#  render.test@intellihelp.tech y cada proceso lo borra al terminar. Además
#  un trap llama a --limpiar al salir por cualquier motivo (fin normal,
#  error, Ctrl+C o kill), así que el usuario no queda en la base.
#
#  Salida: una línea por pantalla y el resumen. Código 0 si todas pasaron,
#  1 si alguna falló (o si el webroot se rechazó).
# ============================================================
set -u

# PROD: «--produccion» si se pidió, vacío si no; se le pasa a cada llamada de
# render_test.php, que es quien decide y frena.
PROD=""
if [ "${1:-}" = "--produccion" ]; then
    PROD="--produccion"
    shift
fi

# WEB: webroot de la instalación. AQUI: carpeta de este script, donde está
# render_test.php.
if [ -n "$PROD" ]; then
    WEB="${1:-/home/u566451014/domains/intellihelp.tech/public_html/crm}"
else
    WEB="${1:-/home/u566451014/domains/intellihelp.tech/public_html/crm-pruebas}"
fi
AQUI="$(cd "$(dirname "$0")" && pwd)"
OK=0
MAL=0
FALLOS=""

# Limpieza garantizada: se borra el usuario de prueba al salir, también si se
# corta el script. INT y TERM salen con su código para que el EXIT corra una
# sola vez.
limpiar() {
    php "$AQUI/render_test.php" --limpiar "$WEB" $PROD >/dev/null 2>&1 || true
}
trap limpiar EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

echo "== Render de pantallas (sesión de administrador) · $WEB =="

# Antes de recorrer, se comprueba que el webroot se acepte: si es el de
# producción sin --produccion, se corta acá con el mensaje de render_test.php
# en vez de dar todas las pantallas por fallidas.
if ! php "$AQUI/render_test.php" --limpiar "$WEB" $PROD >/dev/null; then
    echo "  El webroot se rechazó o la base no responde: no se corrió ninguna pantalla."
    exit 1
fi

# Las claves no tienen espacios, así que un for sobre la salida alcanza y evita
# la sustitución de procesos, que no está disponible en todos los hostings.
for clave in $(php "$AQUI/render_test.php" --rutas); do
    # Cada pantalla en un proceso aparte. Se juntan STDOUT y STDERR: si la salida
    # empieza con «ok » la pantalla pasó; si no, se muestra lo que dijo, recortado.
    salida="$(php "$AQUI/render_test.php" "$clave" "$WEB" $PROD 2>&1)"
    if [[ "$salida" == ok\ * ]]; then
        printf "  [OK] %-22s %s bytes\n" "$clave" "${salida#ok }"
        OK=$((OK + 1))
    else
        printf "  [XX] %-22s %s\n" "$clave" "$(echo "$salida" | tr '\n' ' ' | cut -c1-260)"
        MAL=$((MAL + 1))
        FALLOS="$FALLOS $clave"
    fi
done

echo
echo "========================================================"
printf "  %d pantallas · %d correctas · %d fallidas\n" "$((OK + MAL))" "$OK" "$MAL"
[ -n "$FALLOS" ] && echo "  Fallaron:$FALLOS"
echo "========================================================"
[ "$MAL" -eq 0 ] || exit 1
