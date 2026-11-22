#!/usr/bin/env python3
"""
Despliegue del CRM a Hostinger por SFTP/SSH.

Todo comando necesita el destino, escrito a proposito: --pruebas o --produccion.

    python bin/deploy.py app --pruebas      sube app/web/ al webroot y, a su raiz,
                                            cron.php, reinicio.php y migrar.php
    python bin/deploy.py app --pruebas --limpiar
                                            ademas lista lo que hay en el servidor y ya
                                            no esta en el repositorio, y (con
                                            confirmacion) lo mueve fuera del webroot
    python bin/deploy.py config --pruebas   sube app/server-config/crm-pruebas.php
                                            a crm_config/crm-pruebas.php
    python bin/deploy.py config --produccion
                                            sube app/server-config/config.php
                                            a crm_config/config.php
    python bin/deploy.py schema --pruebas   importa app/db/schema.sql en una base VACIA
    python bin/deploy.py migrar app/db/migracion-NNN-....sql --pruebas [--simular]
                                            aplica una migracion con el migrar.php de la
                                            instalacion (su propia config y su propia base)
    python bin/deploy.py run "<cmd>" --pruebas
                                            ejecuta un comando por SSH
    python bin/deploy.py test --pruebas     sube test/ y corre las baterias alla
    python bin/deploy.py check --pruebas    verifica sintaxis PHP de todo lo desplegado

Las credenciales NO viven en el repositorio: se leen de variables de entorno o
de `bin/deploy.local.json` (ignorado por git).

    CRM_SSH_HOST · CRM_SSH_PORT · CRM_SSH_USER · CRM_SSH_PASS
    schema: CRM_DB_PASS_PRUEBAS / CRM_DB_PASS_PRODUCCION (o CRM_DB_PASS)

Clave del servidor: se compara con la conocida (~/.ssh/known_hosts o
bin/deploy.known_hosts). La primera vez, o si el servidor cambio de clave de
verdad, se acepta a mano con --aceptar-clave-nueva: muestra la huella, pide
confirmarla y la guarda en bin/deploy.known_hosts.
"""
# ============================================================
#  Notas para quien lo usa o lo cambia (el docstring de arriba es la ayuda
#  que se imprime; esto es lo que no entra ahi):
#
#  · No hay destino por defecto: sin --pruebas ni --produccion el script
#    corta con la ayuda. Un typo (--prueba) es una opcion desconocida y
#    tambien corta. Antes, cualquier cosa que no fuera --pruebas iba a
#    produccion sin preguntar.
#  · `--pruebas` cambia webroot, storage, base, URL y el archivo de
#    configuracion (crm-pruebas.php); la carpeta de configuracion (CONFDIR)
#    es una sola para las dos instalaciones.
#  · `app` sube archivo por archivo encima de lo que hay; mientras sube, el
#    sitio queda con una mezcla de archivos nuevos y viejos (son segundos).
#    Con --limpiar, lo que esta en el servidor y no en el repositorio se
#    MUEVE (no se borra) a /home/<usuario>/crm_retirados/<destino>/<fecha>/,
#    despues de mostrar la lista y pedir confirmacion.
#  · Credenciales: SSH por usuario y contrasena (variables CRM_SSH_* o
#    bin/deploy.local.json, ignorado por git). `schema` usa ademas la
#    contrasena de la base, que viaja en un archivo temporal 600 en el
#    servidor (--defaults-extra-file), nunca en la linea de comandos.
#  · Ningun archivo con pinta de secreto (config*.php, *.local.*, .env*,
#    *.pem, *.key, deploy.local.json, crm-pruebas.php…) se sube con `app`
#    ni con `test`, aunque este en la carpeta.
# ============================================================
from __future__ import annotations

import fnmatch
import json
import os
import posixpath
import shlex
import stat
import sys
import time
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent

# La consola de Windows usa cp1252 por defecto y revienta con los acentos y las
# flechas del log. Forzamos UTF-8 en la salida para que el deploy no se corte.
for _flujo in (sys.stdout, sys.stderr):
    try:
        _flujo.reconfigure(encoding="utf-8", errors="replace")
    except AttributeError:  # pragma: no cover
        pass

