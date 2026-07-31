#!/usr/bin/env python3
"""
Unificación de las listas de contactos en una sola base limpia.

Qué resuelve
------------
El material llega en tres formatos distintos:

  · `DB Zoho.csv`                    — la base actual del CRM (71 columnas, en castellano)
  · exportaciones de LinkedIn        — 61 columnas, hasta 4 correos y 4 teléfonos por persona
  · listas simples de campaña        — 4 columnas, separador `;`, codificación Windows

…y entre todas hay la misma persona repetida varias veces, con datos distintos en cada una.

Qué hace
--------
1. **Normaliza** las tres formas a un único esquema.
2. **Fusiona los duplicados de TODO junto**, Zoho incluido: la base actual también tiene la
   misma persona cargada dos veces, así que deduplicar sólo las listas nuevas dejaría el
   problema adentro. La identidad es el correo: si dos filas comparten *cualquiera* de sus
   correos son la misma persona, y la fusión es transitiva (A comparte con B, B con C → los
   tres son uno). Las filas sin correo se comparan por nombre + empresa.
3. **No confunde casillas compartidas con personas.** Una dirección que usan dos personas
   distintas (`fae@empresa.com`, `info@…`) no identifica a nadie: se detecta sola —el mismo
   correo con dos apellidos distintos— y se excluye de la fusión.
4. **Marca lo que ya estaba en Zoho** para poder separar qué hay que cargar de nuevo.
5. **Ordena los correos por calidad**, para no quemar la reputación del remitente:
   primero los verificados, después los de dominio catch-all sin verificar.

Salida (en `CSV/Normalizada completa/`)
--------------------------------------
  · `contactos-unificados.csv`  — la base final completa, lista para importar al CRM
  · `contactos-nuevos.csv`      — sólo lo que NO estaba en Zoho
  · `contactos-en-zoho.csv`     — los que ya estaban, con los datos que aportan las listas
  · `duplicados.csv`            — auditoría: qué se fusionó con qué y por qué
  · `RESUMEN.md`                — el informe de la corrida

Uso
---
    python bin/unificar_listas.py                    # rutas por defecto
    python bin/unificar_listas.py --base "..." --nuevas "..." --salida "..."
"""
from __future__ import annotations

import argparse
import csv
import io
import os
import re
import sys
import unicodedata
from collections import Counter, defaultdict

csv.field_size_limit(10_000_000)

for _f in (sys.stdout, sys.stderr):
    try:
        _f.reconfigure(encoding="utf-8", errors="replace")
    except AttributeError:
        pass

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CSV_DIR = os.path.join(RAIZ, "Documentacion a consultar", "CSV")

BASE_ZOHO = os.path.join(CSV_DIR, "DB Zoho.csv")
DIR_NUEVAS = os.path.join(CSV_DIR, "Contactos a agregar a la lsita de zoho")
DIR_SALIDA = os.path.join(CSV_DIR, "Normalizada completa")


# ============================================================
#  Normalización
# ============================================================

def plano(s: str) -> str:
    """Minúsculas, sin acentos y sin espacios de más: para comparar."""
    s = unicodedata.normalize("NFKD", (s or "").strip().lower())
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"\s+", " ", s)


RE_EMAIL = re.compile(r"^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$")

# Direcciones que no son de una persona: no sirven para outreach nominal y
# ensucian la deduplicación (varias personas comparten info@empresa.com).
PREFIJOS_GENERICOS = (
    "info", "ventas", "contacto", "administracion", "admin", "rrhh", "sales",
    "soporte", "support", "hola", "consultas", "comercial", "recepcion",
    "compras", "marketing", "prensa", "noreply", "no-reply", "webmaster",
    "postmaster", "mailer-daemon", "contact", "office", "atencion",
)


def norm_email(v: str) -> str:
    v = (v or "").strip().lower()
    v = v.strip("<>\"' \t")
    return v if RE_EMAIL.match(v) else ""


def es_generico(email: str) -> bool:
    local = email.split("@", 1)[0]
    return local in PREFIJOS_GENERICOS


PROVINCIAS = [
    "Ciudad de Buenos Aires", "Buenos Aires", "Catamarca", "Chaco", "Chubut", "Córdoba",
    "Corrientes", "Entre Ríos", "Formosa", "Jujuy", "La Pampa", "La Rioja", "Mendoza",
    "Misiones", "Neuquén", "Río Negro", "Salta", "San Juan", "San Luis", "Santa Cruz",
    "Santa Fe", "Santiago del Estero", "Tierra del Fuego", "Tucumán",
]
ALIAS_PROV = {
    "caba": "Ciudad de Buenos Aires", "c.a.b.a.": "Ciudad de Buenos Aires",
    "capital federal": "Ciudad de Buenos Aires", "federal district": "Ciudad de Buenos Aires",
    "ciudad autonoma de buenos aires": "Ciudad de Buenos Aires",
    "buenos aires city": "Ciudad de Buenos Aires",
    "bs as": "Buenos Aires", "bs. as.": "Buenos Aires",
    "provincia de buenos aires": "Buenos Aires", "buenos aires province": "Buenos Aires",
    "cordoba": "Córdoba", "entre rios": "Entre Ríos", "neuquen": "Neuquén",
    "rio negro": "Río Negro", "tucuman": "Tucumán", "santiago del estero": "Santiago del Estero",
}
_PLANO_PROV = {plano(p): p for p in PROVINCIAS}


