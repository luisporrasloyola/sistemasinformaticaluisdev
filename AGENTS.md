# Entorno de despliegue de Life Maquinarias

Este proyecto se desarrolla localmente en `C:\xampp\htdocs\lifemaquinarias` y se publica en un **hosting compartido básico**. Al modificarlo, considerar los límites de recursos del hosting y mantener compatibilidad con la instalación real.

## Datos del hosting pendientes de confirmar

Completar aquí cuando se disponga de la información del panel de hosting:

CPU: 100%
Memoria RAM: 4 GB
Procesos entrantes: 20
Procesos máximos: 100
I/O: 1 MB/s
IOPS: 1,024
Almacenamiento: 50 GB
Base de datos: MySQL/MariaDB

## Cobranzas: lectura de partes diarios en Valorizaciones

- La carga admite hasta 5 fotos o PDF por selección, con un máximo de 5 MB por archivo. Cada archivo corresponde a un registro y se prepara, lee y guarda **uno por uno**. No lanzar solicitudes simultáneas a Gemini desde una selección múltiple: el hosting compartido debe mantener como máximo una lectura activa por instalación.
- Para agilizar la lectura, enviar a la API una copia JPEG reducida de la imagen (lado máximo de 2000 píxeles y hasta 1,5 MB). Conservar el archivo original para el registro. Evitar redibujar la imagen si ya cabe en esa resolución y no reducir más la nitidez sin comprobar que la IA sigue leyendo bien la escritura.
- No introducir esperas artificiales entre archivos. La rapidez también depende del tiempo de respuesta de la API de Gemini; gemini.google.com y la API utilizada por el proyecto no comparten necesariamente disponibilidad ni cuota.
- Distinguir en pantalla los errores de parámetros, clave/permisos, cuota y sobrecarga según el código HTTP real de la API. Un error 500 o 503 rápido admite **un solo reintento breve con espera aleatoria**; no repetir automáticamente errores permanentes ni crear bucles de reintentos. Registrar únicamente el código HTTP y estado de la API para diagnóstico, nunca la clave, la imagen ni datos sensibles del documento.
- Si la lectura falla o faltan datos, permitir revisión e ingreso manual sin perder el archivo seleccionado. Mantener la clave de Gemini fuera del directorio público y del código fuente.

### Rutas clave de esta integración

- `modulos/cobranzas/valorizacion.php`: pantalla, selección de hasta 5 archivos, cola secuencial, progreso, guardado de cada parte y respuestas JSON del guardado múltiple.
- `modulos/cobranzas/valorizacion_form.php`: formulario de revisión y corrección manual antes de guardar.
- `recursos/js/cobranzas_valorizacion_archivo.js`: validación, preparación, compresión cuando corresponde y vista previa del archivo original. Revisar aquí el tiempo de preparación en el navegador.
- `recursos/js/cobranzas_valorizacion_ocr.js`: creación de la copia JPEG para la IA, límite de resolución, lectura automática, aplicación de campos y tratamiento de errores en el navegador. Revisar aquí el tamaño de la imagen enviada y evitar dibujos o conversiones innecesarias.
- `servicios/cobranzas/valorizacion_gemini.php`: llamada a la API, clave, control de una lectura simultánea, límites de la copia, tiempos de espera, reintento breve y diagnóstico por código HTTP. Revisar aquí la latencia de red y del proveedor; no exponer la clave ni el documento en registros.
- `includes/upload.php`: validación y almacenamiento del archivo original; no confundir el límite del original con el de la copia enviada a Gemini.
- `modulos/cobranzas/GEMINI_CONFIGURACION.md`: configuración de la clave fuera del directorio público y comportamiento esperado de la lectura.