# Rutas en el servidor.
#
# Hay DOS instalaciones del mismo codigo: produccion y pruebas. Se elige con
# `--pruebas` o `--produccion`, siempre explicito. El destino se decide aca y
# no a mano, porque copiar a la carpeta equivocada es exactamente el error que
# uno comete una vez y no vuelve a cometer nunca, despues de romper produccion.
HOME      = "/home/u566451014"
BASE_HTML = HOME + "/domains/intellihelp.tech/public_html"
# Una sola carpeta de configuracion para las dos instalaciones. Adentro,
# bootstrap.php busca primero `<carpeta>.php` (crm-pruebas.php para pruebas)
# y si no esta cae en `config.php`, que es la de produccion.
CONFDIR   = HOME + "/crm_config"

# Por instalacion: carpeta publica, carpeta de archivos subidos (fuera del
# webroot), nombre de la base, URL y archivo de configuracion (local, en
# app/server-config/, y remoto, en CONFDIR, con el mismo nombre).
DESTINOS = {
    "produccion": {
        "webroot": BASE_HTML + "/crm",
        "storage": HOME + "/crm_storage",
        "db":      "u566451014_crm",
        "url":     "https://intellihelp.tech/crm",
        "config":  "config.php",
    },
    "pruebas": {
        "webroot": BASE_HTML + "/crm-pruebas",
        "storage": HOME + "/crm_pruebas_storage",
        "db":      "u566451014_crm_pruebas",
        "url":     "https://intellihelp.tech/crm-pruebas",
        "config":  "crm-pruebas.php",
    },
}

# Destino elegido. Lo fija main() con configurar_destino(); las funciones de
# los comandos lo leen de aca.
DESTINO = ""
WEBROOT = ""
STORAGE = ""
URL     = ""

# Opciones que entiende cada comando (ademas de --pruebas / --produccion).
OPCIONES = {
    "app":    {"--limpiar", "--si"},
    "config": set(),
    "schema": set(),
    "migrar": {"--simular"},
    "test":   set(),
    "check":  set(),
    "run":    set(),
}
OPCIONES_GLOBALES = {"--pruebas", "--produccion", "--aceptar-clave-nueva"}

# Nunca se suben al servidor: son del repositorio o son secretos locales. Se
# compara cada nombre (en cualquier nivel del arbol) contra estos patrones.
# Antes se comparaba solo el nombre exacto «config.php», y un
# config.local.php ignorado por git se subia al webroot.
EXCLUIR = (
    ".git", "__pycache__", ".DS_Store", "Thumbs.db",
    "config.php", "config.*.php", "*.local.php", "*.local.*", "crm-pruebas.php",
    ".env", ".env.*", "*.pem", "*.key", "id_rsa*", "*.sqlite", "*.sql.gz",
    "deploy.local.json", "deploy.known_hosts",
)

# Lo que --limpiar NUNCA toca en el servidor aunque no este en el
# repositorio: archivos que pone el hosting o el propio servidor.
CONSERVAR_EN_SERVIDOR = (
    ".well-known", "error_log", ".user.ini", "php.ini", ".htpasswd", "cgi-bin",
)

# Archivo propio de claves de servidores conocidas (ignorado por git).
KNOWN_HOSTS_LOCAL = RAIZ / "bin" / "deploy.known_hosts"


class ErrorUso(Exception):
    """Argumentos mal puestos: se informa con la ayuda y codigo 2."""


def excluido(nombre: str) -> bool:
    """True si un archivo o carpeta con ese nombre no se sube nunca."""
    return any(fnmatch.fnmatchcase(nombre, p) for p in EXCLUIR)


