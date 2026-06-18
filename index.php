<?php
// Landing pública. Si el visitante ya tiene sesión, lo mandamos a su panel
// (login.php rutea según el rol). Si no, mostramos la página de marketing.

require_once __DIR__ . '/src/auth.php';
aplicar_headers_seguridad();
iniciar_sesion_segura();

if (esta_autenticado()) { header('Location: login.php'); exit; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Agente de WhatsApp — tu WhatsApp contesta y agenda solo</title>
<meta name="description" content="Asistente con IA que responde a tus clientes y agenda citas por WhatsApp, 24/7. Para consultorios, barberías, spas y negocios de citas.">
<link rel="stylesheet" href="assets/identidad.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
  .nav { position: sticky; top: 0; background: rgba(255,255,255,.88); backdrop-filter: blur(8px); border-bottom: 1px solid var(--borde); z-index: 10; }
  .nav__in { max-width: 1080px; margin: 0 auto; padding: 13px 24px; display: flex; align-items: center; justify-content: space-between; }
  .nav__acc { display: flex; align-items: center; gap: 18px; }
  .nav__acc .enlace { font-size: 14px; font-weight: 500; color: var(--marca); text-decoration: none; }
  .nav__acc .enlace:hover { text-decoration: underline; }
  .contenedor { max-width: 1080px; margin: 0 auto; padding: 0 24px; }

  .hero { padding: 66px 0 58px; display: grid; grid-template-columns: 1.08fr .92fr; gap: 48px; align-items: center; }
  .hero h1 { font-size: 46px; line-height: 1.07; letter-spacing: -.025em; margin: 16px 0 16px; }
  .hero .lede { font-size: 18px; color: var(--texto-2); margin: 0 0 28px; max-width: 520px; line-height: 1.55; }
  .hero__cta { display: flex; gap: 12px; flex-wrap: wrap; }
  .btn--lg { padding: 14px 24px; font-size: 16px; }
  .nota { font-size: 13px; color: var(--texto-2); margin: 16px 0 0; }
  .nota i { color: var(--accion); margin-right: 6px; }

  .preview { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio-lg); box-shadow: var(--sombra); overflow: hidden; }
  .preview__top { background: var(--marca); color: #fff; padding: 12px 16px; display: flex; align-items: center; gap: 10px; }
  .preview__top .avatar { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,.14); display: inline-flex; align-items: center; justify-content: center; font-size: 15px; }
  .preview__top strong { display: block; font-size: 14px; font-weight: 600; }
  .preview__top span { font-size: 12px; opacity: .8; }
  .preview__chat { padding: 16px; background: #EAF1F2; display: flex; flex-direction: column; gap: 8px; }
  .b { max-width: 84%; padding: 9px 13px; font-size: 14px; line-height: 1.42; border-radius: 12px; }
  .b--c { align-self: flex-end; background: var(--badge-bg); color: var(--tinta); border-bottom-right-radius: 4px; }
  .b--a { align-self: flex-start; background: #fff; border: 1px solid var(--borde); border-bottom-left-radius: 4px; }

  .bloque { padding: 58px 0; }
  .bloque--gris { background: var(--superficie); border-top: 1px solid var(--borde); border-bottom: 1px solid var(--borde); }
  .bloque__t { text-align: center; }
  .bloque__t h2 { font-size: 32px; letter-spacing: -.02em; margin: 0 0 10px; }
  .bloque__t p { color: var(--texto-2); font-size: 17px; margin: 0 auto 40px; max-width: 580px; }

  .features { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 18px; }
  .feature { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio-lg); padding: 24px; }
  .bloque--gris .feature { background: var(--fondo); }
  .feature__ico { width: 44px; height: 44px; border-radius: 11px; background: var(--badge-bg); color: var(--marca); display: inline-flex; align-items: center; justify-content: center; font-size: 19px; margin-bottom: 14px; }
  .feature h3 { font-size: 17px; margin: 0 0 6px; }
  .feature p { font-size: 14px; color: var(--texto-2); margin: 0; line-height: 1.55; }

  .pasos { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 22px; }
  .paso__n { width: 34px; height: 34px; border-radius: 50%; background: var(--accion); color: #fff; font-family: var(--fuente-titulo); font-weight: 700; font-size: 15px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; }
  .paso h3 { font-size: 17px; margin: 0 0 6px; }
  .paso p { font-size: 14px; color: var(--texto-2); margin: 0; line-height: 1.55; }

  .nichos { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
  .nicho { background: var(--superficie); border: 1px solid var(--borde); border-radius: 999px; padding: 9px 16px; font-size: 14px; color: var(--tinta); display: inline-flex; align-items: center; gap: 8px; }
  .nicho i { color: var(--accion); }

  .cta { background: var(--marca); border-radius: var(--radio-lg); padding: 52px 32px; text-align: center; }
  .cta h2 { color: #fff; font-size: 30px; letter-spacing: -.02em; margin: 0 0 10px; }
  .cta p { color: #B6CDD4; font-size: 17px; margin: 0 0 26px; }

  footer.pie { border-top: 1px solid var(--borde); padding: 26px 0; }
  .pie__in { max-width: 1080px; margin: 0 auto; padding: 0 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; color: var(--texto-2); font-size: 13px; }

  @media (max-width: 800px) {
    .hero { grid-template-columns: 1fr; gap: 34px; padding: 40px 0 44px; }
    .hero h1 { font-size: 34px; }
    .nav__acc .enlace.solo-desktop { display: none; }
  }
</style>
</head>
<body>

  <nav class="nav">
    <div class="nav__in">
      <div class="marca">
        <span class="marca__icono"><i class="fas fa-comment-dots"></i></span>
        <span class="marca__nombre">Agente de WhatsApp</span>
      </div>
      <div class="nav__acc">
        <a class="enlace solo-desktop" href="login.php">Iniciar sesión</a>
        <a class="btn btn--primario" href="registro.php">Crear cuenta</a>
      </div>
    </div>
  </nav>

  <header class="contenedor">
    <div class="hero">
      <div>
        <span class="badge"><i class="fas fa-calendar-check"></i> Para negocios de citas</span>
        <h1>Tu WhatsApp contesta y agenda solo.</h1>
        <p class="lede">Un asistente con inteligencia artificial que responde a tus clientes al instante, resuelve sus dudas y agenda sus citas por WhatsApp — 24 horas, los 7 días.</p>
        <div class="hero__cta">
          <a class="btn btn--primario btn--lg" href="registro.php">Crear cuenta</a>
          <a class="btn btn--secundario btn--lg" href="login.php">Ya tengo cuenta</a>
        </div>
        <p class="nota"><i class="fas fa-circle-check"></i> Configúralo en minutos. Sin tarjetas ni complicaciones para empezar.</p>
      </div>

      <div class="preview" aria-hidden="true">
        <div class="preview__top">
          <span class="avatar"><i class="fas fa-comment-dots"></i></span>
          <div><strong>Consultorio Dental Paty</strong><span>en línea</span></div>
        </div>
        <div class="preview__chat">
          <div class="b b--c">Hola, ¿tienen cita para una limpieza mañana?</div>
          <div class="b b--a">¡Hola! Claro. Mañana tengo libre a las 11:00 y a la 1:00. ¿Cuál te acomoda?</div>
          <div class="b b--c">A las 11 por favor</div>
          <div class="b b--a">Perfecto. Te agendé mañana a las 11:00 para limpieza. ¿Me confirmas tu nombre completo?</div>
          <div class="b b--c">María Hernández</div>
          <div class="b b--a">Listo, María. Tu cita quedó confirmada. ¡Te esperamos!</div>
        </div>
      </div>
    </div>
  </header>

  <section class="bloque bloque--gris">
    <div class="contenedor">
      <div class="bloque__t">
        <h2>Deja de perder clientes por no contestar a tiempo</h2>
        <p>Cada mensaje sin respuesta es una cita que se va con el de enfrente. El agente atiende mientras tú trabajas.</p>
      </div>
      <div class="features">
        <div class="feature">
          <span class="feature__ico"><i class="fas fa-bolt"></i></span>
          <h3>Responde al instante</h3>
          <p>Contesta dudas de servicios, precios y horarios en segundos, a cualquier hora, incluso de madrugada.</p>
        </div>
        <div class="feature">
          <span class="feature__ico"><i class="fas fa-calendar-check"></i></span>
          <h3>Agenda citas solo</h3>
          <p>Ofrece horarios libres reales, respeta la duración de cada servicio y evita empalmes. Sin que muevas un dedo.</p>
        </div>
        <div class="feature">
          <span class="feature__ico"><i class="fas fa-headset"></i></span>
          <h3>Escala a una persona</h3>
          <p>Cuando algo se sale de lo común, te avisa para que un humano tome la conversación. Nunca pierdes el control.</p>
        </div>
        <div class="feature">
          <span class="feature__ico"><i class="fas fa-chart-line"></i></span>
          <h3>Tú ves todo</h3>
          <p>Citas, conversaciones y consumo del mes en un panel claro. Sabes qué está pasando en tu negocio.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="bloque">
    <div class="contenedor">
      <div class="bloque__t">
        <h2>Cómo funciona</h2>
        <p>Tres pasos para tener tu WhatsApp atendido por el agente.</p>
      </div>
      <div class="pasos">
        <div class="paso">
          <span class="paso__n">1</span>
          <h3>Configura tu negocio</h3>
          <p>Cargas tus horarios, servicios y precios. Le das las reglas de tu giro en lenguaje sencillo.</p>
        </div>
        <div class="paso">
          <span class="paso__n">2</span>
          <h3>Conecta tu WhatsApp</h3>
          <p>Enlazas el número por el que te escriben tus clientes. Nosotros te guiamos en el proceso.</p>
        </div>
        <div class="paso">
          <span class="paso__n">3</span>
          <h3>El agente atiende</h3>
          <p>Responde, cotiza y agenda por ti. Las citas aparecen en tu panel y te llega un aviso.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="bloque bloque--gris">
    <div class="contenedor">
      <div class="bloque__t">
        <h2>Hecho para negocios de citas</h2>
        <p>Si tu día se mueve por agenda, el agente es para ti.</p>
      </div>
      <div class="nichos">
        <span class="nicho"><i class="fas fa-tooth"></i> Consultorios dentales</span>
        <span class="nicho"><i class="fas fa-shoe-prints"></i> Podología</span>
        <span class="nicho"><i class="fas fa-scissors"></i> Barberías</span>
        <span class="nicho"><i class="fas fa-spa"></i> Spas y estéticas</span>
        <span class="nicho"><i class="fas fa-paw"></i> Veterinarias</span>
        <span class="nicho"><i class="fas fa-user-md"></i> Consultorios médicos</span>
      </div>
    </div>
  </section>

  <section class="bloque">
    <div class="contenedor">
      <div class="cta">
        <h2>Que tu agenda se llene sola</h2>
        <p>Crea tu cuenta y deja que el agente conteste por ti.</p>
        <a class="btn btn--primario btn--lg" href="registro.php">Crear cuenta</a>
      </div>
    </div>
  </section>

  <footer class="pie">
    <div class="pie__in">
      <div class="marca">
        <span class="marca__icono"><i class="fas fa-comment-dots"></i></span>
        <span class="marca__nombre">Agente de WhatsApp</span>
      </div>
      <div>&copy; <?= date('Y') ?> · Asistente de WhatsApp con IA para negocios.</div>
    </div>
  </footer>

</body>
</html>
