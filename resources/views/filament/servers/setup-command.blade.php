<div class="space-y-4 text-sm">
    <p>
        Sign in to the server as root and run this command. It creates the
        <code class="font-mono">{{ $server->ssh_user }}</code> user, installs the panel's key and the
        <code class="font-mono">mandal</code> CLI, and lets that user run only the CLI through sudo.
        Nothing else is changed.
    </p>

    <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100 select-all"><code>{{ $command }}</code></pre>

    <p class="text-gray-500 dark:text-gray-400">
        The link is valid for {{ \App\Models\Server::SETUP_TOKEN_HOURS }} hours and stops working after the
        first successful connection. If the server cannot reach this panel,
        <a href="{{ $scriptUrl }}" target="_blank" rel="noopener" class="font-medium text-primary-600 underline dark:text-primary-400">open the script</a>
        and paste it into a root shell instead; it will still need to download the CLI from this panel.
    </p>
</div>
