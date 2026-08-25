[![Read in English](https://img.shields.io/badge/Read_in-English-red.svg)](README.en.md)
[![Leggi in Italiano](https://img.shields.io/badge/Leggi_in-Italiano-green.svg)](README.md)
[![Leia em Português](https://img.shields.io/badge/Leia_em-Português-yellow.svg)](README.pt-br.md)
[![Generic badge](https://img.shields.io/badge/Version-1.9.1-blue.svg)](https://github.com/vince844/kondomanager-free/releases)
[![License](https://img.shields.io/badge/License-AGPL_3.0-blue.svg)](https://opensource.org/licenses/AGPL-3.0)

# KondoManager - Free and Open Source Condominium Management Software

**KondoManager** is the first open source, self-hosted condominium management software, built with **Laravel, Vue 3, Inertia.js and MySQL**. Designed for property managers who need a serious tool: double-entry bookkeeping, legally validated instalment plans, a digital resident portal and automatic updates — no subscriptions, no vendor lock-in.

---

## Screenshots

<table>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-3.png" alt="Dashboard" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-2.png" alt="Fault reporting" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-1.png" alt="Condominium bulletin board" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-6.png" alt="Document archive" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-4.png" alt="Condominium calendar" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-5.png" alt="User and permission management" width="100%"></td>
  </tr>
</table>

---

## Try the Demo

You can view a demo of the project at the following address:

👉 **[KondoManager demo](https://rebrand.ly/kondomanager)**

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
- Localization: Italian, English, Portuguese
- Import an entire condominium from another management program (first source: Danea Domustudio)
- Full backup and restore from the panel, resumable and encryptable in AES-256

### Accounting and Structure Module

- Management of buildings, stairways and properties
- Condominium bank accounts
- Unlimited millage tables
- Financial year management
- Ordinary and extraordinary management
- Chart of accounts creation
- Installment plan generation with advanced recurrences
- Receipt registration with automatic or manual allocation
- Double-entry bookkeeping
- Smart installment issuance
- Account statement
- Smart inbox for interactive calendar deadlines
- Supplier invoice payment module with bulk transfers, reversals and immutable Ledger
- Treasury Guardian widget for 30-day predictive liquidity analysis
- Dynamic cascading engine (Tenant → Usufructuary → Owner) in compliance with the Civil Code
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
- **Budget Allocation by Table (Installments):** detailed document (A4/A3) crossing properties, subjects (owners, tenants, etc.) and millesimal tables
- **Installment Schedules:** dynamic aggregation by Resident or Property
- **Supplier Payment Slips:** payment summaries with details for bank transfers

---

## Minimum Requirements

To install KondoManager, your server environment must meet the following requirements:

- **PHP** >= 8.4.1 — *from version 1.10.0; earlier versions ran on PHP 8.2*
- **Database:** MySQL 5.7+ or MariaDB 10.3+
- **PHP Extensions:** `zip`, `curl`, `bcmath`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `dom`, `xml` - `gd` is what generates the PDFs: without it the program installs but produces no reports
- **For manual installation:** Node.js & NPM, Composer

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

The automatic update system automatically manages the update lifecycle, ensuring data security with just a few clicks directly from the administration panel.

**Warning:** If you don't configure `CronJob` processes, automatic updates will not work.

**How to Configure CronJob**

Access your hosting panel (cPanel, Plesk) in the "Cron Jobs" or "Task Scheduler" section. Set execution every minute (* * * * *).

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

Make sure to use the absolute path to the PHP 8.4+ executable, for example
/usr/local/bin/ea-php84 /home/yoursite/domain_path/path/to/cron/script 

In the previous example, replace "ea-php99" with the PHP version assigned to the domain you want to use. Check in MultiPHP Manager for the PHP version actually assigned to a domain.

### 3. Update manually from Version 1.9.1 to 1.10.0

Automatic updates are available starting from version 1.9.0. The steps below do not depend on the version you are coming from: they are the same for any manual update from 1.8.0 onwards.

1. Make sure you have a backup of the `database` and files in the `storage` folder
2. Download the [update file](https://kondomanager.short.gy/km-installer) from the official Kondomanager website
3. Upload the `index.php` file to the root of your server
4. Open your browser at: `https://yoursite.com/index.php`.
5. The system will automatically detect the previously installed version.
6. Click on **"Update now"** and follow the guided steps.

**What the system does automatically:**

- Automatic backup of the `.env` file.
- Download and installation of new core files.
- Restoration of data and configurations.
- Execution of database migrations.
- Cache cleanup and optimization.

**Important:** Do not close the browser page during the update process. The `index.php` file will self-delete at the end of the operation for security.

---

## Manual Installation (For developers and advanced users)

If you want to contribute to the code or have full SSH access to the server.

### First Installation

1. **Clone the repository**
```bash
git clone https://github.com/vince844/kondomanager-free.git
cd kondomanager-free
```

2. **Install dependencies**
```bash
composer install
npm install
```

3. **Configure the environment**
```bash
cp .env.example .env
php artisan key:generate
```

Edit the `.env` file by entering your database parameters (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

4. **Database Setup**
```bash
php artisan migrate
php artisan db:seed
```

5. **Start**
```bash
npm run dev
php artisan serve
```

Visit http://localhost:8000.

**Default Credentials:** `admin@km.com` / `password` (Remember to change them immediately by going to your profile `/settings/profile`).

---

### Manual Update (via SSH/Terminal)

If you prefer to update manually, strictly follow these steps to ensure compatibility with the versioning system:

1. **Database Backup (Recommended)**
```bash
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql
```

2. **Update code and dependencies**
```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm install && npm run build
```

3. **CRITICAL STEP**

It is essential to clear the configuration cache before migrating, especially for the new versioning settings system:
```bash
php artisan config:clear
```

4. **Migration and optimization**
```bash
php artisan migrate --force
php artisan optimize:clear
php artisan storage:link
```

5. **Configuration and Starting Queues** 

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

## How to Contribute

Anyone who wants to contribute to growing the project is always welcome!

To contribute, it is recommended to follow the guidelines described in the [official documentation](https://github.com/vince844/kondomanager-free/blob/main/CONTRIBUTING). If you want to actively contribute with simple improvements or corrections, you can [search among the open issues](https://github.com/vince844/kondomanager-free/issues).

---

## Support the Project

Developing open source software requires a lot of commitment and dedication. I would be grateful if you decide to support the project.

[Support KondoManager on Patreon](https://www.patreon.com/KondoManager)

---

## Feedback & Support

- **Feedback:** Use the ["Issues" or "Discussions"](https://github.com/vince844/kondomanager-free/issues) section of this repository.
- **Support:** For customization requests or dedicated support, use the [contact form](https://dev.karibusana.org/gestionale-condominio-contatti.html) on the official website.

---

## License

This project is released under [AGPL-3.0](https://github.com/vince844/kondomanager-free?tab=AGPL-3.0-1-ov-file#readme) license.

---

## Credits

### Lead Developer:
- [Vincenzo Vecchio](https://github.com/vince844) - Project founder and main developer

### Contributors:
- [Amnit Haldar](https://github.com/amit-eiitech) - For his valuable contribution to creating the guided installation
- [k3ntinhu](https://github.com/k3ntinhu) - For his valuable contribution to Docker container configuration and the Portuguese community
- [Stefano B](https://github.com/borghiste) - For reporting and fixing a security bug
- All contributors and developers of the open source community.

### Patreon Supporters:
- **[Fabio Lembo Luscari]** — thank you for your support and for believing in the project!
- **[Mittelcom](https://www.amministrazionitedaldimorea.it/)** — thank you for your support and for believing in the project!

---