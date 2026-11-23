<?php
// ============================================================
//  Vista de usuarios (sólo administración), desde pages/usuarios.php.
//
//  Una fila por usuario con rol, estado, Google Calendar (google_conectado
//  hace una consulta por fila) y último acceso. Cada fila es un formulario
//  a usuario-guardar.
//
//  El <form> de cada fila vive entero en la última celda (id «fu<id>»); el
//  selector de rol y la casilla de activo se le asocian con form="fu<id>".
//  Así el HTML es válido y la estructura de la tabla puede cambiar sin dejar
//  campos fuera del formulario.
// ============================================================
?>
<?php /** @var array $rows */ ?>
<div class="page-head" data-vivo="cabecera">
    <div>
        <h1>Usuarios</h1>
        <p class="muted">Entran con su cuenta de Google del dominio. No hay contraseñas que administrar.</p>
    </div>
</div>

<div class="flash flash-info">
    Cualquier persona con cuenta <strong>@<?= e(google_cfg()['allowed_domain'] ?? 'intellihelp.tech') ?></strong>
    que entre por primera vez se da de alta sola con rol <strong>Comercial</strong>.
    Desde acá le cambiás el rol o la desactivás.
</div>

<div class="card table-card" data-vivo="tabla">
    <div class="table-scroll">
        <table class="grid">
            <thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th class="prio-2">Google Calendar</th>
                       <th class="prio-3">Último acceso</th><th class="acts">Guardar</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $u): ?>
                <tr>
                    <td data-label="Usuario">
                        <div class="uinfo">
                            <?php if ($u['avatar_url']): ?>
                                <img class="avatar avatar-sm" src="<?= e($u['avatar_url']) ?>" alt="" referrerpolicy="no-referrer">
                            <?php else: ?>
                                <span class="avatar-fallback avatar-sm"><?= e(iniciales((string)$u['nombre'])) ?></span>
                            <?php endif; ?>
                            <div class="cell-main">
                                <strong><?= e($u['nombre']) ?></strong>
                                <small><?= e($u['email']) ?></small>
                            </div>
                        </div>
                    </td>
                    <td data-label="Rol">
                        <?php // El formulario vive entero en la última celda; el rol y el
                              // estado se le asocian con el atributo form="…" (un <form>
                              // que abre en una celda y cierra en otra es HTML inválido). ?>
                            <select name="rol" form="fu<?= (int)$u['id'] ?>" aria-label="Rol">
                                <option value="comercial" <?= $u['rol'] === 'comercial' ? 'selected' : '' ?>>Comercial</option>
                                <option value="admin"     <?= $u['rol'] === 'admin'     ? 'selected' : '' ?>>Administrador</option>
                            </select>
                    </td>
                    <td data-label="Estado">
                            <label class="switch">
                                <input type="checkbox" name="activo" value="1" form="fu<?= (int)$u['id'] ?>" <?= (int)$u['activo'] === 1 ? 'checked' : '' ?>>
                                <span class="track"></span><span class="small">Activo</span>
                            </label>
                    </td>
                    <td data-label="Google Calendar" class="prio-2">
                        <?= google_conectado((int)$u['id'])
                            ? '<span class="badge badge-ok">Conectado</span>'
                            : '<span class="badge badge-neutral">Sin conectar</span>' ?>
                    </td>
                    <td data-label="Último acceso" class="prio-3"><?= $u['ultimo_acceso'] ? fecha_hora($u['ultimo_acceso']) : 'nunca' ?></td>
                    <td class="acts" data-label="Guardar">
                        <form class="inline-form" method="post" action="<?= e(url('usuario-guardar')) ?>" id="fu<?= (int)$u['id'] ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button class="act" title="Guardar"><?= icon('ok', 16) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
