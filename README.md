# RakunCMS

RakunCMS es un CMS flat-file de software libre construido sobre un micro-framework PHP propio con adherencia a estándares PSR. Está diseñado para crear sitios web ultra-rápidos basados en contenido Markdown, sin base de datos, y con componentes reactivos renderizados en el servidor (SSR) mediante PHP (vía htmx y Yoyo).

## Características principales

- **Flat-File Architecture:** El contenido vive en archivos Markdown con Frontmatter YAML. La estructura de carpetas es la estructura del sitio.
- **Micro-framework propio:** Basado en estándares PSR-7, PSR-11 y PSR-15, con un peso menor a 5MB en dependencias totales.
- **Componentes Reactivos:** Escribe componentes interactivos en PHP puro que se actualizan sin recargar la página y sin escribir JavaScript.
- **Optimizado para Hosting Compartido:** Despliega por FTP/cPanel. No requiere Node.js, procesos persistentes o VPS.
- **Caché multinivel:** Respuesta en 1-3ms usando full-page cache HTML estático integrado nativamente con OPcache.

---

## Creación de un Nuevo Sitio Web (Implementación)

RakunCMS funciona como un paquete de Composer (`rkn/cms`). Para crear un sitio web utilizando el CMS, no debes modificar el código del framework en sí, sino requerirlo en un proyecto nuevo.

### 1. Inicializar el proyecto con Composer

Normalmente, instalarías el CMS desde Packagist. Si estás desarrollando localmente y probando el framework (como el proyecto `website/`), debes configurar Composer para que use tu ruta local:

```bash
# Crea la carpeta para tu nuevo sitio web
mkdir mi-sitio
cd mi-sitio

# Inicializa el composer.json
composer init --name="mi-usuario/mi-sitio" -n

# (Solo para desarrollo local) Añade el repositorio local apuntando a la carpeta del framework
composer config repositories.rkn path "../rakuncms"

# Instala el framework
composer require rkn/cms:dev-master
```

### 2. Inicializar la estructura base (Scaffolding)

Una vez que Composer haya descargado RakunCMS y sus dependencias (Twig, Yoyo, etc.), usa el CLI incorporado para generar la estructura de carpetas, configuraciones y el *front-controller*.

```bash
php vendor/bin/rakun init
```

El comando `init` creará automáticamente:
- Estructura de contenido en `content/pages/` y `content/blog/`.
- Archivos `.md` de ejemplo y configuración global en YAML.
- Plantillas Twig base en `templates/`.
- Los directorios requeridos para la caché y el almacenamiento.
- **Un archivo ejecutable `rakun` en la raíz de tu proyecto.**

### 3. Levantar el servidor de desarrollo

Ya no necesitas acceder a la carpeta `vendor/bin`. El script de inicialización ha colocado un atajo ejecutable en la raíz de tu proyecto.

Puedes iniciar el servidor local de desarrollo con:

```bash
php rakun serve
# o alternativamente: ./rakun serve
```

Abre `http://localhost:8080` en tu navegador para ver tu nuevo sitio web funcionando con páginas renderizadas desde Markdown y componentes reactivos de PHP.

### 4. Flujo de trabajo

A partir de este momento, todo tu trabajo se centra en el nuevo proyecto:
- Crea contenido editando o añadiendo archivos `.md` en la carpeta `content/`.
- Modifica el diseño visual creando o editando plantillas `.twig` en la carpeta `templates/`.
- Crea nuevos componentes interactivos en `src/Components/` y sus vistas en `templates/yoyo/`.
