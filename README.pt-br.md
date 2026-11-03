[![Read in English](https://img.shields.io/badge/Read_in-English-red.svg)](README.en.md)
[![Leggi in Italiano](https://img.shields.io/badge/Leggi_in-Italiano-green.svg)](README.md)
[![Leia em Português](https://img.shields.io/badge/Leia_em-Português-yellow.svg)](README.pt-br.md)
[![Generic badge](https://img.shields.io/badge/Version-1.11.0-blue.svg)](https://github.com/vince844/kondomanager-free/releases)
[![License](https://img.shields.io/badge/License-AGPL_3.0-blue.svg)](https://opensource.org/licenses/AGPL-3.0)
[![Forum](https://img.shields.io/badge/Forum-Comunidade-orange.svg)](https://kondomanager.short.gy/km-forum)
[![GitHub stars](https://img.shields.io/github/stars/vince844/kondomanager-free?style=social)](https://github.com/vince844/kondomanager-free/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/vince844/kondomanager-free?style=social)](https://github.com/vince844/kondomanager-free/network/members)
[![GitHub issues](https://img.shields.io/github/issues/vince844/kondomanager-free)](https://github.com/vince844/kondomanager-free/issues)
[![GitHub last commit](https://img.shields.io/github/last-commit/vince844/kondomanager-free)](https://github.com/vince844/kondomanager-free/commits/main)

# KondoManager - Software Gratuito e de Código Aberto para Gestão de Condomínios

**KondoManager** é o primeiro software open source e self-hosted para gestão de condomínios, desenvolvido em **Laravel, Vue 3, Inertia.js e MySQL**. Concebido para administradores de condomínios que precisam de uma ferramenta a sério: contabilidade em partidas dobradas, planos de prestações, portal digital para condóminos e atualizações automáticas — sem subscrições, sem vendor lock-in.

---

## Capturas de Ecrã

<table>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-3-new.png" alt="Painel de controlo" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-2-new.png" alt="Reporte de avarias" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-1-new.png" alt="Quadro de avisos do condomínio" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-6-new.png" alt="Arquivo de documentos" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-4-new.png" alt="Agenda do condomínio" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-5-new.png" alt="Gestão de utilizadores e permissões" width="100%"></td>
  </tr>
</table>

---

## Experimente a Demonstração

Pode visualizar uma demonstração do projeto no seguinte endereço:

👉 **[Demonstração KondoManager](https://kondomanager.short.gy/kondo)**

**Atenção:** Por questões de segurança, algumas funcionalidades como o envio de emails e notificações foram desativadas.

**Credenciais de acesso:**

| Função | Email | Palavra-passe |
| :--- | :--- | :--- |
| **Administrador** | `admin@kondomanager.it` | `Pa$$w0rd!` |
| **Utilizador** | `user@kondomanager.it` | `Pa$$w0rd!` |

---

## Funcionalidades do Sistema de Gestão

### Funções Principais

- Sistema de atualização automática a partir do painel de administrador
- Gestão de cadastros de condomínios e fornecedores do condomínio
- Gestão de reportes de avarias com módulo de chat/comentários integrado
- Quadro de avisos digital do condomínio para comunicações
- Arquivo de documentos e categorias do condomínio
- Agenda de prazos com gestão de recorrências
- Gestão avançada de utilizadores, funções e permissões
- Notificações automáticas por email
- Autenticação com proteção de dois fatores
- Sistema de convites para registo de utilizadores
- Idioma da instalação à escolha entre italiano, inglês, português e espanhol; as páginas de gestão estão na maior parte só em italiano
- Importação de um condomínio inteiro a partir de outro programa de gestão (primeira fonte: Danea Domustudio) ou de um modelo Excel preenchido à mão
- Cópia de segurança completa e reposição a partir do painel, retomáveis e cifráveis em AES-256
- Documentos do arquivo numa ou mais categorias, também os carregados pelo condómino no portal
- Pesquisa do código ATECO 2025 do ISTAT na ficha do fornecedor, e categorias de fornecedores geridas a partir da lista de fornecedores
- Imagem Docker de produção (`ghcr.io/vince844/kondomanager-core`), que em cada arranque instala numa base de dados vazia e atualiza a que já existe

### Transmissões de Propriedade, Arrendamentos e Sucessões

- «Registra passaggio» (registar transmissão) a partir da página dos titulares da fração, com cinco tipos: venda ou doação, início de arrendamento, fim de arrendamento, usufruto (constituição ou extinção) e sucessão
- Nas despesas ordinárias a quota da fração divide-se pelos dias de titularidade; nas extraordinárias conta a deliberação
- O painel «Cosa cambierà» (o que vai mudar) mostra o que acontece antes de registar; a última transmissão anula-se a partir do histórico da fração
- Na venda, as prestações em rascunho que vencem a partir da data da transmissão passam para quem compra; para as prestações já emitidas o acerto escreve-se ou deixa-se às partes
- A sucessão divide a parte do falecido entre os herdeiros por quota, legado incluído; a venda pode reservar o usufruto a quem vende
- Mapa dos encargos acessórios em PDF para cada arrendatário, com quem reembolsa quem

### Módulo de Contabilidade de Gestão e Estrutura

- Gestão de edifícios, escadas e imóveis
- Contas correntes do condomínio
- Tabelas de permilagem ilimitadas com repartição por várias tabelas e coeficientes configuráveis
- Gestão de exercícios contabilísticos
- Gestões ordinárias e extraordinárias
- Criação do plano de contas, com o orçamento deliberado separado das despesas imprevistas
- Geração de plano de prestações, também com recorrências avançadas, e fotografia no tempo
- Planos de prestações extraordinários autorizados por uma deliberação da assembleia ou por uma intervenção urgente (Art. 1135 c.c.)
- Registo de faturas de compra com várias linhas e débito direto numa única fração (Art. 63 c.c.)
- Gestão de passivos imprevistos e despesas fora do orçamento (Art. 1130-bis c.c.)
- Gestão de dívidas anteriores e faturas de exercícios anteriores com alarme de prescrição (Art. 2948 c.c.)
- Gestão de excessos orçamentais com três estratégias: acerto, prestação suplementar ou Fundo de Reserva
- Motor de notas de crédito e estornos com validador de equilíbrio ao cêntimo (Write-Then-Reverse)
- Fundos de reserva vinculados com registo de auditoria e desbloqueio por derrogação documentada
- Registo de recebimentos com repartição automática ou manual
- Smart Wallet do condómino com compensação de créditos anteriores
- Emissão silenciosa de prestações com publicação diferida
- Partidas dobradas com o motor Penny-Perfect em cêntimos inteiros
- Extrato de conta do cadastro com ordenação em cascata
- Financial X-Ray: análise financeira por condómino com detalhe por fração
- Painel de auditoria com semáforo contabilístico e deteção de rubricas órfãs
- Caixa de entrada inteligente para prazos interativos na agenda
- Módulo de pagamento de faturas de fornecedores com transferências em massa, estornos e Ledger imutável
- Widget Treasury Guardian para análise preditiva de liquidez a 30 dias
- Mecanismo em cascata dinâmico (Inquilino → Usufrutuário → Proprietário)
- Gestão inteligente de descobertos documentados para unidades vazias
- Livro diário navegável com pesquisa, filtros por período e estado, e verificação do equilíbrio
- Balanço patrimonial com diagnóstico em linguagem corrente dos desequilíbrios e correção no local
- Transferências entre contas bancárias e fundos, com protocolo, pré-visualização e estorno
- Retenções na fonte e modelo F24: mapa por limiar, guia de pagamento e fecho em partida dobrada
- Contribuições já entregues pelos condóminos descontadas do rateio, distribuídas por permilagem
- Registo rápido de pequenas despesas sem abrir uma conta de fornecedor
- Valor realizado ao lado do orçamentado em cada rubrica, com o detalhe dos movimentos que o compõem
- Data da primeira prestação configurável no plano de prestações
- Registo de faturas de compra a partir do ficheiro XML ou do envelope assinado .p7m do SdI, também muitas de uma vez, com o fornecedor criado a partir do ficheiro se não existir
- Notas de crédito ligadas à fatura que retificam: a partir daí, a fatura conta pelo valor líquido da nota
- Recebimentos com «Versato da» (pago por), quando a transferência foi feita por outra pessoa do condomínio, e reembolso do crédito a partir do extrato de conta
- Rubricas de receita, como o aluguer da antena, que reduzem a despesa a repartir
- Diário de caixa (Prima nota) com saldo progressivo, e razão de cada conta com o saldo transportado e a contrapartida de cada linha
- Detalhe do rateio registado na geração do plano de prestações, rubrica a rubrica e pessoa a pessoa

### Suíte de Documentos e Relatórios PDF

- Mecanismo de renderização PDF 100% nativo, sem dependências externas
- Personalização de documentos com upload da assinatura do administrador e notas de rodapé legais
- **Guia de Orçamento / Plano de Contas:** impressão completa de capítulos, subcontas e valores orçamentados
- **Repartição de Despesas:** documento que ilustra a repartição do plano de contas nas tabelas de frações ideais
- **Repartição de Orçamento por Tabela (Prestações):** documento detalhado (A4 horizontal) cruzando imóveis, sujeitos (proprietário, inquilino, etc.) e tabelas de frações ideais
- **Cronogramas de Prestações:** agregação dinâmica por Condómino ou Imóvel
- **Guias de Pagamento a Fornecedores:** resumo de valores e detalhes para transferências bancárias
- **Rateio por Rubrica de Despesa:** a leitura por rubrica orçamental, a par do rateio por tabelas
- **Extrato de Conta do Condómino:** saldos e sequência completa dos movimentos do exercício
- **Modelo F24 e mapa para o home banking:** com a lista dos pagamentos que cada guia liquida
- **Mapa dos Encargos Acessórios:** para cada arrendatário, as rubricas a seu cargo divididas pelos dias do arrendamento, e quem reembolsa quem
- **Diário de Caixa, Livro Diário e Livro Razão:** os registos contabilísticos, com o razão de cada conta

---

## Requisitos Mínimos

Para instalar o KondoManager, o seu ambiente de servidor deve satisfazer os seguintes requisitos:

- **PHP** >= 8.4.1 — *a partir da versão 1.10.0; as anteriores corriam em PHP 8.2*
- **Base de dados:** MySQL 5.7+ ou MariaDB 10.3+
- **Extensões PHP:** `zip`, `curl`, `bcmath`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `dom`, `xml` - `gd` é o que gera os PDF: sem ela o programa instala-se mas não produz nenhum mapa
- **Para instalação manual:** Node.js & NPM, Composer

---

## Documentação Oficial

A documentação completa do KondoManager está disponível em:

**[www.kondomanager.com/docs](https://kondomanager.com/docs)**

Alguns guias para começar (em italiano):

- **Instalação:** [os quatro caminhos](https://kondomanager.com/docs/installation.html), [instalação guiada](https://kondomanager.com/docs/installation-wizard.html), [instalação manual](https://kondomanager.com/docs/installation-manual.html), [Docker para desenvolvimento](https://kondomanager.com/docs/installation-docker.html), [proxy e HTTPS](https://kondomanager.com/docs/configurazione-proxy-https.html), [email SMTP](https://kondomanager.com/docs/configurazione-email-smtp.html), [cópia de segurança e reposição](https://kondomanager.com/docs/backup.html)
- **Primeiros passos:** [exercícios e gestões](https://kondomanager.com/docs/esercizi-e-gestioni-condominio.html), [importar os dados de outro programa](https://kondomanager.com/docs/importare-dati-altro-gestionale.html), [tabelas de permilagem](https://kondomanager.com/docs/tabelle-millesimali-condominio.html), [plano de contas](https://kondomanager.com/docs/piano-dei-conti-condominio.html), [funções e permissões](https://kondomanager.com/docs/roles.html)
- **Prestações e recebimentos:** [criar um plano de prestações](https://kondomanager.com/docs/creare-piano-rate-condominio.html), [vencimentos das prestações](https://kondomanager.com/docs/quando-scadono-rate-condominio.html), [registar um recebimento](https://kondomanager.com/docs/registrare-incasso-rate-condominio.html), [transmissão: venda e mudança de inquilino](https://kondomanager.com/docs/subentro-condominio-vendita-cambio-inquilino.html)
- **Faturas e retenções:** [registar uma fatura de compra](https://kondomanager.com/docs/registrare-fattura-passiva-condominio.html), [importar uma fatura do ficheiro XML](https://kondomanager.com/docs/importare-fattura-xml-condominio.html), [pagar ao fornecedor](https://kondomanager.com/docs/pagare-fornitore-condominio.html), [retenção na fonte](https://kondomanager.com/docs/ritenuta-acconto-condominio.html), [pagar as retenções com o F24](https://kondomanager.com/docs/versare-ritenute-f24-condominio.html)
- **Registos:** [diário de caixa](https://kondomanager.com/docs/prima-nota-condominio.html), [livro diário](https://kondomanager.com/docs/libro-giornale-condominio.html), [razão de uma conta](https://kondomanager.com/docs/mastrino-conto-condominio.html), [balanço patrimonial](https://kondomanager.com/docs/stato-patrimoniale-non-quadra.html), [todos os mapas](https://kondomanager.com/docs/stampe-condominio.html)

---

## Instalação Guiada (Recomendada para utilizadores menos experientes)

Para utilizadores menos experientes ou para instalações rápidas em alojamento partilhado (cPanel, Plesk, etc.), criámos um assistente automatizado.

### 1. Nova Instalação Guiada

1. Descarregue o [ficheiro de instalação](https://kondomanager.short.gy/km-installer) do site oficial do Kondomanager
2. Extraia e carregue o ficheiro `index.php` na **raiz** do seu servidor (via FTP ou Gestor de Ficheiros no cPanel).
3. Abra o navegador no endereço: `https://seusite.com/index.php`.
4. Siga o procedimento guiado no ecrã.

Para mais detalhes, visite o [guia oficial de instalação](https://kondomanager.com/docs/installation.html) ou o nosso [canal YouTube](https://www.youtube.com/@Kondomanager)

### 2. Atualização Automática a partir do Painel de Administrador

O sistema de atualização automática leva a instalação para a versão nova a partir do painel de administração, em dois passos: «Scarica e Installa» (descarregar e instalar) põe os ficheiros novos, depois «Avvia aggiornamento» (iniciar a atualização) atualiza a base de dados, com a cópia de segurança da base de dados proposta antes das migrações, se se parte da 1.10.0 ou de uma versão posterior.

**Atenção:** Se não configurar os processos `CronJob`, a atualização automática não funcionará.

### Configuração do CronJob

**Opção A — A partir do painel de controlo (recomendada para alojamento partilhado)**

A partir da v1.9.0 pode configurar os processos agendados diretamente no painel de administração do KondoManager, sem aceder ao servidor. O sistema funciona com serviços externos como o [cron-job.org](https://cron-job.org), útil em alojamentos partilhados sem cron nativo. Encontra a configuração em **«Impostazioni» (definições) → «Automazione cloud» (automação na nuvem)**.

**Opção B — A partir do painel de alojamento (cPanel / Plesk)**

Aceda ao seu painel de alojamento na secção "Cron Jobs" ou "Agendamento de Tarefas" e configure a execução a cada minuto (`* * * * *`).

**Exemplo para ambiente local ServBay** (a partir do KondoManager 1.10.0 a base é PHP 8.4, não
suportado pela versão gratuita do MAMP — se está a migrar do MAMP, siga o
[guia de migração para ServBay](https://kondomanager.com/docs/migrazione-mamp-a-servbay.html)):

*macOS — crontab (`crontab -e`):*
```bash
/Applications/ServBay/script/alias/php suapasta/artisan schedule:run >> /dev/null 2>&1
```

*Windows — Agendador de Tarefas (o Windows não tem crontab):* abra o Prompt de Comando,
verifique o caminho real do PHP com `where php`, depois registe a tarefa a cada minuto:
```bash
schtasks /create /tn "KondoManager Scheduler" /tr "php C:\suapasta\artisan schedule:run" /sc minute /mo 1
```

**Exemplo para Servidor Partilhado (cPanel/Linux):**
```bash
/usr/local/bin/php /home/seusite/public_html/artisan schedule:run >> /dev/null 2>&1
```

Certifique-se de usar o caminho absoluto para o executável PHP 8.4+, por exemplo `/usr/local/bin/ea-php84`. Verifique no MultiPHP Manager a versão PHP efetivamente atribuída ao domínio.

---

### Configuração do Email (SMTP / Sendmail)

A partir da v1.9.0 as credenciais do email configuram-se diretamente no painel de administração, sem modificar o ficheiro `.env`.

Encontra a configuração em **«Impostazioni» (definições) → «Configurazione email» (configuração do email)**.

| Campo | Descrição |
| :--- | :--- |
| **Driver** | `SMTP` para fornecedores externos (Gmail, Mailgun, etc.) ou `Sendmail` para o mailer PHP local do alojamento |
| **Host** | Endereço do servidor SMTP (ex. `smtp.gmail.com`) |
| **Porta** | Tipicamente `587` (TLS) ou `465` (SSL) |
| **Utilizador** | Endereço de email ou nome de utilizador do fornecedor |
| **Palavra-passe** | Palavra-passe ou App Password do fornecedor |
| **Cifragem** | `tls` ou `ssl` |
| **Remetente** | Nome e endereço que aparecerão como remetente nos emails aos condóminos |

A configuração guardada no painel tem **precedência** sobre o ficheiro `.env` e pode ser modificada a qualquer momento sem reiniciar o servidor.

**Nota sobre o método de envio:** o programa aconselha `SMTP` com um servidor externo (Gmail, Brevo, etc.); `Sendmail` é só para um VPS ou servidor dedicado com Postfix e os registos SPF e DKIM configurados.

---

### 3. Atualização manual (com o ficheiro de instalação)

Os passos abaixo não dependem da versão de origem: são os mesmos para qualquer atualização manual a partir da 1.8.0.

1. Certifique-se de ter uma cópia de segurança da `base de dados` e dos ficheiros da pasta `storage`
2. Descarregue o [ficheiro de atualização](https://kondomanager.short.gy/km-installer) do site oficial do Kondomanager
3. Extraia e carregue o ficheiro `index.php` na raiz do seu servidor
4. Abra o navegador no endereço: `https://seusite.com/index.php`.
5. O sistema detetará automaticamente a versão anterior instalada.
6. Clique em **"Update now"** (em inglês; em italiano, **"Aggiorna ora"**) e siga os passos guiados.

**O que o sistema faz automaticamente:**

- Cópia de segurança automática do ficheiro `.env`.
- Descarregamento e instalação dos novos ficheiros principais.
- Restauro dos dados e das configurações.
- Execução das migrações da base de dados.
- Limpeza e otimização da cache.

**Importante:** Não feche a página do navegador durante o processo de atualização. Ao terminar, o ficheiro `index.php` que carregou elimina-se a si próprio, por segurança; se não conseguir, fica um ficheiro que responde «410 Gone» e pode eliminá-lo manualmente. Se a atualização for interrompida por um erro, o ficheiro `index.php` pode ficar no servidor: elimine-o manualmente.

**Se alguma vez executou `php artisan optimize` no seu servidor**, execute também, depois da atualização:

```bash
php artisan optimize:clear
```

O atualizador limpa a cache da configuração mas não a das rotas: sem este passo o programa continua a servir a tabela de rotas da versão anterior, e as entradas novas respondem «página não encontrada». Se nunca executou `optimize`, não há nada a fazer.


---

## Instalação Manual (Para programadores e utilizadores avançados)

Se deseja contribuir para o código ou tem acesso SSH completo ao servidor.

### Primeira instalação num servidor

O `.env.example` já traz os valores para um servidor: `APP_ENV=production` e `APP_DEBUG=false`. Não ative o debug numa instalação que outros possam alcançar: as páginas de erro mostrariam o código, as consultas e o corpo dos pedidos.

1. **Clone o repositório**
```bash
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

2. **Instale as dependências e compile a interface**
```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

3. **Configure o ambiente**
```bash
cp .env.example .env
php artisan key:generate --force
```

Edite o ficheiro `.env` com o endereço da instalação (`APP_URL`) e os parâmetros da sua base de dados (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

4. **Instale, com o seu administrador**
```bash
php artisan kondomanager:installa --admin-nome="Mario Rossi" --admin-email=mario@exemplo.pt --admin-password="uma-palavra-passe-com-pelo-menos-10-caracteres"
```

O comando cria as tabelas, os perfis, o administrador com os dados que lhe passa, os dados iniciais e as configurações; numa instalação já feita não faz nada. Os dados do administrador também podem ser passados com as variáveis `INSTALL_ADMIN_NAME`, `INSTALL_ADMIN_EMAIL` e `INSTALL_ADMIN_PASSWORD` (veja o `.env.example`), para que a palavra-passe não fique no histórico do terminal.

5. **Publique e inicie os processos em segundo plano**

O servidor web deve servir a pasta `public/`. Configure o CronJob (veja [Configuração do CronJob](#configuração-do-cronjob)) e o worker das filas (veja o passo 3 da atualização manual abaixo).

### Primeira instalação para desenvolver localmente

1. **Clone o repositório e instale as dependências**
```bash
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
composer install
npm install
```

2. **Configure o ambiente de desenvolvimento**
```bash
cp .env.example .env
php artisan key:generate
```

No ficheiro `.env` defina `APP_ENV=local` e `APP_DEBUG=true`, e os parâmetros da sua base de dados (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

3. **Configuração da Base de Dados**
```bash
php artisan migrate
php artisan db:seed
```

4. **Iniciar**
```bash
npm run dev
php artisan serve
```

Visite http://localhost:8000.

**Credenciais de desenvolvimento:** `admin@km.com` / `password`, só com `APP_ENV=local`. Noutro ambiente o `db:seed` cria o administrador com uma palavra-passe aleatória e mostra-a uma única vez. Para desenvolver com Docker veja [docs/docker_local_dev.pt.md](docs/docker_local_dev.pt.md): depois de um `git pull`, `docker compose up -d --build` volta a compilar a imagem, e a cada arranque o contentor faz as migrações e alinha perfis e permissões, a lista dos municípios, a classificação ATECO e a versão registada.

---

### Atualização Manual (via SSH/Terminal)

Se preferir atualizar manualmente, siga rigorosamente estes passos para garantir a compatibilidade com o sistema de versionamento:

> ⚠️ **Se está a subir da 1.9.x para a 1.10.0, o `composer install` não é facultativo.** A 1.10 leva o Livewire da versão 3 para a 4: enquanto as dependências forem as antigas, qualquer comando `php artisan` para com `Attribute [livewire] does not exist`. A base de dados não é tocada — o erro chega antes das migrações — e sai-se concluindo a atualização das dependências. Por isso os comandos abaixo são **uma única cadeia**.

> ⚠️ **Se está a subir da 1.10.0 para a 1.11.0**, a cadeia usa `php artisan kondomanager:aggiorna` em vez de `php artisan migrate --force`: só com as migrações ficariam para trás a permissão nova «Importa dati» (importar dados) e os códigos da classificação ATECO. Depois da atualização verifique no `.env` que `APP_URL` é o endereço com que abre o programa, com `https://` se o programa está em https: usam-no as ligações dos emails de reposição da palavra-passe e de verificação do email. Se o programa estiver atrás de um proxy, o valor aconselhado para `TRUSTED_PROXIES` é `PRIVATE_SUBNETS`, que porém não cobre um proxy com um endereço público: para esse escreve-se a lista dos seus endereços; o `.env.example` desaconselha `*`.

1. **Cópia de Segurança da Base de Dados (Recomendado)**
```bash
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql
```

2. **A atualização, num único comando**

Os passos estão ligados por `&&`: se o composer ou o npm falharem — acontece, por um descarregamento interrompido ou por memória insuficiente no servidor — a cadeia para aí e **as migrações não chegam a correr**. O `config:clear` antes do `kondomanager:aggiorna` é necessário porque as migrações das configurações leem a configuração. O `kondomanager:aggiorna` faz as migrações e alinha perfis e permissões, a lista dos municípios, a classificação ATECO e a versão registada, como a atualização a partir do painel.

```bash
git pull origin main \
 && composer install --no-dev --optimize-autoloader \
 && npm install \
 && npm run build \
 && php artisan config:clear \
 && php artisan kondomanager:aggiorna \
 && php artisan optimize:clear \
 && php artisan storage:link \
 && php artisan queue:restart \
 && echo "OK - atualizacao concluida"
```

Se não aparecer `OK - atualizacao concluida`, a atualização não terminou: leia a última mensagem, resolva o problema e volte a lançar a mesma cadeia — pode ser repetida. O `queue:restart` faz recomeçar com o código novo o worker das filas mantido ativo pelo Supervisor (passo 3); sem um worker não faz nada. Perfis e permissões, a lista dos municípios e a classificação ATECO, se falharem, escrevem-no no registo (`storage/logs`) sem parar a cadeia: se depois da atualização faltar uma permissão ou um código ATECO, veja aí e volte a lançar `php artisan kondomanager:aggiorna`.

3. **Configuração e Início das Filas (Queues)** 

O sistema utiliza por predefinição o controlador de base de dados (também pode utilizar Redis se preferir) para gerir processos em segundo plano. É necessário iniciar o worker para processar as tarefas em fila.
```bash
php artisan queue:work
```
**Nota:** Em ambiente de produção, recomenda-se configurar o Supervisor para manter o processo ativo.

### Verificar Versão Instalada

Pode verificar a versão atual e o funcionamento das configurações através do Tinker:
```bash
php artisan tinker
>>> config('app.version')
```

---

## Documentos Úteis

- [Documentação Laravel](https://laravel.com/docs)
- [Documentação Vue.js](https://vuejs.org/guide/introduction.html)
- [Documentação Tailwind CSS](https://tailwindcss.com/docs)
- [Documentação Inertia.js](https://inertiajs.com/)
- [Spatie Laravel Settings](https://spatie.be/docs/laravel-settings)

---

## Comunidade e Suporte

Tem dúvidas sobre a instalação, quer partilhar a sua experiência ou procurar ajuda de outros utilizadores? Junte-se à comunidade oficial do KondoManager:

**[Fórum oficial do KondoManager](https://kondomanager.short.gy/km-forum)**

O fórum é o sítio certo para:
- pedir suporte técnico e conselhos de configuração
- reportar problemas e trocar impressões com outros administradores de condomínios
- propor novas funcionalidades e discutir a roadmap
- partilhar configurações, integrações e boas práticas

---

## Como Contribuir

Quem quiser contribuir para fazer crescer o projeto é sempre bem-vindo!

Para poder contribuir, recomenda-se seguir as indicações descritas no [guia de contribuição](https://github.com/vince844/kondomanager-free/blob/main/CONTRIBUTING.md). No seu primeiro pull request, ser-lhe-á pedido que assine o [Contributor License Agreement](https://cla-assistant.io/vince844/kondomanager-free): o CLA assistant pede-o automaticamente e só é preciso assiná-lo uma vez. Se quiser contribuir ativamente com melhorias simples ou correções, pode [procurar entre as issues](https://github.com/vince844/kondomanager-free/issues) abertas ou abrir um novo tópico no [Fórum oficial do KondoManager](https://kondomanager.short.gy/km-forum).

---

## Apoie o Projeto

Desenvolver software de código aberto requer muito empenho e dedicação. Ficarei grato se decidir apoiar o projeto.

[Apoie o KondoManager no Patreon](https://www.patreon.com/KondoManager)

---

## Feedback & Suporte

- **Comunidade:** Junte-se ao [fórum oficial](https://kondomanager.short.gy/km-forum) para trocar impressões com outros utilizadores e receber suporte da comunidade.
- **Erros e pedidos:** Utilize a secção ["Issues" ou "Discussions"](https://github.com/vince844/kondomanager-free/issues) deste repositório.
- **Vulnerabilidades de segurança:** Não abra uma issue pública; comunique-as em privado, como explicado em [SECURITY.md](https://github.com/vince844/kondomanager-free/blob/main/SECURITY.md).
- **Suporte dedicado:** Para pedidos de personalização ou suporte profissional, utilize o [formulário de contacto](https://dev.karibusana.org/gestionale-condominio-contatti.html) no site oficial.

---

## Licença

Este projeto é lançado sob a licença [AGPL-3.0](https://github.com/vince844/kondomanager-free?tab=AGPL-3.0-1-ov-file#readme).

---

## Créditos

### Programador Principal:
- [Vincenzo Vecchio](https://github.com/vince844) - Fundador do projeto e programador principal

### Contribuidores:

[![Contribuidores do KondoManager](https://contrib.rocks/image?repo=vince844/kondomanager-free)](https://github.com/vince844/kondomanager-free/graphs/contributors)

### Estrelas no GitHub:
Obrigado a todos os que deram uma estrela ao projeto!

[![Quem deu uma estrela ao KondoManager](https://kondomanager.com/assets/img/stargazers.svg)](https://github.com/vince844/kondomanager-free/stargazers)

### Apoiadores do Patreon:
- **[Mittelcom](https://www.amministrazionitedaldimorea.it/)** — obrigado pelo seu apoio e por acreditar no projeto!
