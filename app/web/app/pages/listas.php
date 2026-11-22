<?php
declare(strict_types=1);
require_login();
// ============================================================
//  Ruta `listas` (módulo Campañas; cualquier usuario con sesión).
//  El índice de listas de destinatarios, con su segmento y cantidad de
//  miembros. Sin filtros ni paginación: trae todas las vivas.
//  Vista: views/listas.php con $rows (listas_listar() de app/campanas.php).
//  La baja se hace desde la vista, por POST a registro-eliminar (papelera).
// ============================================================
render('listas', ['rows' => listas_listar()], 'Listas de destinatarios');
