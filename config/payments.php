<?php

return [
    /*
     * Minutos que tiene el cliente para pagar la seña. La ventana pertenece a
     * la reserva (`bookings.payment_expires_at`), no al pago.
     */
    'window_minutes' => (int) env('PAYMENTS_WINDOW_MINUTES', 30),

    /*
     * Antigüedad máxima aceptada para la marca temporal de una firma entrante.
     */
    'webhook_tolerance_seconds' => (int) env('PAYMENTS_WEBHOOK_TOLERANCE_SECONDS', 300),

    'reconcile' => [
        'batch' => (int) env('PAYMENTS_RECONCILE_BATCH', 100),
        'cadence_minutes' => (int) env('PAYMENTS_RECONCILE_CADENCE_MINUTES', 5),
    ],

    'simulated' => [
        'webhook_secret' => env('PAYMENTS_SIMULATED_WEBHOOK_SECRET'),
    ],
];
