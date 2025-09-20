
# KondoManager - Gestione condominiale

KondoManager è un innovatovo software open source per la gestione condominiale, realizzato in Laravel e database MySql e pensato per gli amministratori di condominio ma anche per gli utenti del condominio.

## Screenshots

<table>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-3.png" alt="Dashboard" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-2.png" alt="Segnalazioni guasto" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-1.png" alt="Bacheca condominio" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-6.png" alt="Archivio documenti" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-4.png" alt="Agenda del condominio" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-5.png" alt="Gestione utenti e permessi" width="100%"></td>
  </tr>
</table>

## Prova la demo di KondoManager
Puoi visualizzare una demo del progetto andando al seguente indirizzo [KondoManager Demo](https://rebrand.ly/kondomanager) 

Attenzione per questioni di sicurezza alcune funzionalità sono state disattivate, puoi accedere con le seguenti credenziali:

```
Accedi come amministratore:
email: admin@kondomanager.it
password: Pa$$w0rd!

Accedi come utente:
email: user@kondomanager.it
password: Pa$$w0rd!
```

## Funzionalità del gestionale

- Gestione dei condomini
- Gestione delle anagrafiche
- Gestione delle segnalazioni guasto
- Gestione della bacheca condominiale
- Gestione dell'archivio documenti e categorie
- Gestione delle scadenze in agenda
- Gestione degli utenti
- Gestione dei ruoli e permessi
- Notifiche email
- Gestionale
  - Gestione palazzine
  - Gestione scale
  - Gestione immobili

## Requisiti minimi 

    PHP >= 8.2
    Node.js & NPM (Latest LTS)
    Composer

## Installazione del gestionale

1. Clona la repository

```bash
https://github.com/vince844/kondomanager-free.git
```

2. Genera il file .env

```bash
cp .env.example .env
php artisan key:generate
```

3. Configura il database MySql nel file .env

```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```
4. Configura il server SMTP nel file .env

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

5. Installa librerie

```bash
composer install
npm install
```

6. Esegui le migrazioni del database

```bash
php artisan migrate
```

7. Popola il database con le configurazioni di default

```bash
php artisan db:seed
```

8. Avvia i server di sviluppo

```bash
npm run dev
php artisan serve
```

🎉 That's it! Visita http://localhost:8000 per iniziare a lavorare con KondoManager.

Se necessario configura APP_URL specificando la porta

```bash
APP_URL=http://localhost:8000
```

Per accedere al pannello di amministrazione usa le seguenti credenziali:

```bash
Indirizzo email: admin@km.com
Password: password
```

Ricordati di modificare l'indirizzo email e la password al primo login andando all'indirizzo /settings/profile

## Documenti utili

- [Laravel Documentation](https://laravel.com/docs)
- [Vue.js Documentation](https://vuejs.org/guide/introduction.html)
- [Tailwind CSS Documentation](https://tailwindcss.com/docs)
- [Inertia.js Documentation](https://inertiajs.com/)
- [TanStack Table Documentation](https://tanstack.com/table/v8)


## Come contribuire al progetto

Chi volesse contribuire a far crescre il progetto è sempre il benvenuto!

Visualizza la pagina `contributing.md` per consigli e direttive sulle contribuzioni.

Ti prego di aderire al nostro `code of conduct`.

## Sostieni il progetto

Sviluppare un software open source richiede molto impegno e dedizione, ti sarò grato se decidi di sostenere il progetto.

[Sostieni KondoManager su Patreon](https://www.patreon.com/KondoManager)

## Feedback

Per chi volesse inviare dei feedback o consigli su moglioramenti può farlo nell'apposita sezione all'interno di questa repository


## Supporto

Per supporto o richieste di personalizzazione del codice, potete contattarmi utlizzando l'apposito modulo contatti che trovate su https://kondomanager.com


## Licenza

[AGPL-3.0](https://github.com/vince844/kondomanager-free?tab=AGPL-3.0-1-ov-file#readme)

