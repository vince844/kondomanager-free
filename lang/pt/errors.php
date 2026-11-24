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
        'invalid_signature' => 'O link que está a utilizar pode ter expirado ou já não ser válido.',
        'account_suspended' => 'A sua conta está temporariamente suspensa. Contacte o administrador do condomínio para a reativar.',
    ],
    '413_title' => 'O ficheiro é demasiado grande',

    '403_title'         => 'Não pode abrir esta página',
    '403_heading'       => 'Não pode abrir esta página',
    '403_message'       => 'A sua conta não tem permissão para esta página. Se precisar, peça-a ao administrador.',
    '429_title'         => 'Demasiadas tentativas',
    '429_heading'       => 'Demasiadas tentativas',
    '429_message'       => 'Demasiadas tentativas em pouco tempo: por segurança, esta operação está suspensa durante algum tempo.',
    '429_retry_minutes' => 'Pode tentar novamente dentro de um minuto.|Pode tentar novamente dentro de :count minutos.',
    '429_retry_later'   => 'Tente novamente mais tarde.',
    '404_title'         => 'Página não encontrada',
    '404_heading'       => 'Página não encontrada',
    '404_message'       => 'A página que procura não existe: o endereço pode estar errado, ou a página foi removida.',
    '500_title'         => 'Erro interno',
    '500_heading'       => 'Erro interno',
    '500_message'       => 'Ocorreu um erro inesperado. Tente novamente dentro de alguns minutos.',
    'back_to_dashboard' => 'Back to Dashboard',
    'back_to_login'     => 'Voltar ao acesso',
    'errore_numero' => 'Erro :numero',
    '500_message_dopo_salvataggio' => 'Ocorreu um erro inesperado. Verifique se a operação foi guardada antes de a repetir.',
    'codice_di_questo_errore' => 'O código deste erro',
    'comunica_allo_studio' => 'Se se repetir, comunique este código ao administrador:',
    'copia' => 'Copiar',
    'copiato' => 'Copiado',
    'avvisa_lo_studio' => 'Avisar o administrador',
    'avvisa_l_amministratore' => 'Avisar o administrador',
    'avviso_sotto_studio' => 'Com um clique o administrador recebe o código, a hora e a página em que estava.',
    'avviso_sotto_amministratore' => 'Recebe o código, a hora e a página em que estava.',
    'avviso_domanda' => 'O que estava a fazer?',
    'facoltativo' => '(opcional)',
    'avviso_esempio' => 'Por exemplo: estava a descarregar o recibo da prestação de novembro',
    'non_scrivere_password' => 'Não escreva palavras-passe.',
    'avviso_fatto_titolo' => 'O administrador foi avisado',
    'avviso_fatto_titolo_amministratore' => 'O administrador foi avisado',
    'avviso_fatto' => 'Recebeu o código :codice, a hora e a página. Não precisa de fazer mais nada.',
    'avviso_fatto_con_nota' => 'Recebeu o código :codice, a hora e a página, com a sua nota. Não precisa de fazer mais nada.',
    'avviso_gia' => 'O administrador já foi avisado deste erro.',
    'avviso_troppi' => 'Já enviou cinco avisos na última hora: o administrador recebeu-os. Tente novamente mais tarde.',
    'avviso_non_riuscito' => 'O aviso não foi enviado. Comunique o código ao administrador.',
    'avviso_non_trovato' => 'Este erro não está no registo: comunique o código ao administrador.',
    'avviso_non_nella_demo' => 'Na demo pública não se enviam avisos: o erro já está registado com o seu código.',
    'avviso_vedi_gia' => 'Já vê o detalhe deste erro: encontra-o no separador «Errori».',
    'avviso_non_attivo' => 'O estúdio não recebe avisos a partir da página de erro: comunique o código ao estúdio.',
    'avviso_spento_frase' => 'Se precisar de ajuda, comunique este código ao estúdio: com o código encontra logo o que aconteceu.',
    'database_fermo_titolo' => 'O programa não consegue aceder aos seus dados.',
    'database_fermo' => 'Este erro só está escrito no registo do servidor, onde quem gere a instalação o encontra com o código. Por agora o aviso ao administrador não pode ser enviado daqui.',
    'troppe_volte' => 'Este erro repetiu-se muitas vezes hoje: só está escrito no registo do servidor, onde quem gere a instalação o encontra com o código.',
    'demo_titolo' => 'Esta é a demo pública.',
    'demo' => 'O detalhe técnico não é mostrado a ninguém; o erro fica registado com o seu código, e quem cuida da demo encontra-o.',
    'dettaglio_titolo' => 'Detalhe técnico (só você o vê)',
    'dettaglio_pagina' => 'Página',
    'dettaglio_nel_programma' => 'No programa',
    'dettaglio_versione' => 'Versão',
    'dettaglio_testo_intero' => 'O texto completo',
    'dettaglio_testo_intero_valore' => 'no registo do servidor, storage/logs/laravel.log: procure :codice',
    'riga' => ':file, linha :riga',
    'copia_il_rapporto' => 'Copiar o relatório',
    'rapporto_copiato' => 'Relatório copiado',
    'apri_nella_scheda' => 'Abrir no separador «Errori»',
    'mostra_la_traccia' => 'Mostrar o rasto (:righe linhas)',
    'riprova' => 'Tentar novamente',
    'riprova_fra' => 'Tentar novamente em :tempo',
    'riprova_adesso' => 'Tentar novamente agora',
    'torna_alla_pagina_iniziale' => 'Voltar à página inicial',
    'torna_indietro' => 'Voltar atrás',
    'pagina_iniziale' => 'Página inicial',
    'ricarica_la_pagina' => 'Recarregar a página',
    '419_title' => 'A página expirou',
    '419_heading' => 'A página expirou',
    '419_message' => 'Por segurança, uma página aberta há muito tempo já não pode guardar. Recarregue a página e repita a última operação: o que tinha escrito e não tinha guardado tem de ser escrito novamente.',
    '503_title' => 'Manutenção em curso',
    '503_heading' => 'Manutenção em curso',
    '503_message' => 'O programa está parado para uma atualização e volta a estar disponível dentro de poucos minutos. Não precisa de fazer nada.',
    '503_ricarica' => 'Esta página recarrega-se sozinha a cada :secondi segundos.',
    '503_ultimo_controllo' => 'Última verificação às :ora.',
    '4xx_title' => 'O pedido não pode ser concluído',
    '4xx_heading' => 'O pedido não pode ser concluído',
    '4xx_message' => 'O programa não pode fazer o que pediu a partir desta página. Volte atrás e tente novamente.',
    '5xx_title' => 'O programa não responde',
    '5xx_heading' => 'O programa não responde',
    '5xx_message' => 'O servidor não consegue responder neste momento. Tente novamente dentro de alguns minutos.',
];
