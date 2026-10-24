<div class="space-y-4 text-sm">
    <p>
        Sign in to the server as root and run this command. It creates the
        <code class="font-mono">{{ $server->ssh_user }}</code> user, installs the panel's key and the
        <code class="font-mono">mandal</code> CLI from the latest release, and lets that user run only the CLI through sudo.
        Nothing else is changed.
    </p>

    <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100 select-all"><code>{{ $server->setupCommand() }}</code></pre>

    <p class="text-gray-500 dark:text-gray-400">
        The command can be run again safely; it skips what is already done. Once the connection test
        succeeds, this panel installs its own build of the CLI on the server.
    </p>
</div>