def leer_argumentos(argv: list[str]) -> tuple[str, str, list[str], set[str]]:
    """Valida los argumentos y devuelve (accion, destino, sueltos, opciones).

    `sueltos` son los argumentos que no empiezan con «--» (el .sql de
    migrar, el comando de run). Corta con ErrorUso si falta la accion, si no
    hay destino, si hay dos destinos o si una opcion no existe para ese
    comando (asi un typo como --prueba no termina en produccion).
    """
    if not argv:
        raise ErrorUso("Falta el comando.")
    accion, resto = argv[0], argv[1:]
    if accion not in OPCIONES:
        raise ErrorUso(f"Comando desconocido: {accion}")
    sueltos = [a for a in resto if not a.startswith("--")]
    opciones = {a for a in resto if a.startswith("--")}
    desconocidas = opciones - OPCIONES_GLOBALES - OPCIONES[accion]
    if desconocidas:
        raise ErrorUso("Opcion desconocida para «" + accion + "»: " + ", ".join(sorted(desconocidas)))
    destinos = opciones & {"--pruebas", "--produccion"}
    if not destinos:
        raise ErrorUso("Falta el destino: agregá --pruebas o --produccion.")
    if len(destinos) > 1:
        raise ErrorUso("Elegí un solo destino: --pruebas o --produccion.")
    destino = "pruebas" if "--pruebas" in destinos else "produccion"
    if accion == "migrar" and not sueltos:
        raise ErrorUso("Falta la migracion: migrar app/db/migracion-NNN-....sql")
    if accion == "run" and not sueltos:
        raise ErrorUso('Falta el comando: run "<cmd>"')
    return accion, destino, sueltos, opciones


def configurar_destino(destino: str) -> None:
    """Fija las rutas globales del destino elegido."""
    global DESTINO, WEBROOT, STORAGE, URL
    DESTINO = destino
    WEBROOT = DESTINOS[destino]["webroot"]
    STORAGE = DESTINOS[destino]["storage"]
    URL = DESTINOS[destino]["url"]


def base_de_config(texto: str) -> str | None:
    """Nombre de la base que declara un config.php (`'name' => '...'` dentro
    de 'db'), o None si no se encuentra. Sirve para no subir la config de
    pruebas como si fuera la de produccion, ni al reves."""
    import re
    bloque = re.search(r"'db'\s*=>\s*\[(.*?)\]", texto, re.S)
    if not bloque:
        return None
    m = re.search(r"'name'\s*=>\s*'([^']*)'", bloque.group(1))
    return m.group(1) if m else None


def config_coincide(destino: str, texto: str) -> str | None:
    """None si el config local corresponde al destino; si no, el motivo."""
    base = base_de_config(texto)
    if base is None:
        return "no se encontro el nombre de la base ('db' => ['name' => ...])"
    esperada = DESTINOS[destino]["db"]
    if base != esperada:
        return f"apunta a la base «{base}» y el destino {destino} usa «{esperada}»"
    return None


def mysql_defaults(usuario: str, clave: str) -> str:
    """Contenido de un archivo de opciones para --defaults-extra-file.

    Los valores van entre comillas dobles, con la barra invertida y la
    comilla escapadas, que es como el cliente mysql los lee. Asi la
    contrasena nunca aparece en la linea de comandos (que en un hosting
    compartido ven los demas procesos) y ningun caracter rompe el comando.
    """
    def cita(v: str) -> str:
        if "\n" in v or "\r" in v:
            raise ValueError("la contrasena no puede tener saltos de linea")
        return '"' + v.replace("\\", "\\\\").replace('"', '\\"') + '"'
    return f"[client]\nuser={cita(usuario)}\npassword={cita(clave)}\n"


def sobrantes(remotos: set[str], locales: set[str]) -> list[str]:
    """Rutas (relativas al webroot) que estan en el servidor y no en el
    repositorio, sin las que se conservan siempre: lo del hosting
    (CONSERVAR_EN_SERVIDOR) y lo que nunca se sube (secretos, EXCLUIR), que
    si esta alla es porque alguien lo puso a proposito."""
    salida = []
    for r in sorted(remotos - locales):
        partes = r.split("/")
        if any(p in CONSERVAR_EN_SERVIDOR or excluido(p) for p in partes):
            continue
        salida.append(r)
    return salida


