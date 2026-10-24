# Validación de Cobranzas → Valorización

## Comprobado localmente

Estas mediciones se hicieron en Chrome de escritorio el 23/10/2026. Una ventana de 390 × 844 px sirve para comprobar el diseño adaptable; **no mide el rendimiento de un celular físico**. No se guardaron registros ni se enviaron los archivos de prueba al servidor.

| Prueba | Resultado |
| --- | --- |
| PDF real disponible en `archivos/cobranzas_parte_diario` (boleta, no parte diario) | 0,86 MiB; una página con texto seleccionable abierta en 1,0 s. Tras ajustar la identificación del formato, 0/5 candidatas tanto por texto PDF como por OCR (3,8 s). |
| Imagen de prueba de 1000 × 1400 px, JPEG con desenfoque de 2 px | 0,02 MiB; OCR en 4,1 s. Fecha y dos horas detectadas; equipo y placa quedaron pendientes por discrepancias entre lectura general y localizada. Ningún campo se rellenó automáticamente. |
| PDF de 5 MiB + 1 byte | Rechazado antes de abrirlo; el selector quedó vacío. |
| Imagen JPEG de prueba de 14,77 MiB | Reducida en el navegador a 4,16 MiB antes de subirla. El servidor conserva el archivo recibido sin volver a codificarlo. |
| Ventana de 390 px de ancho | Modal en una columna, sin desbordamiento horizontal tras ajustar los márgenes del formulario. |
| Dos fotos reales del formato Parte diario, suministradas fuera del proyecto | PNG de 0,96 MiB y JPG de 0,12 MiB; OCR general en 7,0 s y 5,5 s, respectivamente. En ambas, 0/5 campos legibles con seguridad. Se probaron zonas conocidas y contraste; una lectura falsa de equipo fue descartada al exigir coincidencia entre dos pasadas. Ningún campo se rellenó automáticamente. |
| Prueba experimental de TrOCR pequeño manuscrito en el navegador con esas dos fotos | Se probó el modelo cuantizado con recortes amplios y recortes ceñidos a cada casilla. En ambas fotos produjo palabras ajenas al formulario y 0/5 campos confirmables. Se descartó activarlo para usuarios. La prueba no envió las fotos al hosting; los archivos temporales locales se eliminaron. |
| Prueba aislada de PaddleOCR.js 0.4.2 / PP-OCRv5 en Chrome de escritorio | `parte1.png`: 30,2 s incluyendo la carga inicial; reconoció correctamente `16:30` pero leyó la fecha como `J1 09 26`, omitió caracteres de la placa y deformó el equipo e inicio. `parte2.jpg`: 11,3 s con recursos en caché; reconoció `7:30 AM`, leyó `18:30Pn` para una salida que parece `18:30 PM`, separó fecha en `1209` y `26`, y dejó ambiguo el primer carácter de la placa. No se activó para usuarios ni se guardaron registros. |

## Pendiente antes de cerrar la fase

Se probaron dos fotos reales del formato facilitadas en una carpeta externa al proyecto. Ni el OCR local de Tesseract.js ni la prueba de TrOCR pequeño **reconocieron de forma fiable los campos manuscritos de ninguna**. En estas muestras, la tasa de recuperación confirmable fue 0/5 por documento. El formulario muestra ahora ampliaciones locales de las casillas para facilitar el ingreso manual. Este resultado no avala dar por terminado el flujo de extracción automática: requiere una tecnología distinta, cuya carga, costo y desempeño en celulares deben evaluarse antes de adoptarla.

Faltan PDF escaneados y PDF con texto seleccionable **del formato real**, además de pruebas de tiempo en un celular de gama media y uno de recursos limitados. Para cada documento nuevo, anotar los cinco campos esperados (equipo, placa/serie, fecha, llegada y salida), candidatos correctos e incorrectos, campos pendientes, tiempo mostrado por la interfaz y tamaño final seleccionado. El tiempo medido en escritorio no sustituye las pruebas en celulares. No incluir datos personales en este informe.

El formulario exige confirmar las lecturas candidatas una por una y mantiene vacíos los datos dudosos. La fecha y las horas deben validarse antes de guardar; Observaciones se ingresa manualmente.
