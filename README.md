
# KondoManager - Gestione condominiale

KondoManager è un innovatovo software open source per la gestione condominiale, realizzato in Laravel e database MySql e pensato per gli amministratori di condominio.

## Screenshots

<table>
  <tr>
    <td><img src="https://i.imgur.com/5xFDr7X.png" alt="Dashboard" width="100%"></td>
    <td><img src="https://i.imgur.com/kgDhope.png" alt="Segnalazioni guasto" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://i.imgur.com/dNOKWYV.png" alt="Bacheca condominio" width="100%"></td>
    <td><img src="https://i.imgur.com/xkCipgI.png" alt="Archivio documenti" width="100%"></td>
  </tr>
</table>

## Funzionalità del gestionale

- Gestione condomini
- Gestione anagrafiche
- Gestione segnalazioni guasto
- Gestione bacheca condominiale
- Gestione archivio documenti e categorie
- Gestione utenti
- Gestione ruoli e permessi
- Notifiche email

## Requisiti minimi 

    PHP >= 8.2
    Node.js & NPM (Latest LTS)
    Composer

## Installazione del gestionale

1. Clona la repository

```bash
https://github.com/vince844/kondomanager-free.git
```

2. Installa librerie

```bash
composer install
npm install
```

3. Genera il file .env

```bash
cp .env.example .env
php artisan key:generate
```

4. Configura il database MySql nel file .env

```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```
5. Esegui le migrazioni del database

```bash
php artisan migrate
```

6. Popola il database con le configurazioni di default

```bash
php artisan db:seed
```

7. Avvia i server di sviluppo

```bash
npm run dev
php artisan serve
```

🎉 That's it! Visita http://localhost:8000 per iniziare a lavorare con KondoManager.

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