# Datos de conexion SSH. Las variables de entorno mandan sobre el JSON local;
# el puerto por defecto es el SSH de Hostinger (65002). Si falta algo, corta
# con un mensaje y sin conectar.
def credenciales() -> dict:
    local = RAIZ / "bin" / "deploy.local.json"
    datos = json.loads(local.read_text(encoding="utf-8")) if local.is_file() else {}
    cred = {
        "host": os.getenv("CRM_SSH_HOST") or datos.get("host", ""),
        "port": int(os.getenv("CRM_SSH_PORT") or datos.get("port", 65002)),
        "user": os.getenv("CRM_SSH_USER") or datos.get("user", ""),
        "pass": os.getenv("CRM_SSH_PASS") or datos.get("pass", ""),
    }
    if not cred["host"] or not cred["user"] or not cred["pass"]:
        sys.exit(
            "Faltan credenciales. Definí CRM_SSH_HOST / CRM_SSH_USER / CRM_SSH_PASS\n"
            "o creá bin/deploy.local.json con {host, port, user, pass}."
        )
    return cred


def preguntar(texto: str) -> str:
    """Lee una respuesta del teclado; sin teclado (en un script) es «no»."""
    if not sys.stdin or not sys.stdin.isatty():
        return ""
    try:
        return input(texto).strip()
    except EOFError:
        return ""


# Abre la sesion SSH verificando la clave del servidor contra las conocidas
# (~/.ssh/known_hosts y bin/deploy.known_hosts). Un servidor desconocido o con
# otra clave se rechaza: es justo lo que haria un impostor. El respaldo para
# la primera vez (o un cambio de clave legitimo, que Hostinger avisa) es
# --aceptar-clave-nueva: muestra la huella SHA-256, pide confirmarla
# escribiendo «si» y la guarda en bin/deploy.known_hosts. La huella se puede
# comparar con la de hPanel (SSH Access) o con `ssh-keyscan -p 65002 <host>`.
def conectar(aceptar_nueva: bool = False):
    try:
        import paramiko
    except ImportError:  # pragma: no cover
        sys.exit("Falta paramiko:  pip install paramiko")
    import base64
    import hashlib

    c = credenciales()
    cli = paramiko.SSHClient()
    try:
        cli.load_system_host_keys()
    except OSError:
        pass
    if KNOWN_HOSTS_LOCAL.is_file():
        cli.load_host_keys(str(KNOWN_HOSTS_LOCAL))

    class Confirmar(paramiko.MissingHostKeyPolicy):
        def missing_host_key(self, client, hostname, key):
            huella = base64.b64encode(hashlib.sha256(key.asbytes()).digest()).decode().rstrip("=")
            print(f"Clave del servidor {hostname}: {key.get_name()} SHA256:{huella}")
            if preguntar("¿Es la huella correcta? Escribí «si» para aceptarla y guardarla: ").lower() != "si":
                raise SystemExit("Clave no aceptada: no se conecto.")
            client.get_host_keys().add(hostname, key.get_name(), key)
            client.save_host_keys(str(KNOWN_HOSTS_LOCAL))
            print(f"Guardada en {KNOWN_HOSTS_LOCAL.relative_to(RAIZ)}.")

    cli.set_missing_host_key_policy(Confirmar() if aceptar_nueva else paramiko.RejectPolicy())
    try:
        cli.connect(c["host"], port=c["port"], username=c["user"], password=c["pass"], timeout=30)
    except paramiko.BadHostKeyException as e:
        sys.exit(f"La clave del servidor NO coincide con la conocida ({e}). No se conecto.\n"
                 "Si Hostinger la cambio de verdad, sacá la linea vieja de bin/deploy.known_hosts\n"
                 "(o de ~/.ssh/known_hosts) y volvé a correr con --aceptar-clave-nueva.")
    except paramiko.SSHException as e:
        if "not found in known_hosts" in str(e):
            sys.exit("Servidor desconocido: su clave no esta en ~/.ssh/known_hosts ni en\n"
                     "bin/deploy.known_hosts. La primera vez, corré con --aceptar-clave-nueva\n"
                     "y compará la huella con la de hPanel antes de aceptarla.")
        raise
    return cli


