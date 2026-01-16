# Kondomanager – Guia Completo do Ambiente Docker (Dev & Deploy)

Este documento descreve **passo a passo**, de forma **simples e reprodutível**, como qualquer utilizador (mesmo com conhecimentos básicos) pode instalar, configurar e trabalhar com o **Kondomanager** num ambiente Docker, bem como preparar integração contínua e deployment.

----------

## 1. Pré-requisitos

Antes de começares, certifica-te de que tens instalado:

- **Git** (>= 2.30)
- **Docker Desktop** (inclui Docker + Docker Compose)
  - Windows / macOS: https://www.docker.com/products/docker-desktop
  - Linux: docker + docker-compose-plugin

Verificação rápida:
```bash
git --version
docker --version
docker compose version
```

----------

## 2. Fork do repositório

1. Inicia sessão no GitHub
2. Acede a:
   https://github.com/vince844/kondomanager-free
3. Clica em **Fork** (canto superior direito)
4. Escolhe a tua conta pessoal
5. (Opcional) Renomeia o repositório

Resultado: o projeto ficará sob o teu controlo.

----------

## 2.1 Clonar o fork localmente

```bash
git clone https://github.com/TEU_UTILIZADOR/kondomanager-free.git
cd kondomanager-free
```

----------

## 2.2 Estrutura Docker

```
kondomanager/
├── docker/
│   ├── Dockerfile
│   ├── docker-compose_develop.yml
│   ├── entrypoint.sh
│   └── nginx/
│       └── default.conf
```

----------

## 2.3 Serviços incluídos

| Serviço | Finalidade |
|-------|------------|
| app | PHP 8.2 (runtime) + Composer (build-time) |
| nginx | Servidor web |
| db | MySQL 8.0 |
| phpmyadmin | Gestão da base de dados |

----------

## 3. Variáveis de ambiente (.env)

1. Copia o ficheiro de exemplo (se ainda não existir):

```bash
cp .env.example .env
```

2. Confirma as variáveis principais para desenvolvimento:

```env
APP_ENV=local
APP_DEBUG=true

AUTO_KEYGEN=true
AUTO_MIGRATE=true

DB_HOST=db
DB_DATABASE=kondomanager_dev
DB_USERNAME=kondomanager
DB_PASSWORD=kondomanager
```

⚠️ **Nunca versionar ficheiros `.env` com credenciais reais.**

----------

## 4. Iniciar o ambiente de desenvolvimento

A partir da raiz do projeto, executa:

```bash
docker compose -f docker/docker-compose_develop.yml up -d --build
```

E está feito.  
Não é necessário executar mais nenhum comando.

Verificar containers:

```bash
docker compose ps
```

----------

## 5. Pontos de acesso

**Aplicação**  
http://localhost:8889

**phpMyAdmin**  
http://localhost:8990

**MySQL**  
Host: localhost  
Porta: 3307  

Credenciais MySQL:
- Utilizador: `kondomanager`
- Palavra-passe: `kondomanager`

----------

### Comandos Docker úteis

Parar o ambiente:

```bash
docker compose -f docker/docker-compose_develop.yml down
```

Reset completo (incluindo volumes):

```bash
docker compose -f docker/docker-compose_develop.yml down -v
docker compose -f docker/docker-compose_develop.yml up -d --build
```

Ver logs:

```bash
docker compose -f docker/docker-compose_develop.yml logs -f
```

----------

## 6. O que acontece automaticamente

Quando os containers arrancam:

- As extensões PHP são instaladas em build-time
- As dependências Composer são instaladas em build-time
- Os assets frontend são compilados em build-time
- A `APP_KEY` do Laravel é gerada automaticamente (se não existir)
- O link simbólico para `storage` é criado
- As migrations da base de dados são executadas automaticamente quando `AUTO_MIGRATE=true`

Isto garante um ambiente consistente para todos os developers e para pipelines de CI.

----------

## 7. Workflow de desenvolvimento

### Backend (Laravel)

- Controllers, rotas, configuração e templates Blade têm hot reload
- Não é necessário reiniciar containers para alterações de backend

### Frontend

- Os assets frontend são compilados durante o build da imagem Docker
- Para refletir alterações de frontend, é necessário reconstruir a imagem:

```bash
docker compose -f docker/docker-compose_develop.yml up -d --build
```

Este comportamento é intencional para manter o ambiente previsível e reprodutível.

----------

## 8. Nota sobre CI/CD

O Kondomanager é **Docker-first**.

Os pipelines de CI apenas precisam de executar:

```bash
docker build .
```

Em ambientes de CI semelhantes a desenvolvimento, o `docker compose` também pode ser utilizado.  
Não é necessário qualquer setup em runtime.

----------

## 9. Workflow Git recomendado (desenvolvimento)

### Branches

```text
main        → produção
staging     → pré-produção
develop     → desenvolvimento
feature/*   → novas funcionalidades
fix/*       → correções
```

----------

## 10. Notas finais

- Não executar `composer install` ou `npm install` manualmente
- Não montar a pasta inteira do projeto como volume
- Todos os ambientes (dev, CI, produção) devem usar a imagem Docker como fonte da verdade
- Um ficheiro `.dockerignore` é utilizado para tornar os builds rápidos e determinísticos

Esta abordagem garante:
- Onboarding rápido
- Menos erros humanos
- Pipelines CI/CD simples e sustentáveis

----------

## 11. Boas práticas

- ❌ Nunca usar `root` em produção
- ❌ Nunca versionar ficheiros `.env`
- ✅ Backups automáticos da base de dados
- ✅ Logging centralizado
- ✅ Atualizações de segurança regulares

----------

## 12. Suporte

Este guia permite a **qualquer utilizador** levantar um ambiente totalmente funcional em minutos.
Para deploys em produção, valida sempre com um **administrador de sistemas**.
