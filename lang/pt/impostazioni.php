<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_update_notification_preferences' => 'As suas preferências de notificação foram atualizadas com sucesso.',
    'error_update_notification_preferences'   => 'Ocorreu um erro ao tentar atualizar as suas preferências de notificação.',
    'success_save_general_settings'           => 'As configurações gerais foram guardadas com sucesso.',
    'error_save_general_settings'             => 'Ocorreu um erro durante a gravação das configurações gerais.',
    'success_save_cron_settings'              => 'As configurações de automação cloud foram guardadas com sucesso.',
    'error_save_cron_settings'                => 'Ocorreu um erro ao guardar as configurações de automação cloud.',
    'success_regenerate_cron_token'           => 'Token do webhook regenerado com sucesso.',
    'error_regenerate_cron_token'             => 'Ocorreu um erro ao regenerar o token.',
    'success_save_mail_settings'              => 'Configuração de email guardada com sucesso.',
    'error_save_mail_settings'                => 'Erro ao guardar a configuração de email.',
    'success_save_print_settings'             => 'Configurações de impressão guardadas com sucesso.',

    /* ------------------------------------------------------------------
     | Mail Status Badge
     | ------------------------------------------------------------------ */
    'mail_status' => [
        'database' => 'Configuração da Base de Dados',
        'env'      => 'Configuração .env',
        'log'      => 'Modo de Segurança (Log)',
    ],

    /* ------------------------------------------------------------------
     | Driver descriptions
     | ------------------------------------------------------------------ */
    'driver' => [
        'smtp_description'     => 'Recomendado. Use um servidor SMTP externo (Gmail, Brevo, etc.)',
        'sendmail_description' => 'Apenas para VPS ou servidores dedicados com Postfix e SPF/DKIM configurados.',
    ],

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'settings_head'                => 'Configurações',
        'settings_title'               => 'Configurações da aplicação',
        'settings_description'         => 'A seguir uma lista de todas as configurações disponíveis para a aplicação.',
        'general_settings_title'       => 'Configurações gerais',
        'general_settings_description' => 'Nesta página pode gerir as configurações gerais da aplicação.',
        'cron_settings_title'          => 'Automação cloud (Cron externo)',
        'cron_settings_description'    => 'Utilize esta função se o seu alojamento não suporta cron jobs a cada minuto. Serviços suportados: cron-job.org',
        'mail_settings_title'          => 'Configuração de email',
        'mail_settings_description'    => 'Configure o método de envio para recibos, avisos e comunicações oficiais aos condóminos.',
    ],

    /* ------------------------------------------------------------------
     | Labels
     | ------------------------------------------------------------------ */
    'label' => [
        'manage'                => 'Gerir',
        'settings'              => 'Configurações',
        'update_now'            => 'Atualizar agora',
        'back_to_settings'      => 'Configurações',
        'mail_host'             => 'Servidor SMTP (Host)',
        'mail_port'             => 'Porta SMTP',
        'mail_username'         => 'Nome de utilizador / Email',
        'mail_password'         => 'Palavra-passe SMTP',
        'mail_encryption'       => 'Encriptação (Segurança)',
        'mail_from_address'     => 'Endereço de email do remetente',
        'mail_from_name'        => 'Nome do remetente a exibir',
        'save_settings'         => 'Guardar configuração',
        'send_test'             => 'Enviar e-mail de teste',
        'password_is_set'       => 'Palavra-passe definida e segura',
        'enable_db_settings'    => 'Ativar configuração da base de dados',
        'enable_db_description' => 'Se desativado, o sistema usará os parâmetros definidos no ficheiro .env.',
        'mail_driver'           => 'Método de envio',
        'api_key_is_set'        => 'Chave API configurada e segura',
        'encryption_none'       => 'Nenhuma',
        'mail_sendmail_path'      => 'Caminho do Sendmail',
        'mail_sendmail_path_hint' => 'Deixe o valor padrão se não tiver certeza. Altere apenas se o seu servidor usar um caminho diferente.',
        'print_legal_note'        => 'Nota Legal / Rodapé',
        'print_legal_note_help'   => 'Este texto aparecerá no rodapé de cada demonstrativo, junto com o número da página.',
        'print_admin_signature'   => 'Assinatura do Administrador',
        'print_no_signature'      => 'Sem assinatura',
        'print_upload_image'      => 'Enviar Imagem',
        'print_remove_signature'  => 'Remover',
        'print_signature_help'    => 'Use uma imagem PNG ou JPG com fundo branco ou transparente (máx. 2MB). A imagem será impressa no final da última página dos demonstrativos e relatórios.',
    ],

    /* ------------------------------------------------------------------
     | Empty‑state / dialog messages
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'general_settings_title'                => 'Configurações gerais',
        'general_settings_description'          => 'Configurações gerais e opções de personalização da aplicação.',
        'users_settings_title'                  => 'Gestão de utilizadores',
        'users_settings_description'            => 'Configurações de gestão de utilizadores, papéis e permissões.',
        'backups_settings_title'                => 'Gestão de cópias de segurança',
        'backups_settings_description'          => 'Configurações de gestão das cópias de segurança.',
        'updates_title'                         => 'Atualizações do sistema',
        'updates_desc_available'                => 'Nova versão disponível: :version',
        'updates_desc_latest'                   => 'O sistema está atualizado com a versão mais recente.',
        'language_settings_title'               => 'Idioma da aplicação',
        'language_settings_description'         => 'Selecione o idioma principal da aplicação.',
        'default_building_title'                => 'Abrir condomínio ao iniciar sessão',
        'default_building_description'          => 'Se ativado, o utilizador será redirecionado diretamente para o condomínio selecionado.',
        'select_building_title'                 => 'Condomínio predefinido',
        'select_building_description'           => 'Selecione o condomínio a abrir automaticamente após o início de sessão.',
        'user_registration_title'               => 'Ativar registo de utilizadores',
        'user_registration_description'         => 'Se ativado, os utilizadores podem registar-se a partir da página inicial.',
        'default_role_title'                    => 'Função padrão para novos usuários',
        'default_role_description'              => 'Escolha qual função atribuir automaticamente aos usuários que se registram pelo frontend.',
        'force_comment_moderation_title'        => 'Forçar moderação de comentários',
        'force_comment_moderation_description'  => 'Se ativado, todos os comentários de usuários normais exigirão aprovação de um administrador antes de serem publicados e visíveis.',

        'mail_settings_title'       => 'Configuração de email',
        'mail_settings_description' => 'Escolha o método de envio, configure as credenciais e teste a ligação.',
        'print_settings_title'                  => 'Configurações de Impressão PDF',
        'print_settings_description'            => 'Configure a aparência e os dados predefinidos que aparecerão na parte inferior de todos os documentos gerados pelo sistema.',
        'mail_guide_title'   => 'Guia de configuração',
        'mail_guide_gmail'   => 'Gmail (recomendado para todos): Ative a verificação em 2 passos, gere uma "Palavra-passe de aplicação" e use smtp.gmail.com, porta 587, TLS. Funciona em qualquer alojamento sem configurações DNS.',
        'mail_guide_smtp2go' => 'Outros fornecedores SMTP (Brevo, SMTP2Go, etc.): requerem verificação do domínio remetente via registos DNS (SPF/DKIM). Use esta opção apenas se tiver um domínio próprio com acesso ao painel DNS.',
        'mail_guide_domain'  => 'Sendmail: funciona apenas em VPS ou servidores dedicados com Postfix instalado e SPF/DKIM configurados. Não adequado para alojamentos partilhados.',

        'mail_info_title'       => 'Como funciona o envio de emails?',
        'mail_info_description' => 'O Kondomanager suporta dois métodos de envio.<br><br><strong>SMTP</strong> — o método recomendado para todos. Usa um servidor de correio externo (ex: Gmail). Os emails são entregues de forma fiável sem configurações avançadas.<br><br><strong>Sendmail</strong> — para utilizadores avançados com VPS ou servidor dedicado onde o Postfix está instalado e os registos SPF/DKIM estão configurados no DNS. Em alojamentos partilhados os emails serão rejeitados pelos destinatários.<br><br>Se desativar a configuração da base de dados, serão usadas as definições do ficheiro <strong>.env</strong>.',

        'mail_legend_title'    => 'Legenda de estados',
        'mail_legend_database' => 'Usa as credenciais configuradas nesta página (tem prioridade sobre o .env).',
        'mail_legend_env'      => 'Usa a configuração definida no ficheiro .env do servidor.',
        'mail_legend_log'      => 'Envio de email desativado — os emails são escritos apenas no ficheiro de log.',

        'sendmail_guide_title'       => 'Sendmail — apenas para servidores dedicados',
        'sendmail_guide_description' => 'Use esta opção APENAS se o seu servidor é um VPS ou servidor dedicado com Postfix/Exim instalado e os registos SPF e DKIM configurados no DNS do domínio remetente. Em alojamentos partilhados (Altervista, etc.) os emails serão rejeitados pelos destinatários porque os fornecedores modernos (Gmail, Outlook) exigem autenticação DNS. Para alojamentos partilhados use Gmail SMTP.',

        'test_header'          => 'Teste de envio imediato',
        'test_success_title'   => 'Ligação bem-sucedida',
        'test_success_message' => 'O email de teste foi enviado com sucesso para o destinatário.',
        'test_error_title'     => 'Erro de ligação',
        'test_error_message'   => 'Não foi possível enviar o email. Verifique os parâmetros e tente novamente.',
        'test_unsaved_warning' => 'Tem alterações não guardadas. Guarde a configuração antes de enviar o teste.',

        'cron_info_title'               => 'O que é a Automação Cloud?',
        'cron_info_description'         => 'O Kondomanager executa tarefas agendadas em segundo plano (ex: geração de prestações, envio de emails).<br><br>Normalmente, o servidor gere tudo autonomamente. Ative esta opção <strong>APENAS</strong> se estiver num <strong>Alojamento Partilhado</strong> que não permita configurar o "Crontab" do sistema via terminal.',
        'cron_legend_title'             => 'Modo de Operação',
        'cron_legend_external'          => 'Webhook (Externo): O sistema aguarda um sinal do cron-job.org.',
        'cron_legend_internal'          => 'System Cron (Interno): O servidor gere os processos autonomamente.',
        'cron_settings_title'                     => 'Automação cloud',
        'cron_settings_description'               => 'Configure o cron-job.org para alojamentos partilhados.',
        'enable_external_scheduler_title'         => 'Ativar agendador externo',
        'enable_external_scheduler_description'   => 'Permitir que serviços terceiros executem as automações.',
        'webhook_url_title'                       => 'Webhook URL',
        'webhook_url_description'                 => 'Copie este URL e configure uma chamada GET a cada 1 minuto no seu serviço externo.',
        'webhook_url_badge'                       => 'Secreto',
        'security_warning_title'                  => 'Segurança IP ativa',
        'security_warning_description'            => 'O sistema aceita apenas chamadas dos endereços IP oficiais do cron-job.org. Se usar outro serviço, esta configuração não funcionará.',
        'logs_settings_title'                     => 'Auditoria & Logs do Sistema',
        'logs_settings_description'               => 'Visualize o histórico de emails enviados, as atividades dos utilizadores e os logs do sistema.',
    ],

    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'select_building'     => 'Selecionar condomínio',
        'select_language'     => 'Selecionar idioma',
        'search_settings'     => 'Filtrar configurações...',
        'mail_host'           => 'ex: smtp.gmail.com',
        'mail_password'       => 'Insira a palavra-passe SMTP',
        'mail_password_keep'  => 'Deixe em branco para manter a palavra-passe atual',
        'mail_password_enter' => 'Insira a palavra-passe SMTP',
        'mail_from_address'   => 'ex: geral@seu-dominio.pt',
        'test_recipient'      => 'Inserir e-mail para o teste',
        'select_role'         => 'Selecione uma função',
        'print_legal_note'    => 'Ex: Profissão exercida nos termos da lei de 14 de janeiro de 2013, n.4 - NIF 01234567890 - Apólice RC n. XYZ',

        'language' => [
            'it' => 'Italiano',
            'en' => 'Inglês',
            'pt' => 'Português',
            'es' => 'Espanhol',
        ],
    ],

    'actions' => [
        'save_settings'    => 'Guardar configurações',
        'copy_url'         => 'Copiar URL',
        'regenerate_token' => 'Regenerar token',
    ],
    'confirmations' => [
        'regenerate_token' => 'Tem a certeza? Terá de atualizar o URL no cron-job.org.',
    ],
    'sidebar' => [
        'users'       => 'Utilizadores',
        'roles'       => 'Papéis',
        'permissions' => 'Permissões',
        'invites'     => 'Convites',
    ],
    
    /* ------------------------------------------------------------------
     | Guides
     | ------------------------------------------------------------------ */
    'guides' => [
        'language_title' => 'Idioma e Traduções',
        'language_desc'  => 'Escolha o idioma predefinido em que a aplicação será exibida para novos utilizadores e dispositivos não configurados.',
        'access_title'   => 'Acesso e Navegação',
        'access_desc'    => 'Simplifique o seu trabalho diário: configure um condomínio específico para abrir automaticamente logo após iniciar sessão, saltando o dashboard.',
        'security_title' => 'Registo e Papéis',
        'security_desc'  => 'Ative o registo público no site. Aos novos inscritos será automaticamente atribuído o papel predefinido que selecionar aqui.',
        'print_footer_title' => 'Rodapé dos documentos',
        'print_footer_desc'  => 'Configure o texto legal que aparecerá na parte inferior de todos os PDFs gerados.',
        'print_signature_title' => 'Assinatura de documentos',
        'print_signature_desc'  => 'Carregue ou desenhe a sua assinatura para anexar a relatórios e documentos oficiais.',
        'print_layout_title' => 'Formato e layout',
        'print_layout_desc'  => 'Todos os documentos são gerados automaticamente em formato A4, com margens adaptáveis para o cabeçalho.',
    ],
];