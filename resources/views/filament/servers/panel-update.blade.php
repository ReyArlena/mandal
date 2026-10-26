<div class="space-y-4 text-sm">
    <p>
        This panel runs Mandal <span class="font-mono">{{ $current }}</span>; release
        <span class="font-mono">{{ $latest }}</span> is available. The update runs on the server itself,
        because deploying the panel restarts the worker that would otherwise drive it from here.
    </p>

    <p>
        Sign in to the server as the <code class="font-mono">{{ $server->ssh_user }}</code> user. Root logins are
        disabled once the server is provisioned, so your own public key must be allowed first with
        <strong>Grant access</strong> on this page. Then run:
    </p>

    <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100 select-all"><code>ssh {{ $server->ssh_user.'@'.($server->host === '127.0.0.1' ? '<server address>' : $server->host) }}
sudo mandal panel:update</code></pre>

    <p class="text-gray-500 dark:text-gray-400">
        It installs the release's CLI, points the panel app at the release tag and deploys it; the panel is back
        a few seconds after the deploy switches over. Run it again if it is interrupted; every step is skipped
        when already done.
    </p>
</div>