# Corre un comando en el servidor (con un tope de 600 s) y devuelve
# (codigo de salida, salida + errores). Salvo `silencioso`, imprime lo que
# devolvio. El comando va tal cual al shell remoto: quien lo arma es
# responsable de las comillas (shlex.quote para lo variable).
def ejecutar(cli, cmd: str, silencioso: bool = False) -> tuple[int, str]:
    _, out, err = cli.exec_command(cmd, timeout=600)
    salida = out.read().decode("utf-8", "replace")
    error = err.read().decode("utf-8", "replace")
    rc = out.channel.recv_exit_status()
    if not silencioso:
        if salida.strip():
            print(salida.rstrip())
        if error.strip():
            print("stderr:", error.rstrip())
    return rc, salida + error


def mkdirs(sftp, ruta: str) -> None:
    """mkdir -p sobre SFTP."""
    partes, acumulado = ruta.strip("/").split("/"), ""
    for p in partes:
        acumulado += "/" + p
        try:
            sftp.stat(acumulado)
        except IOError:
            sftp.mkdir(acumulado)


def subir_arbol(sftp, origen: Path, destino: str) -> int:
    """Sube un directorio completo (salvo lo de EXCLUIR). Devuelve la
    cantidad de archivos subidos."""
    n = 0
    mkdirs(sftp, destino)
    for item in sorted(origen.iterdir()):
        if excluido(item.name):
            continue
        remoto = posixpath.join(destino, item.name)
        if item.is_dir():
            n += subir_arbol(sftp, item, remoto)
        else:
            sftp.put(str(item), remoto)
            n += 1
            print(f"  → {remoto}")
    return n


def arbol_local(origen: Path, prefijo: str = "") -> set[str]:
    """Rutas relativas (con «/») de lo que `subir_arbol` sube, carpetas
    incluidas."""
    salida: set[str] = set()
    for item in origen.iterdir():
        if excluido(item.name):
            continue
        rel = prefijo + item.name
        salida.add(rel)
        if item.is_dir():
            salida |= arbol_local(item, rel + "/")
    return salida


def arbol_remoto(sftp, raiz: str, prefijo: str = "") -> set[str]:
    """Rutas relativas de todo lo que hay bajo `raiz` en el servidor."""
    salida: set[str] = set()
    for a in sftp.listdir_attr(posixpath.join(raiz, prefijo) if prefijo else raiz):
        rel = prefijo + a.filename
        salida.add(rel)
        if stat.S_ISDIR(a.st_mode or 0):
            salida |= arbol_remoto(sftp, raiz, rel + "/")
    return salida


SCRIPTS_RAIZ = ("cron.php", "reinicio.php", "migrar.php")


# `app`: sube todo app/web/ al webroot del destino (pisando lo que haya) y los
# tres scripts de linea de comandos a su raiz. Crea la carpeta de
# almacenamiento si falta. No toca la base ni la config.
# Con --limpiar, ademas, retira lo que sobra (ver limpiar_sobrantes()).
def cmd_app(cli, opciones: set[str]) -> None:
    sftp = cli.open_sftp()
    print(f"Subiendo app/web/ → {WEBROOT}")
    n = subir_arbol(sftp, RAIZ / "app" / "web", WEBROOT)
    # Scripts que viven junto al webroot pero se corren por línea de comandos.
    # Ninguno se ejecuta por HTTP: reinicio.php y migrar.php devuelven 404, y
    # cron.php pide la clave que se configura en Ajustes.
    for nombre in SCRIPTS_RAIZ:
        local = RAIZ / "app" / nombre
        if not local.is_file():
            continue
        sftp.put(str(local), posixpath.join(WEBROOT, nombre))
        n += 1
        print(f"  → {WEBROOT}/{nombre}")
    print(f"{n} archivos subidos.")
    if "--limpiar" in opciones:
        limpiar_sobrantes(sftp, "--si" in opciones)
    sftp.close()
    ejecutar(cli, f"mkdir -p {shlex.quote(STORAGE)} && chmod 750 {shlex.quote(STORAGE)}")


