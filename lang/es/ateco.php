<?php

// L'aiuto alla compilazione del codice ATECO (1.11.0-beta.8). Il campo resta libero: questi testi
// descrivono un aiuto, non un obbligo. La riga della fonte dichiara la **revisione** e non una data,
// perché l'ATECO cambia per revisioni e nel file ISTAT una data non esiste — verificato cella per
// cella. Vedi `LettoreStrutturaAteco`.

// ⚠️ I due codici citati in `not_found` sono **di ATECO 2025 e vanno riletti a ogni revisione**.
// Verificati sulla tabella caricata il 30/08/2026: 68.32.01 «Gestione di beni immobili per conto
// terzi» e 97.00.10 «Attività di condomini come datori di lavoro per personale domestico» esistono
// entrambi, e i due precedenti (68.32.00 e 97.00.02) **non ci sono più**. Il giorno che
// `kondomanager:verifica-fonte-ateco` segnala una revisione nuova, questa riga è fra le cose da
// ricontrollare: un aiuto che cita un codice ritirato è peggio di un aiuto che non cita niente.

return [
    'button_title' => 'Busca el código en la clasificación ATECO',
    'button_label' => 'Busca el código ATECO',
    'dialog_title' => 'Busca el código ATECO',
    'dialog_description' => 'Escribe el código, o la actividad en palabras. El campo sigue siendo editable a mano: si el código no está, cierra y escríbelo tú.',
    'placeholder' => 'p. ej. 43.22.01, o «instalaciones de fontanería»',
    'searching' => 'Buscando…',
    'min_chars' => 'Escribe al menos dos caracteres.',
    'not_found' => 'Ningún código encontrado. Prueba con una sola palabra, o escribe el código a mano: en ATECO 2025 muchos títulos oficiales han cambiado y ya no coinciden con el nombre del oficio. Si el proveedor eres tú — los honorarios del administrador se registran aquí — el código es 68.32.01, que hoy se llama «gestione di beni immobili per conto terzi»; para la comunidad como empleadora es 97.00.10.',
    'error' => 'No consigo consultar la clasificación. Inténtalo de nuevo, o escribe el código a mano en el campo.',
    'truncated' => 'Mostrados los primeros :mostrati de :totale. Escribe algunas letras más para acotar.',
    'source_version' => 'Clasificación :versione, publicada por ISTAT.',
    'empty_list' => 'La clasificación ATECO todavía no se ha cargado en esta instalación.',
];
