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

    'accepted' => ':attribute must be accepted.',
    'accepted_if' => ':attribute must be accepted when :other is :value.',
    'active_url' => ':attribute does not contain a valid URL.',
    'after' => ':attribute must be a date after :date.',
    'after_or_equal' => ':attribute must be a date after or equal to :date.',
    'alpha' => ':attribute may only contain letters.',
    'alpha_dash' => ':attribute may only contain letters, numbers, dashes and underscores.',
    'alpha_num' => ':attribute may only contain letters and numbers.',
    'array' => ':attribute must be an array.',
    'ascii' => ':attribute must only contain single-byte alphanumeric characters and symbols.',
    'before' => ':attribute must be a date before :date.',
    'before_or_equal' => ':attribute must be a date before or equal to :date.',
    'between' => [
        'array' => ':attribute must contain between :min and :max items.',
        'file' => ':attribute must be between :min and :max kilobytes.',
        'numeric' => ':attribute must be between :min and :max.',
        'string' => ':attribute must be between :min and :max characters.',
    ],
    'boolean' => ':attribute must be true or false.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => ':attribute confirmation does not match.',
    'current_password' => 'The provided password is incorrect.',
    'date' => ':attribute is not a valid date.',
    'date_equals' => ':attribute must be a date equal to :date.',
    'date_format' => ':attribute does not match the format :format.',
    'decimal' => ':attribute must have :decimal decimal places.',
    'declined' => ':attribute must be declined.',
    'declined_if' => ':attribute must be declined when :other is :value.',
    'different' => ':attribute and :other must be different.',
    'digits' => ':attribute must be :digits digits.',
    'digits_between' => ':attribute must be between :min and :max digits.',
    'dimensions' => ':attribute has invalid image dimensions.',
    'distinct' => ':attribute has duplicate values.',
    'doesnt_end_with' => ':attribute must not end with one of the following: :values.',
    'doesnt_start_with' => ':attribute must not start with one of the following: :values.',
    'email' => ':attribute must be a valid email address.',
    'ends_with' => ':attribute must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => ':attribute must be a file.',
    'filled' => ':attribute must have a value.',
    'gt' => [
        'array' => ':attribute must contain more than :value items.',
        'file' => ':attribute must be greater than :value kilobytes.',
        'numeric' => ':attribute must be greater than :value.',
        'string' => ':attribute must contain more than :value characters.',
    ],
    'gte' => [
        'array' => ':attribute must contain at least :value items.',
        'file' => ':attribute must be greater than or equal to :value kilobytes.',
        'numeric' => ':attribute must be greater than or equal to :value.',
        'string' => ':attribute must contain at least :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => ':attribute must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'in_array' => ':attribute does not exist in :other.',
    'integer' => ':attribute must be an integer.',
    'ip' => ':attribute must be a valid IP address.',
    'ipv4' => ':attribute must be a valid IPv4 address.',
    'ipv6' => ':attribute must be a valid IPv6 address.',
    'json' => ':attribute must contain a valid JSON string.',
    'list' => 'The :attribute field must be a list.',
    'lowercase' => ':attribute must be lowercase.',
    'lt' => [
        'array' => ':attribute must contain less than :value items.',
        'file' => ':attribute must be less than :value kilobytes.',
        'numeric' => ':attribute must be less than :value.',
        'string' => ':attribute must contain less than :value characters.',
    ],
    'lte' => [
        'array' => ':attribute must not contain more than :value items.',
        'file' => ':attribute must be less than or equal to :value kilobytes.',
        'numeric' => ':attribute must be less than or equal to :value.',
        'string' => ':attribute must not contain more than :value characters.',
    ],
    'mac_address' => ':attribute must be a valid MAC address.',
    'max' => [
        'array' => ':attribute may not contain more than :max items.',
        'file' => ':attribute may not be greater than :max kilobytes.',
        'numeric' => ':attribute may not be greater than :max.',
        'string' => ':attribute may not be greater than :max characters.',
    ],
    'max_digits' => ':attribute may not contain more than :max digits.',
    'mimes' => ':attribute must be a file of type: :values.',
    'mimetypes' => ':attribute must be a file of type: :values.',
    'min' => [
        'array' => ':attribute must have at least :min items.',
        'file' => ':attribute must be at least :min kilobytes.',
        'numeric' => ':attribute must be at least :min.',
        'string' => ':attribute must contain at least :min characters.',
    ],
    'min_digits' => ':attribute must have at least :min digits.',
    'missing' => ':attribute must be missing.',
    'missing_if' => ':attribute must be missing if :other is :value.',
    'missing_unless' => ':attribute must be missing unless :other is :value.',
    'missing_with' => ':attribute must be missing when :values is present.',
    'missing_with_all' => ':attribute must be missing when :values are present.',
    'multiple_of' => ':attribute must be a multiple of :value.',
    'not_in' => 'The selected :attribute is invalid.',
    'not_regex' => 'The :attribute format is invalid.',
    'numeric' => ':attribute must be a number.',
    'password' => [
        'letters' => ':attribute must contain at least one letter.',
        'mixed' => ':attribute must contain at least one uppercase and one lowercase letter.',
        'numbers' => ':attribute must contain at least one number.',
        'symbols' => ':attribute must contain at least one symbol.',
        'uncompromised' => ':attribute appears in a data breach. Please choose a different :attribute.',
    ],
    'present' => ':attribute must be present.',
    'present_if' => ':attribute must be present when :other is :value.',
    'present_unless' => ':attribute must be present unless :other is :value.',
    'present_with' => ':attribute must be present when :values is present.',
    'present_with_all' => ':attribute must be present when :values are present.',
    'prohibited' => ':attribute is prohibited.',
    'prohibited_if' => ':attribute is prohibited when :other is :value.',
    'prohibited_unless' => ':attribute is prohibited unless :other is in :values.',
    'prohibits' => ':attribute prohibits :other from being present.',
    'regex' => 'The :attribute format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => ':attribute must contain entries for: :values.',
    'required_if' => ':attribute is required when :other is :value.',
    'required_if_accepted' => ':attribute is required when :other is accepted.',
    'required_unless' => ':attribute is required unless :other is in :values.',
    'required_with' => ':attribute is required when :values is present.',
    'required_with_all' => ':attribute is required when :values are present.',
    'required_without' => ':attribute is required when :values is not present.',
    'required_without_all' => ':attribute is required when none of :values are present.',
    'same' => ':attribute and :other must match.',
    'size' => [
        'array' => ':attribute must contain :size items.',
        'file' => ':attribute must be :size kilobytes.',
        'numeric' => ':attribute must be :size.',
        'string' => ':attribute must be :size characters.',
    ],
    'starts_with' => ':attribute must start with one of the following: :values.',
    'string' => ':attribute must be a string.',
    'timezone' => ':attribute must be a valid timezone.',
    'unique' => 'The :attribute field is already in use.',
    'uploaded' => ':attribute failed to upload.',
    'uppercase' => ':attribute must be uppercase.',
    'url' => ':attribute must be a valid URL.',
    'ulid' => ':attribute must be a valid ULID.',
    'uuid' => ':attribute must be a valid UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'email' => [
            'required' => 'The :attribute field is required.',
            'unique' => 'The :attribute field is already in use.',
            'lowercase' => 'The :attribute field must be lowercase.',
            'email' => 'The :attribute field must be a valid email address.',
            'unique_email_across_tables' => 'This email address is already in use.'
        ],
        'email_secondaria' => [
            'required' => 'The :attribute field is required.',
            'unique' => 'The :attribute field is already in use.',
            'lowercase' => 'The :attribute field must be lowercase.',
            'email' => 'The :attribute field must be a valid email address.'
        ],
        'pec' => [
            'required' => 'The :attribute field is required.',
            'unique' => 'The :attribute field is already in use.',
            'lowercase' => 'The :attribute field must be lowercase.',
            'email' => 'The :attribute field must be a valid email address.'
        ],
        'name' => [
            'required' => 'The :attribute field is required.'
        ],
        'password' => [
            'required' => 'The :attribute field is required.',
            'confirmed' => 'The :attribute does not match the confirmation.'
        ],
        'building' => [
            'required' => 'The :attribute field is required.',
            'unique' => 'A building is already registered with this :attribute.',
            'codice_fiscale' => [
                'required' => 'The :attribute field is required.',
                'unique' => 'A building is already registered with this :attribute.'
            ]
        ],
        'anagrafica' => [
            'after:today' => ':attribute must be after today.',
            'before:today' => ':attribute must be before today.',
            'codice_fiscale' => [
                'unique' => 'A resident is already registered with this :attribute'
            ]
        ],
        'evento' => [
            'after_or_equal:today' => ':attribute must be after or equal to today.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        'email' => 'email address',
        'email_secondaria' => 'secondary email address',
        'pec' => 'certified email address',
        'name' => 'full name',
        'building' => [
            'nome' => 'name',
            'roles' => 'roles field'
        ],
        'user' => [
            'roles' => 'role'
        ],
        'anagrafica' => [
            'nome' => 'registry full name field',
            'indirizzo' => 'registry address field',
            'scadenza_documento' => 'document expiration date field',
            'data_nascita' => 'date of birth field',
            'codice_fiscale' => 'tax code'
        ],
        'ruoli' => [
            'name' => 'role name',
            'description' => 'role description',
        ],
        'comunicazioni' => [
            'subject' => 'subject',
            'description' => 'description',
            'is_published' => 'status',
            'priority' => 'priority',
            'stato' => 'communication status',
            'condomini_ids' => 'buildings',
        ],
        'segnalazioni' => [
            'subject' => 'report subject',
            'description' => 'report description',
            'is_published' => 'report status',
            'priority' => 'report priority',
            'stato' => 'report status',
            'condominio_id' => 'building',
        ],
        'documenti' => [
            'name' => 'name',
            'description' => 'description',
            'is_published' => 'document status',
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
            'recurrence_until' => 'recurrence end date',
            'condomini_ids' => 'buildings',
            'visibility' => 'publication status',
        ],
        'palazzine' => [
            'name' => 'building block name',
            'description' => 'building block description',
        ],
        'scale' => [
            'name' => 'staircase name',
            'description' => 'staircase description',
        ],
        'immobili' => [
            'tipologia_id' => 'property type',
        ],

    ],

];
