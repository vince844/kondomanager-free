# 💾 Instalação no Synology NAS (Container Manager)

KondoManager pode ser facilmente hospedado no seu Synology NAS usando o **Container Manager** (anteriormente conhecido como Docker). 
Este guia usa a stack **Standard** (Nginx + PHP-FPM + Supervisor para processos em background), que é a solução mais confiável.

## Pré-requisitos
1. Um Synology NAS compatível com **Container Manager** (geralmente modelos "Plus" como DS220+, DS923+, etc.).
2. **Container Manager** instalado via Centro de Pacotes.
3. Acesso a pastas compartilhadas (certifique-se de ter uma pasta `docker` criada no seu NAS).

---

## Passo 1 — Obter os arquivos do projeto

Você tem duas opções: usar a interface web (File Station) ou usar SSH.

### Opção A: Via File Station (Mais fácil, sem linha de comando)
1. Faça o download do arquivo ZIP do KondoManager no GitHub: [Download v1.8.0beta](https://github.com/vince844/kondomanager-free/archive/refs/heads/v1.8.0beta.zip).
2. Abra o **File Station** no seu Synology.
3. Navegue até a pasta compartilhada `docker`.
4. Crie uma nova subpasta chamada `kondomanager-free`.
5. Envie o arquivo ZIP para dentro desta pasta e extraia-o (clique com o botão direito -> Extrair Aqui).
6. Certifique-se de que todos os arquivos (incluindo `docker-compose.yml`) estejam diretamente dentro de `docker/kondomanager-free/` (e não em uma subpasta aninhada adicional).

### Opção B: Via SSH (Para usuários avançados)
1. Habilite SSH no Painel de Controle do Synology (Terminal e SNMP).
2. Faça login no NAS via terminal (`ssh seuusuario@ip-do-nas`).
3. Execute:
   ```bash
   cd /volume1/docker
   git clone -b v1.8.0beta https://github.com/vince844/kondomanager-free.git
   ```

---

## Passo 2 — Permissões de execução (Crucial!)

Para permitir que o Docker inicie o KondoManager, os arquivos de inicialização devem ter permissões de execução. É aqui que muitos usuários ficam presos com um erro de `permission denied`.

Se você estiver conectado via **SSH**, basta executar:
```bash
cd /volume1/docker/kondomanager-free
chmod +x docker/standard/entrypoint.sh
chmod +x docker/standard/worker-entrypoint.sh
```

**Se você não quiser usar SSH**, pode usar o Agendador de Tarefas do Synology:
1. Vá para **Painel de Controle** -> **Agendador de Tarefas** (Task Scheduler).
2. Criar -> **Tarefa Agendada** -> **Script definido pelo usuário**.
3. Geral: Nome "Permissões KondoManager", Usuário: `root`.
4. Configurações da Tarefa: Insira este código:
   ```bash
   chmod +x /volume1/docker/kondomanager-free/docker/standard/entrypoint.sh
   chmod +x /volume1/docker/kondomanager-free/docker/standard/worker-entrypoint.sh
   ```
5. Clique em OK.
6. Selecione a tarefa recém-criada e clique em **Executar**. Depois de executada, você pode excluí-la.

---

## Passo 3 — Criar o Projeto no Container Manager

1. Abra o **Container Manager** no seu Synology.
2. Vá para a aba **Projeto** à esquerda.
3. Clique em **Criar**.
4. Preencha os campos:
   * **Nome do projeto:** `kondomanager`
   * **Caminho:** Selecione a pasta `docker/kondomanager-free`
   * **Origem:** Selecione "Usar docker-compose.yml existente"
5. Clique em **Avançar**.
6. (Opcional) Na próxima tela, se desejar alterar as portas para evitar conflitos com outros serviços no seu NAS, você pode editar o arquivo YAML diretamente da interface. Por padrão, o KondoManager usará a porta `8889`.
7. Clique em **Avançar** e depois em **Concluído** (certifique-se de que a caixa "Iniciar projeto assim que for criado" esteja marcada).

O Container Manager começará a baixar imagens e construir o projeto. **Esse processo levará cerca de 3-5 minutos**.

---

## Passo 4 — Verificar status e processos em background

No Container Manager, clique no projeto `kondomanager` recém-criado para visualizar seus 4 contêineres:
- `kondo_app` (O núcleo do Laravel)
- `kondo_nginx` (O servidor web)
- `kondo_db` (O banco de dados MySQL)
- `kondo_worker` (Supervisor que gerencia os processos em background)

### Como acessar:
1. Abra seu navegador e vá para `http://IP-DO-SEU-NAS:8889`
2. Faça login com as credenciais padrão:
   - Email: `admin@km.com`
   - Senha: `password`

### Interface do Worker (Supervisor):
Para garantir que os processos em background estejam funcionando corretamente (emails em background, faturamento automático, etc.):
1. Vá para `http://IP-DO-SEU-NAS:9001`
2. Insira o usuário `admin` e a senha `password`.
3. Você verá o processo `laravel-worker` em execução (RUNNING).

---

## Solução de problemas no Synology

### O contêiner `kondo_app` para continuamente
Verifique os logs no Container Manager. Se você vir um erro relacionado a `permission denied` no `entrypoint.sh`, significa que o Passo 2 falhou. Repita a operação com o Agendador de Tarefas, certificando-se de usar o usuário `root`.

### Erro de conexão / CORS no navegador (Redireciona para localhost ou test)
Se você usou essa pasta anteriormente em outros ambientes, o arquivo `.env` pode conter configurações incorretas. Nosso script corrige isso automaticamente definindo `APP_URL=http://localhost:8889`. 
No entanto, como você está em um NAS, você pode querer definir o IP real do seu NAS.
1. No File Station, abra a pasta `kondomanager-free`
2. Edite o arquivo `.env` usando o editor de texto do NAS
3. Altere `APP_URL=http://localhost:8889` para `APP_URL=http://192.168.x.x:8889` (use o IP do seu NAS).
4. Reinicie o projeto no Container Manager.

### Erros de permissão de gravação
Se você receber erros como `The stream or file "/var/www/storage/logs/laravel.log" could not be opened`, o contêiner não tem permissão de gravação na pasta compartilhada.
Pelo terminal ou via Agendador de Tarefas execute:
```bash
chmod -R 777 /volume1/docker/kondomanager-free/storage
chmod -R 777 /volume1/docker/kondomanager-free/bootstrap/cache
```

---

## Expor o KondoManager à Internet (Proxy Reverso do Synology)

Se você deseja acessar o KondoManager externamente (ex. `https://gestao.meudominio.com`) usando certificados SSL válidos, o melhor método é usar o Proxy Reverso integrado no DSM.

1. Vá para **Painel de Controle** -> **Portal de Login** -> **Avançado** -> **Proxy Reverso** (Reverse Proxy).
2. Clique em **Criar**.
3. Configure as regras:
   - **Origem:**
     - Protocolo: `HTTPS`
     - Nome do host: `gestao.meudominio.com` (ou o domínio de sua escolha)
     - Porta: `443`
   - **Destino:**
     - Protocolo: `HTTP`
     - Nome do host: `localhost`
     - Porta: `8889` (ou aquela configurada no Container Manager)
4. (Opcional) Na aba **Cabeçalhos Personalizados** (Custom Headers), clique em *Criar* -> *WebSocket* para permitir que o proxy passe corretamente as conexões em tempo real do Laravel.
5. Clique em **Salvar**.

**AVISO: Atualize seu arquivo `.env`**
Após configurar o proxy reverso, você deve informar ao KondoManager para gerar links (CSS, JS, imagens) usando seu novo domínio seguro, caso contrário o frontend tentará carregar arquivos de `http://localhost` bloqueando tudo.

1. Use o File Station ou o editor de texto do Synology para abrir o arquivo `docker/kondomanager-free/.env`.
2. Encontre a linha `APP_URL=`
3. Altere-a inserindo seu domínio EXATO (incluindo https):
   ```env
   APP_URL=https://gestao.meudominio.com
   ```
4. Se você quiser que os logs de segurança registrem o endereço IP real dos usuários (em vez do IP interno do NAS), encontre a configuração de proxy no arquivo `.env` e defina-a assim:
   ```env
   TRUSTED_PROXIES=*
   ```
5. Reinicie o projeto no Container Manager para aplicar as alterações. Nosso script de inicialização inteligente reconhecerá que você definiu um domínio personalizado e não o substituirá.
