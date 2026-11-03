{{-- The steps that install a newer release of the panel, run on the server that hosts it. --}}
@php
    $user = $server?->ssh_user ?? 'mandal';
    $address = $server === null || $server->host === '127.0.0.1' ? '<server address>' : $server->host;
@endphp

<div class="space-y-4 text-sm">
    <p>
        Release <span class="font-mono">{{ $latest }}</span> is available; this panel runs
        <span class="font-mono">{{ $current }}</span>. The update runs on the server that hosts the panel,
        because deploying the panel restarts the worker that would otherwise drive it from here.
    </p>

    <p>
        Sign in to {{ $server === null ? 'that server' : $server->name }} as the <code class="font-mono">{{ $user }}</code> user.
        Root logins are disabled once a server is provisioned, so your own public key must be allowed first with
        <strong>Add key</strong> on its <strong>Security &gt; SSH</strong> page. Then run:
    </p>

    <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100 select-all"><code>ssh {{ $user.'@'.$address }}
sudo mandal panel:update</code></pre>

    <p class="text-gray-500 dark:text-gray-400">
        It installs the release's CLI, points the panel app at the release tag and deploys it; the panel is back
        a few seconds after the deploy switches over. Run it again if it is interrupted; every step is skipped
        when already done.
    </p>

    @if ($server === null)
        <p class="text-gray-500 dark:text-gray-400">
            No server here is known to host the panel. A panel set up with <code class="font-mono">install.sh</code> registers
            its own server at 127.0.0.1; a panel run from a checkout is updated with <code class="font-mono">git pull</code> instead.
        </p>
    @endif
</div>
