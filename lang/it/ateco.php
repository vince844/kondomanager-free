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
    'button_title' => 'Cerca il codice nella classificazione ATECO',
    'button_label' => 'Cerca il codice ATECO',
    'dialog_title' => 'Cerca il codice ATECO',
    'dialog_description' => 'Scrivi il codice, oppure l\'attività a parole. Il campo resta comunque scrivibile a mano: se il codice non c\'è, chiudi e scrivilo tu.',
    'placeholder' => 'es. 43.22.01, oppure «impianti idraulici»',
    'searching' => 'Cerco…',
    'min_chars' => 'Scrivi almeno due caratteri.',
    'not_found' => 'Nessun codice trovato. Prova con una parola sola, oppure cerca direttamente il codice: in ATECO 2025 i titoli ufficiali sono cambiati. I due che servono più spesso qui: 68.32.01 per l\'amministratore di condominio (oggi si chiama «gestione di beni immobili per conto terzi») e 97.00.10 per il condominio come datore di lavoro.',
    'error' => 'Non riesco a interrogare la classificazione. Riprova, oppure scrivi il codice a mano nel campo.',
    'truncated' => 'Mostrati i primi :mostrati di :totale. Scrivi qualche lettera in più per restringere.',
    'source_version' => 'Classificazione :versione, pubblicata da ISTAT.',
    'empty_list' => 'La classificazione ATECO non è ancora stata caricata su questa installazione.',
];
