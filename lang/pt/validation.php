<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'O campo :attribute deve ser aceite.',
    'accepted_if' => 'O campo :attribute deve ser aceite quando :other é :value.',
    'active_url' => 'O campo :attribute deve ser um URL válido.',
    'after' => 'O campo :attribute deve ser uma data posterior a :date.',
    'after_or_equal' => 'O campo :attribute deve ser uma data posterior ou igual a :date.',
    'alpha' => 'O campo :attribute deve conter apenas letras.',
    'alpha_dash' => 'O campo :attribute deve conter apenas letras, números, hífenes e sublinhados.',
    'alpha_num' => 'O campo :attribute deve conter apenas letras e números.',
    'array' => 'O campo :attribute deve ser um array.',
    'ascii' => 'O campo :attribute deve conter apenas caracteres ASCII.',
    'before' => 'O campo :attribute deve ser uma data anterior a :date.',
    'before_or_equal' => 'O campo :attribute deve ser uma data anterior ou igual a :date.',
    'between' => [
        'array' => 'O campo :attribute deve conter entre :min e :max itens.',
        'file' => 'O campo :attribute deve ter entre :min e :max kilobytes.',
        'numeric' => 'O campo :attribute deve estar entre :min e :max.',
        'string' => 'O campo :attribute deve ter entre :min e :max caracteres.',
    ],
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'can' => 'O campo :attribute contém um valor não autorizado.',
    'confirmed' => 'A confirmação do campo :attribute não corresponde.',
    'contains' => 'O campo :attribute não contém um valor obrigatório.',
    'current_password' => 'A palavra-passe está incorreta.',
    'date' => 'O campo :attribute deve ser uma data válida.',
    'date_equals' => 'O campo :attribute deve ser uma data igual a :date.',
    'date_format' => 'O campo :attribute deve respeitar o formato :format.',
    'decimal' => 'O campo :attribute deve ter :decimal casas decimais.',
    'declined' => 'O campo :attribute deve ser recusado.',
    'declined_if' => 'O campo :attribute deve ser recusado quando :other é :value.',
    'different' => 'Os campos :attribute e :other devem ser diferentes.',
    'digits' => 'O campo :attribute deve ter :digits dígitos.',
    'digits_between' => 'O campo :attribute deve ter entre :min e :max dígitos.',
    'dimensions' => 'O campo :attribute tem dimensões de imagem inválidas.',
    'distinct' => 'O campo :attribute contém um valor duplicado.',
    'doesnt_end_with' => 'O campo :attribute não pode terminar com: :values.',
    'doesnt_start_with' => 'O campo :attribute não pode começar com: :values.',
    'email' => 'O campo :attribute deve ser um endereço de email válido.',
    'ends_with' => 'O campo :attribute deve terminar com: :values.',
    'enum' => 'O valor selecionado para :attribute é inválido.',
    'exists' => 'O valor selecionado para :attribute é inválido.',
    'extensions' => 'O campo :attribute deve ter uma das seguintes extensões: :values.',
    'file' => 'O campo :attribute deve ser um ficheiro.',
    'filled' => 'O campo :attribute deve ter um valor.',
    'gt' => [
        'array' => 'O campo :attribute deve ter mais de :value itens.',
        'file' => 'O campo :attribute deve ser superior a :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser superior a :value.',
        'string' => 'O campo :attribute deve ter mais de :value caracteres.',
    ],
    'gte' => [
        'array' => 'O campo :attribute deve ter :value itens ou mais.',
        'file' => 'O campo :attribute deve ser superior ou igual a :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser superior ou igual a :value.',
        'string' => 'O campo :attribute deve ter :value caracteres ou mais.',
    ],
    'hex_color' => 'O campo :attribute deve ser uma cor hexadecimal válida.',
    'image' => 'O campo :attribute deve ser uma imagem.',
    'in' => 'O valor selecionado para :attribute é inválido.',
    'in_array' => 'O campo :attribute deve existir em :other.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'ip' => 'O campo :attribute deve ser um endereço IP válido.',
    'ipv4' => 'O campo :attribute deve ser um endereço IPv4 válido.',
    'ipv6' => 'O campo :attribute deve ser um endereço IPv6 válido.',
    'json' => 'O campo :attribute deve ser uma string JSON válida.',
    'lowercase' => 'O campo :attribute deve estar em minúsculas.',
    'lt' => [
        'array' => 'O campo :attribute deve ter menos de :value itens.',
        'file' => 'O campo :attribute deve ser inferior a :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser inferior a :value.',
        'string' => 'O campo :attribute deve ter menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'O campo :attribute não pode ter mais de :value itens.',
        'file' => 'O campo :attribute deve ser inferior ou igual a :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser inferior ou igual a :value.',
        'string' => 'O campo :attribute deve ter :value caracteres ou menos.',
    ],
    'mac_address' => 'O campo :attribute deve ser um endereço MAC válido.',
    'max' => [
        'array' => 'O campo :attribute não pode ter mais de :max itens.',
        'file' => 'O campo :attribute não pode ser superior a :max kilobytes.',
        'numeric' => 'O campo :attribute não pode ser superior a :max.',
        'string' => 'O campo :attribute não pode ter mais de :max caracteres.',
    ],
    'max_digits' => 'O campo :attribute não pode ter mais de :max dígitos.',
    'mimes' => 'O campo :attribute deve ser um ficheiro do tipo: :values.',
    'mimetypes' => 'O campo :attribute deve ser um ficheiro do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute deve ter pelo menos :min itens.',
        'file' => 'O campo :attribute deve ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute deve ser pelo menos :min.',
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],
    'min_digits' => 'O campo :attribute deve ter pelo menos :min dígitos.',
    'missing' => 'O campo :attribute não deve estar presente.',
    'missing_if' => 'O campo :attribute não deve estar presente quando :other é :value.',
    'missing_unless' => 'O campo :attribute não deve estar presente exceto quando :other é :value.',
    'missing_with' => 'O campo :attribute não deve estar presente quando :values está presente.',
    'missing_with_all' => 'O campo :attribute não deve estar presente quando :values estão presentes.',
    'multiple_of' => 'O campo :attribute deve ser múltiplo de :value.',
    'not_in' => 'O valor selecionado para :attribute é inválido.',
    'not_regex' => 'O formato do campo :attribute é inválido.',
    'numeric' => 'O campo :attribute deve ser numérico.',
    'password' => [
        'letters' => 'O campo :attribute deve conter pelo menos uma letra.',
        'mixed' => 'O campo :attribute deve conter pelo menos uma letra maiúscula e uma minúscula.',
        'numbers' => 'O campo :attribute deve conter pelo menos um número.',
        'symbols' => 'O campo :attribute deve conter pelo menos um símbolo.',
        'uncompromised' => 'O valor do campo :attribute já apareceu numa fuga de dados. Escolha um valor diferente.',
    ],
    'present' => 'O campo :attribute deve estar presente.',
    'present_if' => 'O campo :attribute deve estar presente quando :other é :value.',
    'present_unless' => 'O campo :attribute deve estar presente exceto quando :other é :value.',
    'present_with' => 'O campo :attribute deve estar presente quando :values está presente.',
    'present_with_all' => 'O campo :attribute deve estar presente quando :values estão presentes.',
    'prohibited' => 'O campo :attribute é proibido.',
    'prohibited_if' => 'O campo :attribute é proibido quando :other é :value.',
    'prohibited_if_accepted' => 'O campo :attribute é proibido quando :other é aceite.',
    'prohibited_if_declined' => 'O campo :attribute é proibido quando :other é recusado.',
    'prohibited_unless' => 'O campo :attribute é proibido exceto quando :other está em :values.',
    'prohibits' => 'O campo :attribute impede a presença de :other.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_array_keys' => 'O campo :attribute deve conter as seguintes chaves: :values.',
    'required_if' => 'O campo :attribute é obrigatório quando :other é :value.',
    'required_if_accepted' => 'O campo :attribute é obrigatório quando :other é aceite.',
    'required_if_declined' => 'O campo :attribute é obrigatório quando :other é recusado.',
    'required_unless' => 'O campo :attribute é obrigatório exceto quando :other está em :values.',
    'required_with' => 'O campo :attribute é obrigatório quando :values está presente.',
    'required_with_all' => 'O campo :attribute é obrigatório quando :values estão presentes.',
    'required_without' => 'O campo :attribute é obrigatório quando :values não está presente.',
    'required_without_all' => 'O campo :attribute é obrigatório quando nenhum dos valores :values está presente.',
    'same' => 'O campo :attribute deve corresponder a :other.',
    'size' => [
        'array' => 'O campo :attribute deve conter :size itens.',
        'file' => 'O campo :attribute deve ter :size kilobytes.',
        'numeric' => 'O campo :attribute deve ser :size.',
        'string' => 'O campo :attribute deve ter :size caracteres.',
    ],
    'starts_with' => 'O campo :attribute deve começar por: :values.',
    'string' => 'O campo :attribute deve ser uma string.',
    'timezone' => 'O campo :attribute deve ser um fuso horário válido.',
    'unique' => 'O valor do campo :attribute já se encontra registado.',
    'uploaded' => 'Falha no carregamento do campo :attribute.',
    'uppercase' => 'O campo :attribute deve estar em maiúsculas.',
    'url' => 'O campo :attribute deve ser um URL válido.',
    'ulid' => 'O campo :attribute deve ser um ULID válido.',
    'uuid' => 'O campo :attribute deve ser um UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'email' => [
            'required' => 'O campo :attribute é obrigatório.',
            'unique' => 'O campo :attribute já se encontra registado.',
            'lowercase' => 'O campo :attribute deve estar em minúsculas.',
            'email' => 'O campo :attribute deve ser um endereço de email válido.'
        ],
        'email_secondaria' => [
            'required' => 'O campo :attribute é obrigatório.',
            'unique' => 'O campo :attribute já se encontra registado.',
            'lowercase' => 'O campo :attribute deve estar em minúsculas.',
            'email' => 'O campo :attribute deve ser um endereço de email válido.'
        ],
        'pec' => [
            'required' => 'O campo :attribute é obrigatório.',
            'unique' => 'O campo :attribute já se encontra registado.',
            'lowercase' => 'O campo :attribute deve estar em minúsculas.',
            'email' => 'O campo :attribute deve ser um endereço de email válido.'
        ],
        'name' => [
            'required' => 'O campo :attribute é obrigatório.'
        ],
        'password' => [
            'required' => 'O campo :attribute é obrigatório.',
            'confirmed' => 'A confirmação do campo :attribute não corresponde.'
        ],
        'building' => [
            'required' => 'O campo :attribute é obrigatório.',
            'unique' => 'Já existe um edifício registado com este :attribute',
            'codice_fiscale' => [
                'required' => 'O campo :attribute é obrigatório.',
                'unique' => 'Já existe um edifício registado com este :attribute'
            ]
        ],
        'anagrafica' => [
            'after:today' => 'O campo :attribute deve ser posterior à data de hoje.',
            'before:today' => 'O campo :attribute deve ser anterior à data de hoje.',
        ],
        'evento' => [
            'after_or_equal:today' => 'O campo :attribute deve ser igual ou posterior à data de hoje.'
        ]
    ],


    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    
    'attributes' => [
        'email' => 'endereço de email',
        'email_secondaria' => 'endereço de email',
        'pec' => 'endereço de email',
        'name' => 'nome completo',

        'building' => [
            'nome' => 'designação do edifício',
            'roles' => 'funções'
        ],
        'user' => [
            'roles' => 'função'
        ],
        'anagrafica' => [
            'nome' => 'nome do condomínio',
            'indirizzo' => 'morada',
            'scadenza_documento' => 'validade do documento',
            'data_nascita' => 'data de nascimento',
        ],
        'ruoli' => [
            'name' => 'nome da função',
            'description' => 'descrição da função',
        ],
        'comunicazioni' => [
            'subject' => 'assunto',
            'description' => 'descrição',
            'is_published' => 'estado de publicação',
            'priority' => 'prioridade',
            'stato' => 'estado',
            'condomini_ids' => 'edifícios',
        ],
        'segnalazioni' => [
            'subject' => 'assunto do ticket',
            'description' => 'descrição do ticket',
            'is_published' => 'estado de publicação',
            'priority' => 'prioridade do ticket',
            'stato' => 'estado do ticket',
            'condominio_id' => 'edifício',
        ],
        'documenti' => [
            'name' => 'nome',
            'description' => 'descrição',
            'is_published' => 'estado',
            'condomini_ids' => 'edifícios',
            'category_id' => 'categoria',
        ],
        'categorie' => [
            'name' => 'nome',
            'description' => 'descrição',
        ],
        'eventi' => [
            'title' => 'título',
            'description' => 'descrição',
            'start_time' => 'data de início',
            'end_time' => 'data de fim',
            'category_id' => 'categoria',
            'recurrence_until' => 'data de fim da recorrência',
            'condomini_ids' => 'edifícios',
            'visibility' => 'estado de visibilidade',
        ],
        'palazzine' => [
            'name' => 'nome do bloco',
            'description' => 'descrição do bloco',
        ],
        'scale' => [
            'name' => 'nome do andar',
            'description' => 'descrição do andar',
        ],
        'immobili' => [
            'tipologia_id' => 'tipo de fração',
        ],
    ],

];
