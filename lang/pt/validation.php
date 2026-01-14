<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | As seguintes linhas de linguagem contêm as mensagens de erro padrão usadas
    | pela classe validadora. Algumas dessas regras têm múltiplas versões,
    | como as regras de tamanho. Sinta-se à vontade para ajustar cada uma
    | dessas mensagens aqui.
    |
    */

    'accepted' => ':attribute deve ser aceito.',
    'accepted_if' => ':attribute deve ser aceito quando :other for :value.',
    'active_url' => ':attribute não contém um endereço de email válido.',
    'after' => ':attribute deve ser posterior a :date.',
    'after_or_equal' => ':attribute deve ser posterior ou igual a :date.',
    'alpha' => ':attribute pode conter somente letras.',
    'alpha_dash' => ':attribute pode conter somente letras, números, traços e underscores.',
    'alpha_num' => ':attribute pode conter somente letras e números.',
    'array' => ':attribute deve ser um array.',
    'ascii' => ':attribute deve conter apenas caracteres alfanuméricos e símbolos de byte único.',
    'before' => ':attribute deve ser uma data anterior a :date.',
    'before_or_equal' => ':attribute deve ser uma data anterior ou igual a :date.',
    'between' => [
        'array' => ':attribute deve conter entre :min e :max elementos.',
        'file' => ':attribute deve ter entre :min e :max kilobytes.',
        'numeric' => ':attribute deve estar entre :min e :max.',
        'string' => ':attribute deve conter entre :min e :max caracteres.',
    ],
    'boolean' => ':attribute deve ser verdadeiro ou falso.',
    'can' => 'O campo :attribute tem um valor não autorizado.',
    'confirmed' => ':attribute não corresponde.',
    'current_password' => 'A senha informada está incorreta.',
    'date' => ':attribute não é uma data válida.',
    'date_equals' => ':attribute deve ser igual a :date.',
    'date_format' => ':attribute não corresponde ao formato :format.',
    'decimal' => ':attribute deve ter :decimal casas decimais.',
    'declined' => ':attribute deve ser recusado.',
    'declined_if' => ':attribute deve ser recusado quando :other for :value.',
    'different' => ':attribute e :other devem ser diferentes.',
    'digits' => ':attribute deve ter :digits dígitos.',
    'digits_between' => ':attribute deve ter entre :min e :max dígitos.',
    'dimensions' => 'As dimensões da imagem de :attribute não são válidas.',
    'distinct' => ':attribute contém valores duplicados.',
    'doesnt_end_with' => ':attribute não deve terminar com um dos seguintes valores: :values.',
    'doesnt_start_with' => ':attribute não deve começar com um dos seguintes valores: :values.',
    'email' => ':attribute deve ser um endereço de email válido.',
    'ends_with' => ':attribute deve terminar com um dos seguintes valores: :values.',
    'enum' => 'O elemento :attribute selecionado não é válido.',
    'exists' => 'O elemento :attribute selecionado não é válido.',
    'extensions' => 'O campo :attribute deve ter uma das seguintes extensões: :values.',
    'file' => ':attribute deve ser um arquivo.',
    'filled' => ':attribute deve estar preenchido.',
    'gt' => [
        'array' => ':attribute deve conter mais de :value elementos.',
        'file' => ':attribute deve ser maior que :value kilobytes.',
        'numeric' => ':attribute deve ser maior que :value.',
        'string' => ':attribute deve conter mais de :value caracteres.',
    ],
    'gte' => [
        'array' => ':attribute deve conter no mínimo :value elementos.',
        'file' => ':attribute deve ser maior ou igual a :value kilobytes.',
        'numeric' => ':attribute deve ser maior ou igual a :value.',
        'string' => ':attribute deve conter pelo menos :value caracteres.',
    ],
    'hex_color' => 'O campo :attribute deve ser uma cor hexadecimal válida.',
    'image' => ':attribute deve ser uma imagem.',
    'in' => ':attribute selecionado não é válido.',
    'in_array' => ':attribute não existe em :other.',
    'integer' => ':attribute deve ser um número inteiro.',
    'ip' => ':attribute deve ser um endereço IP válido.',
    'ipv4' => ':attribute deve ser um endereço IPv4 válido.',
    'ipv6' => ':attribute deve ser um endereço IPv6 válido.',
    'json' => ':attribute deve conter uma string JSON válida.',
    'list' => 'O campo :attribute deve ser uma lista.',
    'lowercase' => ':attribute deve estar em minúsculas.',
    'lt' => [
        'array' => ':attribute deve conter menos de :value elementos.',
        'file' => ':attribute deve ser menor que :value kilobytes.',
        'numeric' => ':attribute deve ser menor que :value.',
        'string' => ':attribute deve conter menos de :value caracteres.',
    ],
    'lte' => [
        'array' => ':attribute não deve conter mais de :value elementos.',
        'file' => ':attribute deve ser menor ou igual a :value kilobytes.',
        'numeric' => ':attribute deve ser menor ou igual a :value.',
        'string' => ':attribute não deve conter mais de :value caracteres.',
    ],
    'mac_address' => ':attribute deve ser um endereço MAC válido.',
    'max' => [
        'array' => ':attribute não pode conter mais de :max elementos.',
        'file' => ':attribute não pode ser maior que :max kilobytes.',
        'numeric' => ':attribute não pode ser maior que :max.',
        'string' => ':attribute não pode ter mais que :max caracteres.',
    ],
    'max_digits' => ':attribute não deve conter mais de :max dígitos.',
    'mimes' => ':attribute deve ser um arquivo do tipo: :values.',
    'mimetypes' => ':attribute deve ser um arquivo do tipo: :values.',
    'min' => [
        'array' => ':attribute deve conter pelo menos :min elementos.',
        'file' => ':attribute deve ter pelo menos :min kilobytes.',
        'numeric' => ':attribute deve ser no mínimo :min.',
        'string' => 'O campo :attribute deve conter pelo menos :min caracteres.',
    ],
    'min_digits' => ':attribute deve ter pelo menos :min dígitos.',
    'missing' => ':attribute deve estar ausente.',
    'missing_if' => ':attribute deve estar ausente quando :other for :value.',
    'missing_unless' => ':attribute deve estar ausente a menos que :other seja :value.',
    'missing_with' => ':attribute deve estar ausente quando :values estiver presente.',
    'missing_with_all' => ':attribute deve estar ausente quando :values estiverem presentes.',
    'multiple_of' => ':attribute deve ser múltiplo de :value.',
    'not_in' => ':attribute selecionado não é válido.',
    'not_regex' => 'O formato de :attribute não é válido.',
    'numeric' => ':attribute deve ser um número.',
    'password' => [
        'letters' => ':attribute deve conter pelo menos uma letra.',
        'mixed' => ':attribute deve conter pelo menos uma letra maiúscula e uma minúscula.',
        'numbers' => ':attribute deve conter pelo menos um número.',
        'symbols' => ':attribute deve conter pelo menos um símbolo.',
        'uncompromised' => ':attribute aparece em um vazamento de dados. Escolha outro :attribute.',
    ],
    'present' => ':attribute deve estar presente.',
    'present_if' => 'O campo :attribute deve estar presente quando :other for :value.',
    'present_unless' => 'O campo :attribute deve estar presente a menos que :other seja :value.',
    'present_with' => 'O campo :attribute deve estar presente quando :values estiver presente.',
    'present_with_all' => 'O campo :attribute deve estar presente quando :values estiverem presentes.',
    'prohibited' => ':attribute é proibido.',
    'prohibited_if' => ':attribute é proibido quando :other for :value.',
    'prohibited_unless' => ':attribute é proibido a menos que :other esteja em :values.',
    'prohibits' => ':attribute proíbe :other de estar presente.',
    'regex' => 'O formato de :attribute não é válido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_array_keys' => ':attribute deve conter um dos seguintes valores: :values.',
    'required_if' => ':attribute é obrigatório quando :other for :value.',
    'required_if_accepted' => ':attribute é obrigatório quando :other for aceito.',
    'required_unless' => ':attribute é obrigatório, a menos que :other esteja em :values.',
    'required_with' => ':attribute é obrigatório quando :values estiver presente.',
    'required_with_all' => ':attribute é obrigatório quando :values estiverem presentes.',
    'required_without' => ':attribute é obrigatório quando :values não estiver presente.',
    'required_without_all' => ':attribute é obrigatório quando nenhum de :values estiver presente.',
    'same' => ':attribute e :other devem corresponder.',
    'size' => [
        'array' => ':attribute deve conter :size elementos.',
        'file' => ':attribute deve ter :size kilobytes.',
        'numeric' => ':attribute deve ser :size.',
        'string' => ':attribute deve ter :size caracteres.',
    ],
    'starts_with' => ':attribute deve começar com um dos seguintes valores: :values.',
    'string' => ':attribute deve ser uma string.',
    'timezone' => ':attribute deve ser um fuso horário válido.',
    'unique' => 'O campo :attribute já está em uso.',
    'uploaded' => 'O upload de :attribute falhou.',
    'uppercase' => ':attribute deve estar em maiúsculas.',
    'url' => ':attribute deve ser uma URL válida.',
    'ulid' => ':attribute deve ser um ULID válido.',
    'uuid' => ':attribute deve ser um UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Aqui você pode especificar mensagens de validação personalizadas para
    | atributos usando a convenção "attribute.rule". Isto torna rápido
    | especificar uma mensagem personalizada para uma regra específica.
    |
    */

    'custom' => [
        'email' => [
            'required' => 'O campo :attribute é obrigatório',
            'unique' => 'O campo :attribute já está em uso',
            'lowercase' => 'O campo :attribute deve estar em minúsculas',
            'email' => 'O campo :attribute deve ser um endereço de email válido',
            'unique_email_across_tables' => 'Este endereço de email já está em uso.'
        ],
        'email_secondaria' => [
            'required' => 'O campo :attribute é obrigatório',
            'unique' => 'O campo :attribute já está em uso',
            'lowercase' => 'O campo :attribute deve estar em minúsculas',
            'email' => 'O campo :attribute deve ser um endereço de email válido'
        ],
        'pec' => [
            'required' => 'O campo :attribute é obrigatório',
            'unique' => 'O campo :attribute já está em uso',
            'lowercase' => 'O campo :attribute deve estar em minúsculas',
            'email' => 'O campo :attribute deve ser um endereço de email válido'
        ],
        'name' => [
            'required' => 'O campo :attribute é obrigatório'
        ],
        'password' => [
            'required' => 'O campo :attribute é obrigatório',
            'confirmed' => 'O campo :attribute não coincide com a confirmação'
        ],
        'building' => [
            'required' => 'O campo :attribute é obrigatório',
            'unique' => 'Já existe um condomínio registrado com este :attribute',
            'codice_fiscale' => [
                'required' => 'O campo :attribute é obrigatório',
                'unique' => 'Já existe um condomínio registrado com este :attribute'
            ]
        ],
        'anagrafica' => [
            'after:today' => ':attribute deve ser posterior a hoje',
            'before:today' => ':attribute deve ser anterior a hoje',
            'codice_fiscale' => [
                'unique' => 'Já existe um anagrafia registrado com este :attribute'
            ]
        ],
        'evento' => [
            'after_or_equal:today' => 'O :attribute deve ser posterior ou igual a hoje'
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | As seguintes linhas de linguagem são usadas para substituir o placeholder
    | do atributo por algo mais legível, como "Endereço de E-mail" em vez de
    | "email". Isso simplesmente nos ajuda a tornar as mensagens mais claras.
    |
    */

    'attributes' => [
        'email' => 'endereço de email',
        'email_secondaria' => 'endereço de email',
        'pec' => 'endereço de email',
        'name' => 'nome e sobrenome',
        'building' => [
            'nome' => 'denominação',
            'roles' => 'o campo papéis'
        ],
        'user' => [
            'roles' => 'papel'
        ],
        'anagrafica' => [
            'nome' => 'o campo nome e sobrenome da anagrafia',
            'indirizzo' => 'o campo endereço da anagrafia',
            'scadenza_documento' => 'o campo validade do documento',
            'data_nascita' => 'o campo data de nascimento',
            'codice_fiscale' => 'número de contribuinte' 
        ],
        'ruoli' => [
            'name' => 'nome do papel',
            'description' => 'descrição do papel',
        ],
        'comunicazioni' => [
            'subject' => 'assunto',
            'description' => 'descrição',
            'is_published' => 'status',
            'priority' => 'prioridade',
            'stato' => 'status das comunicações',
            'condomini_ids' => 'condomínios',
        ],
        'segnalazioni' => [
            'subject' => 'assunto da sinalização',
            'description' => 'descrição da sinalização',
            'is_published' => 'status da sinalização',
            'priority' => 'prioridade da sinalização',
            'stato' => 'status da sinalização',
            'condominio_id' => 'condomínio',
        ],
        'documenti' => [
            'name' => 'nome',
            'description' => 'descrição',
            'is_published' => 'status do documento',
            'condomini_ids' => 'condomínios',
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
            'end_time' => 'data de término',
            'category_id' => 'categoria',
            'recurrence_until' => 'data final da recorrência',
            'condomini_ids' => 'condomínios',
            'visibility' => 'status de publicação',
        ],
        'palazzine' => [
            'name' => 'nome do edifício',
            'description' => 'descrição do edifício',
        ],
        'scale' => [
            'name' => 'nome da escada',
            'description' => 'descrição da escada',
        ],
        'immobili' => [
            'tipologia_id' => 'tipologia do imóvel',
        ],

    ],

];
