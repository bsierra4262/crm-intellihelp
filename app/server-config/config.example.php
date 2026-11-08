<?php
// ============================================================
//  PLANTILLA de configuración — copiá este archivo a config.php y completá
//  los valores reales. config.php NO se versiona (tiene secretos) → ver .gitignore.
//
//  En producción se despliega FUERA del web root:
//      /home/USUARIO/crm_config/config.php     (chmod 600)
//  y se apunta con la variable CRM_CONFIG del .htaccess.
// ============================================================
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'TU_BASE_DE_DATOS',
        'user'    => 'TU_USUARIO_DB',
        'pass'    => 'TU_PASSWORD_DB',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'      => 'CRM INTELLIHELP',
        'org'       => 'INTELLIHELP',
        // Vacío = se deduce de la URL en cada request (funciona igual en el
        // subdominio crm.intellihelp.tech y en intellihelp.tech/crm).
        'base_path' => '',
        // URL canónica: a dónde vuelve Google después del login y con qué
        // dirección se arman los enlaces públicos (agenda, desuscripción).
        // El subdominio redirige acá, así que la app tiene una sola dirección.
        'canonical'   => 'https://intellihelp.tech/crm',
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
];
