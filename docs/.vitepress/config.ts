import { defineConfig } from 'vitepress'

export default defineConfig({
    title: 'Mandal',
    description: 'A self-hosted panel for managing Laravel and Docker servers over SSH.',
    base: '/mandal/',
    lastUpdated: true,
    cleanUrls: true,
    head: [['link', { rel: 'icon', href: '/mandal/favicon.ico' }]],
    themeConfig: {
        nav: [
            { text: 'Guide', link: '/guide/getting-started' },
            { text: 'Reference', link: '/reference/cli' },
            { text: 'Contributing', link: '/contributing/' },
            { text: 'Changelog', link: 'https://github.com/ReyArlena/mandal/blob/main/CHANGELOG.md' },
        ],
        sidebar: {
            '/guide/': [
                {
                    text: 'Guide',
                    items: [
                        { text: 'Getting started', link: '/guide/getting-started' },
                        { text: 'Servers', link: '/guide/servers' },
                        { text: 'Laravel apps', link: '/guide/apps' },
                        { text: 'Docker apps', link: '/guide/docker-apps' },
                        { text: 'Databases and backups', link: '/guide/databases' },
                        { text: 'PHP, firewall, updates and logs', link: '/guide/maintenance' },
                        { text: 'Hosting the panel', link: '/guide/self-hosting' },
                    ],
                },
            ],
            '/reference/': [
                {
                    text: 'Reference',
                    items: [
                        { text: 'Server CLI', link: '/reference/cli' },
                        { text: 'Files changed on the server', link: '/reference/server-files' },
                    ],
                },
            ],
            '/contributing/': [
                {
                    text: 'Contributing',
                    items: [
                        { text: 'Running the project locally', link: '/contributing/' },
                        { text: 'Testing with Incus', link: '/contributing/incus' },
                        { text: 'Releasing', link: '/contributing/releasing' },
                    ],
                },
            ],
        },
        socialLinks: [{ icon: 'github', link: 'https://github.com/ReyArlena/mandal' }],
        editLink: {
            pattern: 'https://github.com/ReyArlena/mandal/edit/main/docs/:path',
            text: 'Edit this page on GitHub',
        },
        search: { provider: 'local' },
        footer: {
            message: 'Released under the MIT License.',
        },
    },
})
