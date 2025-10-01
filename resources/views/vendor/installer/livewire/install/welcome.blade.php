<div class="h-full flex flex-col">
    <p class="mb-6">This wizard will guide you through the setup process for <strong>{{ $appName }}</strong>.</p>

    <p>Before we start, please make sure you have the following information and requirements are with you.</p>

    <ul class="ps-6 list-decimal mt-6 space-y-6">
        <li>
            <strong>PHP:</strong> Your server must have **PHP version {{ $php }} or higher.
            <a class="link block" href="#">How to check my PHP version</a>
        </li>

        @isset($extensions)
            <li><strong>Extensions:</strong> Please be sure the following extensions are enabled in you php configuration.
                <p>{{ $extensions }}</p>
                <a class="link block" href="#">How to enable a PHP extension</a>
            </li>
        @endisset

        @if ($isDatabaseRequired)
            <li>
                <strong>Database:</strong> The application needs a database to record data. Please have your **database name, username, and password** ready with you.
                <a class="link block" href="#">Learn how to create a database</a>
            </li>
        @endif


        @if ($isMailRequired)
            <li>
                <strong>Mail Settings:</strong> The application sends important emails, such as password resets and notifications. You will need a valid **email service** to send these. Please ensure you have a valid email id and password ready aling with server host and port configuration.
                <a class="link block" href="#">How to know email server configurations</a>
            </li>
        @endif

        <li>
            <strong>Clear Cache:</strong> Clear any cache memory in server.
        </li>
    </ul>
</div>
