[![Read in English](https://img.shields.io/badge/Read_in-English-red.svg)](README.en.md)
[![Leggi in Italiano](https://img.shields.io/badge/Leggi_in-Italiano-green.svg)](README.md)
[![Leia em Português](https://img.shields.io/badge/Leia_em-Português-yellow.svg)](README.pt-br.md)
[![Generic badge](https://img.shields.io/badge/Version-1.11.0-blue.svg)](https://github.com/vince844/kondomanager-free/releases)
[![License](https://img.shields.io/badge/License-AGPL_3.0-blue.svg)](https://opensource.org/licenses/AGPL-3.0)
[![Forum](https://img.shields.io/badge/Forum-Community-orange.svg)](https://kondomanager.short.gy/km-forum)
[![GitHub stars](https://img.shields.io/github/stars/vince844/kondomanager-free?style=social)](https://github.com/vince844/kondomanager-free/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/vince844/kondomanager-free?style=social)](https://github.com/vince844/kondomanager-free/network/members)
[![GitHub issues](https://img.shields.io/github/issues/vince844/kondomanager-free)](https://github.com/vince844/kondomanager-free/issues)
[![GitHub last commit](https://img.shields.io/github/last-commit/vince844/kondomanager-free)](https://github.com/vince844/kondomanager-free/commits/main)

# KondoManager - Free and Open Source Condominium Management Software

**KondoManager** is the first open source, self-hosted condominium management software, built with **Laravel, Vue 3, Inertia.js and MySQL**. Designed for property managers who need a serious tool: double-entry bookkeeping, instalment plans, a digital resident portal and automatic updates — no subscriptions, no vendor lock-in.

---

## Screenshots

<table>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-3-new.png" alt="Dashboard" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-2-new.png" alt="Fault reporting" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-1-new.png" alt="Condominium bulletin board" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-6-new.png" alt="Document archive" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-4-new.png" alt="Condominium calendar" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-5-new.png" alt="User and permission management" width="100%"></td>
  </tr>
</table>

---

## Try the Demo

You can view a demo of the project at the following address:

👉 **[KondoManager demo](https://kondomanager.short.gy/kondo)**

**Warning:** For security reasons, some features such as email sending and notifications have been disabled.

**Login Credentials:**

| Role | Email | Password |
| :--- | :--- | :--- |
| **Administrator** | `admin@kondomanager.it` | `Pa$$w0rd!` |
| **User** | `user@kondomanager.it` | `Pa$$w0rd!` |

---

## Management Features

### Core Functions

- Automatic update system from administrator panel
- Management of condominium and supplier records
- Condominium fault reporting management with integrated chat/comments module
- Digital condominium bulletin board for communications
- Document archive and condominium categories
- Deadline calendar with recurrence management
- Advanced user, role and permission management
- Automatic email notifications
- Login with two-factor authentication
- User registration invitation system
- Installation language to choose among Italian, English, Portuguese and Spanish; the management pages are for the most part in Italian only
- Import an entire condominium from another management program (first source: Danea Domustudio)
- Full backup and restore from the panel, resumable and encryptable in AES-256

### Accounting and Structure Module

- Management of buildings, stairways and properties
- Condominium bank accounts
- Unlimited millesimal tables with multi-table allocation and configurable coefficients
- Financial year management
- Ordinary and extraordinary managements
- Chart of accounts creation, keeping the approved budget separate from unforeseen expenses
- Instalment plan generation, also with advanced recurrences, and a snapshot in time
- Extraordinary instalment plans authorised by an assembly resolution or by an urgent intervention (Art. 1135 c.c.)
- Multi-line purchase invoices with direct charge to a single unit (Art. 63 c.c.)
- Management of unforeseen liabilities and off-budget expenses (Art. 1130-bis c.c.)
- Management of prior-year debts and invoices from earlier financial years with a limitation-period alert (Art. 2948 c.c.)
- Budget overrun handling with three strategies: year-end adjustment, supplementary instalment or Reserve Fund
- Credit note and reversal engine with a cent-level balance validator (Write-Then-Reverse)
- Restricted reserve funds with an audit trail and a documented release by exception
- Receipt registration with automatic or manual allocation
- Owner's Smart Wallet with offsetting of earlier credits
- Silent instalment issuance with deferred publication
- Double-entry bookkeeping with the Penny-Perfect engine in whole cents
- Account statement of each contact with cascading sort
- Financial X-Ray: financial breakdown per owner with detail per property
- Audit dashboard with an accounting traffic light and detection of orphan items
- Smart inbox for interactive calendar deadlines
- Supplier invoice payment module with bulk transfers, reversals and immutable Ledger
- Treasury Guardian widget for 30-day predictive liquidity analysis
- Dynamic cascading engine (Tenant → Usufructuary → Owner)
- Smart management of documented shortfalls for vacant units
- Browsable general ledger with search, period and status filters, and balance verification
- Balance sheet with plain-language diagnosis of imbalances and on-the-spot correction
- Transfers between bank accounts and funds, with protocol number, preview and reversal
- Withholding tax and F24 tax form: threshold-based schedule, payment slip and double-entry closure
- Contributions already paid in by owners netted off the breakdown, distributed by thousandths
- Quick recording of petty expenses without opening a supplier account
- Actual spend shown alongside the budget on every item, with the transactions that make it up
- Configurable first-instalment due date on the payment plan

### Document Suite and PDF Reports

- 100% native PDF rendering engine with no external dependencies
- Document customization with administrator signature upload and legal footnotes
- **Budget / Chart of Accounts Slip:** complete printout of chapters, sub-accounts, and budgeted amounts
- **Expense Allocation:** document illustrating the allocation of the chart of accounts across millesimal tables
- **Budget Allocation by Table (Installments):** detailed document (landscape A4) crossing properties, subjects (owners, tenants, etc.) and millesimal tables
- **Installment Schedules:** dynamic aggregation by Resident or Property
- **Supplier Payment Slips:** payment summaries with details for bank transfers
- **Allocation by Expense Chapter:** the reading by budget item, alongside the allocation by table
- **Owner's Account Statement:** balances and the full sequence of the year's transactions
- **F24 tax form and home-banking summary:** with the list of the payments each slip settles

---

## Minimum Requirements

To install KondoManager, your server environment must meet the following requirements:

- **PHP** >= 8.4.1 — *from version 1.10.0; earlier versions ran on PHP 8.2*
- **Database:** MySQL 5.7+ or MariaDB 10.3+
- **PHP Extensions:** `zip`, `curl`, `bcmath`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `dom`, `xml` - `gd` is what generates the PDFs: without it the program installs but produces no reports
- **For manual installation:** Node.js & NPM, Composer

---

## Official Documentation

The complete KondoManager documentation is available at:

**[www.kondomanager.com/docs](https://kondomanager.com/docs)**

You will find detailed guides on:

- [Installation and initial configuration](https://kondomanager.com/docs/installation.html)
- Email and cron job configuration
- Accounting module and chart of accounts
- Instalment plans and receipts
- Updates and maintenance

---

## Guided Installation (Recommended for less experienced users)

For less experienced users or for quick installations on shared hosting (cPanel, Plesk, etc.), we have created an automated wizard.

### 1. New Guided Installation

1. Download the [installation file](https://kondomanager.short.gy/km-installer) from the official Kondomanager website
2. Extract and upload the `index.php` file to the **root** of your server (via FTP or File Manager on cPanel).
3. Open your browser at: `https://yoursite.com/index.php`.
4. Follow the on-screen guided procedure.

For more details, visit the [official installation guide](https://kondomanager.com/docs/installation.html) or our [YouTube channel](https://www.youtube.com/@Kondomanager)

### 2. Automatic Update from Administrator Panel

The automatic update system brings the installation to the new version from the administration panel, in two steps: «Scarica e Installa» (download and install) puts the new files in place, then «Avvia aggiornamento» (start update) updates the database, with the safety copy of the database offered before the migrations, when starting from 1.10.0 or a later version.

**Warning:** If you don't configure `CronJob` processes, automatic updates will not work.

### CronJob Configuration

**Option A — From the control panel (recommended for shared hosting)**

Starting from v1.9.0 you can configure the scheduled processes directly from the KondoManager administration panel, without accessing the server. The system works with external services such as [cron-job.org](https://cron-job.org), useful on shared hosting without a native cron. You will find the configuration in **«Impostazioni» (Settings) → «Automazione cloud» (cloud automation)**.

**Option B — From the hosting panel (cPanel / Plesk)**

Access your hosting panel in the "Cron Jobs" or "Task Scheduler" section and set execution every minute (`* * * * *`).

**Example for ServBay local environment** (from KondoManager 1.10.0 the baseline is PHP 8.4,
not supported by MAMP's free tier — if you're migrating from MAMP, follow the
[MAMP to ServBay migration guide](https://kondomanager.com/docs/migrazione-mamp-a-servbay.html)):

*macOS — crontab (`crontab -e`):*
```bash
/Applications/ServBay/script/alias/php yourfolder/artisan schedule:run >> /dev/null 2>&1
```

*Windows — Task Scheduler (Windows has no crontab):* open Command Prompt, check the actual
PHP path with `where php`, then register the task to run every minute:
```bash
schtasks /create /tn "KondoManager Scheduler" /tr "php C:\yourfolder\artisan schedule:run" /sc minute /mo 1
```

**Example for Shared Server (cPanel/Linux):**
```bash
/usr/local/bin/php /home/yoursite/public_html/artisan schedule:run >> /dev/null 2>&1
```

Make sure to use the absolute path to the PHP 8.4+ executable, for example `/usr/local/bin/ea-php84`. Check in MultiPHP Manager the PHP version actually assigned to the domain.

---

### Email Configuration (SMTP / Sendmail)

Starting from v1.9.0 the email credentials are configured directly from the administration panel, without editing the `.env` file.

You will find the configuration in **«Impostazioni» (Settings) → «Configurazione email» (email configuration)**.

| Field | Description |
| :--- | :--- |
| **Driver** | `SMTP` for external providers (Gmail, Mailgun, etc.) or `Sendmail` for the hosting's local PHP mailer |
| **Host** | Address of the SMTP server (e.g. `smtp.gmail.com`) |
| **Port** | Typically `587` (TLS) or `465` (SSL) |
| **User** | Email address or username of the provider |
| **Password** | Password or App Password of the provider |
| **Encryption** | `tls` or `ssl` |
| **Sender** | Name and address that will appear as the sender of the emails to the owners |

The configuration saved from the panel takes **precedence** over the `.env` file and can be changed at any time without restarting the server.

**Note on the sending method:** the application recommends `SMTP` with an external server (Gmail, Brevo, etc.); `Sendmail` is only for a VPS or dedicated server with Postfix and the SPF and DKIM records configured.

---

### 3. Manual update (with the installer file)

The steps below do not depend on the version you are coming from: they are the same for any manual update from 1.8.0 onwards.

1. Make sure you have a backup of the `database` and files in the `storage` folder
2. Download the [update file](https://kondomanager.short.gy/km-installer) from the official Kondomanager website
3. Extract and upload the `index.php` file to the root of your server
4. Open your browser at: `https://yoursite.com/index.php`.
5. The system will automatically detect the previously installed version.
6. Click on **"Update now"** and follow the guided steps.

**What the system does automatically:**

- Automatic backup of the `.env` file.
- Download and installation of new core files.
- Restoration of data and configurations.
- Execution of database migrations.
- Cache cleanup and optimization.

**Important:** Do not close the browser page during the update process. When it finishes, the `index.php` file you uploaded deletes itself, for security; if it cannot, it is left as a file that answers “410 Gone” and you can delete it by hand. If the update stops with an error, the `index.php` file may stay on the server: delete it by hand.

**If you have ever run `php artisan optimize` on your server**, run this as well after the update:

```bash
php artisan optimize:clear
```

The updater clears the configuration cache but not the route cache: without this step the application keeps serving the previous version's route table, and new menu entries answer "page not found". If you have never run `optimize`, there is nothing to do.


---

## Manual Installation (For developers and advanced users)

If you want to contribute to the code or have full SSH access to the server.

### First installation on a server

`.env.example` already carries the values for a server: `APP_ENV=production` and `APP_DEBUG=false`. Do not turn debug on for an installation that others can reach: error pages would show the code, the queries and the request bodies.

1. **Clone the repository**
```bash
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

2. **Install the dependencies and build the interface**
```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

3. **Configure the environment**
```bash
cp .env.example .env
php artisan key:generate --force
```

Edit the `.env` file with the address of the installation (`APP_URL`) and your database parameters (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

4. **Install, with your administrator**
```bash
php artisan kondomanager:installa --admin-nome="Mario Rossi" --admin-email=mario@example.com --admin-password="a-password-of-at-least-10-characters"
```

The command creates the tables, the roles, the administrator with the data you pass, the initial data and the settings; on an installation already done it does nothing. The administrator data can also be passed with the `INSTALL_ADMIN_NAME`, `INSTALL_ADMIN_EMAIL` and `INSTALL_ADMIN_PASSWORD` variables (see `.env.example`), so the password does not stay in the shell history.

5. **Publish and start the background processes**

The web server must serve the `public/` folder. Set up the CronJob (see [CronJob Configuration](#cronjob-configuration)) and the queue worker (see step 3 of the manual update below).

### First installation for local development

1. **Clone the repository and install the dependencies**
```bash
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
composer install
npm install
```

2. **Configure the development environment**
```bash
cp .env.example .env
php artisan key:generate
```

In the `.env` file set `APP_ENV=local` and `APP_DEBUG=true`, and your database parameters (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

3. **Database Setup**
```bash
php artisan migrate
php artisan db:seed
```

4. **Start**
```bash
npm run dev
php artisan serve
```

Visit http://localhost:8000.

**Development credentials:** `admin@km.com` / `password`, only with `APP_ENV=local`. In any other environment `db:seed` creates the administrator with a random password and prints it only once. For development with Docker see [docs/docker_local_dev.en.md](docs/docker_local_dev.en.md): after a `git pull`, `docker compose up -d --build` rebuilds the image, and at every start the container runs the migrations and aligns roles and permissions, the list of municipalities, the ATECO classification and the recorded version.

---

### Manual Update (via SSH/Terminal)

If you prefer to update manually, strictly follow these steps to ensure compatibility with the versioning system:

> ⚠️ **If you are moving from 1.9.x to 1.10.0, `composer install` is not optional.** Version 1.10 moves Livewire from 3 to 4: while the dependencies are still the old ones, every `php artisan` command stops with `Attribute [livewire] does not exist`. The database is not touched — the error comes before the migrations — and the way out is to finish updating the dependencies. That is why the commands below are **a single chain**.

> ⚠️ **If you are moving from 1.10.0 to 1.11.0**, the chain uses `php artisan kondomanager:aggiorna` instead of `php artisan migrate --force`: with the migrations alone, the new «Importa dati» (import data) permission and the codes of the ATECO classification would be left behind. After the update check in the `.env` that `APP_URL` is the address you open the application with, with `https://` if the application runs on https: the links in the password reset and email verification e-mails use it. If the application sits behind a proxy, the recommended value for `TRUSTED_PROXIES` is `PRIVATE_SUBNETS`, which however does not cover a proxy with a public address: for that one you write the list of its addresses; `.env.example` advises against `*`.

1. **Database Backup (Recommended)**
```bash
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql
```

2. **The update, in a single command**

The steps are joined by `&&`: if composer or npm fail — it happens, through an interrupted download or not enough memory on the server — the chain stops there and **the migrations never run**. The `config:clear` before `kondomanager:aggiorna` is required because the settings migrations read the configuration. `kondomanager:aggiorna` runs the migrations and aligns roles and permissions, the list of municipalities, the ATECO classification and the recorded version, like the update from the panel.

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
 && echo "OK - update complete"
```

If `OK - update complete` does not appear, the update is not finished: read the last message, fix the problem and run the same chain again — it is safe to repeat. `queue:restart` makes the queue worker kept alive by Supervisor (step 3) start again with the new code; without a worker it does nothing. Roles and permissions, the list of municipalities and the ATECO classification, if they fail, write it to the log (`storage/logs`) without stopping the chain: if after the update a permission or an ATECO code is missing, look there and run `php artisan kondomanager:aggiorna` again.

3. **Configuration and Starting Queues** 

The system uses the database driver by default (you can also use Redis if you prefer) to manage background processes. It is necessary to start the worker to process queued tasks.
```bash
php artisan queue:work
```
**Note:** In production environment, it is recommended to configure Supervisor to keep the process running.

### Verify Installed Version

You can verify the current version and the functioning of configurations via Tinker:
```bash
php artisan tinker
>>> config('app.version')
```

---

## Useful Documents

- [Laravel Documentation](https://laravel.com/docs)
- [Vue.js Documentation](https://vuejs.org/guide/introduction.html)
- [Tailwind CSS Documentation](https://tailwindcss.com/docs)
- [Inertia.js Documentation](https://inertiajs.com/)
- [Spatie Laravel Settings](https://spatie.be/docs/laravel-settings)

---

## Community and Support

Do you have questions about the installation, want to share your experience or look for help from other users? Join the official KondoManager community:

**[Official KondoManager forum](https://kondomanager.short.gy/km-forum)**

The forum is the right place to:
- ask for technical support and configuration advice
- report problems and compare notes with other property managers
- propose new features and discuss the roadmap
- share configurations, integrations and best practices

---

## How to Contribute

Anyone who wants to contribute to growing the project is always welcome!

To contribute, it is recommended to follow the guidelines described in the [contributing guide](https://github.com/vince844/kondomanager-free/blob/main/CONTRIBUTING.md). On your first pull request you will be asked to sign the [Contributor License Agreement](https://cla-assistant.io/vince844/kondomanager-free): CLA assistant asks for it automatically, and you sign it only once. If you want to actively contribute with simple improvements or corrections, you can [search among the open issues](https://github.com/vince844/kondomanager-free/issues) or open a new topic on the [official KondoManager forum](https://kondomanager.short.gy/km-forum).

---

## Support the Project

Developing open source software requires a lot of commitment and dedication. I would be grateful if you decide to support the project.

[Support KondoManager on Patreon](https://www.patreon.com/KondoManager)

---

## Feedback & Support

- **Community:** Join the [official forum](https://kondomanager.short.gy/km-forum) to talk with other users and get support from the community.
- **Bugs and requests:** Use the ["Issues" or "Discussions"](https://github.com/vince844/kondomanager-free/issues) section of this repository.
- **Security vulnerabilities:** Do not open a public issue; report them privately as explained in [SECURITY.md](https://github.com/vince844/kondomanager-free/blob/main/SECURITY.md).
- **Dedicated support:** For customization requests or professional support, use the [contact form](https://dev.karibusana.org/gestionale-condominio-contatti.html) on the official website.

---

## License

This project is released under [AGPL-3.0](https://github.com/vince844/kondomanager-free?tab=AGPL-3.0-1-ov-file#readme) license.

---

## Credits

### Lead Developer:
- [Vincenzo Vecchio](https://github.com/vince844) - Project founder and main developer

### Contributors:

[![KondoManager contributors](https://contrib.rocks/image?repo=vince844/kondomanager-free)](https://github.com/vince844/kondomanager-free/graphs/contributors)

### Stargazers:
Thanks to everyone who starred the project!

[![KondoManager stargazers](https://kondomanager.com/assets/img/stargazers.svg)](https://github.com/vince844/kondomanager-free/stargazers)

### Patreon Supporters:
- **[Mittelcom](https://www.amministrazionitedaldimorea.it/)** — thank you for your support and for believing in the project!
