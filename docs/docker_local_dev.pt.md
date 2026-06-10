# 🐳 Desenvolvimento Local com Docker

> **Plataformas suportadas:** Windows (WSL2), macOS, Linux, Synology NAS

---

## Qual stack devo usar?

| Stack | Arquivo Compose | Porta | Recomendado para |
|-------|----------------|-------|------------------|
| **Standard** — PHP-FPM + Nginx | `docker-compose.yml` | `8889` | ✅ Windows / macOS / Linux |
| **FrankenPHP** — Laravel Octane | `docker-compose-franken.yml` | `8889` | 🧪 Synology NAS *(a ser testado)* |

> ℹ️ O `Dockerfile` na raiz do repositório é usado **somente para o Coolify (produção)** — não é necessário para o desenvolvimento local.

**Por que usar o Standard no Windows/macOS/Linux?**  
A stack PHP-FPM + Nginx é amplamente testada, fácil de depurar e bem documentada. O FrankenPHP roda em um único processo (menor uso de memória), o que pode ser interessante em um Synology NAS, mas ainda não foi totalmente validado nesse ambiente.

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

Abra um terminal (ou o terminal WSL no Windows) e execute:

```bash
git clone -b v1.9.1-beta https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

---

## Passo 2 — Definir permissões nos scripts de inicialização

Antes de fazer o build, torne os scripts de inicialização executáveis. Isso é obrigatório no Linux/WSL — sem este passo você receberá um erro `permission denied`.

**Se usar a stack Standard (Nginx):**
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
```

**Se usar a stack FrankenPHP:**
```bash
chmod +x docker/frankenphp/entrypoint.sh
```

---

## Passo 3 — Build e inicialização

### Stack Standard (recomendado)

```bash
docker-compose up --build -d
```

### Stack FrankenPHP

```bash
docker-compose -f docker-compose-franken.yml up --build -d
```

> O primeiro build leva aproximadamente **3–5 minutos** — o Docker instala as extensões PHP, Node.js, dependências do Composer e compila os assets do frontend.

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

| Serviço | URL | Credenciais |
|---------|-----|-------------|
| **Aplicação Web** | http://localhost:8889 | Email: `admin@km.com` / Senha: `password` |
| **Banco de Dados MySQL** | `127.0.0.1:3307` | Usuário: `root` / Senha: `root` / DB: `kondomanager_dev` |

Você pode se conectar ao banco de dados com qualquer cliente MySQL (TablePlus, DBeaver, MySQL Workbench, etc.) usando as credenciais acima.

---

## O que acontece automaticamente na primeira inicialização

O script de entrypoint executa os seguintes passos sem nenhuma entrada manual:

1. Copia `.env.example` → `.env` (se ainda não existir)
2. Configura a conexão com o banco de dados para apontar para o container `db`
3. Instala as dependências PHP via Composer
4. Gera a `APP_KEY`
5. Aguarda o MySQL ficar disponível
6. Instala as dependências Node.js e compila os assets do frontend *(somente na primeira execução)*
7. Executa as migrations do banco de dados
8. Executa os seeders *(somente se o banco estiver vazio — seguro reiniciar)*

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

O arquivo de configuração está em [`docker/supervisord.conf`](../docker/supervisord.conf):

```ini
[supervisord]
nodaemon=true

[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/worker.log
```

**Parâmetros principais:**
- `--sleep=3` — aguarda 3 segundos entre jobs quando a fila está vazia
- `--tries=3` — um job com falha é repetido até 3 vezes
- `--timeout=90` — um job que durar mais de 90 segundos é interrompido
- `numprocs=1` — um único processo worker ativo (aumentável para mais paralelismo)

### Monitorar o worker

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
docker-compose down

# — ou —

# Parar a stack FrankenPHP antes de mudar para Standard
docker-compose -f docker-compose-franken.yml down
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

## Solução de problemas

### `permission denied` ao iniciar
O script de entrypoint não tem permissão de execução. Execute:
```bash
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
# ou para FrankenPHP:
chmod +x docker/frankenphp/entrypoint.sh
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
O build é ignorado se a pasta `public/build/` já existir. Force um rebuild:
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

### Erro CORS / redirecionamento para `https://` em vez de `http://`
Se o navegador exibir um erro `Cross-Origin Request Blocked` ou a página tentar abrir `https://localhost:8889`, o problema está no `APP_URL` no arquivo `.env`.

**Causa:** o `.env` na pasta do projeto foi criado anteriormente pelo Herd, Coolify ou outro ambiente, e contém `APP_URL=https://...`. O Docker monta os arquivos do host diretamente no container (volume mount), portanto usa esse `.env` como está.

**Correção automática (versões recentes):** o `entrypoint.sh` define automaticamente `APP_URL=http://localhost:8889` a cada inicialização — nenhuma intervenção manual é necessária.

**Fix manual (se necessário):**
```bash
docker compose exec app sed -i 's|^APP_URL=.*|APP_URL=http://localhost:8889|' /var/www/.env
docker compose exec app php artisan config:clear
docker compose restart app
```
