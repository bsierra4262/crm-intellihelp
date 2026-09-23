#!/usr/bin/env python3
"""
Despliegue del CRM a Hostinger por SFTP/SSH.

    python bin/deploy.py app          sube app/web/ al webroot y app/cron.php
    ... cualquier comando --pruebas     lo hace sobre la instalacion de pruebas
                                        (public_html/crm-pruebas, base propia)
    python bin/deploy.py config       sube app/server-config/config.php fuera del webroot
    python bin/deploy.py schema       importa app/db/schema.sql en la base
    python bin/deploy.py run "<cmd>"  ejecuta un comando por SSH
    python bin/deploy.py test         sube test/ y corre las baterias alla
    python bin/deploy.py check        verifica sintaxis PHP de todo lo desplegado

Las credenciales NO viven en el repositorio: se leen de variables de entorno o
de `bin/deploy.local.json` (ignorado por git).

    CRM_SSH_HOST · CRM_SSH_PORT · CRM_SSH_USER · CRM_SSH_PASS
"""
from __future__ import annotations

import json
import os
import posixpath
import stat
import sys
from pathlib import Path

try:
    import paramiko
except ImportError:  # pragma: no cover
    sys.exit("Falta paramiko:  pip install paramiko")

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
# `--pruebas` al final del comando. El destino se decide aca y no a mano,
# porque copiar a la carpeta equivocada es exactamente el error que uno comete
# una vez y no vuelve a cometer nunca, despues de romper produccion.
BASE_HTML = "/home/u566451014/domains/intellihelp.tech/public_html"
CONFDIR   = "/home/u566451014/crm_config"

DESTINOS = {
    "produccion": {
        "webroot": BASE_HTML + "/crm",
        "storage": "/home/u566451014/crm_storage",
        "db":      "u566451014_crm",
        "url":     "https://intellihelp.tech/crm",
    },
    "pruebas": {
        "webroot": BASE_HTML + "/crm-pruebas",
        "storage": "/home/u566451014/crm_pruebas_storage",
        "db":      "u566451014_crm_pruebas",
        "url":     "https://intellihelp.tech/crm-pruebas",
    },
}

# Se resuelve una sola vez, al arrancar, mirando los argumentos.
DESTINO = "pruebas" if "--pruebas" in sys.argv else "produccion"
WEBROOT = DESTINOS[DESTINO]["webroot"]
STORAGE = DESTINOS[DESTINO]["storage"]
URL     = DESTINOS[DESTINO]["url"]

# Nunca se suben al servidor: son del repositorio, no de la aplicacion.
EXCLUIR = {".git", "__pycache__", ".DS_Store", "Thumbs.db", "config.php"}


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


def conectar() -> paramiko.SSHClient:
    c = credenciales()
    cli = paramiko.SSHClient()
    cli.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    cli.connect(c["host"], port=c["port"], username=c["user"], password=c["pass"], timeout=30)
    return cli


def ejecutar(cli: paramiko.SSHClient, cmd: str, silencioso: bool = False) -> tuple[int, str]:
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


def mkdirs(sftp: paramiko.SFTPClient, ruta: str) -> None:
    """mkdir -p sobre SFTP."""
    partes, acumulado = ruta.strip("/").split("/"), ""
    for p in partes:
        acumulado += "/" + p
        try:
            sftp.stat(acumulado)
        except IOError:
            sftp.mkdir(acumulado)


def subir_arbol(sftp: paramiko.SFTPClient, origen: Path, destino: str) -> int:
    """Sube un directorio completo. Devuelve la cantidad de archivos subidos."""
    n = 0
    mkdirs(sftp, destino)
    for item in sorted(origen.iterdir()):
        if item.name in EXCLUIR:
            continue
        remoto = posixpath.join(destino, item.name)
        if item.is_dir():
            n += subir_arbol(sftp, item, remoto)
        else:
            sftp.put(str(item), remoto)
            n += 1
            print(f"  → {remoto}")
    return n


