<?php

return [
    'title' => 'Allegati',
    'subtitle' => 'Allegati di segnalazioni e comunicazioni di tutti i condomini',
    'empty' => 'Nessun allegato presente.',
    'empty_description' => 'Quando verranno caricati file nelle segnalazioni o nelle comunicazioni, compariranno qui.',
    'empty_filtered_title' => 'Nessun allegato per questi filtri',
    'empty_filtered_description' => 'Prova a cambiare i criteri selezionati o azzera i filtri per rivedere l\'elenco completo.',
    'empty_element_description' => 'Non sono ancora stati caricati file per questo elemento.',
    'pending_badge' => 'Da approvare',
    'published_badge' => 'Pubblicato',

    'header' => [
        'list_head' => 'Elenco allegati',
        'list_title' => 'Elenco allegati',
        'list_description' => 'Di seguito la tabella con gli allegati di segnalazioni e comunicazioni di tutti i condomini',
    ],

    'guides' => [
        'overview_title' => 'Elenco unico',
        'overview_desc' => 'Consulta in un solo posto i file allegati a segnalazioni e comunicazioni, con il rimando diretto alla relativa scheda.',
        'moderation_title' => 'Moderazione in blocco',
        'moderation_desc' => 'Approva o elimina gli allegati in attesa anche selezionandoli in blocco direttamente dalla tabella.',
        'export_title' => 'Esportazione in ZIP',
        'export_desc' => 'Scarica gli allegati filtrati in un archivio ordinato per condominio, tipo ed elemento, completo del riepilogo elenco.csv.',
    ],

    'upload' => [
        'label' => 'Aggiungi allegati',
        'hint' => '{0} Formati ammessi: :formati, al massimo :limite per file. Non si possono aggiungere altri file.|{1} Formati ammessi: :formati, al massimo :limite per file. Si può aggiungere ancora un file (al massimo :massimo).|[2,*] Formati ammessi: :formati, al massimo :limite per file. Si possono aggiungere ancora :rimasti file (al massimo :massimo).',
        'no_slots' => 'È stato raggiunto il limite di :massimo allegati per questo elemento.',
        'button' => 'Carica allegati',
        'uploading' => 'Caricamento…',
        'selected_files' => 'File selezionati',
        'remove_file' => 'Rimuovi',
    ],

    'actions' => [
        'open' => 'Apri',
        'download' => 'Scarica',
        'rename' => 'Rinomina',
        'save_name' => 'Salva',
        'cancel' => 'Annulla',
        'delete' => 'Elimina',
        'approve' => 'Approva',
        'approve_selected' => 'Approva selezionati',
        'delete_selected' => 'Elimina selezionati',
        'download_zip' => 'Scarica in ZIP',
        'save_settings' => 'Salva impostazioni',
    ],

    'confirm_delete' => [
        'title' => 'Elimina allegato',
        'description' => 'Vuoi eliminare definitivamente l\'allegato «:nome»? L\'operazione non si può annullare.',
        'bulk_title' => 'Elimina allegati selezionati',
        'bulk_description' => 'Vuoi eliminare definitivamente gli allegati selezionati? L\'operazione non si può annullare.',
    ],

    'settings' => [
        'title' => 'Impostazioni allegati',
        'dialog_description' => 'Configura la visibilità e la moderazione dei file allegati a questo elemento.',
        'access_label' => 'Accesso agli allegati',
        'access_aperti' => 'Aperti',
        'access_consultazione' => 'Solo consultazione',
        'access_riservati' => 'Solo amministrazione',
        'hint_aperti' => 'Chi vede l\'elemento vede e scarica i file. Ne aggiunge di nuovi, dal portale, chi sta nel palazzo per le segnalazioni e chi l\'ha scritta per le comunicazioni.',
        'hint_consultazione' => 'Nel portale i file si vedono e si scaricano, ma non se ne aggiungono di nuovi. Chi ha caricato un file può ancora rinominarlo o eliminarlo.',
        'hint_riservati' => 'I file sono visibili e gestibili solo dall\'amministrazione.',
        'moderation_label' => 'In moderazione',
        'moderation_hint' => 'Accesa: i file nuovi aspettano l\'approvazione, tranne quelli di chi li può approvare. Spenta: escono subito anche quelli di chi ha il permesso di pubblicarli.',
    ],

    'filters' => [
        'status' => 'Stato',
        'status_all' => 'Tutti gli stati',
        'status_pending' => 'In attesa di approvazione',
        'status_published' => 'Pubblicati',
        'type' => 'Tipo',
        'type_all' => 'Tutti i tipi',
        'type_segnalazioni' => 'Segnalazioni',
        'type_comunicazioni' => 'Comunicazioni',
        'condominio' => 'Condominio',
        'condominio_all' => 'Tutti i condomini',
        'author' => 'Autore',
        'author_placeholder' => 'Cerca per autore…',
        'author_tooltip' => 'La colonna mostra il nome dell\'anagrafica collegata (o il nome dell\'account se non c\'è un\'anagrafica). La ricerca trova sia il nome dell\'anagrafica sia il nome dell\'account utente.',
        'date_from' => 'Dal',
        'date_to' => 'Al',
        'reset' => 'Azzera filtri',
    ],

    'table' => [
        'name' => 'File',
        'element' => 'Elemento',
        'condominio' => 'Condominio',
        'author' => 'Autore',
        'date' => 'Caricato il',
        'status' => 'Stato',
        'size' => 'Peso',
        'actions' => 'Azioni',
    ],
];