def norm_provincia(v: str) -> str:
    v = (v or "").strip()
    if not v:
        return ""
    p = plano(v)
    # LinkedIn escribe «Chubut Province» o «Provincia de Santa Fe»: se saca el
    # apéndice antes de comparar.
    p = re.sub(r"^(provincia de|province of)\s+", "", p)
    p = re.sub(r"\s+(province|provincia)$", "", p)
    if p in ALIAS_PROV:
        return ALIAS_PROV[p]
    if p in _PLANO_PROV:
        return _PLANO_PROV[p]
    return v


PAISES = {
    "argentina": "Argentina", "republica argentina": "Argentina", "ar": "Argentina",
    "mexico": "México", "chile": "Chile", "colombia": "Colombia", "brazil": "Brasil",
    "brasil": "Brasil", "peru": "Perú", "uruguay": "Uruguay", "spain": "España",
    "españa": "España", "united states": "Estados Unidos", "usa": "Estados Unidos",
    "united kingdom": "Reino Unido", "paraguay": "Paraguay", "bolivia": "Bolivia",
    "ecuador": "Ecuador", "panama": "Panamá", "germany": "Alemania", "australia": "Australia",
}


def norm_pais(v: str) -> str:
    v = (v or "").strip()
    if not v or v.startswith("http") or len(v) > 40:
        return ""
    return PAISES.get(plano(v), v)


def norm_localidad(v: str) -> str:
    """Descarta lo que claramente no es una ciudad (basura de LinkedIn)."""
    v = (v or "").strip()
    if not v or len(v) > 60 or v.startswith("http") or v.count(",") >= 2:
        return ""
    return v


def norm_tel(v: str) -> str:
    v = re.sub(r"[^\d+()\-\s.]", "", (v or "").strip())
    v = re.sub(r"\s+", " ", v).strip()
    return v if len(re.sub(r"\D", "", v)) >= 6 else ""


def norm_url(v: str) -> str:
    v = (v or "").strip()
    if not v or "." not in v:
        return ""
    if not v.startswith(("http://", "https://")):
        v = "https://" + v
    return v


def titulizar(v: str) -> str:
    """Arregla los nombres que vienen TODO EN MAYÚSCULAS o todo en minúsculas."""
    v = (v or "").strip()
    if not v:
        return ""
    if v.isupper() or v.islower():
        # Preserva partículas y siglas cortas ya escritas correctamente.
        chicas = {"de", "del", "la", "las", "los", "y", "da", "di", "van", "von"}
        piezas = []
        for i, w in enumerate(v.split()):
            piezas.append(w.lower() if (i and w.lower() in chicas) else w.capitalize())
        return " ".join(piezas)
    return v


SUFIJOS_EMPRESA = (
    " s.a.", " sa", " s.r.l.", " srl", " s.a.s.", " sas", " s.a", " sociedad anonima",
    " inc", " inc.", " llc", " ltd", " ltda", " limited", " corp", " corp.", " co.",
    " gmbh", " b.v.", " bv", " nv", " plc", " group", " grupo", " argentina",
)


def clave_empresa(v: str) -> str:
    """Nombre de empresa reducido a su forma comparable (sin tipo societario)."""
    p = plano(v)
    p = re.sub(r"[.,]", "", p)
    cambio = True
    while cambio:
        cambio = False
        for suf in SUFIJOS_EMPRESA:
            s = suf.strip().replace(".", "")
            if p.endswith(" " + s):
                p = p[: -(len(s) + 1)].strip()
                cambio = True
    return p


# ============================================================
#  Lectura de los tres formatos
# ============================================================

def abrir_csv(ruta: str):
    """Abre un CSV detectando codificación y separador. Devuelve un DictReader."""
    codificacion = None
    for cod in ("utf-8-sig", "utf-8", "cp1252", "latin-1"):
        try:
            with io.open(ruta, encoding=cod, newline="") as f:
                muestra = f.read(16384)
            codificacion = cod
            break
        except UnicodeDecodeError:
            continue
    if codificacion is None:
        raise RuntimeError(f"No se pudo determinar la codificación de {ruta}")
    primera = muestra.splitlines()[0] if muestra else ""
    sep = ";" if primera.count(";") > primera.count(",") else ","
    f = io.open(ruta, encoding=codificacion, newline="", errors="replace")
    return csv.DictReader(f, delimiter=sep), f, codificacion, sep


