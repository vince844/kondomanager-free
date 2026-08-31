<?php

return [

    /* ------------------------------------------------------------------
     | Notificações Backend
     | ------------------------------------------------------------------ */
    'success_create_fornitore'           => "O novo fornecedor foi criado com sucesso.",
    'error_create_fornitore'             => "Ocorreu um erro ao criar o novo fornecedor.",
    'success_update_fornitore'           => "O fornecedor foi atualizado com sucesso.",
    'error_update_fornitore'             => "Ocorreu um erro ao atualizar o fornecedor.",
    'success_delete_fornitore'           => "O fornecedor foi eliminado com sucesso.",     
    'error_delete_fornitore'             => "Ocorreu um erro ao eliminar o fornecedor.",
    'success_attach_anagrafica'          => "O registo foi associado com sucesso ao fornecedor.",
    'error_attach_anagrafica'            => "Ocorreu um erro ao associar o registo ao fornecedor.",
    'success_detach_anagrafica'          => "O registo foi desassociado com sucesso do fornecedor.",
    'error_detach_anagrafica'            => "Ocorreu um erro ao desassociar o registo do fornecedor.",
    'error_delete_has_invoices'          => "Impossível eliminar: o fornecedor tem faturas registadas no sistema. Para não comprometer a contabilidade, recomendamos alterar o \"Estado\" para \"Inativo\".",

    /* ------------------------------------------------------------------
     | Cabeçalhos, Títulos e Descrições
     | ------------------------------------------------------------------ */
    'header' => [
        'list_fornitori_head'           => "Lista de fornecedores",
        'list_fornitori_title'          => "Lista de fornecedores",
        'list_fornitori_description'    => "Gerencie o registo de fornecedores e profissionais associados aos seus condomínios.",
        'new_fornitore_head'            => "Criar fornecedor",
        'new_fornitore_title'           => "Criar fornecedor",
        'new_fornitore_description'     => "Insira os dados para registar uma nova empresa ou profissional.",
        'edit_fornitore_head'           => "Editar fornecedor",
        'edit_fornitore_title'          => "Editar fornecedor",
        'edit_fornitore_description'    => "Atualize os dados mestres e fiscais do fornecedor para manter a contabilidade alinhada.",
    ],

    /* ------------------------------------------------------------------
     | Tabela
     | ------------------------------------------------------------------ */
    'table' => [
        'category'                => 'Categoria',
        'name'           => 'Razão Social',
        'address'        => 'Morada',
        'contacts'       => 'Contactos',
        'type'           => 'Tipo',
        'actions'        => 'Ações',
        'click_to_view'  => 'Clique para visualizar',
        'filter_by_name' => 'Procurar fornecedor...',
        'residents'      => 'Referentes',
        'representatives_title' => 'Representantes do fornecedor',
        'representatives_desc'  => 'As pessoas que respondem por esta empresa. Abre uma ficha para os seus contactos.',
        'residents_desc' => 'Lista de contactos e referentes associados a este fornecedor.',
    ],

    /* ------------------------------------------------------------------
     | Rótulos e Placeholders
     | ------------------------------------------------------------------ */
    'label' => [
        'tax_code'   => 'Número de Contribuinte',
        'vat_number' => 'Número de IVA',
    ],

    'placeholder' => [
        'no_address' => 'Morada não disponível',
    ],

    /* ------------------------------------------------------------------
     | Ações
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_fornitore'   => 'Novo Fornecedor',
        'edit_fornitore'  => 'Editar',
        'delete_fornitore'=> 'Eliminar',
        'save_fornitore'  => 'Guardar Fornecedor',
        'list'            => 'Lista', 
    ],

    /* ------------------------------------------------------------------
     | Guias (PageHeaderGuide)
     | ------------------------------------------------------------------ */
    'guides' => [
        'portfolio_title'             => 'Directório de Fornecedores',
        'portfolio_desc'              => 'Aceda rapidamente aos dados fiscais e de contacto de todas as empresas e profissionais.',
        'compliance_title'            => 'Conformidade Fiscal',
        'compliance_desc'             => 'Verifique NIFs e códigos fiscais para uma faturação eletrónica correta.',
        'management_title'            => 'Gestão Rápida',
        'management_desc'             => 'Adicione novos fornecedores ou atualize contactos para melhorar a comunicação.',
        'new_fornitore_guide_title'   => 'Inserção de Dados', 
        'new_fornitore_guide_desc'    => 'Certifique-se de introduzir corretamente o NIF para permitir o envio de retenções na fonte.', 
        'edit_status_title'           => 'Ciclo de Vida',
        'edit_status_desc'            => 'Use o estado "Inativo" ou "Suspenso" em vez de apagar a empresa, para manter os registos contabilísticos antigos intactos.',
        'edit_treasury_title'         => 'Dados Bancários',
        'edit_treasury_desc'          => 'Mantenha o IBAN principal atualizado para garantir a precisão dos fluxos de caixa e transferências bancárias.',
        'edit_compliance_title'       => 'Retenções e Impostos',
        'edit_compliance_desc'        => 'Atualize as taxas de imposto para permitir que o sistema calcule automaticamente os pagamentos líquidos.',
    ],
    

    /* ------------------------------------------------------------------
     | Categorias de fornecedor (1.11.0-beta.9)
     | ------------------------------------------------------------------ */
    'categorie' => [
        'success_create'      => "A categoria foi criada com sucesso.",
        'error_create'        => "Ocorreu um erro ao criar a categoria.",
        'success_update'      => "A categoria foi atualizada com sucesso.",
        'error_update'        => "Ocorreu um erro ao atualizar a categoria.",
        'success_delete'      => "A categoria foi eliminada com sucesso.",
        'error_delete'        => "Ocorreu um erro ao eliminar a categoria.",
        'in_uso'              => "{1} A categoria «:nome» não foi eliminada: há um fornecedor que a usa. Muda a categoria dele e tenta de novo.|[2,*] A categoria «:nome» não foi eliminada: há :quanti fornecedores que a usam. Muda a categoria deles e tenta de novo.",

        'head'                => "Categorias de fornecedor",
        'title'               => "Categorias dos fornecedores",
        'description'         => "Cria, renomeia e elimina as categorias com que classificas empresas e profissionais.",
        'back'                => "Voltar aos fornecedores",

        'new'                 => "Nova categoria",
        'new_title'           => "Criar nova categoria",
        'new_description'     => "Adiciona uma categoria para classificar empresas e profissionais.",
        'edit_title'          => "Modificar categoria: :categoria",
        'edit_description'    => "Aqui podes mudar o nome e a descrição da categoria.",

        'name'                => "Nome",
        'name_placeholder'    => "Por exemplo: vidraceiro",
        'description_label'   => "Descrição",
        'description_hint'    => "Facultativa: serve só para lembrar o que entra aqui.",
        'description_placeholder' => "Que tipo de fornecedores reúne esta categoria",
        'used_by'             => "Fornecedores",
        'suppliers_title'     => "Fornecedores desta categoria",
        'suppliers_desc'      => "Quem está classificado como «:categoria». Abre uma ficha para lhe mudar a categoria.",

        'actions'             => "Ações",
        'edit'                => "Modificar",
        'delete'              => "Eliminar",
        'blocked_title'       => "Esta categoria não se pode eliminar",
        'blocked_intro'       => "{1} Há um fornecedor classificado como «:categoria». Enquanto for assim, eliminá-la deixá-lo-ia sem categoria.|[2,*] Há :count fornecedores classificados como «:categoria». Enquanto for assim, eliminá-la deixá-los-ia sem categoria.",
        'blocked_how'         => "Abre uma ficha para lhe mudar a categoria: quando já ninguém a usar, a eliminação funciona.",
        'blocked_close'       => "Percebi",
        'delete_title'        => "Eliminar a categoria?",
        'delete_description'  => "Os fornecedores que a usam perderiam a categoria, por isso a eliminação só funciona se ninguém a estiver a usar.",
        'save'                => "Guardar",
        'cancel'              => "Cancelar",

        'filtro_non_valido'   => "O filtro por categoria já não é válido: essa categoria já não existe. Aqui está a lista completa.",
        'clear_filters'       => "Limpar os filtros",
        'filter'              => "Filtrar por nome...",
        'no_results'          => "Nenhuma categoria encontrada.",
        'empty'               => "Ainda não há nenhuma categoria.",

        'quick_add'           => "Criar uma nova categoria",
        'quick_add_title'     => "Nova categoria de fornecedor",
        'quick_add_description' => "Cria-la aqui e fica selecionada: não perdes o que já escreveste na ficha.",
        'quick_created'       => "Categoria criada e selecionada.",
        'manage'              => "Gerir as categorias",

        'guides' => [
            'own_title'       => "Categorias tuas",
            'own_desc'        => "As categorias iniciais são um ponto de partida: acrescenta os ofícios de que os teus condomínios precisam e tira os que não usas.",
            'use_title'       => "Para que servem",
            'use_desc'        => "Classificam empresas e profissionais na lista de fornecedores e permitem encontrá-los por tipo de trabalho.",
            'safe_title'      => "Eliminação protegida",
            'safe_desc'       => "Uma categoria usada por pelo menos um fornecedor não se elimina: primeiro há que mudar a categoria a esses fornecedores.",
        ],
    ],

];