# Lo que esta en el webroot y ya no en el repositorio (paginas borradas o
# renombradas, que seguirian accesibles) se muestra y, si se confirma, se
# MUEVE a ~/crm_retirados/<destino>/<fecha>/ conservando la ruta. No se borra
# nada: si algo hacia falta, se vuelve a poner en su lugar.
def limpiar_sobrantes(sftp, sin_preguntar: bool) -> None:
    locales = arbol_local(RAIZ / "app" / "web") | set(SCRIPTS_RAIZ)
    lista = sobrantes(arbol_remoto(sftp, WEBROOT), locales)
    # Si una carpeta entera sobra, alcanza con moverla a ella.
    lista = [r for r in lista if not any(r.startswith(o + "/") for o in lista)]
    if not lista:
        print("Limpieza: no sobra nada en el servidor.")
        return
    print(f"\nEn {WEBROOT} hay {len(lista)} cosa(s) que ya no estan en el repositorio:")
    for r in lista:
        print(f"  · {r}")
    destino = f"{HOME}/crm_retirados/{DESTINO}/{time.strftime('%Y%m%d-%H%M%S')}"
    if not sin_preguntar and preguntar(f"¿Moverlas a {destino}? Escribí «si»: ").lower() != "si":
        print("No se movio nada.")
        return
    for r in lista:
        nuevo = posixpath.join(destino, r)
        mkdirs(sftp, posixpath.dirname(nuevo))
        sftp.rename(posixpath.join(WEBROOT, r), nuevo)
        print(f"  ↷ {r}")
    print(f"Movidas a {destino}.")


# `config`: sube el archivo de configuracion del destino a CONFDIR con
# permisos 600 (y la carpeta 700): produccion = app/server-config/config.php
# → CONFDIR/config.php; pruebas = app/server-config/crm-pruebas.php →
# CONFDIR/crm-pruebas.php (los dos ignorados por git). Antes de subir
# comprueba que el archivo apunte a la base de ese destino: subir la config
# de pruebas como la de produccion (o al reves) cruzaria las bases.
def cmd_config(cli) -> None:
    nombre = DESTINOS[DESTINO]["config"]
    local = RAIZ / "app" / "server-config" / nombre
    if not local.is_file():
        sys.exit(f"No existe app/server-config/{nombre} (copiá config.example.php y completalo).")
    motivo = config_coincide(DESTINO, local.read_text(encoding="utf-8"))
    if motivo:
        sys.exit(f"No se sube app/server-config/{nombre}: {motivo}.")
    remoto = f"{CONFDIR}/{nombre}"
    sftp = cli.open_sftp()
    mkdirs(sftp, CONFDIR)
    sftp.chmod(CONFDIR, stat.S_IRWXU)  # 700
    sftp.put(str(local), remoto)
    sftp.chmod(remoto, stat.S_IRUSR | stat.S_IWUSR)  # 600
    sftp.close()
    print(f"{nombre} desplegado en {CONFDIR} (600).")