# Calidad del correo: primero lo verificado. Es lo que evita que una campaña
# arranque con 30 % de rebotes y el proveedor nos marque como spam.
ORDEN_ESTADO = {"valid": 0, "accept_all_unverifiable": 1, "": 2, "unknown": 2}
ORDEN_TIPO = {"main job": 0, "job": 1, "personal": 2, "": 3}


def correos_de_linkedin(fila: dict) -> list[dict]:
    salida = []
    for i in (1, 2, 3, 4):
        em = norm_email(fila.get(f"email{i}", ""))
        if not em:
            continue
        salida.append({
            "email": em,
            "estado": (fila.get(f"email{i}_status") or "").strip().lower(),
            "tipo": (fila.get(f"email{i}_type") or "").strip().lower(),
        })
    salida.sort(key=lambda e: (ORDEN_ESTADO.get(e["estado"], 2), ORDEN_TIPO.get(e["tipo"], 3)))
    return salida


def telefonos_de_linkedin(fila: dict) -> list[dict]:
    salida = []
    for i in (1, 2, 3, 4):
        t = norm_tel(fila.get(f"phone{i}", ""))
        if not t:
            continue
        salida.append({"tel": t, "tipo": (fila.get(f"phone{i}_type") or "").strip().lower()})
    return salida


def ficha_vacia() -> dict:
    return {
        "nombre": "", "apellido": "", "empresa": "", "cargo": "", "departamento": "",
        "correos": [], "telefonos": [], "localidad": "", "provincia": "", "pais": "",
        "sector": "", "sitio_web": "", "linkedin": "", "linkedin_empresa": "",
        "empleados": "", "descripcion": "", "propietario": "", "creado_en": "",
        "zoho_id": "", "origen": set(),
    }


def leer_linkedin(ruta: str) -> list[dict]:
    lector, fh, _, _ = abrir_csv(ruta)
    origen = os.path.splitext(os.path.basename(ruta))[0]
    fichas = []
    with fh:
        for fila in lector:
            f = ficha_vacia()
            nombre = titulizar(fila.get("first_name", ""))
            apellido = titulizar(fila.get("last_name", ""))
            if not nombre and not apellido:
                completo = titulizar(fila.get("full_name", ""))
                partes = completo.split()
                nombre = partes[0] if partes else ""
                apellido = " ".join(partes[1:]) if len(partes) > 1 else ""
            f["nombre"] = nombre
            f["apellido"] = apellido
            f["empresa"] = (fila.get("company_name") or "").strip()
            f["cargo"] = (fila.get("current_position") or fila.get("headline") or "").strip()
            f["correos"] = correos_de_linkedin(fila)
            f["telefonos"] = telefonos_de_linkedin(fila)
            # La dirección se toma como BLOQUE: o la de la persona o la de la empresa.
            # Mezclarlas producía cosas como «New York, Argentina», porque la persona
            # vive afuera y la empresa es argentina.
            persona = [fila.get("person_city", ""), fila.get("person_state", ""),
                       fila.get("person_country", "")]
            empresa = [fila.get("company_city", ""), fila.get("company_state", ""),
                       fila.get("company_country", "")]
            ciudad, estado, pais = persona if any(x.strip() for x in persona) else empresa
            f["localidad"] = norm_localidad(ciudad)
            f["provincia"] = norm_provincia(estado)
            f["pais"] = norm_pais(pais)
            # Si la provincia es argentina y no vino el país, se deduce. Al revés no:
            # un país sin provincia no permite inventar la provincia.
            if not f["pais"] and f["provincia"] in PROVINCIAS:
                f["pais"] = "Argentina"
            # País y provincia incompatibles: la provincia manda, porque es el dato más
            # específico. Se limpia el país en vez de afirmar algo falso.
            if f["pais"] == "Argentina" and f["provincia"] and f["provincia"] not in PROVINCIAS:
                f["pais"] = ""
            f["sector"] = (fila.get("person_industry") or "").strip()
            f["sitio_web"] = norm_url(fila.get("company_website", ""))
            f["linkedin"] = norm_url(fila.get("person_linkedin_url", ""))
            f["linkedin_empresa"] = norm_url(fila.get("company_linkedin_url", ""))
            desde = (fila.get("company_size_from") or "").strip()
            hasta = (fila.get("company_size_to") or "").strip()
            f["empleados"] = f"{desde}-{hasta}" if desde or hasta else ""
            f["origen"] = {origen}
            fichas.append(f)
    return fichas


def leer_lista_simple(ruta: str) -> list[dict]:
    lector, fh, _, _ = abrir_csv(ruta)
    origen = os.path.splitext(os.path.basename(ruta))[0]
    fichas = []
    with fh:
        for fila in lector:
            f = ficha_vacia()
            f["nombre"] = titulizar(fila.get("first_name", ""))
            f["apellido"] = titulizar(fila.get("last_name", ""))
            f["empresa"] = (fila.get("company_name") or "").strip()
            em = norm_email(fila.get("email1", ""))
            if em:
                f["correos"] = [{"email": em, "estado": "", "tipo": ""}]
            f["origen"] = {origen}
            fichas.append(f)
    return fichas


