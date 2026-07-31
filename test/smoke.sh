#!/usr/bin/env bash
# ============================================================
#  Smoke test HTTP — CRM INTELLIHELP
#
#  Verifica, contra el sitio desplegado, que:
#    · el login responde y muestra el botón de Google
#    · TODA ruta privada redirige al login (nadie entra sin sesión)
#    · las rutas públicas (agenda, baja) responden sin sesión
#    · el código de la aplicación NO se puede pedir por HTTP
#    · los assets están donde deben
#    · la app responde igual por el subdominio y por la subcarpeta
#
#  Uso:  bash test/smoke.sh [https://intellihelp.tech/crm]
# ============================================================
set -u

BASE="${1:-https://intellihelp.tech/crm}"
OK=0
FALLOS=0

codigo() { curl -s -o /dev/null -w '%{http_code}' -m 25 "$1"; }

probar() {   # probar <descripción> <url> <códigos esperados separados por |>
    local desc="$1" url="$2" esperados="$3"
    local c; c="$(codigo "$url")"
    if [[ "|$esperados|" == *"|$c|"* ]]; then
        echo "  [OK] $desc  ($c)"
        OK=$((OK + 1))
    else
        echo "  [XX] $desc  → $c, se esperaba $esperados   $url"
        FALLOS=$((FALLOS + 1))
    fi
}

contiene() {  # contiene <descripción> <url> <texto>
    local desc="$1" url="$2" aguja="$3"
    if curl -s -L -m 25 "$url" | grep -qF "$aguja"; then
        echo "  [OK] $desc"
        OK=$((OK + 1))
    else
        echo "  [XX] $desc   (no apareció «$aguja» en $url)"
        FALLOS=$((FALLOS + 1))
    fi
}

echo "== Una sola dirección: el subdominio redirige =="
red="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -m 25 https://crm.intellihelp.tech/login)"
case "$red" in
    30*\ https://intellihelp.tech/crm/login*)
        echo "  [OK] crm.intellihelp.tech redirige a intellihelp.tech/crm  ($red)"
        OK=$((OK + 1)) ;;
    *)
        echo "  [XX] el subdominio no redirige como corresponde  → $red"
        FALLOS=$((FALLOS + 1)) ;;
esac

echo "== Acceso =="
probar "el login responde"                 "$BASE/login"            "200"
contiene "muestra el ingreso con Google"   "$BASE/login"            "Ingresar con Google Workspace"
contiene "aclara el dominio autorizado"    "$BASE/login"            "@intellihelp.tech"
probar "la raíz lleva al login"            "$BASE/"                 "200|302"

echo "== Rutas privadas: sin sesión, todas al login =="
for r in dashboard contactos cuentas posibles negocios embudo actividades campanas \
         plantillas listas agenda reservas reportes buscar \
         contacto-form cuenta-form posible-form negocio-form actividad-form \
         campana-form plantilla-form lista-form agenda-form \
         usuarios papelera ajustes cron api-keys importar cuentas-correo imagenes plantilla-vista; do
    probar "«$r» exige sesión"              "$BASE/$r"               "302"
done

echo "== Rutas públicas =="
probar "baja con token inválido informa"   "$BASE/baja?t=nada"      "200"
probar "píxel de apertura devuelve imagen" "$BASE/px?t=nada"        "200"
probar "agenda inexistente da 404"         "$BASE/r/nadie/nada"     "404"
probar "cancelación sin token responde"    "$BASE/r/cancelar"       "200"

echo "== API =="
probar "la API rechaza sin clave"          "$BASE/api/estado"       "401"

echo "== El código no se sirve por HTTP =="
probar "bootstrap.php bloqueado"           "$BASE/app/bootstrap.php"          "403"
probar "vistas bloqueadas"                 "$BASE/app/views/layout.php"       "403"
probar "controladores bloqueados"          "$BASE/app/pages/dashboard.php"    "403"
probar "el esquema no es accesible"        "$BASE/db/schema.sql"              "403|404"
probar "la config no es accesible"         "$BASE/server-config/config.php"   "403|404"

echo "== Assets =="
probar "hoja de estilos"                   "$BASE/assets/css/app.css"         "200"
probar "javascript"                        "$BASE/assets/js/app.js"           "200"
probar "editor de plantillas"              "$BASE/assets/js/editor.js"        "200"
probar "logo"                              "$BASE/assets/img/ITH-logo-139x75.png" "200"
probar "favicon"                           "$BASE/assets/img/favicon.png"     "200"

echo "== Ruta inexistente =="
probar "404 propio"                        "$BASE/esto-no-existe"   "404"

echo
echo "========================================================"
printf "  %d comprobaciones · %d correctas · %d fallidas\n" "$((OK + FALLOS))" "$OK" "$FALLOS"
echo "========================================================"
[ "$FALLOS" -eq 0 ] || exit 1
