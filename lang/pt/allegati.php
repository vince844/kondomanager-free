<?php

return [
    'title' => 'Anexos',
    'subtitle' => 'Anexos de sinalizações e avisos de todos os condomínios',
    'empty' => 'Nenhum anexo presente.',
    'empty_description' => 'Quando forem carregados ficheiros nas sinalizações ou nos avisos, aparecerão aqui.',
    'empty_filtered_title' => 'Nenhum anexo para estes filtros',
    'empty_filtered_description' => 'Tente alterar os critérios selecionados ou limpe os filtros para rever a lista completa.',
    'empty_element_description' => 'Ainda não foram carregados ficheiros para este elemento.',
    'pending_badge' => 'A aguardar aprovação',
    'published_badge' => 'Publicado',

    'header' => [
        'list_head' => 'Lista de anexos',
        'list_title' => 'Lista de anexos',
        'list_description' => 'Abaixo encontra-se a tabela com os anexos de sinalizações e avisos de todos os condomínios',
    ],

    'guides' => [
        'overview_title' => 'Lista única',
        'overview_desc' => 'Consulte num só lugar os ficheiros anexados a sinalizações e avisos, com ligação direta à respetiva ficha.',
        'moderation_title' => 'Moderação em bloco',
        'moderation_desc' => 'Aprove ou elimine os anexos pendentes também selecionando-os em bloco diretamente na tabela.',
        'export_title' => 'Exportação em ZIP',
        'export_desc' => 'Transfira os anexos filtrados num arquivo organizado por condomínio, tipo e elemento, completo com o resumo elenco.csv.',
    ],

    'upload' => [
        'label' => 'Adicionar anexos',
        'hint' => '{0} Formatos permitidos: :formati, no máximo :limite por ficheiro. Não é possível adicionar mais ficheiros.|{1} Formatos permitidos: :formati, no máximo :limite por ficheiro. Pode adicionar mais um ficheiro (no máximo :massimo).|[2,*] Formatos permitidos: :formati, no máximo :limite por ficheiro. Pode adicionar mais :rimasti ficheiros (no máximo :massimo).',
        'no_slots' => 'Foi atingido o limite de :massimo anexos para este elemento.',
        'button' => 'Carregar anexos',
        'uploading' => 'A carregar…',
        'selected_files' => 'Ficheiros selecionados',
        'remove_file' => 'Remover',
    ],

    'actions' => [
        'open' => 'Abrir',
        'download' => 'Transferir',
        'rename' => 'Renomear',
        'save_name' => 'Guardar',
        'cancel' => 'Cancelar',
        'delete' => 'Eliminar',
        'approve' => 'Aprovar',
        'approve_selected' => 'Aprovar selecionados',
        'delete_selected' => 'Eliminar selecionados',
        'download_zip' => 'Transferir em ZIP',
        'save_settings' => 'Guardar definições',
    ],

    'confirm_delete' => [
        'title' => 'Eliminar anexo',
        'description' => 'Pretende eliminar definitivamente o anexo «:nome»? Esta operação não pode ser anulada.',
        'bulk_title' => 'Eliminar anexos selecionados',
        'bulk_description' => 'Pretende eliminar definitivamente os anexos selecionados? Esta operação não pode ser anulada.',
    ],

    'settings' => [
        'title' => 'Definições dos anexos',
        'dialog_description' => 'Configure a visibilidade e moderação dos ficheiros anexados a este elemento.',
        'access_label' => 'Acesso aos anexos',
        'access_aperti' => 'Abertos',
        'access_consultazione' => 'Apenas consulta',
        'access_riservati' => 'Apenas administração',
        'hint_aperti' => 'Quem vê o elemento vê e descarrega os ficheiros. A partir do portal, adiciona novos quem reside no edifício para as ocorrências e quem a escreveu para os comunicados.',
        'hint_consultazione' => 'No portal os ficheiros podem ser vistos e descarregados, mas não se adicionam novos. Quem carregou um ficheiro ainda o pode renomear ou eliminar.',
        'hint_riservati' => 'Os ficheiros são visíveis e geríveis apenas pela administração.',
        'moderation_label' => 'Em moderação',
        'moderation_hint' => 'Ativa: os ficheiros novos aguardam aprovação, exceto os de quem os pode aprovar. Desativada: saem de imediato também os de quem tem permissão para os publicar.',
    ],

    'filters' => [
        'status' => 'Estado',
        'status_all' => 'Todos os estados',
        'status_pending' => 'A aguardar aprovação',
        'status_published' => 'Publicados',
        'type' => 'Tipo',
        'type_all' => 'Todos os tipos',
        'type_segnalazioni' => 'Sinalizações',
        'type_comunicazioni' => 'Avisos',
        'condominio' => 'Condomínio',
        'condominio_all' => 'Todos os condomínios',
        'author' => 'Autor',
        'author_placeholder' => 'Procurar por autor…',
        'author_tooltip' => 'A coluna mostra o nome do registo associado (ou o nome da conta se não houver registo). A pesquisa encontra tanto o nome do registo como o da conta de utilizador.',
        'date_from' => 'De',
        'date_to' => 'Até',
        'reset' => 'Limpar filtros',
    ],

    'table' => [
        'name' => 'Ficheiro',
        'element' => 'Elemento',
        'condominio' => 'Condomínio',
        'author' => 'Autor',
        'date' => 'Carregado em',
        'status' => 'Estado',
        'size' => 'Tamanho',
        'actions' => 'Ações',
    ],
];

