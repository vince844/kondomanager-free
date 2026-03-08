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
        'referents_list_title'          => "Referentes do fornecedor",
        'documents_list_title'          => "Documentos do fornecedor",
        'contacts_new_title'            => "Associar referente",
        'documents_new_title'           => "Novo documento do fornecedor",
        'documents_edit_title'          => "Editar documento do fornecedor",
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
        'name'           => 'Razão Social',
        'address'        => 'Morada',
        'contacts'       => 'Contactos',
        'type'           => 'Tipo',
        'actions'        => 'Ações',
        'title'          => 'Título',
        'status'         => 'Estado',
        'role'           => 'Função',
        'login_access'   => 'Acesso ao portal',
        'click_to_view'  => 'Clique para visualizar',
        'filter_by_name' => 'Procurar fornecedor...',
        'no_results'     => 'Nenhum resultado encontrado',
        'residents'      => 'Referentes',
        'residents_desc' => 'Lista de contactos e referentes associados a este fornecedor.',
    ],

    /* ------------------------------------------------------------------
     | Rótulos e Placeholders
     | ------------------------------------------------------------------ */
    'label' => [
        'tax_code'             => 'Número de Contribuinte',
        'vat_number'           => 'Número de IVA',
        'document'             => 'Documento',
        'document_name'        => 'Nome do documento',
        'document_description' => 'Descrição',
        'select_document'      => 'Selecionar documento',
        'record'               => 'Registo',
        'role'                 => 'Função',
        'created'              => 'Criado',
        'file_status'          => 'Estado do ficheiro',
        'present'              => 'Disponível',
        'missing'              => 'Em falta',
    ],

    'placeholder' => [
        'no_address'            => 'Morada não disponível',
        'document_name'         => 'Introduza o nome do documento',
        'document_description'  => 'Introduza a descrição do documento',
        'publish_status'        => 'Selecionar visibilidade',
        'record'                => 'Selecionar registo',
        'role'                  => 'Selecionar função',
    ],

    /* ------------------------------------------------------------------
     | Ações
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_fornitore'   => 'Novo Fornecedor',
        'edit_fornitore'  => 'Editar',
        'delete_fornitore'=> 'Eliminar',
        'detach_referent' => 'Desassociar',
        'associate'       => 'Associar',
        'save'            => 'Guardar',
        'save_changes'    => 'Guardar alterações',
        'edit'            => 'Editar',
        'delete'          => 'Eliminar',
        'remove'          => 'Remover',
        'cancel'          => 'Cancelar',
        'replace_file'    => 'Substituir ficheiro',
        'suppliers'       => 'Fornecedores',
        'save_fornitore'  => 'Guardar Fornecedor',
        'list'            => 'Lista', 
    ],

    'navigation' => [
        'details'   => 'Detalhes',
        'referents' => 'Referentes',
        'documents' => 'Documentos',
        'suppliers' => 'Fornecedores',
    ],

    'dialogs' => [
        'delete_supplier_title'       => 'Tem a certeza de que pretende eliminar este fornecedor?',
        'delete_supplier_description' => 'Esta ação não pode ser revertida. Irá eliminar o fornecedor e todos os dados associados.',
        'detach_referent_title'       => 'Tem a certeza de que pretende desassociar este registo do fornecedor?',
        'detach_referent_description' => 'Esta ação não pode ser revertida. O registo será desassociado e deixará de poder visualizar os dados do fornecedor.',
        'delete_document_title'       => 'Tem a certeza de que pretende eliminar este documento?',
        'delete_document_description' => 'Esta ação não pode ser revertida.',
        'drop_document_title'         => 'Largue o ficheiro aqui',
        'drop_document_description'   => 'ou clique para selecionar um documento',
        'replace_existing'            => 'Este ficheiro vai substituir o documento existente',
        'only_pdf'                    => 'Apenas são permitidos ficheiros PDF.',
        'max_20mb'                    => 'Tamanho máximo do ficheiro: 20MB.',
    ],

    'common' => [
        'back'           => 'Voltar',
        'loading'        => 'A carregar...',
        'reset_filters'  => 'Limpar filtros',
        'selected_count' => ':count selecionados',
    ],

    'roles' => [
        'owner'      => 'Titular',
        'admin'      => 'Administrativo',
        'sales'      => 'Comercial',
        'technician' => 'Técnico',
        'contact'    => 'Referente',
        'other'      => 'Outro',
    ],

    'sections' => [
        'publish_status'      => 'Estado de publicação',
        'publish_status_desc' => 'Escolha se o documento fica visível.',
        'contact_assoc_title' => 'Associação de referente',
        'contact_assoc_desc'  => 'Associe um registo existente a este fornecedor.',
        'info'                => 'Informação',
    ],

    'forms' => [
        'main_info_title'                    => 'Informações principais',
        'main_info_desc'                     => 'Dados de identificação e legais essenciais do fornecedor.',
        'company_name'                       => 'Razão social',
        'company_name_placeholder'           => 'Ex.: Rossi Impianti S.r.l.',
        'main_contact'                       => 'Referente principal',
        'internal_notes'                     => 'Notas internas adicionais',
        'internal_notes_placeholder'         => 'Insira uma nota visível apenas aos administradores',
        'contacts_title'                     => 'Contactos e sede',
        'contacts_desc'                      => 'Morada operacional e canais oficiais de comunicação.',
        'address'                            => 'Morada e número',
        'address_placeholder'                => 'Rua, Praça, Avenida...',
        'zip_code'                           => 'Código postal',
        'city'                               => 'Localidade',
        'province'                           => 'Distrito',
        'phone'                              => 'Telefone fixo',
        'mobile'                             => 'Telemóvel',
        'fax'                                => 'Fax',
        'email'                              => 'Email',
        'email_placeholder'                  => 'email@exemplo.pt',
        'pec'                                => 'Email PEC',
        'pec_placeholder'                    => 'pec@legalmail.it',
        'website'                            => 'Website',
        'website_placeholder'                => 'https://...',
        'billing_title'                      => 'Faturação e pagamentos',
        'billing_desc'                       => 'Regras para registo e pagamento de honorários.',
        'withholding_subject'                => 'Sujeito a retenção na fonte',
        'primary_iban'                       => 'IBAN principal (conta padrão)',
        'payment_method'                     => 'Método de pagamento',
        'payment_method_placeholder'         => 'Selecione o método...',
        'deadline_days'                      => 'Prazo (dias)',
        'tax_automation_title'               => 'Automações fiscais (F24 e CU)',
        'tax_automation_desc'                => 'Estes parâmetros permitem ao sistema <strong>calcular automaticamente a retenção</strong> ao registar faturas.',
        'withholding_rate'                   => '% a reter',
        'taxable_base'                       => '% base tributável',
        'tax_code'                           => 'Código de tributo',
        'company_data_title'                 => 'Dados da empresa',
        'company_data_desc'                  => 'Registos em câmaras de comércio, ordens e certificações.',
        'cciaa_registration'                 => 'Registo CCIAA',
        'cciaa_registration_placeholder'     => 'Número de registo CCIAA',
        'cciaa_registration_date'            => 'Data de registo CCIAA',
        'share_capital'                      => 'Capital social',
        'supplier_category'                  => 'Categoria do fornecedor',
        'select_category'                    => 'Selecione categoria...',
        'professional_register'              => 'Inscrição em ordem profissional (se aplicável)',
        'professional_register_placeholder'  => 'Número de inscrição',
        'iso_certification'                  => 'A empresa possui certificação ISO conforme normas europeias',
        'select_date'                        => 'Selecionar data',
        'example_4'                          => 'Ex. 4',
        'example_100'                        => 'Ex. 100',
        'example_1040'                       => 'Ex. 1040',
        'example_10000'                      => 'Ex: 10.000,00',
        'payment_methods' => [
            'bank_transfer' => 'Transferência bancária',
            'mav'           => 'MAV',
            'riba'          => 'Ri.Ba.',
            'cash'          => 'Numerário',
        ],
    ],

    'states' => [
        'active'             => 'Ativo',
        'inactive'           => 'Inativo',
        'suspended'          => 'Suspenso',
        'ended'              => 'Cessado',
        'approved_tooltip'   => 'Visível para utilizadores',
        'unapproved_tooltip' => 'Visível apenas para administradores',
    ],

    'messages' => [
        'delete_document_error' => 'Erro ao eliminar o documento.',
    ],

    'view' => [
        'title'                  => 'Detalhes do fornecedor',
        'breadcrumb_detail'      => 'Detalhe do fornecedor',
        'supplier_fallback'      => 'Fornecedor',
        'edit_data'              => 'Editar dados',
        'phone_landline'         => 'Fixo',
        'phone_mobile'           => 'Telemóvel',
        'no_contacts'            => 'Nenhum contacto registado.',
        'iso_active'             => 'Certificação ISO ativa',
        'iso_inactive'           => 'Sem certificação ISO',
        'not_registered'         => 'Não registado',
        'default_payment_method' => 'Transferência bancária',
        'days_abbr'              => 'dias',
        'withholding'            => 'Retenção na fonte',
        'not_subject_withholding'=> 'Não sujeito a retenção na fonte.',
        'no_notes'               => 'Sem notas adicionais.',
        'guides' => [
            'contacts_title' => 'Contactos e dados',
            'contacts_desc'  => 'Consulte morada, telefones, email e website do fornecedor.',
            'treasury_title' => 'Tesouraria e pagamentos',
            'treasury_desc'  => 'Verifique IBAN, método de pagamento e retenções.',
            'company_title'  => 'Dados da empresa',
            'company_desc'   => 'Confirme inscrições e dados de conformidade.',
        ],
        'sections' => [
            'contacts' => 'Contactos e dados',
            'company'  => 'Dados da empresa e inscrições',
            'treasury' => 'Tesouraria e pagamentos',
            'notes'    => 'Notas internas',
        ],
        'labels' => [
            'ateco_code'            => 'Código ATECO',
            'cciaa_registration'    => 'Inscrição CCIAA',
            'registration_date'     => 'Data de inscrição',
            'professional_register' => 'Ordem profissional',
            'share_capital'         => 'Capital social',
            'primary_iban'          => 'IBAN principal',
            'method'                => 'Método',
            'deadline'              => 'Prazo',
            'rate'                  => 'Taxa',
            'taxable'               => 'Incid.',
            'tax_code_short'        => 'Tributo',
        ],
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
    
];
