<?php
declare(strict_types=1);

// ============================================================
//  Ruta: GET / (la raíz de la instalación). Sólo redirige al panel.
//  index.php ya exigió sesión: sin ella, se termina en el login.
// ============================================================
redirect('dashboard');
