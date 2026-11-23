<?php
// ============================================================
//  PLANTILLA de configuración — copiá este archivo a config.php y completá
//  los valores reales. config.php NO se versiona (tiene secretos) → ver .gitignore.
//
//  En producción se despliega FUERA del web root:
//      /home/USUARIO/crm_config/config.php     (chmod 600)
//  y lo encuentra solo bootstrap.php, por el nombre de la carpeta de la
//  instalación: crm/ usa crm_config/config.php y crm-pruebas/ usa
//  crm_config/crm-pruebas.php. El .htaccess ya NO fija CRM_CONFIG (hacerlo
//  apuntaba las dos instalaciones a la misma base); la variable de entorno
//  sólo se respeta si alguien la define a propósito.
//  La carpeta crm_config/ se deduce de la ruta de la instalación
//  (/home/USUARIO/domains/… → /home/USUARIO/crm_config); si el hosting la
//  tuviera en otro lado, se indica con la variable de entorno CRM_CONFIG_DIR.
//  `storage` es obligatorio en la práctica: sin él los adjuntos irían al lado
//  de la instalación, que en Hostinger queda dentro de public_html.
//  En el repo, bootstrap.php también prueba app/server-config/config.php y
//  app/web/config.local.php (desarrollo local).
//
//  Cada instalación tiene su propio archivo y, por lo tanto, su propia base,
//  su propio `storage` y su propio `secret`: no se copian de una a otra.
// ============================================================
return [
    // Conexión a MySQL/MariaDB (db.php). En Hostinger la base está en el mismo
    // servidor: 'localhost'.
    'db' => [
        'host'    => 'localhost',
        'name'    => 'TU_BASE_DE_DATOS',
        'user'    => 'TU_USUARIO_DB',
        'pass'    => 'TU_PASSWORD_DB',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        // 'name' es el nombre de respaldo: el ajuste `app_nombre` de la base
        // (pantalla Ajustes) tiene prioridad en el layout.
        'name'      => 'CRM INTELLIHELP',
        'org'       => 'INTELLIHELP',
        // Vacío = se deduce de la URL en cada request (funciona igual en el
        // subdominio crm.intellihelp.tech y en intellihelp.tech/crm).
        'base_path' => '',
        // URL canónica: a dónde vuelve Google después del login y con qué
        // dirección se arman los enlaces públicos (agenda, desuscripción).
        // El subdominio redirige acá, así que la app tiene una sola dirección.
        'canonical'   => 'https://intellihelp.tech/crm',
        // 'env' sólo decide si los errores se muestran en pantalla ('dev') o
        // van únicamente al log ('prod'). NO es lo mismo que el modo
        // testing/producción del sistema, que es el ajuste `app_entorno` de
        // la base y decide si las campañas mandan correos reales.
        // 'storage' tiene que estar fuera del web root: si falta, se usa una
        // carpeta `storage` al lado de la instalación, que en Hostinger
        // queda DENTRO de public_html.
        'env'         => 'prod',                                  // 'prod' | 'dev'
        'timezone'    => 'America/Argentina/Buenos_Aires',
        'storage'     => '/home/USUARIO/crm_storage',             // adjuntos, fuera del web root
        'secret'      => 'GENERAR_UN_SECRET_ALEATORIO_LARGO',     // cifrado de tokens de Google
        'idle_ttl'    => 600,                                     // cierre por inactividad (seg)
        'abs_ttl'     => 28800,                                   // sesión máxima absoluta (seg)
        // Proxies propios delante del CRM (IPs o rangos CIDR). Vacío en
        // Hostinger: el CDN ya deja la IP del visitante en REMOTE_ADDR. Sólo
        // desde estos se lee X-Forwarded-For (ver ip_confiable()).
        'proxies_confiables' => [],
        // 'secret' cifra los tokens de Google y firma el hash de las IP
        // (ip_hash): cambiarlo con el sistema en uso obliga a reconectar
        // Google Calendar y rompe la comparación con los hashes viejos.
        // Marca blanca: vacío = colores y logo de INTELLIHELP (layout.php).
        'brand'       => [
            'primary' => '',                                      // ej. '#663399'
            'accent'  => '',                                      // ej. '#9CC930'
            'logo'    => '',                                      // ej. 'assets/img/mi-logo.png'
        ],
    ],
    'google' => [
        // Login con Google Workspace + Calendar + Meet (OAuth 2.0, app Interna).
        // Consola: Google Cloud → API y servicios → Credenciales.
        'client_id'      => 'TU_CLIENT_ID.apps.googleusercontent.com',
        'client_secret'  => 'GOCSPX-...',                         // NUNCA subir a git
        'allowed_domain' => 'intellihelp.tech',                   // hd: sólo cuentas del dominio
        'auto_provision' => true,                                 // crear usuarios nuevos con rol 'comercial'
    ],
    // Sólo en la config de PRUEBAS (crm-pruebas.php): todo correo que salga
    // (campañas, respuestas, agenda, avisos) va únicamente a esta casilla, con
    // el destinatario original en el asunto, y los invitados de Google Calendar
    // se reemplazan por ella. Ver correo_desvio() en envio.php. En producción NO.
    // 'mail' => ['desviar_a' => 'usuario@intellihelp.tech'],
    // Sólo en la config de PRUEBAS (crm-pruebas.php): los datos de personas
    // reales que reinicio.php usa para sembrar los contactos de prueba. No van
    // en el código porque el repositorio es público. Cada uno se puede dejar
    // también como ajuste `pruebas_<clave>`; lo que falte no se siembra.
    // 'pruebas' => [
    //     'responsable'      => 'usuario@intellihelp.tech',  // dueño de los datos de prueba
    //     'correo_externo'   => 'alguien@gmail.com',         // casilla de otro proveedor, para contestar
    //     'correo_interno'   => '',                          // vacío = el correo del responsable
    //     'telefono_externo' => '',
    //     'telefono_interno' => '',
    //     'empresa_telefono' => '',
    //     'empresa_whatsapp' => '',
    // ],
];
