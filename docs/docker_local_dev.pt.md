# 🐳 Desenvolvimento Local com Docker

<!-- verifica-documentazione -->
> **Estado:** Corresponde ao código — relida em 04/11/2026 em 1.12.0-beta.1, conferida com `docker-compose.yml`, `docker-compose-franken.yml` e os scripts de `docker/standard/` e `docker/frankenphp/`. **Reescrita em 04/11/2026:** o clone não aponta mais para o branch de uma versão antiga, e sim para o branch padrão (`main`); as portas da stack estão abertas só em `127.0.0.1`; no início há um aviso de que esta stack serve para desenvolver o KondoManager, não para usá-lo; foram tiradas as referências ao Synology NAS (para um NAS há o [guia do Synology](synology_nas_install.pt.md)) e corrigidas as frases que os scripts de inicialização não confirmavam mais (o que se instala na inicialização e quando se recompila o frontend).
> Não testada nesta reescrita em um computador limpo: as linhas das portas foram validadas com `docker compose config`, não iniciadas.
<!-- /verifica-documentazione -->

> **Plataformas suportadas:** Windows (WSL2), macOS, Linux

> ⚠️ **Este guia serve para desenvolver o KondoManager no seu computador. Não é a forma de instalá-lo para usá-lo.** Nesta stack o ambiente é `local`, o debug está ativado e as senhas são as escritas abaixo: quem ler o guia as conhece. Por isso as portas (aplicação, MySQL, Supervisor) estão abertas **só em `127.0.0.1`**, isto é, só a partir do computador onde roda. Para usar o KondoManager em um escritório, em um servidor ou em um NAS existe a imagem de produção: o [guia de Docker (produção)](https://kondomanager.com/docs/installation-docker-produzione.html), escrito em italiano, e para o Synology o [guia para Synology NAS](synology_nas_install.pt.md).

---

## Qual stack devo usar?

| Stack | Arquivo Compose | Porta | Recomendado para |
|-------|----------------|-------|------------------|
| **Standard** — PHP-FPM + Nginx | `docker-compose.yml` | `8889` | ✅ Windows / macOS / Linux |
| **FrankenPHP** — Laravel Octane | `docker-compose-franken.yml` | `8889` | 🧪 Experimental |

> ℹ️ O `Dockerfile` na raiz do repositório constrói a **imagem de produção** (`ghcr.io/vince844/kondomanager-core`, publicada a cada versão): para desenvolver não é necessário. As duas stacks de desenvolvimento usam `docker/standard/Dockerfile` e `docker/frankenphp/Dockerfile`.

**Por que usar o Standard?**
A stack PHP-FPM + Nginx é amplamente testada, fácil de depurar e bem documentada. O FrankenPHP atende os pedidos web com um único processo, mas a stack é experimental: se você não tem um motivo para testá-la, use o Standard.

---

## Pré-requisitos

- **Docker Desktop** ≥ 4.x instalado e em execução
  - No Windows: ative o backend WSL2 → *Settings → General → "Use the WSL 2 based engine"*
- Git

### ⚠️ Usuários Windows / WSL2 — importante

Sempre clone o repositório **dentro do WSL** (sistema de arquivos Linux), não na unidade Windows. Trabalhar a partir de `/mnt/c/Users/...` causa erros de permissão e é extremamente lento.

```bash
# ✅ Correto — sistema de arquivos Linux (melhor desempenho)
cd ~/projects
git clone ...

# ❌ Evite — sistema de arquivos Windows montado
# /mnt/c/Users/seunome/...
```

---

## Passo 1 — Clonar o repositório

Abra o seu terminal (no macOS/Linux) ou o terminal WSL (no Windows) e execute:

```bash
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

Sem outras opções o git baixa o branch padrão, `main`: no guia não há um número de versão para manter atualizado. Para partir de uma versão precisa (por exemplo para reproduzir um problema) use a etiqueta, escrita por extenso porque `v1.11.0` é também o nome de um branch:

```bash
git checkout refs/tags/v1.11.0
```

---

## Passo 2 — Verificar as permissões dos scripts de inicialização

Os scripts de inicialização chegam do repositório com a permissão de execução já definida: o git a conserva. Ela pode se perder se você baixou o ZIP ou clonou a partir do sistema de arquivos do Windows, e então a inicialização para com o erro `permission denied`. Tornar os scripts executáveis é inofensivo mesmo quando já são, por isso você pode fazer isso de qualquer forma antes do build.

**Se usar a stack Standard (Nginx):**
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
```

**Se usar a stack FrankenPHP:**
```bash
chmod +x docker/frankenphp/entrypoint.sh
chmod +x docker/frankenphp/worker-entrypoint.sh
```

---

## Passo 3 — Build e inicialização

### Stack Standard (recomendado)

```bash
docker compose up --build -d
```

### Stack FrankenPHP

```bash
docker compose -f docker-compose-franken.yml up --build -d
```

> A primeira inicialização leva alguns minutos: o Docker constrói a imagem (extensões PHP e Node.js) e depois o container da aplicação, na primeira inicialização, instala as dependências do Composer e do npm e compila o frontend. A compilação do frontend chega a cerca de **1,1 GB de memória**: se a memória concedida ao Docker for inferior, esse passo é interrompido. Nesse caso o banco de dados já está atualizado, mas o script de inicialização para com um erro e o container da aplicação não inicia: a aplicação não responde até você dar mais memória ao Docker e iniciar a stack de novo (`docker compose up -d`; para o FrankenPHP `docker compose -f docker-compose-franken.yml up -d`).

---

## Passo 4 — Verificar os logs

Aguarde a mensagem de inicialização no log do container da aplicação:

**Stack Standard:**
```bash
docker logs kondo_app
```
Procure por: `✅ KondoManager Standard Pronto!`

**Stack FrankenPHP:**
```bash
docker logs kondo_app_franken
```
Procure por: `✅ KondoManager FrankenPHP Pronto!`

---

## Passo 5 — Abrir a aplicação

Assim que a mensagem de sucesso aparecer:

| Serviço | URL | Credenciais de desenvolvimento |
|---------|-----|-------------|
| **Aplicação Web** | http://localhost:8889 | Email: `admin@km.com` / Senha: `password` |
| **Banco de Dados MySQL** | `127.0.0.1:3307` | Usuário: `root` / Senha: `root` / DB: `kondomanager_dev` |

Você pode se conectar ao banco de dados com qualquer cliente MySQL (TablePlus, DBeaver, MySQL Workbench, etc.) usando as credenciais acima.

> ⚠️ **São credenciais de desenvolvimento, e funcionam só porque a stack está no ambiente `local`.** O administrador `admin@km.com` é criado pelo seeder apenas com `APP_ENV=local`; com outro ambiente o seeder gera uma senha aleatória. Não use estas senhas, nem esta stack, para os dados de um escritório.
>
> As portas respondem **só a partir deste computador** (`127.0.0.1`): a partir do celular ou de outro computador da rede não abrem. Se precisar usá-las a partir de outro dispositivo, retire `127.0.0.1:` à frente da porta em `docker-compose.yml` (ou em `docker-compose-franken.yml`) e recrie os containers: a partir desse momento qualquer pessoa na sua rede pode alcançá-la, com essas senhas.

---

## O que acontece a cada inicialização

O script de entrypoint executa os seguintes passos sem nenhuma entrada manual. Alguns são só da primeira inicialização, outros se repetem a cada inicialização do container:

1. Copia `.env.example` → `.env` se ainda não existir, e o coloca no ambiente de desenvolvimento: `APP_ENV=local` e `APP_DEBUG=true` *(só quando cria o arquivo: um `.env` que já existe não é tocado, exceto as linhas do banco de dados e `APP_URL`, abaixo)*
2. Configura a conexão com o banco de dados para apontar para o container `db` *(a cada inicialização)*
3. Define `APP_URL=http://localhost:8889` se estiver vazio, for exatamente `http://localhost` ou contiver `kondomanager-free.test`; qualquer outro valor fica como está
4. Instala as dependências PHP via Composer *(a cada inicialização: custa poucos segundos quando não há nada a fazer)*
5. Gera a `APP_KEY` se faltar
6. Aguarda o MySQL ficar disponível
7. Instala as dependências Node.js com `npm install` *(a cada inicialização)*
8. Limpa as caches (`php artisan optimize:clear`) e executa as migrations do banco de dados
9. Executa os seeders *(só se ainda não houver nenhum usuário — seguro reiniciar)*
10. Alinha perfis e permissões, a lista dos municípios, a classificação ATECO e a versão registrada (`php artisan kondomanager:aggiorna`)
11. Compila os assets do frontend *(só se `public/build/manifest.json` faltar, ou se um arquivo de `resources`, `lang`, `vite.config.ts` ou `package-lock.json` for mais recente do que o manifest)*

---

## Processos em background — Supervisor

Na stack Standard, os processos em background (queue worker, scheduler) são gerenciados pelo **Supervisor**, que os mantém ativos e os reinicia automaticamente em caso de falha.

### Arquitetura

| Container | Processo | Gerenciado por |
|-----------|---------|---------------|
| `kondo_app` | PHP-FPM (requisições web) | php-fpm diretamente |
| `kondo_worker` | Laravel queue worker | **Supervisor** |
| `kondo_nginx` | Servidor web | Nginx |
| `kondo_db` | Banco de dados | MySQL |

O container `kondo_worker` inicia o Supervisor na inicialização, que por sua vez inicia e monitora `php artisan queue:work`.

### Configuração do Supervisor

O arquivo de configuração está em [`docker/supervisord.conf`](../docker/supervisord.conf). Abaixo, um excerto (faltam as seções `[rpcinterface:supervisor]` e `[supervisorctl]`, que servem ao comando `supervisorctl`). **O usuário e a senha da interface web são os de desenvolvimento, escritos no arquivo:**

```ini
[supervisord]
nodaemon=true
logfile=/var/www/storage/logs/supervisord.log
pidfile=/var/run/supervisord.pid

[inet_http_server]
port = *:9001
username = admin
password = password

[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/worker.log

[program:laravel-scheduler]
command=php /var/www/artisan schedule:work
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/scheduler.log
```

> ℹ️ A interface web do Supervisor (porta 9001) existe só nesta stack de desenvolvimento, e responde só a partir deste computador porque a porta está publicada em `127.0.0.1`. A imagem de produção não a abre: o seu Supervisor tem só um socket local e nenhuma porta HTTP.

**Parâmetros principais:**
- `--sleep=3` — aguarda 3 segundos entre jobs quando a fila está vazia
- `--tries=3` — um job com falha é repetido até 3 vezes
- `--timeout=90` — um job que durar mais de 90 segundos é interrompido
- `numprocs=1` — um único processo worker ativo (aumentável para mais paralelismo)

### Monitorar o worker

**1. Interface Web (Recomendado)**
Você pode verificar convenientemente o status dos processos e ler os logs no seu navegador:
- Acesse: `http://localhost:9001`
- Usuário: `admin` / Senha: `password` *(credenciais de desenvolvimento)*

**2. Linha de comando**
```bash
# Ver os logs do worker em tempo real
docker compose logs -f worker

# Ver os logs escritos pelo Supervisor no arquivo
docker compose exec worker cat /var/www/storage/logs/worker.log

# Status do Supervisor dentro do container
docker compose exec worker supervisorctl status

# Reiniciar manualmente o worker
docker compose exec worker supervisorctl restart laravel-worker:*
```

### Aumentar os processos worker (para alta carga)

Edite `docker/supervisord.conf`:
```ini
numprocs=3   # inicia 3 workers em paralelo
```

Em seguida, reconstrua o container:
```bash
docker compose up --build -d worker
```

---

## Alternando entre stacks

> ⚠️ **Ambas as stacks usam as mesmas portas (8889 e 3307).** Se quiser alternar de uma para a outra, pare a stack ativa primeiro para evitar conflitos de porta.

```bash
# Parar a stack Standard antes de mudar para FrankenPHP
docker compose down

# — ou —

# Parar a stack FrankenPHP antes de mudar para Standard
docker compose -f docker-compose-franken.yml down
```

---

## Comandos úteis

```bash
# Executar um comando Artisan dentro do container da aplicação
docker compose exec app php artisan <comando>

# Abrir um shell dentro do container da aplicação
docker compose exec app bash

# Ver os logs do worker (stack Standard)
docker compose logs -f worker

# Ver o status de todos os containers
docker compose ps

# Reiniciar o container da aplicação (ex.: após editar o .env)
docker compose restart app

# Reset completo — destrói todos os containers E o volume do banco de dados
docker compose down -v
docker compose up --build -d

# Forçar recompilação dos assets do frontend
docker compose exec app rm -rf public/build
docker compose exec app npm run build

# Forçar re-execução dos seeders (útil durante o desenvolvimento)
docker compose exec app php artisan db:seed --force
```

---

## Upload de arquivos e persistência dos documentos

*Seção acrescentada em 18/08/2026 com a 1.10.0-beta.58.*

### Os limites de upload estão declarados nas imagens

Até a beta.57 nenhuma das três imagens declarava um limite, então valia o padrão do nginx —
**1 MB** — e um PDF de 1,5 MB era recusado enquanto a aplicação prometia 20. Agora os valores estão declarados na configuração de cada imagem — `docker/standard/Dockerfile` e `docker/standard/nginx/default.conf`, `docker/frankenphp/Dockerfile`, `docker/production/php.ini` e `docker/production/nginx.conf` — e são coerentes entre si:

| | Valor |
| :--- | :--- |
| `upload_max_filesize` (PHP) | 20M |
| `post_max_size` (PHP) | 25M |
| `client_max_body_size` (nginx) | 30M |

Na stack FrankenPHP não há nginx: valem os dois valores do PHP.

A ordem não é casual: o nginx é o mais alto, por isso quem recusa é o **PHP**, que sabe dizer isso com
uma mensagem compreensível em vez de um erro do servidor web. A aplicação não tem um limite próprio:
lê o do PHP e mostra esse na tela.

### O que não sobrevive à recriação de um container

`storage/app` guarda os documentos enviados, os backups e os anexos. Neste compose a
pasta chega do bind mount `./:/var/www`, portanto vive no host e está segura. **Em um deploy de verdade
não é garantido**: sem um volume declarado essa pasta fica na camada gravável do container e
desaparece na primeira recriação, sem que nada avise antes.

Para saber antes em vez de depois:

```bash
docker compose exec app php artisan kondomanager:verifica-persistenza
```

Responde dizendo quantos arquivos e quantos megabytes estão em jogo, e se a pasta vive dentro do
container explica onde declarar o volume. Fora do ambiente de desenvolvimento, com `--rigoroso` sai com código de erro quando falta o volume, para poder entrar
em uma pipeline de deploy; na stack de desenvolvimento (ambiente `local`) responde que não é necessário um volume e não falha.

---

## Solução de problemas

### `permission denied` ao iniciar
O script de entrypoint não tem permissão de execução. Execute:
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
# ou para FrankenPHP:
chmod +x docker/frankenphp/entrypoint.sh
chmod +x docker/frankenphp/worker-entrypoint.sh
```

### O container `app` fica reiniciando
Verifique os logs para identificar o erro específico:
```bash
docker compose logs app
```

### MySQL não responde / aplicação não consegue conectar ao DB
O MySQL leva ~10–15 segundos para inicializar na primeira vez. O script de entrypoint aguarda automaticamente, mas se você o interrompeu, tente:
```bash
docker compose restart app
docker compose logs db
```

### Assets do frontend não atualizam após alterações no código
O frontend é recompilado a cada inicialização do container, mas só se `public/build/manifest.json` faltar ou se um arquivo de `resources`, `lang`, `vite.config.ts` ou `package-lock.json` for mais recente do que o manifest: reinicie o container (`docker compose restart app`). Para forçar a recompilação:
```bash
docker compose exec app rm -rf public/build
docker compose exec app npm run build
```

### Porta 8889 ou 3307 já em uso
Outro processo ou stack Docker está usando essa porta. Execute `docker compose down` em qualquer outra stack ativa, ou verifique com:
```bash
# macOS / Linux / WSL
lsof -i :8889
lsof -i :3307
```

### A aplicação não abre a partir do celular nem de outro computador
As portas da stack estão publicadas só em `127.0.0.1`, por isso respondem apenas a partir do computador onde o Docker roda. É de propósito: veja o Passo 5 para saber o que acontece se retirar `127.0.0.1:` à frente da porta.

### Erro CORS / redirecionamento para `https://` em vez de `http://`
Se o navegador exibir um erro `Cross-Origin Request Blocked` ou a página tentar abrir `https://localhost:8889`, o problema está no `APP_URL` no arquivo `.env`.

**Causa:** o `.env` na pasta do projeto foi criado anteriormente pelo Herd, Coolify ou outro ambiente, e contém `APP_URL=https://...`. O Docker monta os arquivos do host diretamente no container (volume mount), portanto usa esse `.env` como está.

**Correção automática, mas condicionada:** o `entrypoint.sh` define `APP_URL=http://localhost:8889` **apenas se** o valor atual estiver vazio, for exatamente `http://localhost` ou contiver `kondomanager-free.test`. Qualquer outro valor (por exemplo um `https://...` deixado pelo Herd ou pelo Coolify) é **preservado**, para não quebrar as instalações atrás de um proxy inverso: nesse caso use a correção manual abaixo.

**Fix manual (se necessário):**
```bash
docker compose exec app sed -i 's|^APP_URL=.*|APP_URL=http://localhost:8889|' /var/www/.env
docker compose exec app php artisan config:clear
docker compose restart app
```
