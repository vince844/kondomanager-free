<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

// Le parole del compito «da moderare» dell'Action inbox (decisioni 445 e 449): il titolo con i numeri veri, al singolare e al plurale, e la riga sotto.

return [
    'commenti' => '{1} :count comentario|[0,*] :count comentarios',
    'allegati' => '{1} :count adjunto|[0,*] :count adjuntos',
    // La frase che chiude i numeri: «2 commenti in attesa», «1 commento e 2 allegati in attesa».
    'in_attesa' => 'pendientes',
    'e' => 'y',
    'comunicazione_da_approvare' => 'Comunicación por aprobar',
    'documento_da_approvare' => 'Documento por aprobar',
    'evento_da_approvare' => 'Evento por aprobar',
    'sotto' => [
        'segnalazione' => 'Incidencia «:nome»',
        'comunicazione' => 'Comunicación «:nome»',
        'documento' => 'Documento «:nome»',
        'evento' => 'Evento «:nome»',
    ],
];