def leer_zoho(ruta: str) -> list[dict]:
    lector, fh, _, _ = abrir_csv(ruta)
    fichas = []
    with fh:
        for fila in lector:
            f = ficha_vacia()
            f["zoho_id"] = (fila.get("ID de registro") or "").strip()
            f["nombre"] = (fila.get("Nombre") or "").strip()
            f["apellido"] = (fila.get("Apellidos") or "").strip()
            f["empresa"] = (fila.get("Nombre de Cuenta") or "").strip()
            f["cargo"] = (fila.get("Título") or "").strip()
            f["departamento"] = (fila.get("Departamento") or "").strip()
            for col in ("Correo electrónico", "Correo electrónico secundario",
                        "Correo electrónico terciario"):
                em = norm_email(fila.get(col, ""))
                if em and em not in [c["email"] for c in f["correos"]]:
                    f["correos"].append({"email": em, "estado": "zoho", "tipo": ""})
            directo = norm_tel(fila.get("Teléfono Directo") or fila.get("Teléfono", ""))
            movil = norm_tel(fila.get("Móvil", ""))
            conmutador = norm_tel(fila.get("Conmutador") or fila.get("Fax", ""))
            for t, tipo in ((directo, "work"), (movil, "mobile"), (conmutador, "switchboard")):
                if t:
                    f["telefonos"].append({"tel": t, "tipo": tipo})
            f["localidad"] = norm_localidad(fila.get("Localidad")
                                            or fila.get("Ciudad para correspondencia", ""))
            f["provincia"] = norm_provincia(fila.get("Provincia", ""))
            f["pais"] = norm_pais(fila.get("País para correspondencia", ""))
            f["sitio_web"] = norm_url(fila.get("Página Web", ""))
            f["linkedin"] = norm_url(fila.get("LinkedIn Personal", ""))
            f["descripcion"] = (fila.get("Descripción") or "").strip()
            f["propietario"] = (fila.get("Propietario de Contacto") or "").strip()
            f["creado_en"] = (fila.get("Hora de creación") or "").strip()
            f["origen"] = {"DB Zoho"}
            fichas.append(f)
    return fichas


# ============================================================
#  Fusión
# ============================================================

def clave_persona(f: dict) -> str:
    """Clave de respaldo para las fichas sin correo: nombre + empresa."""
    n = plano(f["nombre"] + " " + f["apellido"])
    e = clave_empresa(f["empresa"])
    return f"{n}|{e}" if n and e else ""


def _tokens(v: str) -> set[str]:
    """Palabras significativas de un nombre, sin puntuación ni tratamientos."""
    p = re.sub(r"[^\wáéíóúñü ]", " ", plano(v))
    fuera = {"sr", "sra", "srta", "dr", "dra", "ing", "lic", "cr", "cra", "clor", "mg", "arq"}
    return {t for t in p.split() if len(t) > 1 and t not in fuera}


def mismo_apellido(a: str, b: str) -> bool:
    """
    ¿Dos apellidos pueden ser el mismo escrito distinto?

    La base viene con apellidos abreviados ("Chris B." por "Chris Bevacqua") y
    con basura pegada ("Arzeno- Finanzas"). Se acepta que uno sea prefijo del
    otro; el nombre de pila termina de decidir.
    """
    a, b = re.sub(r"[^\w ]", "", plano(a)).strip(), re.sub(r"[^\w ]", "", plano(b)).strip()
    if not a or not b:
        return True
    return a.startswith(b) or b.startswith(a)


def misma_persona(f1: dict, f2: dict) -> bool:
    """¿Las dos fichas parecen la misma persona? (para juzgar un correo compartido)"""
    if not mismo_apellido(f1["apellido"], f2["apellido"]):
        return False
    n1, n2 = _tokens(f1["nombre"]), _tokens(f2["nombre"])
    if not n1 or not n2:
        return True
    # Alcanza con que compartan un nombre de pila, o que uno sea la inicial del
    # otro: "Luz Maria" y "Luz M." son la misma persona.
    if n1 & n2:
        return True
    return any(x.startswith(y) or y.startswith(x) for x in n1 for y in n2)