# `schema`: importa app/db/schema.sql con el cliente mysql del servidor, solo
# en una base VACIA (instalacion nueva). Sobre una base con tablas se niega:
# para cambiar una base en uso estan las migraciones (`migrar`). La base y el
# usuario salen del destino (no de variables de entorno, que podian quedar de
# otra sesion y mandar el esquema a otra base). La contrasena va en un
# archivo de opciones temporal con permisos 600, dentro de una carpeta 700,
# que se borra al terminar.
def cmd_schema(cli) -> None:
    db = DESTINOS[DESTINO]["db"]
    us = db
    pw = os.getenv(f"CRM_DB_PASS_{DESTINO.upper()}") or os.getenv("CRM_DB_PASS", "")
    if not pw:
        sys.exit(f"Definí CRM_DB_PASS_{DESTINO.upper()} (o CRM_DB_PASS) para importar el esquema.")
    carpeta = f"{HOME}/crm_migracion"
    opciones = f"{carpeta}/.my-{os.getpid()}.cnf"
    remoto = f"{carpeta}/schema-{os.getpid()}.sql"
    sftp = cli.open_sftp()
    mkdirs(sftp, carpeta)
    sftp.chmod(carpeta, stat.S_IRWXU)  # 700
    # El archivo se crea vacio, se le ponen permisos 600 y recien despues se
    # escribe la contrasena.
    with sftp.open(opciones, "w") as f:
        pass
    sftp.chmod(opciones, stat.S_IRUSR | stat.S_IWUSR)
    with sftp.open(opciones, "w") as f:
        f.write(mysql_defaults(us, pw))
    sftp.put(str(RAIZ / "app" / "db" / "schema.sql"), remoto)
    sftp.close()
    mysql = f"mysql --defaults-extra-file={shlex.quote(opciones)} -h localhost {shlex.quote(db)}"
    try:
        # Sin pipefail, un error de conexion daria «0 tablas» (el codigo de wc).
        rc, salida = ejecutar(cli, f"set -o pipefail; {mysql} -N -e 'SHOW TABLES' | wc -l", silencioso=True)
        tablas = salida.strip().splitlines()[-1].strip() if salida.strip() else ""
        if rc != 0 or not tablas.isdigit():
            print(salida.rstrip())
            sys.exit("No se pudo consultar la base: no se importo nada.")
        if int(tablas) > 0:
            sys.exit(f"La base {db} ya tiene {tablas} tablas: `schema` es solo para una instalacion nueva.\n"
                     "Para cambiar una base en uso, escribí una migracion y aplicala con `migrar`.")
        rc, _ = ejecutar(cli, f"{mysql} < {shlex.quote(remoto)} && echo OK")
    finally:
        ejecutar(cli, f"rm -f {shlex.quote(opciones)} {shlex.quote(remoto)}", silencioso=True)
    sys.exit(rc)


def cmd_migrar(cli, sueltos: list[str], opciones: set[str]) -> None:
    """Aplica una migracion con el migrar.php de la instalacion elegida.

    No hace falta ninguna contraseña de base: migrar.php arranca con el
    bootstrap de esa instalacion, que ya sabe a que base conectarse.
    """
    # Solo se acepta un .sql que este directamente en app/db/ (se resuelve la
    # ruta para que «../» no saque el archivo de ahi). Si se pasan varios,
    # se aplica solo el primero.
    local = (RAIZ / sueltos[0]).resolve()
    carpeta = (RAIZ / "app" / "db").resolve()
    if not local.is_file() or local.parent != carpeta or local.suffix != ".sql":
        sys.exit(f"No es una migracion de app/db/: {sueltos[0]}")

    # Fuera del webroot y con el nombre original: migrar.php saca de ahí el
    # número para dejar la marca `migracion_<nnn>`.
    carpeta_remota = f"{HOME}/crm_migracion"
    remoto = f"{carpeta_remota}/{local.name}"
    sftp = cli.open_sftp()
    mkdirs(sftp, carpeta_remota)
    sftp.chmod(carpeta_remota, stat.S_IRWXU)  # 700
    sftp.put(str(local), remoto)
    sftp.chmod(remoto, stat.S_IRUSR | stat.S_IWUSR)  # 600
    sftp.close()
    print(f"Migracion {local.name} subida. Aplicando en {DESTINO}...")
    simular = " --simular" if "--simular" in opciones else ""
    # El .sql se borra del servidor pase lo que pase. El codigo de salida es
    # el de migrar.php (1 si fallo alguna sentencia), para poder encadenarlo.
    # Requiere que `app` ya haya subido migrar.php a ese webroot.
    try:
        rc, _ = ejecutar(cli, f"php {shlex.quote(WEBROOT + '/migrar.php')} {shlex.quote(remoto)}{simular}")
    finally:
        ejecutar(cli, f"rm -f {shlex.quote(remoto)}", silencioso=True)
    sys.exit(rc)


