<div class="h-full flex flex-col">
    <p>{{ __('installer.welcome.before_start') }}</p>

    <ul class="ps-6 list-decimal mt-4 space-y-4">
        <li>
            <strong>{{ __('installer.requirements.php_version') }}:</strong> {{ __('installer.welcome.php_requirement', ['version' => $php]) }}
            <a class="link block" target="_blank" href="https://www.php.net/manual/en/function.phpversion.php">{{ __('installer.welcome.php_check_link') }}</a>
        </li>

        @isset($extensions)
            <li><strong>{{ __('installer.welcome.extensions_label') }}</strong> {{ __('installer.welcome.extensions_text') }}
                <p>{{ $extensions }}</p>
                <a class="link block" target="_blank" href="https://www.php.net/manual/en/function.extension-loaded.php">{{ __('installer.welcome.extensions_check_link') }}</a>
            </li>
        @endisset

        @if ($isDatabaseRequired)
            <li>
                <strong>{{ __('installer.welcome.database_label') }}</strong> {{ __('installer.welcome.database_text') }}
                <a class="link block" target="_blank" href="https://support.cpanel.net/hc/en-us/articles/360057550753-How-to-create-a-database-and-database-user-in-cPanel">{{ __('installer.welcome.database_link') }}</a>
            </li>
        @endif

        @if ($isMailRequired)
            <li>
                <strong>{{ __('installer.welcome.mail_label') }}</strong> {{ __('installer.welcome.mail_text') }}
                <a class="link block" target="_blank" href="https://www.smtper.net/">{{ __('installer.welcome.mail_link') }}</a>
            </li>
        @endif

        <li>
            <strong>{{ __('installer.welcome.cache_label') }}</strong> {{ __('installer.welcome.cache_text') }}
        </li>
    </ul>
</div>