def casillas_compartidas(fichas: list[dict]) -> set[str]:
    """
    Correos que NO identifican a una persona porque los usan varios.

    Se detecta con los datos, no con una lista fija de prefijos: si la misma
    dirección aparece con personas que claramente NO son la misma, es una
    casilla de empresa (`info@`, `ventas@`, `fae@…`) y fusionar por ella
    convertiría a varias personas en un solo contacto.

    La comparación es tolerante con los nombres mal escritos: lo que se busca
    es la evidencia de que son personas *distintas*, no de que estén escritas
    igual.
    """
    por_email: dict[str, list[dict]] = defaultdict(list)
    for f in fichas:
        if not plano(f["apellido"]) and not plano(f["nombre"]):
            continue
        for c in f["correos"]:
            if len(por_email[c["email"]]) < 12:      # con 12 alcanza para decidir
                por_email[c["email"]].append(f)

    compartidas = set()
    for email, grupo in por_email.items():
        if len(grupo) < 2:
            continue                                  # una sola ficha: nada que decidir
        base = grupo[0]
        if any(not misma_persona(base, otra) for otra in grupo[1:]):
            compartidas.add(email)
            continue
        # Con una casilla de tipo `info@` o `administracion@` no alcanza con que
        # los nombres coincidan: si además aparece en dos empresas distintas,
        # es una casilla genérica y no identifica a nadie.
        if es_generico(email):
            empresas = {clave_empresa(g["empresa"]) for g in grupo if g["empresa"].strip()}
            if len(empresas) > 1:
                compartidas.add(email)
    return compartidas


def fusionar(destino: dict, extra: dict) -> None:
    """Vuelca `extra` sobre `destino` sin pisar lo que ya tiene valor."""
    for campo in ("nombre", "apellido", "empresa", "cargo", "departamento", "localidad",
                  "provincia", "pais", "sector", "sitio_web", "linkedin", "linkedin_empresa",
                  "empleados", "propietario", "creado_en", "zoho_id"):
        if not destino[campo] and extra[campo]:
            destino[campo] = extra[campo]
    # La descripción se acumula: son notas escritas por gente distinta.
    if extra["descripcion"] and extra["descripcion"] not in destino["descripcion"]:
        destino["descripcion"] = " · ".join(x for x in (destino["descripcion"],
                                                        extra["descripcion"]) if x)
    vistos = {c["email"] for c in destino["correos"]}
    for c in extra["correos"]:
        if c["email"] not in vistos:
            destino["correos"].append(c)
            vistos.add(c["email"])
    vistos_t = {re.sub(r"\D", "", t["tel"]) for t in destino["telefonos"]}
    for t in extra["telefonos"]:
        d = re.sub(r"\D", "", t["tel"])
        if d not in vistos_t:
            destino["telefonos"].append(t)
            vistos_t.add(d)
    destino["origen"] |= extra["origen"]


class UnionFind:
    """Agrupa fichas que comparten al menos un correo, de forma transitiva."""

    def __init__(self) -> None:
        self.padre: dict[int, int] = {}

    def buscar(self, x: int) -> int:
        self.padre.setdefault(x, x)
        while self.padre[x] != x:
            self.padre[x] = self.padre[self.padre[x]]
            x = self.padre[x]
        return x

    def unir(self, a: int, b: int) -> None:
        ra, rb = self.buscar(a), self.buscar(b)
        if ra != rb:
            self.padre[rb] = ra


def deduplicar(fichas: list[dict], registro: list[dict]) -> tuple[list[dict], set[str]]:
    """
    Fusiona las fichas que son la misma persona.

    Las fichas se procesan en el orden en que llegan y la PRIMERA de cada grupo
    es la que queda como base: por eso las de Zoho van primero, para conservar
    su identificador, su propietario y su fecha de alta.

    Devuelve (fichas únicas, casillas compartidas detectadas).
    """
    compartidas = casillas_compartidas(fichas)

    uf = UnionFind()
    por_email: dict[str, int] = {}
    por_linkedin: dict[str, int] = {}
    por_nombre: dict[str, int] = {}

    for i, f in enumerate(fichas):
        uf.buscar(i)
        for c in f["correos"]:
            em = c["email"]
            if em in compartidas:
                continue
            if em in por_email:
                uf.unir(por_email[em], i)
                registro.append({"motivo": "mismo correo", "clave": em,
                                 "se_fusiona": f'{f["nombre"]} {f["apellido"]} · {f["empresa"]}',
                                 "origen": " + ".join(sorted(f["origen"]))})
            else:
                por_email[em] = i

        # El perfil de LinkedIn es identidad fuerte: una URL, una persona. Sirve
        # para unir fichas que no comparten ningún correo.
        li = plano(f["linkedin"]).rstrip("/")
        if li:
            if li in por_linkedin:
                uf.unir(por_linkedin[li], i)
                registro.append({"motivo": "mismo perfil de LinkedIn", "clave": li,
                                 "se_fusiona": f'{f["nombre"]} {f["apellido"]} · {f["empresa"]}',
                                 "origen": " + ".join(sorted(f["origen"]))})
            else:
                por_linkedin[li] = i

    # Segunda pasada: mismo nombre completo Y misma empresa es la misma persona,
    # aunque tenga cargados correos distintos en cada lista. Sin empresa NO se
    # agrupa: hay demasiados homónimos y fusionar a dos personas distintas es
    # peor que dejar un duplicado.
    for i, f in enumerate(fichas):
        k = clave_persona(f)
        if not k:
            continue
        if k in por_nombre:
            if uf.buscar(por_nombre[k]) != uf.buscar(i):
                uf.unir(por_nombre[k], i)
                registro.append({"motivo": "mismo nombre y empresa", "clave": k,
                                 "se_fusiona": f'{f["nombre"]} {f["apellido"]} · {f["empresa"]}',
                                 "origen": " + ".join(sorted(f["origen"]))})
        else:
            por_nombre[k] = i

    grupos: dict[int, list[int]] = defaultdict(list)
    for i in range(len(fichas)):
        grupos[uf.buscar(i)].append(i)

    salida = []
    for miembros in grupos.values():
        miembros.sort()
        base = fichas[miembros[0]]
        for j in miembros[1:]:
            fusionar(base, fichas[j])
        # Reordenar los correos por calidad después de fusionar.
        base["correos"].sort(key=lambda e: (ORDEN_ESTADO.get(e["estado"], 2),
                                            ORDEN_TIPO.get(e["tipo"], 3)))
        salida.append(base)
    return salida, compartidas


