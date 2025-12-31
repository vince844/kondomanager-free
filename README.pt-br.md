[![en](https://img.shields.io/badge/lang-en-red.svg)](https://github.com/vince844/kondomanager-free/blob/main/README.en.md)
[![it](https://img.shields.io/badge/lang-it-green.svg)](https://github.com/vince844/kondomanager-free/blob/main/README.md)
[![pt-br](https://img.shields.io/badge/lang-pt--br-yellow.svg)](https://github.com/vince844/kondomanager-free/blob/main/README.pt-br.md)

# KondoManager - Gestão condominial

KondoManager é um software open source inovador para gestão condominial, desenvolvido em Laravel e banco de dados MySQL, projetado para administradores de condomínio, mas também para usuários do condomínio.

## Screenshots

<table>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-3.png" alt="Dashboard" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-2.png" alt="Relatórios de falhas" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-1.png" alt="Quadro de avisos do condomínio" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-6.png" alt="Arquivo de documentos" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-4.png" alt="Agenda do condomínio" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-5.png" alt="Gestão de usuários e permissões" width="100%"></td>
  </tr>
</table>

## Experimente a demo do KondoManager
Você pode visualizar uma demo do projeto acessando o seguinte endereço [KondoManager Demo](https://rebrand.ly/kondomanager) 

Atenção: por questões de segurança, algumas funcionalidades foram desativadas. Você pode fazer login com as seguintes credenciais:
```
Login como administrador:
email: admin@kondomanager.it
password: Pa$$w0rd!

Login como usuário:
email: user@kondomanager.it
password: Pa$$w0rd!
```

## Funcionalidades do sistema de gestão

- Gestão de condomínios
- Gestão de cadastros
- Gestão de relatórios de falhas
- Gestão do quadro de avisos condominial
- Gestão de arquivo de documentos e categorias
- Gestão de prazos na agenda
- Gestão de usuários
- Gestão de funções e permissões
- Notificações por email
- Módulo de gestão
  - Gestão de edifícios
  - Gestão de escadas
  - Gestão de imóveis
  - Tabelas de frações
  - Gestão de exercícios fiscais
  - Gestão ordinária e extraordinária
  - Criação de plano de contas (orçamento de despesas)
  - Criação de plano de parcelas

## Requisitos mínimos

    PHP >= 8.2
    Banco de dados MySQL
    Node.js & NPM (Para instalação manual)
    Composer (Para instalação manual)

## Instalação guiada do sistema de gestão

Para usuários menos experientes que desejam instalar o KondoManager em servidor compartilhado, criamos um assistente guiado para o processo de instalação.
Baixe o [arquivo de instalação](https://kondomanager.short.gy/installer) e faça upload do arquivo index.php para o seu servidor, depois siga o processo de instalação guiada. Para mais informações visite a página do [guia oficial](https://www.kondomanager.com/docs/installation.html).

## Instalação manual do sistema de gestão

1. Clone o repositório
```bash
https://github.com/vince844/kondomanager-free.git
```

2. Instale as bibliotecas
```bash
composer install
npm install
```

3. Gere o arquivo .env
```bash
cp .env.example .env
php artisan key:generate
```

4. Configure o banco de dados MySQL no arquivo .env
```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=seu_banco_de_dados
DB_USERNAME=seu_usuario
DB_PASSWORD=sua_senha
```

5. Configure o servidor SMTP no arquivo .env
```bash
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"
```

6. Execute as migrações do banco de dados
```bash
php artisan migrate
```

7. Popule o banco de dados com as configurações padrão
```bash
php artisan db:seed
```

8. Inicie os servidores de desenvolvimento
```bash
npm run dev
php artisan serve
```

🎉 Pronto! Visite http://localhost:8000 para começar a trabalhar com o KondoManager.

Se necessário, configure APP_URL no arquivo .env especificando a porta
```bash
APP_URL=http://localhost:8000
```

Para acessar o painel de administração use as seguintes credenciais:
```bash
Endereço de email: admin@km.com
Senha: password
```

Lembre-se de alterar o endereço de email e a senha no primeiro login acessando /settings/profile

## Documentos úteis

- [Documentação do Laravel](https://laravel.com/docs)
- [Documentação do Vue.js](https://vuejs.org/guide/introduction.html)
- [Documentação do Tailwind CSS](https://tailwindcss.com/docs)
- [Documentação do Inertia.js](https://inertiajs.com/)
- [Documentação do TanStack Table](https://tanstack.com/table/v8)

## Como contribuir com o projeto

Quem quiser contribuir para o crescimento do projeto é sempre bem-vindo!

Para contribuir, recomenda-se seguir as indicações descritas na [documentação oficial](https://github.com/vince844/kondomanager-free/blob/main/CONTRIBUTING).

Se você deseja contribuir ativamente com melhorias simples ou correções, pode [pesquisar entre as issues](https://github.com/vince844/kondomanager-free/issues).

## Apoie o projeto

Desenvolver um software open source requer muito esforço e dedicação. Ficarei grato se você decidir apoiar o projeto. [Apoie o KondoManager no Patreon](https://www.patreon.com/KondoManager)

## Feedback

Quem quiser enviar feedback ou sugestões de melhorias pode fazê-lo na seção apropriada dentro deste repositório ou abrir um [ticket no uservoice](https://feedback.userreport.com/92d7d7e1-d2e5-4654-a90d-066dd5d2fe10/#ideas/popular)

## Suporte

Para suporte ou solicitações de personalização de código, você pode me contatar usando o [formulário de contato](https://dev.karibusana.org/gestionale-condominio-contatti.html) apropriado

## Licença

[AGPL-3.0](https://github.com/vince844/kondomanager-free?tab=AGPL-3.0-1-ov-file#readme)

## Créditos

### Desenvolvedor Principal
- **Vincenzo Vecchio** - Fundador do projeto e desenvolvedor principal

### Colaboradores

Agradecimentos a estas pessoas incríveis que contribuíram para este projeto:

- [Amnit Haldar](https://github.com/amit-eiitech) - Excelente desenvolvedor Laravel
