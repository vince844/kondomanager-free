<?php

return [

    /* ------------------------------------------------------------------
    | header
    | ------------------------------------------------------------------ */
    'header' => [
        'elenco_scadenze_agenda'            => "Lista de eventos na agenda",
        'description'                       => "Abaixo está a tabela com a lista de todas as próximas datas na agenda do condomínio",
        'modifica_evento'                   => 'Editar evento ',
        'modifica_scadenza_in_agenda'       => 'Editar prazo na agenda ',
        'crea_scadenza_in_agenda'           => 'Criar prazo na agenda ',
    ],
    
    
    /* ------------------------------------------------------------------
     | messages
     | ------------------------------------------------------------------ */
    'messages' => [
    'success_create_event'                => "O novo evento da agenda foi criado com sucesso.",
    'success_create_event_in_moderation'  => "O novo evento da agenda foi criado com sucesso, mas necessita de aprovação do administrador.",
    'error_create_event'                  => "Ocorreu um erro ao criar o novo evento da agenda.",
    'success_delete_event'                => "O evento da agenda foi eliminado com sucesso.",
    'success_update_event'                => "O evento da agenda foi atualizado com sucesso.",
    'error_update_event'                  => "Ocorreu um erro ao atualizar o evento da agenda.",
    'error_delete_event'                  => "Ocorreu um erro ao eliminar o evento da agenda.",
    ],
    /* ------------------------------------------------------------------
     | UI strings
     | ------------------------------------------------------------------ */
    'ui' => [
        'elenco'                                    => 'Lista',
        'evento_ricorrente'                         => 'Evento recorrente ',
        'quando_viene_selezionata_questa_opzione' => 'Quando viene selezionata questa opzione verrano abilitati i campi per la configurazione della ricorrenza ',
    ],

    /* ------------------------------------------------------------------
     | label
     | ------------------------------------------------------------------ */
    'label' => [

        /* ------------------------------------------------------------------
        | frequencies
        | ------------------------------------------------------------------ */
        'giornaliera'                   => 'Diária',
        'settimanale'                   => 'Semanal',
        'mensile'                       => 'Mensal',
        'annuale'                       => 'Anual',        
        
        /* ------------------------------------------------------------------
        | weekdays
        | ------------------------------------------------------------------ */
        'lunedi'                        => 'Segunda‑feira',
        'martedi'                       => 'Terça‑feira',
        'mercoledi'                     => 'Quarta‑feira',
        'giovedi'                       => 'Quinta‑feira',
        'venerdi'                       => 'Sexta‑feira',
        'sabato'                        => 'Sábado',
        'domenica'                      => 'Domingo',

        /* ------------------------------------------------------------------
        | other labels
        | ------------------------------------------------------------------ */        
        'oggetto'                           => 'Assunto ',
        'descrizione'                       => 'Descrição ',
        'note_aggiuntive'                   => 'Notas adicionais ',
        'inizio'                            => 'Início ',
        'fine'                              => 'Fim ',
        'imposta_evento_ricorrente'         => 'Definir evento recorrente ',
        'ricorrenza'                        => 'Recorrência ',
        'giorni_specifici'                  => 'Dias específicos ',
        'ripeti_fino_al'                    => 'Repetir até ',
        'stato_pubblicazione'               => 'Estado da publicação ',
        'categorie'                         => 'Categorias ',
        'condomini'                         => 'Condomínio ',
        'anagrafiche'                       => 'Registo ',
    ],      



    /* ------------------------------------------------------------------
     | js
     | ------------------------------------------------------------------ */
    'js' => [
        'error_fetching_anagrafiche'        => 'Erro ao obter o registo: ',
        'seleziona_periodo'                 => "Selecione o período ",
        'filtra_per_nome'                   => "Filtra por nome... ",

        /* ------------------------------------------------------------------
        | Data filters
        | ------------------------------------------------------------------ */
        'scaduti_ultimi_7_giorni'           => 'Expirou nos últimos 7 dias ',
        'scadenza_prossimi_7_giorni'        => 'Expiram nos próximos 7 dias ',
        'scadenza_prossimi_14_giorni'       => 'Expiram nos próximos 14 dias ',
        'scadenza_prossimi_30_giorni'       => 'Expiram nos próximos 30 dias ',
    ],


    /* ------------------------------------------------------------------
     | input
     | ------------------------------------------------------------------ */
    'input' => [
        'filtra_per_nome'                   => "Filtra por nome... ",
    ],

    /* ------------------------------------------------------------------
     | buttons
     | ------------------------------------------------------------------ */
    'button' => [
        'crea_nuovo_evento'         => "Criar",
        'cancella'                  => "Cancelar",
        'resetta_filtri'            => "Redefinir filtros",
        'categoria'                 => "Categoria",
        'crea_evento'               => "Criar evento ",
    ],

    /* ------------------------------------------------------------------
     | table
     | ------------------------------------------------------------------ */
    'table' => [
        'nessum_risultato_trovato'           => "Nenhum resultado encontrado",
        'modifica'                           => "Editar",
        'elimina'                            => "Eliminar",
    ],

    /* ------------------------------------------------------------------
     | placeholder
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'stato_pubblicazione'               => 'Estado de publicação ',
        'seleziona_condomini'               => 'Selecione o condomínio ',
        'anagrafiche'                       => 'Registo',
        'seleziona_frequenza'               => 'Selecione a frequência ',
        'seleziona_giorni_specifici'        => 'Selecione dias específicos ',
        'seleziona_data'                    => 'Selecione a data ',
        'seleziona_categoria'               => 'Selecione a categoria ',
        'note_aggiuntive'                   => 'Insira uma nota aqui ',
    ],

];
