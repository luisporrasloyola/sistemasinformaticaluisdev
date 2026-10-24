# Lectura opcional con Gemini

En Cobranzas → Valorización, la carga se realiza desde la página. Tras seleccionar JPG, PNG, WEBP o PDF, una barra muestra la preparación y lectura. Se envía una copia JPEG reducida de la vista actual a Google. Si se identifican equipo y placa, fecha, hora de inicio y hora final, y el formulario pasa las validaciones, se guarda el registro con su archivo y aparece en la tabla. Las horas totales restan una hora de refrigerio. Si falta un dato o la lectura falla, aparece el panel para completar los campos manualmente. Los registros se pueden corregir después desde Editar; las observaciones se ingresan manualmente allí.

## Configuración de la clave

La clave de Gemini no se guarda en el código ni en Git. Configure una de estas opciones en el servidor:

1. Defina la variable de entorno `GEMINI_API_KEY` con la clave nueva. Tiene prioridad sobre cualquier archivo.
2. Guarde únicamente la clave nueva en un archivo de texto fuera del directorio público y del repositorio, y defina `GEMINI_API_KEY_FILE` con su ruta absoluta. En cPanel, por ejemplo, el archivo puede estar en `/home/USUARIO/lifemaquinarias_gemini.key`, fuera de `public_html`. Si la variable `HOME` de PHP apunta a `/home/USUARIO`, se busca ese archivo automáticamente sin configurar `GEMINI_API_KEY_FILE`. Restrinja su lectura a la cuenta del sitio (permisos 600 cuando el hosting lo permita).

No coloque la clave en `.htaccess`, JavaScript ni archivos publicados. Si no se configura, la lectura automática devuelve un error de configuración y el ingreso manual sigue disponible. Antes de publicar este cambio, revoque la clave expuesta y genere otra en Google; una clave retirada del código puede seguir visible en commits anteriores.

La función usa Gemini 3.5 Flash-Lite, hace como máximo una solicitud simultánea por instalación, acepta imágenes de lectura de hasta 1,5 MB y aplica una espera de 3 segundos entre solicitudes de la misma sesión. Las cuotas y disponibilidad dependen del proyecto de Google AI Studio. Cuando la cuota se agota o el servicio falla, el registro manual y el OCR local siguen disponibles. La llamada puede ocupar un proceso PHP durante varios segundos; vigile los tiempos reales antes de habilitar un uso intenso en hosting compartido.
