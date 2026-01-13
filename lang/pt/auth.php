<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed'                    => 'As credenciais inseridas estão incorretas.',
    'password'                  => 'A senha inserida está incorreta.',
    'throttle'                  => 'Muitas tentativas falhadas. Por favor, tente novamente em :seconds segundos.',
    'reset_link_sent'           => 'Um link de redefinição será enviado para o endereço de email se existir uma conta associada.',
    'not_authenticated'         => 'O utilizador não foi autenticado ou falta um perfil associado.',
    'missing_2fa_code'          => 'Por favor, insira um código de autenticação de dois fatores válido.',
    'invalid_2fa_code'          => 'O código de autenticação de dois fatores fornecido é inválido.',
    'invalid_2fa_recovery_code' => 'O código de recuperação da autenticação de dois fatores fornecido é inválido.',
    'too_many_2fa_attempts'     => 'Muitas tentativas de autenticação de dois fatores. Tente novamente em :seconds segundos.',
    
    /* ------------------------------------------------------------------
     | Headers, Titles and Descriptions
     | ------------------------------------------------------------------ */
    'header' => [
        'login' => [
            'head'        => 'Login',
            'title'       => 'Entrar na sua conta',
            'description' => 'Insira o seu email e a sua senha para entrar',
        ],
        'forgot_password' => [
            'head'        => 'Redefinição de senha',
            'title'       => 'Redefinir senha',
            'description' => 'Insira o seu endereço de email para receber o link de redefinição de senha',
        ],
        'confirm_password' => [
            'head'        => 'Confirmar senha',
            'title'       => 'Confirme a sua senha',
            'description' => 'Esta é uma área segura. Por favor, confirme a sua senha antes de continuar.',
        ],
        'register' => [
            'head'        => 'Registar',
            'title'       => 'Criar uma conta',
            'description' => 'Insira os seus dados para criar uma nova conta',
        ],
        'reset_password' => [
            'head'        => 'Redefinição de senha',
            'title'       => 'Redefinir senha',
            'description' => 'Por favor, insira a sua nova senha',
        ],
        'two_factor_challenge' => [
            'head'                             => 'Autenticação de dois fatores',
            'title_authentication_code'        => 'Código de autenticação',
            'title_recovery_code'              => 'Código de recuperação',
            'description_authentication_code'  => 'Insira o código de autenticação fornecido pela sua aplicação de autenticação.',
            'description_recovery_code'        => 'Confirme o acesso à sua conta inserindo um dos seus códigos de recuperação de emergência.',
        ],
        'verification_notice' => [
            'head'        => 'Verificação de email',
            'title'       => 'Verificar endereço de email',
            'description' => 'Por favor, verifique o seu endereço de email clicando no link que enviámos para o seu email.',
        ],
        'invitation_register' => [
            'head'        => 'Registar',
            'title'       => 'Criar uma conta',
            'description' => 'Insira os seus dados para criar uma nova conta',
        ],
        'set_password' => [
            'head'        => 'Definir senha',
            'title'       => 'Redefinir senha',
            'description' => 'Por favor, defina uma nova senha',
        ],
    ],
    
    /* ------------------------------------------------------------------
     | Labels for form fields
     | ------------------------------------------------------------------ */
    'label' => [
        'login' => [
            'email'    => 'Endereço de email',
            'password' => 'Senha',
            'remember' => 'Lembrar-me',
        ],
        'confirm_password' => [
            'password' => 'Senha',
        ],
        'register' => [
            'name'                  => 'Nome completo',
            'email'                 => 'Endereço de email',
            'password'              => 'Senha',
            'password_confirmation' => 'Confirmar senha',
        ],
        'reset_password' => [
            'email'                 => 'Endereço de email',
            'password'              => 'Senha',
            'password_confirmation' => 'Confirmar senha',
        ],
        'two_factor_challenge' => [
            'code'          => 'Código de autenticação',
            'recovery_code' => 'Código de recuperação',
        ],
        'invitation_register' => [
            'name'                  => 'Nome completo',
            'email'                 => 'Endereço de email',
            'password'              => 'Senha',
            'password_confirmation' => 'Confirmar senha',
        ],
        'set_password' => [
            'email'                 => 'Endereço de email',
            'password'              => 'Senha',
            'password_confirmation' => 'Confirmar senha',
        ],
    ],
    
    /* ------------------------------------------------------------------
     | Buttons
     | ------------------------------------------------------------------ */
    'button' => [
        'login'                => 'Entrar',
        'forgot_password'      => 'Enviar link de redefinição',
        'confirm_password'     => 'Confirmar senha',
        'register'             => 'Criar conta',
        'reset_password'       => 'Redefinir senha',
        'two_factor_challenge' => 'Continuar',
        'verification_notice'  => 'Reenviar email de verificação',
        'logout'               => 'Sair',
        'invitation_register'  => 'Criar conta',
        'set_password'         => 'Guardar senha',
    ],
    
    /* ------------------------------------------------------------------
     | Placeholders for inputs
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'login' => [
            'email'    => 'exemplo@email.com',
            'password' => 'Insira a sua senha',
        ],
        'confirm_password' => [
            'password' => 'Insira a sua senha',
        ],
        'register' => [
            'name'                  => 'Nome completo',
            'email'                 => 'email@example.com',
            'password'              => 'Senha',
            'password_confirmation' => 'Confirmar senha',
        ],
        'reset_password' => [
            'password'              => 'Senha',
            'password_confirmation' => 'Confirmar senha',
        ],
        'two_factor_challenge' => [
            'recovery_code' => 'Insira o código de recuperação',
        ],
        'invitation_register' => [
            'name'                  => 'Nome completo',
            'password'              => 'Senha',
            'password_confirmation' => 'Confirmar senha',
        ],
        'set_password' => [
            'password'              => 'Senha',
            'password_confirmation' => 'Confirmar senha',
        ],
    ],
    
    /* ------------------------------------------------------------------
     | Links
     | ------------------------------------------------------------------ */
    'link' => [
        'forgot_password' => 'Esqueceu a senha?',
        'register'        => 'Registar',
        'no_account'      => 'Ainda não tem uma conta?',
        'back_to_login'   => 'login',
        'or_back_to_login'=> 'Ou, volte para o',
        'have_account'    => 'Já tem uma conta?',
        'login'           => 'Entrar',
        'logout'          => 'Sair',
        'two_factor_challenge' => [
            'toggle_authentication_code' => 'entrar usando um código de autenticação',
            'toggle_recovery_code'       => 'entrar usando um código de recuperação',
            'or'                         => 'ou pode',
        ],
    ],

    /* ------------------------------------------------------------------
     | Status Messages
     | ------------------------------------------------------------------ */
    'status' => [
        'verification_link_sent' => 'Um novo link de verificação foi enviado para o endereço de email utilizado no registo.',
        'verification_required'  => 'Verifique o seu endereço de email para continuar.',
        'already_verified'       => 'O seu endereço de email já foi verificado.',
    ],
];
