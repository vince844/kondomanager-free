# Kondomanager – Guia Completo de Ambiente Docker (Dev & Deploy)
 
Este documento descreve **passo a passo**, de forma **simples e reproduzível**, como qualquer utilizador (mesmo com conhecimentos básicos) pode instalar, configurar e trabalhar com o **Kondomanager** num ambiente Docker, bem como preparar integração contínua e deploy.

----------

## 1. Pré‑requisitos

Antes de começares, garante que tens instalado:

-   **Git** (>= 2.30)
-   **Docker Desktop** (inclui Docker + Docker Compose)
    
    -   Windows / macOS: [https://www.docker.com/products/docker-desktop](https://www.docker.com/products/docker-desktop)
    -   Linux: docker + docker-compose-plugin
        
 
Verificação rápida:
```bash
git --version
docker --version
docker compose version
```

----------

## 2. Fork do repositório

1.  Inicia sessão no GitHub
    
2.  Acede a:  
    [https://github.com/vince844/kondomanager-free](https://github.com/vince844/kondomanager-free)
    
3.  Clica em **Fork** (canto superior direito)
    
4.  Escolhe a tua conta pessoal
    
5.  (Opcional) Renomeia o repositório
    

Resultado: terás o projeto sob o teu controlo.

----------

## 3. Clonar o fork localmente

```bash
git clone https://github.com/TEU_UTILIZADOR/kondomanager-free.git
cd kondomanager-free

```

----------

## 4. Estrutura Docker 

```
kondomanager/
└── docker/
    └── Dockerfile
    └── nginx/
        └── default.conf

```

### Serviços incluídos
- Serviço
- Função
- app
- PHP 8.2 + Composer + Node.js
- nginx
- Servidor web
- db
- MySQL 8.0
- phpmyadmin
- Gestão da base de dados

----------

## 5. Variáveis de ambiente (.env)

1.  Copia o ficheiro exemplo (se ainda não existir):
    

```bash
cp .env.example .env

```

2.  Confirma os valores principais:
    
```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8889

DB_HOST=db
DB_DATABASE=kondomanager_dev
DB_USERNAME=kondomanager
DB_PASSWORD=kondomanager

```

⚠️ **Nunca fazer commit do `.env` em produção com passwords reais**.

----------

## 6. Construir e arrancar o ambiente Docker

Na raiz do projeto:

```bash
docker compose build
docker compose up -d

```

Verificar containers:

```bash
docker compose ps

```

----------

## 7. Instalação da aplicação

### Entrar no container da aplicação

```bash
docker compose exec app bash

```

### Instalar dependências PHP

```bash
composer install

```

### Instalar dependências frontend

```bash
npm install
npm run dev

```

### Gerar chave da aplicação

```bash
php artisan key:generate

```

### Migrar base de dados

```bash
php artisan migrate --seed

```

----------

## 8. Acessos

**Aplicação**
[http://localhost:8889](http://localhost:8889/)

**phpMyAdmin**
[http://localhost:8990](http://localhost:8990/)

**MySQL**
localhost:3307

Credenciais MySQL:
-   Utilizador: `kondomanager`   
-   Password: `kondomanager`
    

----------

## 9. Comandos Docker úteis

```bash
# Parar containers
docker compose down

# Rebuild completo
docker compose down -v
docker compose build --no-cache
docker compose up -d

# Logs
docker compose logs -f app

```

----------

## 10. Fluxo Git recomendado (desenvolvimento)

### Branches

```text
main        → produção
staging     → pré‑produção
develop     → desenvolvimento
feature/*   → novas funcionalidades
fix/*       → correções

```

### Criar nova funcionalidade

```bash
git checkout develop
git pull
git checkout -b feature/minha-feature

```

Commit:

```bash
git add .
git commit -m "feat: descrição clara"
git push origin feature/minha-feature

```

Abrir Pull Request para `develop`.

----------

## 11. Boas práticas

-   ❌ Nunca usar `root` em produção    
-   ❌ Nunca versionar `.env`
-   ✅ Backups automáticos da base de dados
-   ✅ Logs centralizados
-   ✅ Atualizações de segurança regulares
    
----------

## 12. Suporte

Este guia permite a **qualquer utilizador** levantar um ambiente funcional em minutos.
Para produção, valida sempre com um **administrador de sistemas**.
