# 💾 Instalação no Synology NAS (Container Manager)

<!-- verifica-documentazione -->
> **Estado:** Corresponde ao código — **reescrita em 04/11/2026 em 1.12.0-beta.1, e ainda não testada em um Synology NAS.** Descreve a imagem `ghcr.io/vince844/kondomanager-core` da 1.11.0, a mesma do [guia de Docker (produção)](https://kondomanager.com/docs/installation-docker-produzione.html) do site (escrito em italiano), que foi testado no Docker Desktop (macOS, em emulação) e não em um NAS. Os passos da interface do DSM são escritos a partir de como a Synology documenta o Container Manager (DSM 7.2) e não foram verificados em um aparelho real. A versão anterior deste guia fazia construir o código a partir dos fontes com a stack de desenvolvimento (debug ativado, senhas de teste, portas abertas na rede): foi substituída.
<!-- /verifica-documentazione -->

> ⚠️ **Este guia ainda não foi testado em um Synology NAS.** Os arquivos e os comandos vêm da imagem de produção, testada em outro lugar; os passos da interface do DSM não. Na primeira vez, faça em uma pasta nova, sem tocar nas pastas compartilhadas que você já tem: a seção «Para remover o teste», no fim, diz como tirar tudo.

O KondoManager roda em um Synology NAS como uma imagem Docker já pronta (`ghcr.io/vince844/kondomanager-core`): não se baixa o código e não se compila nada. No **Container Manager** cria-se um projeto a partir de dois arquivos, um `docker-compose.yml` e um `.env`, e o contêiner faz o resto: na primeira inicialização instala-se sozinho, e a cada inicialização atualiza o banco de dados.

## O que você precisa

1. Um Synology NAS com **DSM 7.2 ou posterior** e **Container Manager**, que se instala pelo Centro de Pacotes. Antes da 7.2 o pacote se chama «Docker»: este guia não foi escrito para ele.
2. Um modelo com processador **x86-64** (Intel ou AMD). A imagem é construída somente para `linux/amd64` (conferido no registro em 04/11/2026): nos modelos com processador ARM o Docker pode emulá-la, mas não testamos e não recomendamos. O processador do seu modelo está nas especificações, no site da Synology.
3. Memória livre para o MySQL e o PHP juntos. Não a medimos, então não há um número: antes de começar, confira quanta memória sobra livre no NAS.
4. Um computador, para preparar dois arquivos de texto e para criar a chave e as senhas com o `openssl` (macOS e Linux; no Windows o terminal do Git ou o WSL).
5. Somente se você quiser abrir o KondoManager de fora de casa, em https: um nome de domínio e o proxy reverso do DSM (última seção).

## Antes de começar: onde vão os arquivos, e o que não é tocado

- Os dois arquivos ficam em **uma pasta nova, só para o KondoManager**: a subpasta `kondomanager` da pasta compartilhada `docker`, a que o Container Manager usa para seus projetos (normalmente ele mesmo a cria quando você o instala; se não existir, crie uma nova pasta compartilhada só para isto). Não use uma pasta que contenha seus documentos. O arquivo `.env` contém as senhas: confira que a pasta `docker` não esteja compartilhada com outros usuários.
- **Antes de criar a pasta, confira que o nome esteja livre.** No File Station não deve existir já uma pasta `docker/kondomanager`, e no Container Manager, aba **Projeto**, não deve existir já um projeto `kondomanager`: a versão anterior deste guia o criava com este nome, na pasta `docker/kondomanager-free`. O Docker reconhece um projeto pelo seu nome, e trata dois projetos com o mesmo nome como um só: os comandos deste guia agiriam também sobre os contêineres do outro. Se o nome já estiver ocupado, use `kondomanager-prova` para a pasta e para o projeto, e leia-o no lugar de `kondomanager` nos caminhos, no nome do projeto e nos nomes dos volumes de todo o guia.
- **Nenhuma pasta compartilhada que você já tem é ligada ao contêiner.** O banco de dados e os documentos enviados ficam em dois *volumes* que o Docker cria por conta própria, normalmente sob `@docker` (a área do Docker no volume do NAS), e não em uma pasta compartilhada visível pelo File Station.
- **Não é preciso nenhum `chmod` nem mudar nenhuma permissão**: o contêiner prepara sozinho as suas pastas. Se em outro lugar você ler que é preciso dar permissões `777` a uma pasta compartilhada, para este guia não é necessário: não faça.
- Quando o teste terminar, tira-se tudo, sem deixar nada: veja «Para remover o teste».

---

## Passo 1 — Os dois arquivos

Em um computador, crie uma pasta `kondomanager` e, dentro dela, dois arquivos.

### `docker-compose.yml`

É o mesmo arquivo do guia de Docker (produção) do site:

```yaml
services:
  app:
    image: ghcr.io/vince844/kondomanager-core:${KONDOMANAGER_VERSIONE}
    platform: linux/amd64
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    ports:
      - "8080:80"
    environment:
      APP_KEY: ${APP_KEY}
      APP_URL: ${APP_URL}
      DB_HOST: db
      DB_DATABASE: kondomanager
      DB_USERNAME: kondomanager
      DB_PASSWORD: ${DB_PASSWORD}
      INSTALL_ADMIN_NAME: ${INSTALL_ADMIN_NAME}
      INSTALL_ADMIN_EMAIL: ${INSTALL_ADMIN_EMAIL}
      INSTALL_ADMIN_PASSWORD: ${INSTALL_ADMIN_PASSWORD}
    volumes:
      - storage:/var/www/storage

  db:
    image: mysql:8.4
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: kondomanager
      MYSQL_USER: kondomanager
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - database:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1"]
      interval: 5s
      timeout: 5s
      retries: 30

volumes:
  storage:
  database:
```

- `app` é o KondoManager: responde na porta 8080 do NAS. Se a 8080 já for usada por outro serviço, mude o número da esquerda (por exemplo `"8090:80"`) e use-o também nos endereços deste guia. Quando você tiver o https com o proxy reverso do DSM, escreva `"127.0.0.1:8080:80"`: assim a porta responde só a partir do próprio NAS e não da rede.
- `platform: linux/amd64` diz ao Docker que a imagem existe somente para x86-64.
- `db` é o banco de dados, MySQL 8.4 (a versão que testamos). **Não tem `ports:`**: não se alcança pela rede, só a partir do KondoManager.
- `storage` e `database` são os dois volumes: ali ficam os documentos, os backups e os dados, e eles sobrevivem quando os contêineres são recriados.
- **Não há nenhuma linha `volumes:` que aponte para uma pasta do NAS**: é de propósito.

### `.env`

```
KONDOMANAGER_VERSIONE=1.11.0
APP_KEY=
APP_URL=http://192.168.1.50:8080
DB_PASSWORD=
DB_ROOT_PASSWORD=
INSTALL_ADMIN_NAME=Mario Rossi
INSTALL_ADMIN_EMAIL=mario@esempio.it
INSTALL_ADMIN_PASSWORD=
```

As linhas vazias são a chave e as senhas: **você as escreve**, e nenhuma está nesta página. Enquanto ficarem vazias o contêiner não se instala. `APP_URL` é o endereço com o qual você abre o KondoManager: para o primeiro teste, `http://` mais o endereço IP do seu NAS e `:8080` (aqui um exemplo). Escreva o seu nome e o seu email como administrador.

O nome do arquivo é exatamente `.env`, com o ponto na frente e nenhuma outra extensão (alguns editores do Windows acrescentam `.txt`).

## Passo 2 — A chave e as senhas

No computador, no terminal:

```bash
echo "base64:$(openssl rand -base64 32)"
openssl rand -hex 16
```

- O primeiro comando imprime uma linha que começa com `base64:`: cole-a inteira em `APP_KEY`.
- O segundo imprime uma senha de 32 caracteres: execute-o duas vezes, uma para `DB_PASSWORD` e outra para `DB_ROOT_PASSWORD`. Serve também para `INSTALL_ADMIN_PASSWORD`; se você escolher uma, que tenha pelo menos 10 caracteres e não tenha o caractere `$` (o Docker Compose o lê como o início de uma variável).

**Guarde a chave e o arquivo `.env`.** Sem `APP_KEY` o contêiner não inicia e diz isso no registro; com uma chave diferente da da primeira inicialização os dados cifrados deixam de poder ser lidos. Guarde uma cópia do arquivo `.env` fora do NAS.

Se você tem acesso SSH ao NAS e não tem o `openssl` no computador, a chave também se cria com a própria imagem: `sudo docker run --rm --entrypoint php ghcr.io/vince844/kondomanager-core:1.11.0 artisan key:generate --show`.

## Passo 3 — Colocar os arquivos no NAS e criar o projeto

Os nomes dos botões são os do Container Manager no DSM 7.2 como a Synology os documenta: **não foram verificados em um NAS**.

1. Abra o **File Station**, vá para a pasta compartilhada `docker`, crie a pasta `kondomanager` e envie para dentro os dois arquivos, `docker-compose.yml` e `.env`. Se o File Station não mostrar o `.env`, é um arquivo oculto (começa com ponto): ative a exibição de arquivos ocultos nas configurações do File Station.
2. Abra o **Container Manager** e vá para a aba **Projeto**, depois **Criar**.
3. Preencha os campos:
   * **Nome do projeto:** `kondomanager`, o mesmo nome da pasta: os comandos por SSH deste guia encontram o projeto pelo nome da pasta do seu `docker-compose.yml`
   * **Caminho:** a pasta `docker/kondomanager`
   * **Origem:** «Usar docker-compose.yml existente»
4. **Avançar.** Se o Container Manager propuser configurar um portal web (Web Station), não ative: o proxy reverso do DSM é a última seção deste guia.
5. **Avançar** e depois **Concluído**, com a caixa «Iniciar projeto assim que for criado» marcada.

O Container Manager baixa as duas imagens (`kondomanager-core` e `mysql:8.4`) e inicia os dois contêineres. Quanto tempo leva no seu NAS não sabemos: não medimos.

Se você prefere o terminal e tem acesso SSH, basta `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d`. A pasta compartilhada `docker` fica normalmente em `/volume1`; se você a criou em outro volume do NAS, o caminho começa por ele (`/volume2/…`). Os comandos por SSH deste guia levam o caminho completo do arquivo: assim agem sobre este projeto a partir de qualquer pasta em que você os execute.

## Passo 4 — Conferir que iniciou

1. No Container Manager, no projeto `kondomanager`, abra o registro do contêiner `app`. A cada inicialização o contêiner confere a chave, prepara as pastas, espera o banco de dados e, na primeira inicialização, instala o KondoManager com o administrador do seu `.env`: no registro você encontra, entre outras linhas, «KondoManager installato. Accedi con mario@esempio.it.» (em italiano, com o seu email).
2. No navegador de um computador da sua rede abra `http://IP-DO-NAS:8080/up`: responde com `"stato":"ok"`, `"documenti":"su_volume"` e a versão. O detalhe aparece porque você chama diretamente, de uma rede privada e sem proxy; através de um proxy responde somente com `"stato"`.
3. Abra `http://IP-DO-NAS:8080` e entre com o email e a senha que você escreveu em `INSTALL_ADMIN_EMAIL` e `INSTALL_ADMIN_PASSWORD`. O contêiner os lê somente em um banco de dados vazio, na primeira inicialização: depois do primeiro acesso você pode tirar a senha do `.env` e a troca pela aplicação.
4. **Não há nenhuma interface do Supervisor para abrir.** A imagem de produção mantém no mesmo contêiner o servidor web, o PHP, o processador de filas e o agendador, e não abre nenhuma porta para controlá-los: o estado é dado pelo registro.

## Se algo não funcionar

**O contêiner reinicia o tempo todo, e o registro diz «APP_KEY manca».**
A chave não chegou ao contêiner: escreva-a como no Passo 2 no arquivo `.env`, na mesma pasta do `docker-compose.yml`.

**Aparece «invalid reference format» e o projeto não inicia.**
O `.env` não foi lido e o número da versão da imagem (`KONDOMANAGER_VERSIONE`) ficou vazio: a imagem se chamaria `ghcr.io/vince844/kondomanager-core:`, sem versão. Confira que o arquivo se chama `.env` e está ao lado do `docker-compose.yml`.

**O registro do projeto diz «The "APP_KEY" variable is not set» (ou o mesmo para outra variável).**
O `.env` não foi lido. O Docker Compose escreve esses avisos no registro do *projeto*, não no do contêiner `app`. Confira que se chama `.env` e está ao lado do `docker-compose.yml`. Se o Container Manager continuar sem lê-lo, escreva os valores diretamente no `docker-compose.yml` no lugar dos `${...}`.

**«Mancano i dati dell'amministratore: nessuna scrittura» (faltam os dados do administrador).**
O banco de dados está vazio e os dados do administrador faltam ou não são válidos: é preciso um email válido em `INSTALL_ADMIN_EMAIL`, uma senha de pelo menos 10 caracteres em `INSTALL_ADMIN_PASSWORD` e, se você escrever, um nome de pelo menos 2. As linhas anteriores dizem qual. O contêiner não escreveu nada: corrija o `.env` e recrie o projeto.

**«Il database non risponde: nessuna scrittura» (o banco de dados não responde).**
O banco de dados não respondeu em 120 segundos. Confira o registro do contêiner `db`.

**`/up` responde 503 e o registro diz «storage/app NON è su un volume separato».**
Falta o volume `storage` no `docker-compose.yml`: coloque-o de volta como no Passo 1. Sem ele, documentos e backups desapareceriam na primeira vez que o contêiner fosse recriado.

**A porta 8080 já está em uso.**
Mude o número da esquerda na linha `ports:` (por exemplo `"8090:80"`) e recrie o projeto.

**O aviso «The requested image's platform (linux/amd64) does not match the detected host platform».**
O seu NAS tem um processador ARM: a imagem é somente para x86-64. Veja «O que você precisa».

## Expor o KondoManager à Internet (proxy reverso do DSM)

Para abrir o KondoManager de fora de casa, por exemplo `https://gestao.meudominio.com`, com um certificado válido, o melhor método é o proxy reverso integrado ao DSM. O certificado é gerenciado pelo DSM.

1. Vá para **Painel de Controle** → **Portal de Login** → **Avançado** → **Proxy Reverso**, depois **Criar**.
2. Configure a regra:
   - **Origem:** protocolo `HTTPS`, nome do host `gestao.meudominio.com` (ou o domínio que você escolheu), porta `443`.
   - **Destino:** protocolo `HTTP`, nome do host `127.0.0.1` (não `localhost`: com `"127.0.0.1:8080:80"` a porta responde só nesse endereço), porta `8080` (ou a que você escolheu no compose).
3. Salve.
4. No arquivo `.env` escreva em `APP_URL` o seu domínio **exato**, https incluído (`APP_URL=https://gestao.meudominio.com`): é o endereço que o KondoManager coloca nos seus links e do qual carrega as páginas. Depois recrie o projeto, porque uma simples reinicialização não relê o `.env`: com SSH, `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d`; no Container Manager é a ação do projeto que reconstrói os contêineres (o nome do botão não foi verificado).
5. **`TRUSTED_PROXIES`: não escreva nada.** Na imagem já vale `PRIVATE_SUBNETS`, que confia em um proxy conectado a partir de uma rede privada, incluídas as redes do Docker: o proxy do DSM se conecta a partir do próprio NAS. **Nunca use `*`**: com `*` quem chega de fora escolhe o seu próprio endereço, e o bloqueio das tentativas de acesso deixa de funcionar. Os outros casos estão na página [Proxy e HTTPS](https://kondomanager.com/docs/configurazione-proxy-https.html) do site (em italiano).
6. Quando o https funcionar, escreva no `docker-compose.yml` `"127.0.0.1:8080:80"`, para que a porta em http deixe de responder a partir da rede de casa, e recrie o projeto.

## Atualizar

Dentro do contêiner a atualização pelo painel está desativada: atualizar significa usar a imagem de uma versão nova.

1. Pelo programa (a interface dele é em italiano): **Impostazioni → Gestione backups**, «Crea backup», e baixe-o.
2. No arquivo `.env` escreva a versão nova em `KONDOMANAGER_VERSIONE`.
3. Baixe a imagem nova e recrie o contêiner do KondoManager. Com SSH:
   ```bash
   sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml pull app
   sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml up -d
   ```
   `pull app` baixa somente a imagem do KondoManager: o banco de dados fica como está (um `pull` sem nome baixaria também um MySQL novo). Qual botão do Container Manager faz o mesmo não foi verificado.

Ao iniciar, o contêiner aplica as migrações e atualiza permissões, municípios, ATECO e versão, antes de servir uma única página.

## O backup e os volumes

- O backup do programa (**Impostazioni → Gestione backups**) contém o banco de dados e os documentos e fica em `storage/app/backups`, isto é, no volume `storage`: baixa-se pela interface. Como funciona está no guia [Backup e restauração](https://kondomanager.com/docs/backup.html) do site (em italiano). A restauração dentro do contêiner ainda não testamos.
- O arquivo `.env` não está no arquivo compactado: no contêiner a configuração fica fora, ao lado do compose. Guarde uma cópia do `.env`, com a sua `APP_KEY`, junto com os arquivos de backup.
- Um backup que fica no NAS não protege você se perder o NAS: baixe-o e guarde-o em outro lugar.
- Os volumes `storage` e `database` não estão em uma pasta compartilhada. Não verificamos se os programas de cópia do NAS (por exemplo o Hyper Backup) os incluem: não conte com isso, e baixe os arquivos de backup do programa.

## Se você já instalou com a versão anterior deste guia

A versão anterior fazia construir o código a partir dos fontes, em uma pasta `kondomanager-free`, com a stack de desenvolvimento. Essa stack deixa **na rede do NAS** a porta do banco de dados e a da interface dos processos em segundo plano, com as credenciais de teste que o próprio guia publicava, um administrador com a senha escrita no guia e o debug ativado. Qualquer pessoa na sua rede pode tentá-las.

Enquanto você não passar para este guia, pelo menos estes três passos, que **não testamos em um NAS**:

1. Pelo programa, troque a senha do administrador que o guia antigo fazia criar.
2. No `docker-compose.yml` dessa pasta retire o bloco `ports:` dos serviços `db` e `worker`, isto é, a linha `ports:` e a de baixo (o programa continua acessível pela porta do serviço `nginx`), e recrie o projeto.
3. No `.env` da mesma pasta escreva `APP_DEBUG=false` e recrie o projeto.

Depois, assim que puder, faça um backup pelo programa, baixe-o e passe para este guia. A mudança dos dados para o contêiner novo não foi testada. O projeto da versão anterior também se chama `kondomanager`: para este guia use o outro nome, `kondomanager-prova`, como diz «Antes de começar».

## Para remover o teste

Tudo o que este guia cria está em uma pasta e dois volumes, e sai sem tocar em mais nada. Não apaga nenhuma das pastas compartilhadas que você tinha.

1. **O projeto e os dados do teste.** Com SSH, primeiro `sudo docker compose ls -a`: ao lado do projeto `kondomanager` deve aparecer o arquivo `/volume1/docker/kondomanager/docker-compose.yml`. Depois `sudo docker compose -f /volume1/docker/kondomanager/docker-compose.yml down -v` para e remove os contêineres e **apaga os dois volumes**, isto é, o banco de dados e os documentos do teste; os volumes dos outros projetos não, porque o caminho do arquivo diz ao Docker de que projeto se trata. Um `down -v` sem `-f`, executado a partir da pasta de outro projeto, apagaria os volumes desse. Sem SSH: no Container Manager, aba **Projeto**, `kondomanager`, **Parar** e depois **Excluir**; os dois volumes (`kondomanager_database` e `kondomanager_storage`) porém continuam até você apagá-los, e ocupam espaço no volume do NAS mas não tocam em nenhuma pasta compartilhada.
2. **As imagens.** No Container Manager, **Imagem**: exclua `ghcr.io/vince844/kondomanager-core` e `mysql:8.4`, esta última somente se nenhum outro projeto a usar.
3. **Os arquivos.** No File Station exclua a pasta `docker/kondomanager`: ela contém somente os dois arquivos deste guia.
4. **O proxy, se você o criou.** **Painel de Controle** → **Portal de Login** → **Avançado** → **Proxy Reverso**: exclua a regra do KondoManager.

## O que este guia não cobre

Não testamos, então não prometemos: os passos do Container Manager em um NAS real, o DSM anterior à 7.2, os modelos com processador ARM, quanta memória é necessária e quanto tempo leva a primeira inicialização, o endereço que o KondoManager vê atrás do proxy do DSM, a restauração de um backup dentro do contêiner e a mudança de uma instalação que você já tem (documentos incluídos): nesse caso, primeiro um backup.
