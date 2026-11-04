{{-- The steps that install a newer build of the panel from a shell, shown when the panel does not know which server hosts it. --}}
<div class="space-y-4 text-sm">
    <p>
        <span class="font-medium">{{ $update->label() }}</span> is available; this panel runs
        <span class="font-mono">{{ $running }}</span>. No server here is known to host the panel, so the update
        runs from a shell on that server.
    </p>

    <p>
        Sign in to that server as the <code class="font-mono">mandal</code> user. Root logins are disabled once a
        server is provisioned, so your own public key must be allowed first with <strong>Add key</strong> on its
        <strong>Security &gt; SSH</strong> page. Then run:
    </p>

    <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100 select-all"><code>ssh mandal@&lt;server address&gt;
{{ $update->command() }}</code></pre>

    <p class="text-gray-500 dark:text-gray-400">
        It installs that build's CLI, points the panel app at <span class="font-mono">{{ $update->ref }}</span> and deploys it;
        the panel is back a few seconds after the deploy switches over. Run it again if it is interrupted; every step is
        skipped when already done.
    </p>

    <p class="text-gray-500 dark:text-gray-400">
        A panel set up with <code class="font-mono">install.sh</code> registers its own server at 127.0.0.1, and any
        server's inventory says when it hosts the panel; a panel run from a checkout is updated with
        <code class="font-mono">git pull</code> instead.
    </p>
</div>
