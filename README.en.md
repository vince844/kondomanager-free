[![en](https://img.shields.io/badge/lang-en-red.svg)](https://github.com/vince844/kondomanager-free/blob/main/README.en.md)
[![it](https://img.shields.io/badge/lang-it-green.svg)](https://github.com/vince844/kondomanager-free/blob/main/README.md)
[![pt-br](https://img.shields.io/badge/lang-pt--br-yellow.svg)](https://github.com/vince844/kondomanager-free/blob/main/README.pt-br.md)

# KondoManager - Condominium Management

KondoManager is an innovative open source software for condominium management, built with Laravel and MySQL database, designed for condominium administrators as well as condominium users.

## Screenshots

<table>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-3.png" alt="Dashboard" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-2.png" alt="Fault reports" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-1.png" alt="Condominium board" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-6.png" alt="Document archive" width="100%"></td>
  </tr>
  <tr>
    <td><img src="https://dev.karibusana.org/github/Screenshot-4.png" alt="Condominium calendar" width="100%"></td>
    <td><img src="https://dev.karibusana.org/github/Screenshot-5.png" alt="User and permission management" width="100%"></td>
  </tr>
</table>

## Try the KondoManager demo
You can view a demo of the project by going to the following address [KondoManager Demo](https://rebrand.ly/kondomanager) 

Please note that for security reasons some features have been disabled. You can login with the following credentials:
```
Login as administrator:
email: admin@kondomanager.it
password: Pa$$w0rd!

Login as user:
email: user@kondomanager.it
password: Pa$$w0rd!
```

## Management software features

- Condominium management
- Registry management
- Fault report management
- Condominium board management
- Document archive and category management
- Calendar deadline management
- User management
- Role and permission management
- Email notifications
- Management module
  - Building management
  - Staircase management
  - Property management
  - Thousandth tables
  - Fiscal year management
  - Ordinary and extraordinary management
  - Chart of accounts creation (expense budget)
  - Installment plan creation

## Minimum requirements

    PHP >= 8.2
    MySQL Database
    Node.js & NPM (For manual installation)
    Composer (For manual installation)

## Guided installation of the management software

For less experienced users who want to install KondoManager on a shared server, we have created a guided wizard for the installation process.
Download the [installation file](https://kondomanager.short.gy/installer) and upload the index.php file to your server, then follow the guided installation process. For more information visit the [official guide](https://www.kondomanager.com/docs/installation.html) page.

## Manual installation of the management software

1. Clone the repository
```bash
https://github.com/vince844/kondomanager-free.git
```

2. Install libraries
```bash
composer install
npm install
```

3. Generate the .env file
```bash
cp .env.example .env
php artisan key:generate
```

4. Configure the MySQL database in the .env file
```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

5. Configure the SMTP server in the .env file
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

6. Run database migrations
```bash
php artisan migrate
```

7. Populate the database with default configurations
```bash
php artisan db:seed
```

8. Start development servers
```bash
npm run dev
php artisan serve
```

🎉 That's it! Visit http://localhost:8000 to start working with KondoManager.

If necessary, configure APP_URL in the .env file specifying the port
```bash
APP_URL=http://localhost:8000
```

To access the administration panel use the following credentials:
```bash
Email address: admin@km.com
Password: password
```

Remember to change the email address and password at first login by going to /settings/profile

## Useful documents

- [Laravel Documentation](https://laravel.com/docs)
- [Vue.js Documentation](https://vuejs.org/guide/introduction.html)
- [Tailwind CSS Documentation](https://tailwindcss.com/docs)
- [Inertia.js Documentation](https://inertiajs.com/)
- [TanStack Table Documentation](https://tanstack.com/table/v8)

## How to contribute to the project

Anyone who wants to contribute to the growth of the project is always welcome!

To contribute, it is recommended to follow the instructions described in the [official documentation](https://github.com/vince844/kondomanager-free/blob/main/CONTRIBUTING).

If you want to actively contribute with simple improvements or corrections you can [search through the issues](https://github.com/vince844/kondomanager-free/issues).

## Support the project

Developing open source software requires a lot of effort and dedication. I would be grateful if you decide to support the project. [Support KondoManager on Patreon](https://www.patreon.com/KondoManager)

## Feedback

Anyone who wants to send feedback or suggestions for improvements can do so in the appropriate section within this repository or open a [ticket on uservoice](https://feedback.userreport.com/92d7d7e1-d2e5-4654-a90d-066dd5d2fe10/#ideas/popular)

## Support

For support or code customization requests, you can contact me using the appropriate [contact form](https://dev.karibusana.org/gestionale-condominio-contatti.html)

## License

[AGPL-3.0](https://github.com/vince844/kondomanager-free?tab=AGPL-3.0-1-ov-file#readme)

## Credits

### Lead Developer
- **Vincenzo Vecchio** - Project founder and main developer

### Contributors

Thanks to these amazing people who have contributed to this project:

- [Amnit Haldar](https://github.com/amit-eiitech) - Amazing laravel developer
