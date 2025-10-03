<div class="h-full flex flex-col">
    <p class="mb-6">Questa procedura guidata ti guiderà attraverso il processo di installazione e configurazione di <strong>{{ $appName }}</strong>.</p>

    <p>Prima di iniziare, per favore assicurati di avere le seguenti informazioni e requisiti minimi del server.</p>

    <ul class="ps-6 list-decimal mt-6 space-y-6">
        <li>
            <strong>PHP:</strong> Il server deve avere installato la versione {{ $php }} o maggiore.
            <a class="link block" href="https://www.php.net/manual/en/function.phpversion.php">Come controllare la versione di PHP installata</a>
        </li>

        @isset($extensions)
            <li><strong>Extensioni:</strong> Assicurati di aver le seguenti estensioni abilitate nella configurazione di PHP.
                <p>{{ $extensions }}</p>
                <a class="link block" target="_blank" href="https://www.php.net/manual/en/function.extension-loaded.php">Come controllare quali estensioni PHP installate</a>
            </li>
        @endisset

        @if ($isDatabaseRequired)
            <li>
                <strong>Database:</strong> L'applicazione necessita di un database MySQL per la registrazione dei dati. Per favore assicurati di aver creato un database e di avere i seguenti dati: host, porta, nome del database, username e password.
                <a class="link block" target="_blank" href="https://support.cpanel.net/hc/en-us/articles/360057550753-How-to-create-a-database-and-database-user-in-cPanel">Come creare un database su cPanel</a>
            </li>
        @endif


        @if ($isMailRequired)
            <li>
                <strong>Impostazioni email:</strong> L'applicazione invia e-mail importanti come: registrazione utenti, impostazione password e notifiche. Per inviarle è necessario un servizio di posta elettronica valido. Assicurati di avere a portata di mano un indirizzo email e una password validi, in linea con la configurazione dell'host e della porta del server.
                <a class="link block" target="_blank"href="https://www.smtper.net/">Testa la configurazione SMTP</a>
            </li>
        @endif

        <li>
            <strong>Pulisci la cache:</strong> Se necessario pulisci la cache del server.
        </li>
    </ul>
</div>
