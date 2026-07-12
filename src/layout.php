<?php
// Layout compartido de la app: barra lateral (sidebar) + área de contenido.
// Único lugar de verdad de la navegación. Cada pantalla llama a layout_inicio()
// al empezar a imprimir, escribe su contenido, y cierra con layout_fin().
//
//   layout_inicio('Negocios', 'superadmin', 'negocios');
//   ... HTML del contenido ...
//   layout_fin();
//
// $contexto: 'superadmin' (gestión de la plataforma) | 'negocio' (operar un negocio)
// $activo:   clave del módulo activo (negocios|usuarios|citas|config|chat)
// $opts:     ['negocio' => array, 'css' => string, 'plano' => bool]

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/escalacion.php';

function layout_inicio(string $titulo, string $contexto, string $activo, array $opts = []): void {
    $u     = usuario_actual();
    $neg   = $opts['negocio'] ?? null;
    $css   = $opts['css'] ?? '';
    $plano = !empty($opts['plano']);
    $slug  = $neg ? urlencode($neg['slug']) : '';
    $esc   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    if ($contexto === 'superadmin') {
        $items = [
            ['negocios', 'superadmin.php', 'fa-store',          'Negocios'],
            ['usuarios', 'usuarios.php',   'fa-users',          'Usuarios'],
        ];
    } else {
        $items = [
            ['citas',  "panel.php?t=$slug",          'fa-calendar-check', 'Citas'],
            ['agenda', "agenda.php?t=$slug",         'fa-calendar-days',  'Agenda'],
            ['caja',   "caja.php?t=$slug",           'fa-cash-register',  'Caja'],
        ];
        if (!empty($neg['a_domicilio'])) {
            $items[] = ['clientes', "clientes.php?t=$slug", 'fa-address-book', 'Clientes'];
        }
        $items[] = ['config', "configuracion.php?t=$slug",  'fa-sliders',      'Configuración'];
        $items[] = ['chat',   "chat.php?t=$slug",           'fa-comment-dots', 'Probar chat'];
    }
    // Cuántos chats están esperando atención humana (para el contador en "Citas").
    $badgeCitas = ($contexto === 'negocio' && $neg) ? count(contactos_escalados((int)$neg['id'])) : 0;
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $esc($titulo) ?> — Agente de WhatsApp</title>
<link rel="stylesheet" href="assets/identidad.css?v=<?= @filemtime(__DIR__ . '/../assets/identidad.css') ?: '1' ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<?php if ($css !== ''): ?><style><?= $css ?></style><?php endif; ?>
</head>
<body class="app">
  <aside class="sidebar">
    <div class="sidebar__marca">
      <span class="marca__icono"><i class="fas fa-comment-dots"></i></span>
      <span class="marca__nombre">Agente de WhatsApp</span>
    </div>
    <?php if ($contexto === 'negocio' && $neg): ?>
      <div class="sidebar__ctx"><small>Negocio</small><strong><?= $esc($neg['nombre']) ?></strong></div>
      <?php if (es_superadmin()): ?>
        <a class="nav-item nav-item--volver" href="superadmin.php"><i class="fas fa-arrow-left"></i> Todos los negocios</a>
      <?php endif; ?>
    <?php endif; ?>
    <nav class="sidebar__nav">
      <?php foreach ($items as [$clave, $href, $icono, $label]): ?>
        <a class="nav-item <?= $activo === $clave ? 'activo' : '' ?>" href="<?= $href ?>"><i class="fas <?= $icono ?>"></i> <?= $esc($label) ?><?php if ($clave === 'citas' && $badgeCitas > 0): ?><span class="nav-badge"><?= $badgeCitas ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar__pie">
      <div class="sidebar__user"><?= $esc($u['email'] ?? '') ?></div>
      <a href="logout.php"><i class="fas fa-right-from-bracket"></i> Salir</a>
    </div>
  </aside>
  <main class="contenido<?= $plano ? ' contenido--plano' : '' ?>">
<?php
}

function layout_fin(): void {
    echo "\n  </main>\n</body>\n</html>";
}
