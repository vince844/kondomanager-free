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
    'button_title' => 'Procura o código na classificação ATECO',
    'button_label' => 'Procura o código ATECO',
    'dialog_title' => 'Procura o código ATECO',
    'dialog_description' => 'Escreve o código, ou a atividade por palavras. O campo continua a poder ser escrito à mão: se o código não estiver lá, fecha e escreve-o tu.',
    'placeholder' => 'p. ex. 43.22.01, ou «instalações hidráulicas»',
    'searching' => 'A procurar…',
    'min_chars' => 'Escreve pelo menos dois caracteres.',
    'not_found' => 'Nenhum código encontrado. Tenta com uma só palavra, ou procura pelo código: os títulos oficiais do ATECO 2025 estão em italiano e mudaram com a nova classificação. Os dois mais usados aqui: 68.32.01 para o administrador de condomínio e 97.00.10 para o condomínio como entidade empregadora.',
    'error' => 'Não consigo consultar a classificação. Tenta de novo, ou escreve o código à mão no campo.',
    'truncated' => 'Mostrados os primeiros :mostrati de :totale. Escreve mais algumas letras para restringir.',
    'source_version' => 'Classificação :versione, publicada pelo ISTAT.',
    'empty_list' => 'A classificação ATECO ainda não foi carregada nesta instalação.',
];
