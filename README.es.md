# Paymos para WooCommerce

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Pasarela oficial de WooCommerce para Paymos. Añade pagos con stablecoins a tu checkout, dirige al comprador a la página alojada de Paymos y actualiza el pedido de WooCommerce mediante un webhook firmado. El comprador elige el token y la red habilitados en tu proyecto Paymos. El importe se envía en la moneda propia de tu tienda y el tipo de cambio se bloquea en el momento del pago, de modo que el importe liquidado coincide exactamente con el facturado.

Nada en el ciclo de vida del pedido depende de que el comprador regrese a tu sitio. Una pestaña cerrada, una batería agotada o un fallo del navegador terminan igual: el webhook llega y el pedido se actualiza.

[![Ver video: 12 plugins oficiales de CMS para pagos cripto — conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video demostrativo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

- WordPress 6.5 o superior (probado hasta 7.0);
- WooCommerce 8.0 o superior (probado hasta 10.9);
- PHP 7.4 o superior con las extensiones `curl`, `hash`, `json` y `openssl`;
- una URL HTTPS accesible públicamente para que puedan entregarse los webhooks;
- una cuenta de Paymos con el proyecto receptor abierto en el panel.

El SDK de PHP de Paymos viene incluido en el paquete. No se necesita Composer en la tienda. Compatible con High-Performance Order Storage (HPOS) y Checkout Blocks.

## Instalación y conexión

1. Descarga el paquete oficial más reciente desde [GitHub Releases](https://github.com/paymos-labs/woocommerce/releases/latest) o desde el panel **CMS integration** de tu dashboard de Paymos.
2. Súbelo y actívalo en **Plugins → Añadir nuevo → Subir plugin**.
3. Abre **WooCommerce → Ajustes → Pagos → Paymos**.
4. Haz clic en **Connect Paymos**. Se abrirá una nueva pestaña de Paymos para aprobar la conexión.
5. Paymos utiliza el proyecto seleccionado actualmente en el panel. Para Sandbox y Live reutiliza la única clave Payment activa del comercio (o crea una si no existe) y reutiliza un webhook de facturas exacto o crea uno dedicado para la URL de esta tienda.
6. Vuelve a WooCommerce y selecciona el modo Sandbox o Live.

El paquete es idéntico para todos los comercios y no contiene credenciales: sin API key, API secret, project id, webhook secret, token OAuth ni código de dispositivo. Descargarlo no conecta nada; la aprobación del paso 4 es lo que aprovisiona la tienda.

No hay claves que pegar ni selector de proyecto en WooCommerce. La respuesta de autorización de dispositivo de un solo uso se valida contra el tipo de plugin y la URL de la tienda, se guarda como opción de WordPress cifrada con AES-256-GCM (sin autoload) y el token OAuth temporal se descarta. A partir de ese momento todas las llamadas a la Merchant API van firmadas con HMAC. Los valores guardados nunca se renderizan de vuelta en el HTML de ajustes ni se introducen a mano: vuelve a conectar si rotas una clave o el secreto del webhook.

URL del webhook, registrada automáticamente y mostrada en solo lectura en los ajustes:

```text
https://your-store.example/wp-json/paymos/v1/webhook
```

## Ciclo de vida del pedido

Al confirmar el pedido se crea la factura, se vacía el carrito y se redirige al comprador al checkout alojado. Antes de redirigir, el pedido queda en **En espera (On hold)** con la nota *Awaiting Paymos payment* para que nunca quede en un estado indefinido.

| Evento recibido | Estado del pedido |
|---|---|
| `invoice.confirming` | En espera (On hold) — la transferencia está en cadena reuniendo confirmaciones |
| `invoice.underpaid_waiting` | En espera (On hold), con una nota indicando el importe restante |
| `invoice.awaiting_payment` | En espera (On hold) — una transferencia contabilizada se revirtió en una reorganización de red |
| `invoice.paid`, `invoice.paid_over` | `payment_complete()` — WooCommerce decide entre Procesando (Processing) o Completado (Completed) si el pedido es totalmente virtual y descargable |
| `invoice.underpaid` | Fallido (Failed) |
| `invoice.expired`, `invoice.cancelled` | Cancelado (Cancelled) |

Dos salvaguardas protegen esa tabla. Un pedido pagado nunca baja de estado por un evento tardío o desordenado: el estado obsoleto queda registrado como nota y se descarta. Y si llega un evento pagado cuyo importe ya no coincide con el total del pedido, este permanece **En espera (On hold)** con *needs manual review* en lugar de completarse, evitando liquidaciones silenciosas tras editar el pedido.

Al completarse, el hash de transacción y el enlace al explorador se guardan en los metadatos del pedido y se muestran en la página de agradecimiento, en el correo al cliente y en el cuadro **Paymos Payment** del pedido, junto con el ID de factura, la instantánea del importe y el último evento con su ID y marca de tiempo.

## Probar primero en Sandbox

1. Deja **Mode** en Sandbox, activa el método y realiza un pedido de prueba en tu tienda.
2. Abre esa factura en el panel de Paymos. Los pagos en Sandbox no se confirman por temporizador: elige el resultado que quieras probar (`paid`, `overpaid`, `underpaid`, `cancelled`) y se dispararán los mismos eventos del ciclo de vida que en red principal.
3. Observa cómo cambia el pedido en WooCommerce y revisa las notas del pedido.
4. Cambia **Mode** a Live cuando el flujo sea correcto. La conexión cubre ambos entornos, sin necesidad de reconectar.

Las transferencias de Sandbox no generan pruebas on-chain, por lo que el hash de transacción y el enlace al explorador quedan vacíos; es el comportamiento esperado.

## Webhooks y conciliación

Las entregas van firmadas con `X-Webhook-Signature` (`t={timestamp},v1={hmac_hex}`, HMAC-SHA256, comparación en tiempo constante y ventana de gracia durante rotaciones de secreto). Cada evento lleva un `X-Webhook-Id` estable que el plugin almacena para reconocer reintentos sin aplicarlos dos veces.

Antes de escribir cualquier estado final, el plugin vuelve a consultar la factura en la Merchant API y la verifica, de modo que un cuerpo falsificado o repetido no puede completar un pedido por sí solo.

Una tarea WP-Cron (`paymos_reconcile_orders`) se ejecuta cada diez minutos como red de seguridad: revisa hasta 50 pedidos de Paymos aún pendientes o en espera y hasta 50 fallidos o cancelados cuya factura todavía no sea final, consulta la API y aplica el mismo mapeo y validaciones del webhook. Otra tarea WP-Cron cada minuto (`paymos_woocommerce_refunds_reconcile`) concilia los reembolsos pendientes.

## Reembolsos

El plugin admite reembolsos nativos mediante la Paymos Merchant API (`/v1/refunds`) en dos modos calculados por el servidor: `full` (reembolso total de la factura) y `overpayment` (reembolso del excedente en una factura `paid_over`). Paymos envía el reembolso como una nueva transacción saliente estrictamente a la dirección `first_sender` verificada en la factura pagada; no se introduce ninguna dirección manualmente ni se admiten importes parciales arbitrarios.

Desde la pantalla de edición del pedido de WooCommerce, los administradores pueden iniciar un reembolso de dos formas:

- el botón nativo **Reembolsar (Refund)** de WooCommerce en el bloque de artículos del pedido (envía una solicitud `full`);
- el panel de reembolsos **Paymos Payment** en la pantalla del pedido (`woocommerce_admin_order_data_after_order_details`), que admite ambos modos (`full` y `overpayment`) y muestra el destinatario `first_sender` verificado y el estado actual de la operación.

WooCommerce no registra un `WC_Order_Refund` local mientras la transferencia en cadena sigue en curso. Los webhooks firmados en `/wp-json/paymos/v1/webhook` y la tarea cada minuto `paymos_woocommerce_refunds_reconcile` verifican el reembolso contra la Merchant API y registran el reembolso en WooCommerce (o una nota en el pedido para `overpayment`) cuando la operación alcanza `completed`. En modo Sandbox puedes simular resultados finales (`completed`, `failed`, `cancelled`).

## Solución de problemas

**El pedido nunca sale de En espera (On hold).** La tienda debe ser accesible desde internet público para recibir webhooks; un entorno de pruebas tras autenticación HTTP o red privada no los recibirá. Comprueba que la URL del webhook responda y revisa **WooCommerce → Estado → Registros** con **Debug logging** activado en los ajustes de la pasarela.

**Pagado en cadena, pero el pedido indica revisión manual del importe.** El total del pedido cambió después de crear la factura. Compara la instantánea del importe en **Paymos Payment** con el total actual y resuélvelo manualmente: el plugin no completará un pedido que no pueda conciliar.

**La pasarela no aparece en el checkout.** Se oculta automáticamente cuando el entorno activo no está completamente conectado. Abre los ajustes de la pasarela y revisa la tabla **Connection status**; cualquier fila con *Missing* indica que la conexión de ese entorno no terminó.

- [Documentación](https://paymos.io/es/docs/cms-woocommerce)
- [Código fuente](https://github.com/paymos-labs/woocommerce)
- [Registro de cambios](CHANGELOG.md)
- [Soporte](mailto:support@paymos.io)
