#!/usr/bin/env python3
"""
Pruebas de las herramientas en Python: bin/deploy.py y bin/unificar_listas.py.

Sólo funciones puras: no se conecta a ningún servidor ni lee datos reales.

Uso:  python test/herramientas_test.py
"""
# ============================================================
#  No forma parte de las baterías que corre `deploy.py test` (esas son PHP y
#  corren en el servidor): se corre en la máquina de desarrollo. Necesita
#  sólo la biblioteca estándar; paramiko no hace falta (deploy.py lo importa
#  recién al conectar).
# ============================================================
from __future__ import annotations

import os
import sys
import tempfile
import unittest

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(RAIZ, "bin"))

import deploy  # noqa: E402
import unificar_listas as U  # noqa: E402


class Argumentos(unittest.TestCase):
    def test_sin_destino_es_error(self):
        with self.assertRaises(deploy.ErrorUso):
            deploy.leer_argumentos(["app"])

    def test_un_typo_no_termina_en_produccion(self):
        with self.assertRaises(deploy.ErrorUso):
            deploy.leer_argumentos(["app", "--prueba"])

    def test_dos_destinos_es_error(self):
        with self.assertRaises(deploy.ErrorUso):
            deploy.leer_argumentos(["app", "--pruebas", "--produccion"])

    def test_produccion_explicita(self):
        self.assertEqual(deploy.leer_argumentos(["check", "--produccion"])[1], "produccion")

    def test_pruebas_en_cualquier_posicion(self):
        accion, destino, sueltos, op = deploy.leer_argumentos(
            ["migrar", "--pruebas", "app/db/migracion-018-indices-casillas.sql", "--simular"])
        self.assertEqual((accion, destino, sueltos), ("migrar", "pruebas",
                                                      ["app/db/migracion-018-indices-casillas.sql"]))
        self.assertIn("--simular", op)

    def test_opcion_de_otro_comando_es_error(self):
        with self.assertRaises(deploy.ErrorUso):
            deploy.leer_argumentos(["config", "--pruebas", "--limpiar"])

    def test_run_con_guiones_adentro_del_comando(self):
        _, destino, sueltos, _ = deploy.leer_argumentos(["run", "ls --all", "--pruebas"])
        self.assertEqual((destino, sueltos), ("pruebas", ["ls --all"]))

    def test_migrar_sin_archivo(self):
        with self.assertRaises(deploy.ErrorUso):
            deploy.leer_argumentos(["migrar", "--pruebas"])

    def test_ayuda_sin_conectar(self):
        with self.assertRaises(SystemExit) as c:
            deploy.main(["--help"])
        self.assertEqual(c.exception.code, 0)
        with self.assertRaises(SystemExit) as c:
            deploy.main(["app"])
        self.assertEqual(c.exception.code, 2)


class Config(unittest.TestCase):
    PRUEBAS = "<?php return ['db' => ['host' => 'localhost', 'name' => 'u566451014_crm_pruebas'], 'app' => []];"
    PROD = "<?php return ['db' => [\n 'name' => 'u566451014_crm',\n 'user' => 'x'],\n 'app' => ['name' => 'CRM']];"

    def test_pruebas_sube_crm_pruebas_php(self):
        self.assertEqual(deploy.DESTINOS["pruebas"]["config"], "crm-pruebas.php")
        self.assertEqual(deploy.DESTINOS["produccion"]["config"], "config.php")

    def test_lee_la_base_del_bloque_db(self):
        self.assertEqual(deploy.base_de_config(self.PROD), "u566451014_crm")

    def test_no_cruza_configs(self):
        self.assertIsNone(deploy.config_coincide("pruebas", self.PRUEBAS))
        self.assertIsNone(deploy.config_coincide("produccion", self.PROD))
        self.assertIsNotNone(deploy.config_coincide("produccion", self.PRUEBAS))
        self.assertIsNotNone(deploy.config_coincide("pruebas", self.PROD))
        self.assertIsNotNone(deploy.config_coincide("pruebas", "<?php return [];"))


class Secretos(unittest.TestCase):
    def test_excluye_por_patron(self):
        for n in ("config.php", "config.local.php", "app.local.php", "crm-pruebas.php", ".env",
                  ".env.prod", "clave.pem", "privada.key", "deploy.local.json", ".git"):
            self.assertTrue(deploy.excluido(n), n)

    def test_no_excluye_codigo(self):
        for n in ("index.php", "configuracion.php", "ajustes.php", ".htaccess", "app.css"):
            self.assertFalse(deploy.excluido(n), n)


class MysqlDefaults(unittest.TestCase):
    def test_contrasena_con_comillas_y_barras(self):
        txt = deploy.mysql_defaults("u1", "a'b\"c\\d $x")
        self.assertIn('password="a\'b\\"c\\\\d $x"', txt)
        self.assertTrue(txt.startswith("[client]\n"))

    def test_salto_de_linea_no(self):
        with self.assertRaises(ValueError):
            deploy.mysql_defaults("u", "a\nb")