# ============================================================
#  Escritura
# ============================================================

# Columnas con los nombres que ya entiende el importador del CRM, más unas
# pocas propias para no perder lo que aportan las listas de LinkedIn.
COLUMNAS = [
    "ID de registro", "Nombre", "Apellidos", "Nombre de Cuenta", "Título", "Departamento",
    "Correo electrónico", "Correo electrónico secundario", "Correo electrónico terciario",
    "Otros correos", "Estado del correo", "Teléfono Directo", "Móvil", "Conmutador",
    "Localidad", "Provincia", "País para correspondencia", "Sector", "Página Web",
    "LinkedIn Personal", "LinkedIn Empresa", "Empleados", "Descripción",
    "Propietario de Contacto", "Hora de creación", "Estado en el CRM", "Origen de la lista",
]


def a_fila(f: dict, estado_crm: str) -> dict:
    # Coherencia geográfica, ya con la ficha fusionada: si la provincia no es
    # argentina, el país no puede decir Argentina. Pasa cuando el país viene de
    # Zoho y la provincia de LinkedIn (la persona se mudó o trabaja afuera).
    provincia, pais = f["provincia"], f["pais"]
    if pais == "Argentina" and provincia and provincia not in PROVINCIAS:
        pais = ""
    if not pais and provincia in PROVINCIAS:
        pais = "Argentina"

    correos = [c["email"] for c in f["correos"]]
    est = f["correos"][0]["estado"] if f["correos"] else ""
    directo = next((t["tel"] for t in f["telefonos"] if t["tipo"] in ("work", "direct")), "")
    movil = next((t["tel"] for t in f["telefonos"] if t["tipo"] in ("mobile", "cell")), "")
    resto = [t["tel"] for t in f["telefonos"] if t["tel"] not in (directo, movil)]
    conmutador = resto[0] if resto else ""
    if not directo and not movil and resto:
        directo, conmutador = resto[0], (resto[1] if len(resto) > 1 else "")
    return {
        "ID de registro": f["zoho_id"],
        "Nombre": f["nombre"],
        "Apellidos": f["apellido"],
        "Nombre de Cuenta": f["empresa"],
        "Título": f["cargo"],
        "Departamento": f["departamento"],
        "Correo electrónico": correos[0] if len(correos) > 0 else "",
        "Correo electrónico secundario": correos[1] if len(correos) > 1 else "",
        "Correo electrónico terciario": correos[2] if len(correos) > 2 else "",
        "Otros correos": ", ".join(correos[3:]),
        "Estado del correo": {"valid": "Verificado",
                              "accept_all_unverifiable": "Sin verificar (dominio catch-all)",
                              "zoho": "Ya estaba en el CRM"}.get(est, "Desconocido") if correos else "",
        "Teléfono Directo": directo,
        "Móvil": movil,
        "Conmutador": conmutador,
        "Localidad": f["localidad"],
        "Provincia": provincia,
        "País para correspondencia": pais,
        "Sector": f["sector"],
        "Página Web": f["sitio_web"],
        "LinkedIn Personal": f["linkedin"],
        "LinkedIn Empresa": f["linkedin_empresa"],
        "Empleados": f["empleados"],
        "Descripción": f["descripcion"],
        "Propietario de Contacto": f["propietario"],
        "Hora de creación": f["creado_en"],
        "Estado en el CRM": estado_crm,
        "Origen de la lista": " + ".join(sorted(f["origen"])),
    }


