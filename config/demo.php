<?php

/*
 * Fuente canónica del deployment de demo pública.
 *
 * La contraseña de acá es PÚBLICA por definición: se publica en
 * /como-funciona y existe para que cualquiera entre. No es un secreto
 * productivo. Lo que importa es que exista un solo lugar: antes vivía
 * hardcodeada en DemoSeeder y en la página, por separado.
 */
return [

    /*
     * Interruptor explícito del modo demo. Los comandos destructivos NO se
     * conforman con APP_ENV=production: una base productiva de verdad
     * también tiene APP_ENV=production.
     */
    'public_mode' => filter_var(env('DEMO_PUBLIC_MODE', false), FILTER_VALIDATE_BOOL),

    /*
     * Segunda confirmación, independiente del flag y no booleana: el nombre
     * exacto de la base a la que se puede apuntar. Un .env copiado a otra
     * instancia se delata acá, porque el flag viaja en la copia pero el
     * nombre de la base no coincide.
     */
    'target_database' => env('DEMO_TARGET_DATABASE'),

    /*
     * Contraseña publicada de las cuentas de demo.
     */
    'password' => env('DEMO_ACCOUNT_PASSWORD', 'password'),

    /*
     * Slugs del dataset canónico. `businesses.slug` no es editable
     * (UpdateBusinessSettings asigna campo por campo y lo excluye), así que
     * sirve como marca estable de "esta base es la de la demo" durante toda
     * la semana, incluso después de que los visitantes creen sus propios
     * negocios desde el registro público.
     */
    'business_slugs' => ['peluqueria-demo', 'estudio-demo'],

    /*
     * Cuentas que demo:restore-access devuelve a su estado canónico.
     * `business_slug` solo se usa para los owners: si un visitante le cambia
     * el email a la cuenta compartida, el owner se vuelve a encontrar por
     * (negocio, rol). DemoConfigTest verifica que esta lista y DemoSeeder no
     * se desincronicen.
     */
    'accounts' => [
        ['email' => 'owner@reservahub.test', 'business_slug' => 'peluqueria-demo', 'role' => 'owner'],
        ['email' => 'ana@reservahub.test', 'business_slug' => 'peluqueria-demo', 'role' => 'employee'],
        ['email' => 'beto@reservahub.test', 'business_slug' => 'peluqueria-demo', 'role' => 'employee'],
        ['email' => 'marina@reservahub.test', 'business_slug' => null, 'role' => 'customer'],
        ['email' => 'lucia@reservahub.test', 'business_slug' => null, 'role' => 'customer'],
        ['email' => 'rodrigo@reservahub.test', 'business_slug' => null, 'role' => 'customer'],
        ['email' => 'julian@reservahub.test', 'business_slug' => null, 'role' => 'customer'],
        ['email' => 'owner2@reservahub.test', 'business_slug' => 'estudio-demo', 'role' => 'owner'],
        ['email' => 'carla@reservahub.test', 'business_slug' => 'estudio-demo', 'role' => 'employee'],
        ['email' => 'valentina@reservahub.test', 'business_slug' => null, 'role' => 'customer'],
        ['email' => 'nico@reservahub.test', 'business_slug' => null, 'role' => 'customer'],
    ],

];
