# Agente de WhatsApp con IA

Asistente que contesta los mensajes de WhatsApp de un negocio usando Claude.
MVP en PHP puro (sin frameworks, sin Composer). Primer piloto: el consultorio.

## Como funciona

```
Cliente escribe por WhatsApp
        |
        v
   Twilio  --POST-->  webhook.php
                          |
                          v
              base de conocimiento + historial
                          |
                          v
                   API de Claude  -->  respuesta
                          |
                          v
   Twilio  <--TwiML--  webhook.php  -->  el cliente recibe la respuesta
```

Multitenant desde el diseno: hoy un solo negocio (el consultorio); manana se
identifican por el numero que recibio el mensaje.

## Archivos

- `webhook.php` ......... recibe el mensaje de Twilio y orquesta la respuesta
- `probar.php` .......... prueba el agente desde la terminal, sin Twilio
- `src/claude.php` ...... llamada a la API de Claude
- `src/conocimiento.php`  arma las instrucciones de la IA con los datos del negocio
- `src/conversaciones.php` guarda el historial por numero (JSON en storage/)
- `src/twilio.php` ...... valida que el webhook venga de Twilio
- `src/entorno.php` ..... lee el .env
- `storage/conocimiento.json` datos del negocio (EDITALO con los reales)

## Puesta en marcha

### 1. Configurar la API key de Claude

1. Copia `.env.example` a `.env`.
2. Entra a https://console.anthropic.com -> Settings -> API Keys.
   - Si ya tienes una pero no recuerdas el secreto, crea una nueva (el secreto
     solo se muestra al crearla).
   - Si no tienes cuenta, creala y agrega saldo (es de prepago).
3. Pega la llave (empieza con `sk-ant-`) en `ANTHROPIC_API_KEY` dentro de `.env`.

### 2. Editar los datos del consultorio

Abre `storage/conocimiento.json` y pon horarios, ubicacion y **precios reales**.

### 3. Probar sin WhatsApp (lo mas rapido)

Desde la carpeta del proyecto, en la terminal de Laragon:

```
php probar.php "Hola, cuanto cuesta una limpieza y que horario tienen?"
```

Si responde con los datos del consultorio, la IA ya funciona. Aqui puedes
iterar el tono y las reglas editando `src/conocimiento.php`.

### 4. Conectar WhatsApp con el Sandbox de Twilio

1. Crea cuenta en https://www.twilio.com y entra a
   Messaging -> Try it out -> Send a WhatsApp message (el Sandbox).
2. Sigue las instrucciones para unir tu telefono al sandbox (mandas un
   "join <palabra>" al numero de Twilio por WhatsApp).
3. Expon tu Laragon a internet con un tunel (Twilio necesita una URL publica):
   ```
   ngrok http 80
   ```
   Copia la URL `https://....ngrok-free.app`.
4. En el Sandbox de Twilio, en "When a message comes in", pon:
   ```
   https://....ngrok-free.app/agente-whatsapp/webhook.php
   ```
   Metodo: HTTP POST.
5. En tu `.env`:
   - `TWILIO_AUTH_TOKEN` = el Auth Token de tu consola de Twilio.
   - `WEBHOOK_URL` = exactamente la misma URL que pusiste en Twilio.
6. Escribele al numero del sandbox por WhatsApp. El agente deberia contestarte.

> Para la PRIMERA prueba puedes poner `VALIDAR_FIRMA_TWILIO=0` y omitir el token.
> Pero vuelvelo a `1` enseguida: sin esa validacion, cualquiera que descubra tu
> URL puede hacer que gastes API de Claude.

## Siguientes pasos (no en el MVP)

- Panel web para ver conversaciones y editar la base de conocimiento.
- Aviso real al dueno cuando hay que pasar a un humano (hoy solo responde la IA).
- Agendado real conectado a un calendario.
- Pasar de Sandbox de Twilio a numero propio / WhatsApp Cloud API oficial.
- Multiples negocios (resolver el tenant por numero).