def cmd_test(cli) -> None:
    """Sube test/ y corre las baterias contra la instalacion elegida.

    En la maquina de desarrollo el PHP no trae todas las extensiones del CRM: el
    interprete de referencia es el del hosting. Correr los tests alla ademas
    prueba la version exacta que atiende al usuario, que es la que importa.
    """
    # Una sola carpeta de pruebas para los dos destinos: lo que cambia es a
    # que webroot y URL apuntan las baterias (CRM_SRC, CRM_URL).
    remoto = f"{HOME}/crm_test"
    sftp = cli.open_sftp()
    n = subir_arbol(sftp, RAIZ / "test", remoto)
    sftp.close()
    print(f"{n} archivos de prueba subidos.\n")

    fallos = 0
    for nombre, cmd in (
        ("logica",    f"cd {remoto} && CRM_SRC={WEBROOT}/app php run_tests.php"),
        ("interfaz",  f"cd {remoto} && CRM_SRC={WEBROOT} php ui_test.php"),
        # En pruebas, el campo trampa de la agenda publica se prueba con el tipo
        # que siembra reinicio.php. En produccion la Agenda sigue apagada.
        ("HTTP",      f"cd {remoto} && bash smoke.sh {URL} "
                      + (shlex.quote(os.getenv("CRM_SMOKE_AGENDA", "bsierra/demo-30")) if DESTINO == "pruebas" else "")),
    ) + (
        # La bateria de la API escribe en la base (crea su propia clave, reserva
        # y cancela), asi que corre SOLO contra pruebas. Contra produccion no
        # se ofrece ni por error.
        (("API", f"cd {remoto} && CRM_WEBROOT={WEBROOT} CRM_SRC={WEBROOT}/app CRM_URL={URL} php api_test.php"),)
        if DESTINO == "pruebas" and (RAIZ / "test" / "api_test.php").is_file() else ()
    ):
        print(f"----- {nombre} -----")
        rc, _ = ejecutar(cli, cmd)
        if rc != 0:
            fallos += 1
        print()
    sys.exit(1 if fallos else 0)


# `check`: corre `php -l` sobre cada .php del webroot del destino y muestra
# solo los que tienen errores. El codigo de salida es siempre 0: sirve para
# mirar, no para cortar una cadena de comandos.
def cmd_check(cli) -> None:
    rc, salida = ejecutar(
        cli,
        f"find {shlex.quote(WEBROOT)} -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors' || true",
    )
    print("Sintaxis PHP: OK" if not salida.strip() else "Revisar los errores de arriba.")


def main(argv: list[str] | None = None) -> None:
    argv = sys.argv[1:] if argv is None else argv
    if not argv or argv[0] in ("-h", "--help", "help"):
        print(__doc__)
        sys.exit(0 if argv else 2)
    try:
        accion, destino, sueltos, opciones = leer_argumentos(argv)
    except ErrorUso as e:
        print(f"Error: {e}\n", file=sys.stderr)
        print(__doc__, file=sys.stderr)
        sys.exit(2)
    configurar_destino(destino)
    # Que se vea SIEMPRE adonde va.
    print(f"Destino: {DESTINO.upper()}  ({URL})")
    cli = conectar("--aceptar-clave-nueva" in opciones)
    try:
        if accion == "app":
            cmd_app(cli, opciones)
        elif accion == "config":
            cmd_config(cli)
        elif accion == "schema":
            cmd_schema(cli)
        elif accion == "migrar":
            cmd_migrar(cli, sueltos, opciones)
        elif accion == "test":
            cmd_test(cli)
        elif accion == "check":
            cmd_check(cli)
        elif accion == "run":
            rc, _ = ejecutar(cli, sueltos[0])
            sys.exit(rc)
    finally:
        cli.close()


if __name__ == "__main__":
    main()
