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
#    · la API v1 de la web rechaza lo que no viene firmado
#    · la agenda pública descarta lo que completa el campo trampa
#
#  Uso:  bash test/smoke.sh [https://intellihelp.tech/crm] [usuario/tipo]
#
#  El segundo argumento (o SMOKE_AGENDA) es una agenda pública activa, por
#  ejemplo `bsierra/demo-30` en pruebas. Con ella se prueba el campo trampa
#  con el formulario que sirve el sitio; sin ella esa prueba se saltea. Nada
#  de esto crea reuniones: lo que se manda se descarta antes de tocar el
#  calendario.
# ============================================================
set -u

BASE="${1:-https://intellihelp.tech/crm}"
AGENDA="${2:-${SMOKE_AGENDA:-}}"
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

bien() { echo "  [OK] $1"; OK=$((OK + 1)); }
mal()  { echo "  [XX] $1"; FALLOS=$((FALLOS + 1)); }

# pedir <método> <url> [opciones de curl...] → el cuerpo seguido de «|código»
pedir() {
    local metodo="$1" url="$2"; shift 2
    curl -s -m 25 -X "$metodo" -w '|%{http_code}' "$@" "$url"
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
         usuarios papelera ajustes cron api-keys importar cuentas-correo imagenes plantilla-vista \
         entradas-web; do
    probar "«$r» exige sesión"              "$BASE/$r"               "302"
done

echo "== Rutas públicas =="
probar "baja con token inválido informa"   "$BASE/baja?t=nada"      "200"
probar "píxel de apertura devuelve imagen" "$BASE/px?t=nada"        "200"
probar "agenda inexistente da 404"         "$BASE/r/nadie/nada"     "404"
# Con el módulo Agenda apagado (producción, por ahora) lo público también se
# apaga: 404 con «Agenda no disponible». Encendido, la página responde.
probar "cancelación sin token responde"    "$BASE/r/cancelar"       "200|404"
probar "cambio de horario sin token responde" "$BASE/r/mover"       "200|404"
cuerpo="$(curl -s -m 25 "$BASE/r/mover")"
if [[ "$cuerpo" == *"El enlace no es válido"* || "$cuerpo" == *"Agenda no disponible"* ]]; then
    bien "cambio de horario sin token: avisa que el enlace no sirve"
else
    mal "cambio de horario sin token: no dijo que el enlace no es válido"
fi

echo "== Agenda pública: campo trampa =="
if [ -z "$AGENDA" ]; then
    echo "  (salteada: falta la agenda, p. ej. bash test/smoke.sh $BASE bsierra/demo-30)"
else
    pagina="$(curl -s -m 25 "$BASE/r/$AGENDA")"
    tipo_id="$(printf '%s' "$pagina" | sed -n 's/.*name="tipo_id" value="\([0-9]*\)".*/\1/p' | head -n1)"
    token="$(printf '%s' "$pagina" | sed -n 's/.*name="form_token" value="\([^"]*\)".*/\1/p' | head -n1)"
    if [[ "$pagina" == *"Agenda no disponible"* ]]; then
        res="$(pedir POST "$BASE/reservar" --data "tipo_id=1&sitio_web=x")"
        if [[ "${res##*|}" == "404" ]]; then bien "con la Agenda apagada, reservar responde 404"
        else mal "con la Agenda apagada, reservar respondió ${res##*|}"; fi
    elif [ -z "$tipo_id" ] || [ -z "$token" ]; then
        mal "la página de la agenda $AGENDA no trae el formulario (tipo y token)"
    else
        # Datos inventados y el campo trampa completo, como lo llenaría un
        # programa. La espera es la de una persona: así lo único que puede
        # frenar el envío es la trampa, no el token.
        sleep 4
        res="$(pedir POST "$BASE/reservar" \
            --data-urlencode "tipo_id=$tipo_id" --data-urlencode "form_token=$token" \
            --data-urlencode "inicio=$(( $(date +%s) + 3 * 86400 ))" \
            --data-urlencode "nombre=Prueba Automática" --data-urlencode "email=bot@example.com" \
            --data-urlencode "acepto=1" --data-urlencode "sitio_web=https://spam.example.com")"
        if [[ "$res" == *"No pudimos agendar la reunión"* && "$res" != *"Reunión confirmada"* ]]; then
            bien "con el campo trampa completo, la reserva se descarta"
        else
            mal "con el campo trampa completo, la reserva NO se descartó (${res##*|})"
        fi
    fi
fi

echo "== API =="
probar "la API rechaza sin clave"          "$BASE/api/estado"       "401"

echo "== API v1 (lo que entra desde la web) =="
# Sin clave: 401 antes de mirar nada más, aunque falte la migración.
res="$(pedir GET "$BASE/api/v1/disponibilidad?tipo=diagnostico")"
if [[ "${res##*|}" == "401" && "$res" == *'"error":"falta_clave"'* ]]; then
    bien "disponibilidad sin clave: 401 falta_clave"
else
    mal "disponibilidad sin clave → ${res##*|}, se esperaba 401 falta_clave"
fi
cabeceras="$(curl -s -m 25 -o /dev/null -D - "$BASE/api/v1/disponibilidad" | tr 'A-Z' 'a-z')"
if [[ "$cabeceras" == *"application/json"* ]]; then bien "responde JSON también en los errores"
else mal "el error de la API no vino como JSON"; fi
if [[ "$cabeceras" == *"cache-control: no-store"* ]]; then bien "y sin caché"
else mal "el error de la API se puede guardar en caché"; fi

res="$(pedir GET "$BASE/api/v1/reserva")"
if [[ "${res##*|}" == "405" ]]; then bien "reserva por GET: 405"
else mal "reserva por GET → ${res##*|}, se esperaba 405"; fi
res="$(pedir POST "$BASE/api/v1/disponibilidad")"
if [[ "${res##*|}" == "405" ]]; then bien "disponibilidad por POST: 405"
else mal "disponibilidad por POST → ${res##*|}, se esperaba 405"; fi

CLAVE_FALSA='crm_0000000000000000000000000000000000000000'
FIRMA_FALSA="$(printf '%064d' 0)"

# Hora vieja: 401 sin llegar a la base.
res="$(pedir POST "$BASE/api/v1/reserva" -H 'Content-Type: application/json' \
        -H "X-ITH-Key: $CLAVE_FALSA" -H "X-ITH-Timestamp: $(( $(date +%s) - 3600 ))" \
        -H "X-ITH-Signature: $FIRMA_FALSA" --data '{}')"
if [[ "${res##*|}" == "401" && "$res" == *'"error":"hora_fuera_de_rango"'* ]]; then
    bien "pedido con la hora vieja: 401 hora_fuera_de_rango"
else
    mal "pedido con la hora vieja → ${res##*|}, se esperaba 401 hora_fuera_de_rango"
fi

# Hora al día pero sin firma (o con una inventada): rechazado. 401 si la API
# está habilitada; 503 si en esa instalación todavía falta la migración o el
# secreto de la aplicación. Nunca un 2xx.
for recurso in reserva entrada; do
    for firma in "" "$FIRMA_FALSA"; do
        res="$(pedir POST "$BASE/api/v1/$recurso" -H 'Content-Type: application/json' \
                -H "X-ITH-Key: $CLAVE_FALSA" -H "X-ITH-Timestamp: $(date +%s)" \
                -H "X-ITH-Signature: $firma" -H 'X-ITH-Idempotency: smoke-000000000000000000' \
                --data '{"persona":{}}')"
        que="$([ -z "$firma" ] && echo 'sin firma' || echo 'con una firma inventada')"
        case "${res##*|}" in
            401|503) bien "$recurso $que: rechazado (${res##*|})" ;;
            *)       mal  "$recurso $que → ${res##*|}, se esperaba 401" ;;
        esac
    done
done

echo "== El código no se sirve por HTTP =="
probar "bootstrap.php bloqueado"           "$BASE/app/bootstrap.php"          "403"
probar "vistas bloqueadas"                 "$BASE/app/views/layout.php"       "403"
probar "controladores bloqueados"          "$BASE/app/pages/dashboard.php"    "403"
probar "el esquema no es accesible"        "$BASE/db/schema.sql"              "403|404"
probar "la config no es accesible"         "$BASE/server-config/config.php"   "403|404"
probar "la API v1 no se pide directo"      "$BASE/app/api.php"                "403"
probar "ni sus controladores"              "$BASE/app/pages/api/v1/reserva.php" "403"
probar "las migraciones no corren por HTTP" "$BASE/migrar.php"                "404"
probar "el reinicio no corre por HTTP"     "$BASE/reinicio.php"               "404"

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
