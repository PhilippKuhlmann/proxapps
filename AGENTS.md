# ProxApps

Laravel 13 + Livewire 4 + Tailwind 4 (Vite) app that deploys apps (catalog/<id>/app.yaml + install.sh)
as Debian LXC containers on Proxmox VE. UI follows the DokuVault design (~/code/dokumentation-os).

- Run tests: `php artisan test`
- Format: `vendor/bin/pint`
- Build assets: `npm run build`
- UI language: German. Code/comments: English.
- The Proxmox host 10.10.250.10 is production: only ever create new containers tagged `proxapps`; never touch others.
