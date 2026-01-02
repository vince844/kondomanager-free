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

<<<<<<< HEAD
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
=======
    'accepted' => 'The :attribute field must be accepted.',
    'accepted_if' => 'The :attribute field must be accepted when :other is :value.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => 'The :attribute field must be a date after :date.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'array' => 'The :attribute field must be an array.',
    'ascii' => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
    'before' => 'The :attribute field must be a date before :date.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'between' => [
        'array' => 'The :attribute field must have between :min and :max items.',
        'file' => 'The :attribute field must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute field must be between :min and :max.',
        'string' => 'The :attribute field must be between :min and :max characters.',
    ],
    'boolean' => 'The :attribute field must be true or false.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'contains' => 'The :attribute field is missing a required value.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute field must be a valid date.',
    'date_equals' => 'The :attribute field must be a date equal to :date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'dimensions' => 'The :attribute field has invalid image dimensions.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'doesnt_end_with' => 'The :attribute field must not end with one of the following: :values.',
    'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
    'email' => 'The :attribute field must be a valid email address.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => 'The :attribute field must be a file.',
    'filled' => 'The :attribute field must have a value.',
    'gt' => [
        'array' => 'The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'in_array' => 'The :attribute field must exist in :other.',
    'integer' => 'The :attribute field must be an integer.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'ipv4' => 'The :attribute field must be a valid IPv4 address.',
    'ipv6' => 'The :attribute field must be a valid IPv6 address.',
    'json' => 'The :attribute field must be a valid JSON string.',
    'list' => 'The :attribute field must be a list.',
    'lowercase' => 'The :attribute field must be lowercase.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digits.',
    'missing' => 'The :attribute field must be missing.',
    'missing_if' => 'The :attribute field must be missing when :other is :value.',
    'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
    'missing_with' => 'The :attribute field must be missing when :values is present.',
    'missing_with_all' => 'The :attribute field must be missing when :values are present.',
    'multiple_of' => 'The :attribute field must be a multiple of :value.',
    'not_in' => 'The selected :attribute is invalid.',
    'not_regex' => 'The :attribute field format is invalid.',
    'numeric' => 'The :attribute field must be a number.',
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'present' => 'The :attribute field must be present.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_if_accepted' => 'The :attribute field is prohibited when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is prohibited when :other is declined.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_if_accepted' => 'The :attribute field is required when :other is accepted.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => 'The :attribute field must match :other.',
    'size' => [
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size characters.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => 'The :attribute field must be a string.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'uppercase' => 'The :attribute field must be uppercase.',
    'url' => 'The :attribute field must be a valid URL.',
    'ulid' => 'The :attribute field must be a valid ULID.',
    'uuid' => 'The :attribute field must be a valid UUID.',
>>>>>>> ece9f11 (Addedd Portughese language)

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
<<<<<<< HEAD
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


=======
            'required' => 'The field :attribute is required',
            'unique' => 'The field :attribute is already taken',
            'lowercase' => 'The field :attribute has to be lowercase',
            'email' => 'The field :attribute has to be a valid email address',
            'unique_email_across_tables' => "This email address is already taken.",
        ],
        'email_secondaria' => [
            'required' => 'The field :attribute èis required',
            'unique' => 'The field :attribute is already taken',
            'lowercase' => 'The field :attribute has to be loweercase',
            'email' => 'The field :attribute has to be a valid email address'
        ],
        'pec' => [
            'required' => 'The field :attribute is required',
            'unique' => 'The field :attribute is already taken',
            'lowercase' => 'The field :attribute has to be lower case',
            'email' => 'The field :attribute has to be a valid email address'
        ],
        'name' => [
            'required' => 'The field :attribute is required'
        ],
        'password' => [
            'required' => 'The field :attribute is required',
            'confirmed' => "The field :attribute doesn't match"
        ],
        'building' => [
            'required' => 'The field :attribute is required',
            'unique' => 'You already have a building registered with this :attribute',
            'codice_fiscale' => [
                'required' => 'The field :attribute is required',
                'unique' => 'You already have a building registered with this :attribute'
            ]
        ],
        'anagrafica' => [
            'after:today' => ':attribute has to be after today date',
            'before:today' => ':attribute has to be before today date',
        ],
        'evento' => [
            'after_or_equal:today' => 'The :attribute must be later than or equal to today'
        ]
    ],

>>>>>>> ece9f11 (Addedd Portughese language)
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
<<<<<<< HEAD
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

=======
        'email' => 'email address',
        'email_secondaria' => 'email address',
        'pec' => 'email address',
        'name' => 'name and surname',
        'building' => [
            'nome' => 'denomination',
            'roles' => 'The field roles'
        ],
        'user' => [
            'roles' => 'role'
        ],
        'anagrafica' => [
            'nome' => 'The field name and surname',
            'indirizzo' => 'The field address',
            'scadenza_documento' => 'The field document expiry',
            'data_nascita' => 'The field date of birth',
        ],
        'ruoli' => [
            'name' => 'role name',
            'description' => 'role description',
        ],
        'comunicazioni' => [
            'subject' => 'subject',
            'description' => 'description',
            'is_published' => 'published status',
            'priority' => 'priority',
            'stato' => 'status',
            'condomini_ids' => 'buildings',
        ],
        'segnalazioni' => [
            'subject' => 'ticket object',
            'description' => 'ticket description',
            'is_published' => 'ticket published status',
            'priority' => 'ticket priority',
            'stato' => 'ticket status',
            'condominio_id' => 'building',
        ],
        'documenti' => [
            'name' => 'name',
            'description' => 'description',
            'is_published' => 'status',
            'condomini_ids' => 'buildings',
            'category_id' => 'category',
        ],
        'categorie' => [
            'name' => 'name',
            'description' => 'description',
        ],
        'eventi' => [
            'title' => 'title',
            'description' => 'description',
            'start_time' => 'start date',
            'end_time' => 'end date',
            'category_id' => 'category',
            'recurrence_until' => 'recurrency end date',
            'condomini_ids' => 'buildings',
            'visibility' => 'visibility status',
        ],
        'palazzine' => [
            'name' => 'name palazzina',
            'description' => 'description palazzina',
        ],
        'scale' => [
            'name' => 'noame scala',
            'description' => 'description scala',
        ],
        'immobili' => [
            'tipologia_id' => 'immobile type',
        ],

    ],


>>>>>>> ece9f11 (Addedd Portughese language)
];