class Limpieza(unittest.TestCase):
    def test_sobrantes(self):
        remotos = {"index.php", "viejo.php", "app", "app/x.php", "app/pages/borrada.php",
                   ".well-known", ".well-known/acme", "error_log", "config.local.php", ".user.ini"}
        locales = {"index.php", "app", "app/x.php"}
        self.assertEqual(deploy.sobrantes(remotos, locales), ["app/pages/borrada.php", "viejo.php"])


class CambioDeVersion(unittest.TestCase):
    """Los comandos de shell de `app` (cambio de carpetas, poda, --volver),
    corridos de verdad con bash en una carpeta temporal."""

    def bash(self, cmd: str) -> int:
        import shutil
        import subprocess
        b = shutil.which("bash")
        if not b:
            self.skipTest("sin bash")
        return subprocess.run([b, "-c", cmd], capture_output=True).returncode

    def armar(self, d: str, nombre: str, contenido: str) -> str:
        ruta = d + "/" + nombre
        os.makedirs(ruta, exist_ok=True)
        with open(ruta + "/index.php", "w") as f:
            f.write(contenido)
        return ruta

    def leer(self, ruta: str) -> str:
        with open(ruta + "/index.php") as f:
            return f.read()

    def test_activa_la_nueva_y_guarda_la_vieja(self):
        with tempfile.TemporaryDirectory() as d:
            d = d.replace("\\", "/")
            web, nueva = self.armar(d, "crm", "vieja"), self.armar(d, "build", "nueva")
            self.assertEqual(self.bash(deploy.comando_cambio(web, nueva, d + "/versiones/20260101-000000")), 0)
            self.assertEqual(self.leer(web), "nueva")
            self.assertEqual(self.leer(d + "/versiones/20260101-000000"), "vieja")
            self.assertFalse(os.path.exists(nueva))

    def test_si_falla_el_segundo_paso_el_sitio_queda_como_estaba(self):
        with tempfile.TemporaryDirectory() as d:
            d = d.replace("\\", "/")
            web = self.armar(d, "crm", "vieja")
            self.assertNotEqual(self.bash(deploy.comando_cambio(web, d + "/no-existe", d + "/versiones/x")), 0)
            self.assertEqual(self.leer(web), "vieja")

    def test_primera_instalacion_sin_webroot(self):
        with tempfile.TemporaryDirectory() as d:
            d = d.replace("\\", "/")
            nueva = self.armar(d, "build", "nueva")
            self.assertEqual(self.bash(deploy.comando_cambio(d + "/crm", nueva, d + "/versiones/x")), 0)
            self.assertEqual(self.leer(d + "/crm"), "nueva")

    def test_poda_deja_las_ultimas(self):
        with tempfile.TemporaryDirectory() as d:
            d = d.replace("\\", "/")
            nombres = [f"2026010{i}-000000" for i in range(1, 8)] + ["20260109-000000-descartada", "otra-cosa"]
            for n in nombres:
                os.makedirs(d + "/" + n)
            self.assertEqual(self.bash(deploy.comando_podar(d, 3)), 0)
            self.assertEqual(sorted(os.listdir(d)),
                             ["20260106-000000", "20260107-000000", "20260109-000000-descartada", "otra-cosa"])


class Unificar(unittest.TestCase):
    def ficha(self, **kw):
        f = U.ficha_vacia()
        f.update(kw)
        return f

    def test_no_se_pierde_el_id_de_la_segunda_ficha_de_zoho(self):
        a = self.ficha(zoho_id="Z1", nombre="Ana", origen={"DB Zoho"})
        b = self.ficha(zoho_id="Z2", nombre="Ana", origen={"DB Zoho"})
        U.fusionar(a, b)
        self.assertEqual(a["zoho_id"], "Z1")
        self.assertEqual(a["zoho_ids_fusionados"], ["Z2"])
        self.assertEqual(U.a_fila(a, "x")["IDs de Zoho fusionados"], "Z2")

    def test_argentina_ya_no_une_empresas(self):
        self.assertNotEqual(U.clave_empresa("Telecom Argentina"), U.clave_empresa("Telecom"))
        self.assertEqual(U.clave_empresa("Acme S.A."), U.clave_empresa("Acme"))

    def test_casilla_compartida_aunque_la_primera_sea_ambigua(self):
        e = [{"email": "fae@empresa.com", "estado": "", "tipo": ""}]
        fichas = [self.ficha(nombre="", apellido="Gomez", correos=list(e)),
                  self.ficha(nombre="Juan", apellido="Gomez", correos=list(e)),
                  self.ficha(nombre="Pedro", apellido="Gomez", correos=list(e))]
        self.assertIn("fae@empresa.com", U.casillas_compartidas(fichas))

    def test_codificacion_con_el_archivo_entero(self):
        with tempfile.TemporaryDirectory() as d:
            ruta = os.path.join(d, "x.csv")
            # 20 K de ASCII y después un carácter en cp1252: antes se abría
            # como UTF-8 y quedaba «�».
            with open(ruta, "wb") as f:
                f.write(b"Nombre;Apellidos\n" + b"a;b\n" * 6000 + "José;Pérez\n".encode("cp1252"))
            cod, sep = U.detectar_formato(ruta)
            self.assertEqual((cod, sep), ("cp1252", ";"))


if __name__ == "__main__":
    unittest.main(verbosity=1)
