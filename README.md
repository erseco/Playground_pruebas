# Moodle Playground Assets

Blueprints y archivos auxiliares para montar un entorno de demostración en
[Moodle Playground](https://ateeducacion.github.io/moodle-playground/): idioma español, tema
Adaptable, roles y escalas personalizados, un curso restaurado desde copia de seguridad y varios
plugins (eXeLearning, Tiles, accesibilidad).

## Blueprints

Los blueprints usan los **pasos nativos** del playground para idioma, roles, escalas y copia de
seguridad (`installLanguagePack`, `importRoles`, `createScales`, `restoreCourse`), y `setConfigFile`
para el logo del tema. Haz clic para cargarlos:

- **[Cargar blueprint (es_acc)](https://ateeducacion.github.io/moodle-playground/?blueprint-url=https://raw.githubusercontent.com/HenarLG/Playground_pruebas/refs/heads/main/moodle-playground-adaptable_config_7_es_acc.json)**
  — `moodle-playground-adaptable_config_7_es_acc.json`
- **[Cargar blueprint (es_acc_bis)](https://ateeducacion.github.io/moodle-playground/?blueprint-url=https://raw.githubusercontent.com/HenarLG/Playground_pruebas/refs/heads/main/moodle-playground-adaptable_config_7_es_acc_bis.json)**
  — `moodle-playground-adaptable_config_7_es_acc_bis.json`

Patrón general de carga:

```
https://ateeducacion.github.io/moodle-playground/?blueprint-url=<URL_RAW_DEL_BLUEPRINT>
```

## ¿Qué hacen?

Ambos blueprints son equivalentes y provisionan:

- **Idioma español** (`installLanguagePack`).
- **Tema Adaptable**: instalación del tema + plugin local, importación de los ajustes desde
  `adaptable-config.json` y registro del logo (`setConfigFile`).
- **Roles personalizados** desde los presets XML (`importRoles`): coordinación, tutor, autor y
  "invitado para ver cursos ocultos".
- **Escalas** estándar (`createScales`) desde `moodle-standard-scales.json`.
- **Curso "Curso Pensar"** restaurado desde `copia_curso_pensar.mbz` (`restoreCourse`).
- Plugins eXeLearning, formato Tiles y accesibilidad, más un curso de ejemplo con actividades
  evaluables (eXeLearning, SCORM, H5P) y usuarios matriculados.

## Archivos

- `moodle-playground-adaptable_config_7_es_acc.json`, `..._acc_bis.json`: los blueprints.
- `adaptable-config.json`: configuración exportada del tema Adaptable (la importa un `runPhpCode`).
- `coordinacion.xml`, `tutor.xml`, `autor.xml`, `student_vercursosocultos.xml`: presets de roles.
- `moodle-standard-scales.json`: escalas estándar de la plataforma.
- `copia_curso_pensar.mbz`: copia de seguridad del curso de ejemplo.
- `Mec_Nuevo_logo.jpg`: logo del sitio.
- `theme_adaptable.zip`, `local_adaptable.zip`, `local_accessibility.zip`,
  `format_tiles_moodle45_2025070355.zip`: paquetes de tema/plugins.

Todos se referencian desde los blueprints mediante URLs públicas de GitHub Raw.
