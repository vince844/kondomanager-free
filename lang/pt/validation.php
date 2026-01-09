<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Linhas de linguagem para validação
    |--------------------------------------------------------------------------
    |
    | As seguintes linhas de linguagem contêm as mensagens de erro padrão usadas
    | pela classe de validação. Algumas destas regras têm múltiplas versões,
    | como as regras de tamanho. Pode ajustar livremente cada uma destas mensagens aqui.
    |
    */
    'accepted'        => ':attribute deve ser aceite.',
    'accepted_if'     => ':attribute deve ser aceite quando :other é :value.',
    'active_url'      => ':attribute não é um URL válido.',
    'after'           => ':attribute deve ser uma data posterior a :date.',
    'after_or_equal'  => ':attribute deve ser uma data posterior ou igual a :date.',
    'alpha'           => ':attribute só pode conter letras.',
    'alpha_dash'      => ':attribute só pode conter letras, números, traços e underscores.',
    'alpha_num'       => ':attribute só pode conter letras e números.',
    'array'           => ':attribute deve ser um array.',
    'ascii'           => ':attribute só pode conter caracteres alfanuméricos e símbolos de byte único.',
    'before'          => ':attribute deve ser uma data anterior a :date.',
    'before_or_equal' => ':attribute deve ser uma data anterior ou igual a :date.',
    'between' => [
        'array'   => ':attribute deve ter entre :min e :max itens.',
        'file'    => ':attribute deve ter entre :min e :max kilobytes.',
        'numeric' => ':attribute deve estar entre :min e :max.',
        'string'  => ':attribute deve ter entre :min e :max caracteres.',
    ],
    'boolean'           => ':attribute deve ser verdadeiro ou falso.',
    'can'               => 'O campo :attribute contém um valor não autorizado.',
    'confirmed'         => 'A confirmação de :attribute não corresponde.',
    'current_password'  => 'A palavra-passe atual está incorreta.',
    'date'              => ':attribute não é uma data válida.',
    'date_equals'       => ':attribute deve ser uma data igual a :date.',
    'date_format'       => ':attribute não corresponde ao formato :format.',
    'decimal'           => ':attribute deve ter :decimal casas decimais.',
    'declined'          => ':attribute deve ser recusado.',
    'declined_if'       => ':attribute deve ser recusado quando :other é :value.',
    'different'         => ':attribute e :other devem ser diferentes.',
    'digits'            => ':attribute deve ter :digits dígitos.',
    'digits_between'    => ':attribute deve ter entre :min e :max dígitos.',
    'dimensions'        => ':attribute tem dimensões de imagem inválidas.',
    'distinct'          => ':attribute tem valores duplicados.',
    'doesnt_end_with'   => ':attribute não pode terminar com um dos seguintes valores: :values.',
    'doesnt_start_with' => ':attribute não pode começar com um dos seguintes valores: :values.',
    'email'             => ':attribute deve ser um endereço de correio eletrónico válido.',
    'ends_with'         => ':attribute deve terminar com um dos seguintes valores: :values.',
    'enum'              => 'O :attribute selecionado é inválido.',
    'exists'            => 'O :attribute selecionado é inválido.',
    'extensions'        => 'O campo :attribute deve ter uma das seguintes extensões: :values.',
    'file'              => ':attribute deve ser um ficheiro.',
    'filled'            => ':attribute deve ter um valor.',
    'gt' => [
        'array'   => ':attribute deve ter mais de :value itens.',
        'file'    => ':attribute deve ser maior que :value kilobytes.',
        'numeric' => ':attribute deve ser maior que :value.',
        'string'  => ':attribute deve ter mais de :value caracteres.',
    ],
    'gte' => [
        'array'   => ':attribute deve ter :value itens ou mais.',
        'file'    => ':attribute deve ser maior ou igual a :value kilobytes.',
        'numeric' => ':attribute deve ser maior ou igual a :value.',
        'string'  => ':attribute deve ter :value caracteres ou mais.',
    ],
    'hex_color'  => 'O campo :attribute deve ser uma cor hexadecimal válida.',
    'image'      => ':attribute deve ser uma imagem.',
    'in'         => 'O :attribute selecionado é inválido.',
    'in_array'   => ':attribute não existe em :other.',
    'integer'    => ':attribute deve ser um número inteiro.',
    'ip'         => ':attribute deve ser um endereço IP válido.',
    'ipv4'       => ':attribute deve ser um endereço IPv4 válido.',
    'ipv6'       => ':attribute deve ser um endereço IPv6 válido.',
    'json'       => ':attribute deve ser uma string JSON válida.',
    'list'       => 'O campo :attribute deve ser uma lista.',
    'lowercase'  => ':attribute deve estar em minúsculas.',
    'lt' => [
        'array'   => ':attribute deve ter menos de :value itens.',
        'file'    => ':attribute deve ser menor que :value kilobytes.',
        'numeric' => ':attribute deve ser menor que :value.',
        'string'  => ':attribute deve ter menos de :value caracteres.',
    ],
    'lte' => [
        'array'   => ':attribute não deve ter mais de :value itens.',
        'file'    => ':attribute deve ser menor ou igual a :value kilobytes.',
        'numeric' => ':attribute deve ser menor ou igual a :value.',
        'string'  => ':attribute não deve ter mais de :value caracteres.',
    ],
    'mac_address' => ':attribute deve ser um endereço MAC válido.',
    'max' => [
        'array'   => ':attribute não pode ter mais de :max itens.',
        'file'    => ':attribute não pode ser maior que :max kilobytes.',
        'numeric' => ':attribute não pode ser maior que :max.',
        'string'  => ':attribute não pode ter mais de :max caracteres.',
    ],
    'max_digits' => ':attribute não pode ter mais de :max dígitos.',
    'mimes'      => ':attribute deve ser um ficheiro do tipo: :values.',
    'mimetypes'  => ':attribute deve ser um ficheiro do tipo: :values.',
    'min' => [
        'array'   => ':attribute deve ter pelo menos :min itens.',
        'file'    => ':attribute deve ter pelo menos :min kilobytes.',
        'numeric' => ':attribute deve ser pelo menos :min.',
        'string'  => ':attribute deve ter pelo menos :min caracteres.',
    ],
    'min_digits'       => ':attribute deve ter pelo menos :min dígitos.',
    'missing'          => ':attribute deve estar ausente.',
    'missing_if'       => ':attribute deve estar ausente quando :other é :value.',
    'missing_unless'   => ':attribute deve estar ausente a menos que :other seja :value.',
    'missing_with'     => ':attribute deve estar ausente quando :values está presente.',
    'missing_with_all' => ':attribute deve estar ausente quando todos os :values estão presentes.',
    'multiple_of'      => ':attribute deve ser um múltiplo de :value.',
    'not_in'           => 'O :attribute selecionado é inválido.',
    'not_regex'        => 'O formato de :attribute é inválido.',
    'numeric'          => ':attribute deve ser um número.',
    'password' => [
        'letters'       => ':attribute deve conter pelo menos uma letra.',
        'mixed'         => ':attribute deve conter pelo menos uma letra maiúscula e uma minúscula.',
        'numbers'       => ':attribute deve conter pelo menos um número.',
        'symbols'       => ':attribute deve conter pelo menos um símbolo.',
        'uncompromised' => ':attribute apareceu numa fuga de dados. Escolha outro :attribute.',
    ],
    'present'              => ':attribute deve estar presente.',
    'present_if'           => 'O campo :attribute deve estar presente quando :other é :value.',
    'present_unless'       => 'O campo :attribute deve estar presente a menos que :other seja :value.',
    'present_with'         => 'O campo :attribute deve estar presente quando :values está presente.',
    'present_with_all'     => 'O campo :attribute deve estar presente quando todos os :values estão presentes.',
    'prohibited'           => ':attribute é proibido.',
    'prohibited_if'        => ':attribute é proibido quando :other é :value.',
    'prohibited_unless'    => ':attribute é proibido a menos que :other esteja em :values.',
    'prohibits'            => ':attribute proíbe que :other esteja presente.',
    'regex'                => 'O formato de :attribute é inválido.',
    'required'             => 'O campo :attribute é obrigatório.',
    'required_array_keys'  => ':attribute deve conter entradas para: :values.',
    'required_if'          => 'O campo :attribute é obrigatório quando :other é :value.',
    'required_if_accepted' => 'O campo :attribute é obrigatório quando :other é aceite.',
    'required_unless'      => 'O campo :attribute é obrigatório a menos que :other esteja em :values.',
    'required_with'        => 'O campo :attribute é obrigatório quando :values está presente.',
    'required_with_all'    => 'O campo :attribute é obrigatório quando todos os :values estão presente.',
    'required_without'     => 'O campo :attribute é obrigatório quando :values não está presente.',
    'required_without_all' => 'O campo :attribute é obrigatório quando nenhum dos :values está presente.',
    'same'                 => ':attribute e :other devem corresponder.',
    'size' => [
        'array'   => ':attribute deve conter :size itens.',
        'file'    => ':attribute deve ter :size kilobytes.',
        'numeric' => ':attribute deve ser :size.',
        'string'  => ':attribute deve ter :size caracteres.',
    ],
    'starts_with' => ':attribute deve começar com um dos seguintes valores: :values.',
    'string'      => ':attribute deve ser uma string.',
    'timezone'    => ':attribute deve ser um fuso horário válido.',
    'unique'      => ':attribute já está em uso.',
    'uploaded'    => 'O upload de :attribute falhou.',
    'uppercase'   => ':attribute deve estar em maiúsculas.',
    'url'         => ':attribute deve ser um URL válido.',
    'ulid'        => ':attribute deve ser um ULID válido.',
    'uuid'        => ':attribute deve ser um UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Linhas de validação personalizadas
    |--------------------------------------------------------------------------
    |
    | Aqui pode especificar mensagens de validação personalizadas para atributos
    | usando a convenção "attribute.rule" para nomear as linhas. Isto permite
    | definir rapidamente uma mensagem customizada para uma regra específica.
    |
    */
    'custom' => [
        'email' => [
            'required'                   => 'O campo :attribute é obrigatório',
            'unique'                     => ':attribute já está em uso',
            'lowercase'                  => 'O campo :attribute deve estar em minúsculas',
            'email'                      => 'O campo :attribute deve ser um endereço de correio eletrónico válido',
            'unique_email_across_tables' => 'Este endereço de correio eletrónico já está em uso.',
        ],
        'email_secondaria' => [
            'required'  => 'O campo :attribute é obrigatório',
            'unique'    => ':attribute já está em uso',
            'lowercase' => 'O campo :attribute deve estar em minúsculas',
            'email'     => 'O campo :attribute deve ser um endereço de correio eletrónico válido',
        ],
        'pec' => [
            'required'  => 'O campo :attribute é obrigatório',
            'unique'    => ':attribute já está em uso',
            'lowercase' => 'O campo :attribute deve estar em minúsculas',
            'email'     => 'O campo :attribute deve ser um endereço de correio eletrónico válido',
        ],
        'name' => [
            'required' => 'O campo :attribute é obrigatório',
        ],
        'password' => [
            'required'   => 'O campo :attribute é obrigatório',
            'confirmed'  => 'A confirmação de :attribute não corresponde',
        ],
        'building' => [
            'required' => 'O campo :attribute é obrigatório',
            'unique'   => 'Já existe um condomínio registado com este :attribute',
        ],
        'codice_fiscale' => [
            'required' => 'O campo :attribute é obrigatório',
            'unique'   => 'Já existe um condomínio registado com este :attribute',
        ],
        'anagrafica' => [
            'after:today'          => ':attribute deve ser posterior a hoje',
            'before:today'         => ':attribute deve ser anterior a hoje',
        ],
        'evento' => [
            'after_or_equal:today' => 'A :attribute deve ser posterior ou igual a hoje',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Atributos de validação personalizados
    |--------------------------------------------------------------------------
    |
    | As seguintes linhas permitem substituir o placeholder do atributo por algo
    | mais legível, como "Endereço de correio eletrónico" em vez de "email".
    | Isto ajuda a tornar as mensagens mais expressivas e amigáveis.
    |
    */
    'attributes' => [
        'email'            => 'endereço de correio eletrónico',
        'email_secondaria' => 'endereço de correio eletrónico',
        'pec'              => 'endereço de correio eletrónico',
        'name'             => 'nome completo',
        'building' => [
            'nome'  => 'denominação',
            'roles' => 'papéis',
        ],
        'user' => [
            'roles' => 'papel',
        ],
        'anagrafica' => [
            'nome'               => 'nome completo',
            'indirizzo'          => 'morada',
            'scadenza_documento' => 'data de validade do documento',
            'data_nascita'       => 'data de nascimento',
        ],
        'ruoli' => [
            'name'        => 'nome do papel',
            'description' => 'descrição do papel',
        ],
        'comunicazioni' => [
            'subject'       => 'assunto',
            'description'   => 'descrição',
            'is_published'  => 'estado',
            'priority'      => 'prioridade',
            'stato'         => 'estado das comunicações',
            'condomini_ids' => 'condomínios',
        ],
        'segnalazioni' => [
            'subject'        => 'assunto da sinalização',
            'description'    => 'descrição da sinalização',
            'is_published'   => 'estado da sinalização',
            'priority'       => 'prioridade da sinalização',
            'stato'          => 'estado da sinalização',
            'condominio_id'  => 'condomínio',
        ],
        'documenti' => [
            'name'          => 'nome',
            'description'   => 'descrição',
            'is_published'  => 'estado do documento',
            'condomini_ids' => 'condomínios',
            'category_id'   => 'categoria',
        ],
        'categorie' => [
            'name'        => 'nome',
            'description' => 'descrição',
        ],
        'eventi' => [
            'title'            => 'título',
            'description'      => 'descrição',
            'start_time'       => 'data de início',
            'end_time'         => 'data de fim',
            'category_id'      => 'categoria',
            'recurrence_until' => 'data de fim da recorrência',
            'condomini_ids'    => 'condomínios',
            'visibility'       => 'estado de publicação',
        ],
        'palazzine' => [
            'name'        => 'nome do edifício',
            'description' => 'descrição do edifício',
        ],
        'scale' => [
            'name'        => 'nome da escada',
            'description' => 'descrição da escada',
        ],
        'immobili' => [
            'tipologia_id' => 'tipologia de imóvel',
        ],
    ],
];