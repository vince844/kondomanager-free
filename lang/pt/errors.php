<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Errors Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during HTTP errors for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    '403' => [
        'invalid_signature' => 'O link que está a utilizar pode ter expirado ou já não ser válido.',
        'account_suspended' => 'A sua conta está temporariamente suspensa. Contacte o administrador do condomínio para a reativar.',
    ],
    '413_title' => 'O ficheiro é demasiado grande',

    '403_title'         => '403 - Access Denied',
    '403_heading'       => '403 - Access Denied',
    '403_message'       => 'You do not have permission to access this page.',
    '429_title'         => '429 - Demasiadas tentativas',
    '429_heading'       => '429 - Demasiadas tentativas',
    '429_message'       => 'Demasiadas tentativas em pouco tempo: por segurança, esta operação está suspensa durante algum tempo.',
    '429_retry_minutes' => 'Pode tentar novamente dentro de um minuto.|Pode tentar novamente dentro de :count minutos.',
    '429_retry_later'   => 'Tente novamente mais tarde.',
    '404_title'         => '404 - Page Not Found',
    '404_heading'       => '404 - Page Not Found',
    '404_message'       => 'The page you are looking for does not exist or has been removed.',
    '500_title'         => '500 - Server Error',
    '500_heading'       => '500 - Internal Server Error',
    '500_message'       => 'An unexpected error occurred. Please try again later.',
    'back_to_dashboard' => 'Back to Dashboard',
    'back_to_login'     => 'Back to Login',
];