def escribir(ruta: str, filas: list[dict], columnas: list[str]) -> None:
    os.makedirs(os.path.dirname(ruta), exist_ok=True)
    # BOM + separador ';' porque el equipo abre estos archivos con Excel en español.
    with io.open(ruta, "w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=columnas, delimiter=";", quoting=csv.QUOTE_MINIMAL)
        w.writeheader()
        for fila in filas:
            w.writerow(fila)


# ============================================================
#  Programa principal
# ============================================================

def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--base", default=BASE_ZOHO, help="CSV de la base actual de Zoho")
    ap.add_argument("--nuevas", default=DIR_NUEVAS, help="Carpeta con las listas nuevas")
    ap.add_argument("--salida", default=DIR_SALIDA, help="Carpeta de salida")
    args = ap.parse_args()

    print("=" * 70)
    print("  UNIFICACIÓN DE LISTAS DE CONTACTOS")
    print("=" * 70)

    # ---- 1. Leer las listas nuevas ----
    archivos = sorted(f for f in os.listdir(args.nuevas) if f.lower().endswith(".csv"))
    if not archivos:
        print(f"No hay CSV en {args.nuevas}")
        return 1

    nuevas: list[dict] = []
    detalle_archivos = []
    for nombre in archivos:
        ruta = os.path.join(args.nuevas, nombre)
        lector, fh, cod, sep = abrir_csv(ruta)
        columnas = lector.fieldnames or []
        fh.close()
        formato = "LinkedIn" if "person_linkedin_url" in columnas else "lista simple"
        fichas = leer_linkedin(ruta) if formato == "LinkedIn" else leer_lista_simple(ruta)
        nuevas.extend(fichas)
        detalle_archivos.append((nombre, formato, len(fichas), cod, sep))
        print(f"  leído  {len(fichas):>6} filas  ·  {formato:<13} ·  {nombre}")

    print(f"\n  Total de filas en las listas nuevas: {len(nuevas)}")

    # ---- 2. Leer la base actual ----
    zoho = leer_zoho(args.base)
    print(f"  Base actual de Zoho: {len(zoho)} contactos")

    # ---- 3. Deduplicar TODO junto, con Zoho primero ----
    # Zoho va adelante para que su ficha sea la base de cada fusión y conserve
    # el identificador, el propietario y la fecha de alta originales. Incluirlo
    # en la misma pasada es lo que además limpia los duplicados que la propia
    # base de Zoho ya traía adentro.
    registro_dup: list[dict] = []
    unicas, compartidas = deduplicar(zoho + nuevas, registro_dup)

    dup_zoho = sum(1 for d in registro_dup if d["origen"] == "DB Zoho")
    print(f"\n  Filas leídas en total: {len(zoho) + len(nuevas)}")
    print(f"  Después de fusionar duplicados: {len(unicas)}"
          f"  (se fusionaron {len(zoho) + len(nuevas) - len(unicas)})")
    print(f"    · de los cuales ya estaban duplicados DENTRO de Zoho: {dup_zoho}")
    print(f"  Casillas compartidas detectadas (no identifican a una persona): {len(compartidas)}")

    # ---- 4. Separar lo que ya estaba de lo que es nuevo ----
    ya_estaban = [f for f in unicas if "DB Zoho" in f["origen"]]
    nuevos = [f for f in unicas if "DB Zoho" not in f["origen"]]
    enriquecidos = sum(1 for f in ya_estaban if len(f["origen"]) > 1)

    print(f"\n  Contactos que ya estaban en el CRM: {len(ya_estaban)}"
          f"  (de ellos, {enriquecidos} se completaron con datos de las listas nuevas)")
    print(f"  Contactos realmente nuevos:         {len(nuevos)}")

    # ---- 5. Base final ----
    finales = [a_fila(f, "Ya estaba en el CRM" if "DB Zoho" in f["origen"] else "Nuevo")
               for f in unicas]
    finales.sort(key=lambda r: (plano(r["Nombre de Cuenta"]), plano(r["Apellidos"]),
                                plano(r["Nombre"])))

    con_correo = sum(1 for r in finales if r["Correo electrónico"])
    verificados = sum(1 for r in finales if r["Estado del correo"] == "Verificado")
    catch_all = sum(1 for r in finales
                    if r["Estado del correo"] == "Sin verificar (dominio catch-all)")

    print(f"\n  BASE FINAL UNIFICADA: {len(finales)} contactos")
    print(f"    · con correo: {con_correo} ({100*con_correo/max(1,len(finales)):.1f} %)")
    print(f"    · correos verificados: {verificados}")
    print(f"    · correos de dominio catch-all (riesgo de rebote): {catch_all}")

    # ---- 6. Escribir ----
    os.makedirs(args.salida, exist_ok=True)
    escribir(os.path.join(args.salida, "contactos-unificados.csv"), finales, COLUMNAS)
    escribir(os.path.join(args.salida, "contactos-nuevos.csv"),
             [r for r in finales if r["Estado en el CRM"] == "Nuevo"], COLUMNAS)
    escribir(os.path.join(args.salida, "contactos-en-zoho.csv"),
             [r for r in finales if r["Estado en el CRM"] == "Ya estaba en el CRM"], COLUMNAS)
    escribir(os.path.join(args.salida, "duplicados.csv"), registro_dup,
             ["motivo", "clave", "se_fusiona", "origen"])

    motivos = Counter(d["motivo"] for d in registro_dup)
    empresas = len({clave_empresa(r["Nombre de Cuenta"]) for r in finales
                    if r["Nombre de Cuenta"]})
    sectores = Counter(r["Sector"] for r in finales if r["Sector"])
    provincias = Counter(r["Provincia"] for r in finales if r["Provincia"])

    resumen = [
        "# Base de contactos unificada\n",
        f"> Generado por `bin/unificar_listas.py`. {len(finales)} contactos únicos.\n",
        "## De dónde sale\n",
        "| Archivo | Formato | Filas |",
        "|---|---|---:|",
    ]
    for nombre, formato, n, _, _ in detalle_archivos:
        resumen.append(f"| {nombre} | {formato} | {n} |")
    resumen += [
        f"| **DB Zoho.csv** | base actual del CRM | {len(zoho)} |",
        f"| | **Total leído** | **{len(nuevas) + len(zoho)}** |",
        "\n## Qué pasó al unificar\n",
        "Todo se deduplicó **en una sola pasada**, con la base de Zoho incluida: la base actual",
        "también tenía la misma persona cargada dos veces, así que limpiar sólo las listas",
        "nuevas habría dejado el problema adentro.\n",
        "| Paso | Cantidad |",
        "|---|---:|",
        f"| Filas en las listas nuevas | {len(nuevas)} |",
        f"| Filas en la base actual de Zoho | {len(zoho)} |",
        f"| **Total leído** | **{len(zoho) + len(nuevas)}** |",
        f"| Duplicados fusionados | {len(zoho) + len(nuevas) - len(unicas)} |",
        f"| …de ellos, duplicados que ya venían **dentro de Zoho** | {dup_zoho} |",
        f"| Contactos que **ya estaban** en el CRM | {len(ya_estaban)} |",
        f"| …de ellos, **completados** con datos de las listas nuevas | {enriquecidos} |",
        f"| Contactos **realmente nuevos** | {len(nuevos)} |",
        f"| **BASE FINAL** | **{len(finales)}** |",
        "\n### Por qué se fusionó cada duplicado\n",
        "| Motivo | Cantidad |",
        "|---|---:|",
    ]
    for k, v in motivos.most_common():
        resumen.append(f"| {k} | {v} |")
    resumen += [
        "\n## Calidad de la base final\n",
        "| Dato | Cantidad |",
        "|---|---:|",
        f"| Contactos | {len(finales)} |",
        f"| Con correo | {con_correo} ({100*con_correo/max(1,len(finales)):.1f} %) |",
        f"| Correo **verificado** | {verificados} |",
        f"| Correo de dominio **catch-all** (no verificable) | {catch_all} |",
        f"| Empresas distintas | {empresas} |",
        f"| Casillas compartidas (`info@`, `ventas@`…) detectadas | {len(compartidas)} |",
        "\n> **Sobre las casillas compartidas.** Son direcciones que usan varias personas de la",
        "> misma empresa. No identifican a nadie, así que **no** se usaron para fusionar: dos",
        "> personas que comparten `info@empresa.com` siguen siendo dos contactos. El CRM igual",
        "> impide mandarle dos veces el mismo correo a la misma dirección en una campaña.\n",
        "\n> **Sobre los correos catch-all.** Son dominios que aceptan cualquier dirección, así",
        "> que el proveedor no puede confirmar si la casilla existe. Sirven, pero rebotan más.",
        "> Recomendación: armar la primera campaña sólo con los **verificados** y dejar los",
        "> catch-all para una segunda tanda, mirando la tasa de rebote de la primera.\n",
        "\n## Distribución\n",
        "### Principales sectores\n",
        "| Sector | Contactos |",
        "|---|---:|",
    ]
    for k, v in sectores.most_common(15):
        resumen.append(f"| {k} | {v} |")
    resumen += ["\n### Provincias\n", "| Provincia | Contactos |", "|---|---:|"]
    for k, v in provincias.most_common(15):
        resumen.append(f"| {k} | {v} |")
    resumen += [
        "\n## Archivos generados\n",
        "| Archivo | Qué tiene |",
        "|---|---|",
        "| `contactos-unificados.csv` | **La base final completa**: Zoho + lo nuevo, sin duplicados |",
        "| `contactos-nuevos.csv` | Sólo lo que NO estaba en Zoho |",
        "| `contactos-en-zoho.csv` | Los que ya estaban, con los datos que aportan las listas nuevas |",
        "| `duplicados.csv` | Auditoría: qué se fusionó con qué y por qué |",
        "\nTodos van con BOM UTF-8 y separador `;`, para que Excel en español los abra bien,",
        "y con los nombres de columna que ya entiende el importador del CRM.\n",
    ]
    with io.open(os.path.join(args.salida, "RESUMEN.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(resumen))

    print(f"\n  Archivos escritos en: {args.salida}")
    print("=" * 70)
    return 0


if __name__ == "__main__":
    sys.exit(main())
