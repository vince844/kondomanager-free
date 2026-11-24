<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
        'invalid_signature' => 'El enlace que está utilizando puede haber caducado o ya no ser válido.',
        'account_suspended' => 'Su cuenta está suspendida temporalmente. Póngase en contacto con el administrador del condominio para reactivarla.',
    ],
    '413_title' => 'El archivo es demasiado grande',

    '403_title'         => 'No puede abrir esta página',
    '403_heading'       => 'No puede abrir esta página',
    '403_message'       => 'Su cuenta no tiene permiso para esta página. Si lo necesita, pídalo al administrador.',
    '429_title'         => 'Demasiados intentos',
    '429_heading'       => 'Demasiados intentos',
    '429_message'       => 'Demasiados intentos en poco tiempo: por seguridad, esta operación está suspendida durante un rato.',
    '429_retry_minutes' => 'Puede volver a intentarlo dentro de un minuto.|Puede volver a intentarlo dentro de :count minutos.',
    '429_retry_later'   => 'Vuelva a intentarlo más tarde.',
    '404_title'         => 'Página no encontrada',
    '404_heading'       => 'Página no encontrada',
    '404_message'       => 'La página que busca no existe: la dirección puede ser incorrecta, o la página se ha eliminado.',
    '500_title'         => 'Error interno',
    '500_heading'       => 'Error interno',
    '500_message'       => 'Se ha producido un error inesperado. Vuelva a intentarlo dentro de unos minutos.',
    'back_to_dashboard' => 'Back to Dashboard',
    'back_to_login'     => 'Volver al acceso',
    'errore_numero' => 'Error :numero',
    '500_message_dopo_salvataggio' => 'Se ha producido un error inesperado. Compruebe si la operación se ha guardado antes de repetirla.',
    'codice_di_questo_errore' => 'El código de este error',
    'comunica_allo_studio' => 'Si se repite, comunique este código al administrador:',
    'copia' => 'Copiar',
    'copiato' => 'Copiado',
    'avvisa_lo_studio' => 'Avisar al administrador',
    'avvisa_l_amministratore' => 'Avisar al administrador',
    'avviso_sotto_studio' => 'Con un clic el administrador recibe el código, la hora y la página en la que estaba.',
    'avviso_sotto_amministratore' => 'Recibe el código, la hora y la página en la que estaba.',
    'avviso_domanda' => '¿Qué estaba haciendo?',
    'facoltativo' => '(opcional)',
    'avviso_esempio' => 'Por ejemplo: descargaba el recibo de la cuota de noviembre',
    'non_scrivere_password' => 'No escriba contraseñas.',
    'avviso_fatto_titolo' => 'El administrador ha sido avisado',
    'avviso_fatto_titolo_amministratore' => 'El administrador ha sido avisado',
    'avviso_fatto' => 'Ha recibido el código :codice, la hora y la página. No tiene que hacer nada más.',
    'avviso_fatto_con_nota' => 'Ha recibido el código :codice, la hora y la página, con su nota. No tiene que hacer nada más.',
    'avviso_gia' => 'El administrador ya ha sido avisado de este error.',
    'avviso_troppi' => 'Ya ha enviado cinco avisos en la última hora: el administrador los ha recibido. Vuelva a intentarlo más tarde.',
    'avviso_non_riuscito' => 'El aviso no se ha enviado. Comunique usted el código al administrador.',
    'avviso_non_trovato' => 'Este error no está en el registro: comunique usted el código al administrador.',
    'avviso_non_nella_demo' => 'En la demo pública no se envían avisos: el error ya está registrado con su código.',
    'avviso_vedi_gia' => 'Usted ya ve el detalle de este error: lo encuentra en la pestaña «Errori».',
    'avviso_non_attivo' => 'El estudio no recibe avisos desde la página de error: comunique usted el código al estudio.',
    'avviso_spento_frase' => 'Si necesita ayuda, comunique este código al estudio: con el código encuentra enseguida lo que ha pasado.',
    'database_fermo_titolo' => 'El programa no consigue acceder a sus datos.',
    'database_fermo' => 'Este error solo está escrito en el registro del servidor, donde quien gestiona la instalación lo encuentra con el código. Por ahora el aviso al administrador no se puede enviar desde aquí.',
    'troppe_volte' => 'Este error se ha repetido muchas veces hoy: solo está escrito en el registro del servidor, donde quien gestiona la instalación lo encuentra con el código.',
    'demo_titolo' => 'Esta es la demo pública.',
    'demo' => 'El detalle técnico no se muestra a nadie; el error queda registrado con su código, y quien cuida la demo lo encuentra.',
    'dettaglio_titolo' => 'Detalle técnico (solo lo ve usted)',
    'dettaglio_pagina' => 'Página',
    'dettaglio_nel_programma' => 'En el programa',
    'dettaglio_versione' => 'Versión',
    'dettaglio_testo_intero' => 'El texto completo',
    'dettaglio_testo_intero_valore' => 'en el registro del servidor, storage/logs/laravel.log: busque :codice',
    'riga' => ':file, línea :riga',
    'copia_il_rapporto' => 'Copiar el informe',
    'rapporto_copiato' => 'Informe copiado',
    'apri_nella_scheda' => 'Abrir en la pestaña «Errori»',
    'mostra_la_traccia' => 'Mostrar la traza (:righe líneas)',
    'riprova' => 'Reintentar',
    'riprova_fra' => 'Reintentar en :tempo',
    'riprova_adesso' => 'Reintentar ahora',
    'torna_alla_pagina_iniziale' => 'Volver a la página inicial',
    'torna_indietro' => 'Volver atrás',
    'pagina_iniziale' => 'Página inicial',
    'ricarica_la_pagina' => 'Recargar la página',
    '419_title' => 'La página ha caducado',
    '419_heading' => 'La página ha caducado',
    '419_message' => 'Por seguridad, una página abierta durante mucho tiempo ya no puede guardar. Recargue la página y repita la última operación: lo que había escrito y no había guardado hay que volver a escribirlo.',
    '503_title' => 'Mantenimiento en curso',
    '503_heading' => 'Mantenimiento en curso',
    '503_message' => 'El programa está detenido por una actualización y volverá a estar disponible en pocos minutos. No tiene que hacer nada.',
    '503_ricarica' => 'Esta página se recarga sola cada :secondi segundos.',
    '503_ultimo_controllo' => 'Última comprobación a las :ora.',
    '4xx_title' => 'La solicitud no se puede completar',
    '4xx_heading' => 'La solicitud no se puede completar',
    '4xx_message' => 'El programa no puede hacer lo que ha pedido desde esta página. Vuelva atrás e inténtelo de nuevo.',
    '5xx_title' => 'El programa no responde',
    '5xx_heading' => 'El programa no responde',
    '5xx_message' => 'El servidor no puede responder en este momento. Vuelva a intentarlo dentro de unos minutos.',
];