def cmd_app(cli: paramiko.SSHClient) -> None:
    sftp = cli.open_sftp()
    print(f"Subiendo app/web/ → {WEBROOT}")
    n = subir_arbol(sftp, RAIZ / "app" / "web", WEBROOT)
    # Scripts que viven junto al webroot pero se corren por línea de comandos.
    # Los dos se niegan a ejecutarse por HTTP (devuelven 404).
    for nombre in ("cron.php", "reinicio.php"):
        local = RAIZ / "app" / nombre
        if not local.is_file():
            continue
        mkdirs(sftp, posixpath.dirname(WEBROOT))
        sftp.put(str(local), posixpath.join(WEBROOT, nombre))
        n += 1
        print(f"  → {WEBROOT}/{nombre}")
    sftp.close()
    print(f"{n} archivos subidos.")
    ejecutar(cli, f"mkdir -p {STORAGE} && chmod 750 {STORAGE}")


def cmd_config(cli: paramiko.SSHClient) -> None:
    local = RAIZ / "app" / "server-config" / "config.php"
    if not local.is_file():
        sys.exit("No existe app/server-config/config.php (copiá config.example.php y completalo).")
    sftp = cli.open_sftp()
    mkdirs(sftp, CONFDIR)
    sftp.put(str(local), f"{CONFDIR}/config.php")
    sftp.chmod(f"{CONFDIR}/config.php", stat.S_IRUSR | stat.S_IWUSR)  # 600
    sftp.close()
    ejecutar(cli, f"chmod 700 {CONFDIR}")
    print(f"config.php desplegado en {CONFDIR} (600).")


def cmd_schema(cli: paramiko.SSHClient) -> None:
    sql = RAIZ / "app" / "db" / "schema.sql"
    sftp = cli.open_sftp()
    remoto = "/home/u566451014/crm_schema.sql"
    sftp.put(str(sql), remoto)
    sftp.close()
    db = os.getenv("CRM_DB_NAME", "u566451014_crm")
    us = os.getenv("CRM_DB_USER", "u566451014_crm")
    pw = os.getenv("CRM_DB_PASS", "")
    if not pw:
        sys.exit("Definí CRM_DB_PASS para importar el esquema.")
    rc, _ = ejecutar(cli, f"mysql -h localhost -u {us} -p'{pw}' {db} < {remoto} && echo OK")
    ejecutar(cli, f"rm -f {remoto}", silencioso=True)
    sys.exit(rc)


def cmd_test(cli: paramiko.SSHClient) -> None:
    """Sube test/ y corre las baterias contra la instalacion elegida.

    En la maquina de Brian no hay PHP: el unico interprete disponible es el del
    hosting. Correr los tests alla ademas prueba la version exacta que atiende
    al usuario, que es la que importa.
    """
    remoto = "/home/u566451014/crm_test"
    sftp = cli.open_sftp()
    n = subir_arbol(sftp, RAIZ / "test", remoto)
    sftp.close()
    print(f"{n} archivos de prueba subidos.\n")

    fallos = 0
    for nombre, cmd in (
        ("logica",    f"cd {remoto} && CRM_SRC={WEBROOT}/app php run_tests.php"),
        ("interfaz",  f"cd {remoto} && CRM_SRC={WEBROOT} php ui_test.php"),
        ("HTTP",      f"cd {remoto} && bash smoke.sh {URL}"),
    ):
        print(f"----- {nombre} -----")
        rc, _ = ejecutar(cli, cmd)
        if rc != 0:
            fallos += 1
        print()
    sys.exit(1 if fallos else 0)


def cmd_check(cli: paramiko.SSHClient) -> None:
    rc, salida = ejecutar(
        cli,
        f"find {WEBROOT} -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors' || true",
    )
    print("Sintaxis PHP: OK" if not salida.strip() else "Revisar los errores de arriba.")


def main() -> None:
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)
    accion = sys.argv[1]
    # Que se vea SIEMPRE adonde va. Un despliegue silencioso a produccion
    # creyendo que iba a pruebas es la clase de error que no perdona.
    print(f"Destino: {DESTINO.upper()}  ({DESTINOS[DESTINO]['url']})")
    cli = conectar()
    try:
        if accion == "app":
            cmd_app(cli)
        elif accion == "config":
            cmd_config(cli)
        elif accion == "schema":
            cmd_schema(cli)
        elif accion == "test":
            cmd_test(cli)
        elif accion == "check":
            cmd_check(cli)
        elif accion == "run":
            rc, _ = ejecutar(cli, sys.argv[2] if len(sys.argv) > 2 else "echo ok")
            sys.exit(rc)
        else:
            print(__doc__)
            sys.exit(1)
    finally:
        cli.close()


if __name__ == "__main__":
    main()
