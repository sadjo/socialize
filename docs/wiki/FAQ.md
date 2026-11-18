# FAQ and Troubleshooting

### The container stops with "APP_SECRET est obligatoire"
`APP_SECRET` is required in production. Generate one with `openssl rand -hex 32` and put it in `docker.env`.

### Caddy cannot obtain a certificate
Ports 80 and 443 must be reachable from the Internet and the domain must point to the server. Behind a reverse
proxy, use `SERVER_NAME=:80` (see [Installation](Installation#behind-a-reverse-proxy)).

### Visitors only see a login form
That is the default (`HOME_LOGIN_ONLY=1`). Set `HOME_LOGIN_ONLY=0` to show the public home page and feeds.

### Nobody can sign up
With `REGISTRATION=invite`, only invited people can join: create invitations in *Administration › Invitations*.
Use `REGISTRATION=open` for an open instance.

### A user cannot post
New accounts have no publishing permission: grant *Publication* in *Administration › Comptes*.

### Videos stay "pending"
Videos are converted by the background workers. Check that `WORKERS` is not `0` (or that worker containers run) and
look at the logs: `docker compose logs app`. `messenger:stats` shows the pending messages.

### Invitation e-mails are not sent
Configure `MAILER_DSN` and `MAILER_FROM` ([Configuration](Configuration#e-mail)). Special characters in the SMTP
user name or password must be URL-encoded. Invitation links can always be copied and shared manually.

### Push notifications do not arrive on iPhone
Add the site to the home screen first (*Share › Add to Home Screen*), open it from there, then enable notifications
in *Notifications › Sur cet appareil*. HTTPS is required.

### My Mastodon friends cannot find my account
Search for the full address `@you@your-domain`. The instance must be reachable over HTTPS and `DEFAULT_URI` must be
the public address. See [Federation › Troubleshooting](Federation#troubleshooting).

### I changed my domain name and federation broke
Remote servers identify accounts by their address: changing `DEFAULT_URI` creates new identities. Go back to the
previous domain, or start the federation over with the new one.

### I lost APP_SECRET
Account keys, VAPID keys and connection tokens can no longer be decrypted. Restore it from a backup of `docker.env`;
otherwise remote followers and connected services will have to be set up again.

### How do I see the version I run?
At the bottom of the side menu, or with `docker compose images`.

### Is the interface available in English?
Not yet: the interface is in French. Contributions to extract and translate the strings are welcome.
