# Guía de contribución

Convenciones del proyecto **CRM INTELLIHELP**.

## Flujo de trabajo

1. Crear una rama desde `main`: `git checkout -b feat/mi-cambio`.
2. Realizar cambios atómicos y acompañados de tests.
3. Ejecutar las baterías antes de abrir el Pull Request:
   `php test/run_tests.php` (lógica pura, sin base ni red) y
   `bash test/smoke.sh` (rutas y permisos contra el sitio desplegado).
4. Abrir un Pull Request describiendo el qué y el porqué del cambio.

## Mensajes de commit — [Conventional Commits](https://www.conventionalcommits.org/es/)

```
<tipo>(ámbito opcional): <descripción en imperativo, minúscula, sin punto>
```

Tipos: `feat`, `fix`, `docs`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`.

Ejemplos:

```
feat(campanas): pausa aleatoria por campaña y límite diario
fix(importador): no duplicar empresas cuando el nombre difiere en mayúsculas
docs(readme): agrega diagrama del motor de envío
test(agenda): regresión de doble reserva sobre el mismo horario
```

## Reglas del proyecto

- **Cada defecto detectado incorpora un test de regresión** (en `test/`) que falle con el bug
  y pase con el arreglo, para impedir su reaparición.
- **Toda funcionalidad se documenta en el mismo cambio**: documento maestro
  ([`md/DOCUMENTACION-MAESTRA.md`](md/DOCUMENTACION-MAESTRA.md)), README y changelog.
- **Sin secretos ni datos reales en el repositorio.** Las credenciales viven en `config.php`
  (fuera del web root) o en la tabla de ajustes. Ver [`.gitignore`](.gitignore) y
  [SECURITY.md](SECURITY.md).
- **Nada se borra físico**: borrado lógico (`eliminado_en`) en toda tabla de negocio.
- **Una sección nueva copia el diseño de la equivalente ya aprobada** y sólo cambia campos y
  lógica. El módulo **Contactos** es el patrón de referencia.
- **Nada de código ni jerga de desarrollador en la interfaz** fuera de las secciones API y
  CRON: quien usa el sistema no tiene por qué ver rutas, archivos ni endpoints.
- **Responsive sin scroll horizontal en ningún ancho**, iconos SVG en línea, ventanas
  emergentes propias (nunca `confirm()` del navegador).

## Estilo

- PHP 8.1+, `declare(strict_types=1)`, indentación de 4 espacios, sin framework ni Composer.
- Nombres de dominio en castellano (`contacto_guardar`, `campana_preparar`); los patrones
  genéricos, en inglés cuando corresponda.
- Comentarios que explican el **porqué** de una decisión, no el qué obvio del código.
- Validar antes de desplegar: `php -l` sobre los archivos tocados y las dos baterías de tests.
- Mantener al día `README.md`, `CHANGELOG.md` y los `md/*.md` relevantes.
