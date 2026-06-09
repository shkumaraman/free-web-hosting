<p align="center">
  <img src="/3a87c554-19da-4aba-bb2e-33a950476d90.png" alt="Banner">
</p>

<div align="center">

<img src="https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white" />
<img src="https://img.shields.io/badge/Apache-Web%20Server-D22128?style=for-the-badge&logo=apache&logoColor=white" />
<img src="https://img.shields.io/badge/MariaDB-Database-003545?style=for-the-badge&logo=mariadb&logoColor=white" />
<img src="https://img.shields.io/badge/Alpine-Linux-0D597F?style=for-the-badge&logo=alpine-linux&logoColor=white" />
<img src="https://img.shields.io/badge/FFmpeg-Media%20Toolkit-007808?style=for-the-badge&logo=ffmpeg&logoColor=white" />
<img src="https://img.shields.io/badge/Hugging%20Face-Spaces-FFD21E?style=for-the-badge&logo=huggingface&logoColor=black" />
<img src="https://img.shields.io/badge/Cloudflare-Workers-F38020?style=for-the-badge&logo=cloudflare&logoColor=white" />
<img src="https://img.shields.io/badge/Web%20Terminal-Integrated-4d4d4d?style=for-the-badge&logo=gnu-bash&logoColor=white" />
<img src="https://img.shields.io/badge/License-MIT-22c55e?style=for-the-badge" />

# 🐘 PHP Web Server — Alpine LAMP Stack

### ⚡ Lightweight · 🔧 Developer-Friendly · 🚀 HF Spaces Ready

> A complete PHP development environment packed into a **single Docker container** — Apache, PHP 8.x, MariaDB, phpMyAdmin, FFmpeg, and a File Manager with optional integrated Web Terminal support.  
> Deploy on **Hugging Face Spaces** for free, or on any **VPS / local machine**.

</div>

---

## 📋 Table of Contents

- [✨ Features](#-features)
- [📦 What's Inside](#-whats-inside)
- [🐘 PHP Extensions](#-php-extensions)
- [☁️ Deploy on Hugging Face Spaces](#-deploy-on-hugging-face-spaces) ⭐ Recommended
- [🖥️ Deploy on VPS / Local Machine](#-deploy-on-vps--local-machine)
- [🌐 Access URLs](#-access-urls)
- [🗄️ Database Setup](#-database-setup)
- [💾 Persistent Storage](#-persistent-storage)
- [⚙️ Environment Variables & .env](#-environment-variables--env)
- [📁 File Manager](#-file-manager)
- [💻 Web Terminal](#-web-terminal)
- [🔀 Custom Domain via Cloudflare Workers](#-custom-domain-via-cloudflare-workers)
- [🔒 Security Notes](#-security-notes)
- [💡 Pro Tips](#-pro-tips)
- [🤝 Contributing](#-contributing)

---

## ✨ Features

| Feature | Description |
|---|---|
| 🐘 **PHP 8.x** | PHP runtime with OPcache pre-enabled for better performance |
| 🌐 **Apache** | Configured with `mod_rewrite` and `.htaccess` support |
| 🗄️ **MariaDB** | Full database engine with phpMyAdmin UI at `/sql` |
| 📁 **File Manager** | Custom File Manager at `/files` |
| 💻 **Web Terminal** | Browser-based shell support through the bundled File Manager |
| ⚙️ **.env Support** | Auto-loads `/var/www/localhost/htdocs/.env` at startup |
| 💾 **Persistent Storage** | `/data` mount recommended/required for preserving database and site files |
| 🔒 **Non-root** | Runs as user `1000` for improved container security |
| 🐳 **Alpine Base** | Lightweight Linux base image |
| 🎬 **FFmpeg** | Audio/video processing toolkit for media workflows |
| ☁️ **HF Spaces Ready** | Designed for Hugging Face Docker Spaces |
| 🔀 **Custom Domain** | Optional custom domain proxy via Cloudflare Workers |

> ⚠️ This image is best suited for development, demos, personal hosting, and small self-hosted apps. If exposed publicly, change all default credentials and hide/protect admin URLs.

---

## 📦 What's Inside

```txt
Alpine Linux latest
├── Apache 2             → Web server on port 7860
├── PHP 8.x              → PHP runtime with common extensions
├── MariaDB              → Database server
├── phpMyAdmin           → Database UI at /sql
├── File Manager         → Custom File Manager at /files
├── Web Terminal         → Integrated if supported by bundled File Manager
├── Composer             → PHP dependency manager
├── Git                  → Version control
├── Nano                 → Terminal text editor
├── Curl                 → HTTP/file download tool
├── Wget                 → HTTP/file download tool
├── Zip / Unzip          → Archive creation and extraction
├── libarchive-tools     → Additional archive extraction support
├── ImageMagick          → Advanced image processing
├── FFmpeg               → Audio/video processing
└── Tini                 → Minimal init system for clean process handling

```
## 🐘 PHP Extensions
| Extension | Purpose |
|---|---|
| **php-mysqli** | MySQL / MariaDB direct connection |
| **php-pdo** | PHP Data Objects — database abstraction base |
| **php-pdo_mysql** | PDO driver for MySQL / MariaDB |
| **php-mbstring** | Multibyte string handling — required by many frameworks |
| **php-xml** | Core XML support — required for XML parsing and related XML modules |
| **php-simplexml** | Simple XML object interface — used by WordPress and APIs |
| **php-dom** | Full DOM XML/HTML parsing — required by Laravel and Symfony |
| **php-xmlwriter** | Writing XML documents programmatically |
| **php-xmlreader** | Streaming XML reader for large files |
| **php-xsl** | XSLT transformations |
| **php-gd** | Image creation and manipulation: resize, crop, watermark |
| **php-imagick** | Advanced image processing via ImageMagick |
| **php-exif** | Read image metadata such as camera, GPS, and dimensions |
| **php-curl** | HTTP requests — required by APIs, Guzzle, SDKs |
| **php-session** | PHP session management |
| **php-opcache** | Bytecode caching for faster PHP execution |
| **php-phar** | PHP Archive support — required by Composer |
| **php-openssl** | SSL/TLS encryption, JWT, and secure hashing |
| **php-sodium** | Modern cryptography library |
| **php-iconv** | Character encoding conversion |
| **php-zip** | Create and extract ZIP archives |
| **php-bz2** | Bzip2 compression support |
| **php-intl** | Internationalization — dates, currencies, locales |
| **php-gettext** | Translations and i18n support |
| **php-bcmath** | Arbitrary precision math — used by payment gateways |
| **php-gmp** | GNU Multiple Precision — cryptography and big numbers |
| **php-apcu** | In-memory user cache for repeated operations |
| **php-redis** | Redis cache and session driver |
| **php-soap** | SOAP web services client/server support |
| **php-ldap** | LDAP authentication and directory services |
| **php-ctype** | Character type checking functions |
| **php-fileinfo** | Detect file MIME types |
| **php-tokenizer** | PHP code tokenizer — required by Composer and Laravel |
| **php-sockets** | Low-level socket programming and WebSocket support |
| **php-posix** | POSIX process functions |
| **php-pcntl** | Process control — fork, signals, process management |
| **php-ftp** | FTP client functions |
| **php-calendar** | Calendar and date conversion functions |
| **php-shmop** | Shared memory read/write |
| **php-sysvmsg** | System V message queues |
| **php-sysvsem** | System V semaphores |
| **php-sysvshm** | System V shared memory |
| **php-tidy** | HTML cleanup and repair |
## ☁️ Deploy on Hugging Face Spaces
> ⭐ **Recommended** — free hosting, no server required.
> 
### Step 1 — Create a New Space
 1. Go to huggingface.co/spaces
 2. Click **Create new Space**
 3. Give your Space a name, for example my-php-server
 4. Select **SDK → Docker**
 5. Choose Space visibility
 6. Click **Create Space**
> Public Spaces are easiest for free hosting. Private Spaces may have different availability depending on your Hugging Face plan.
> 
### Step 2 — Upload the Dockerfile
Only the Dockerfile is needed in your Space repository:
```txt
your-space/
└── Dockerfile    ✅ only this file is needed here

```
You can drag and drop the Dockerfile in the **Files** tab, or push via Git:
```bash
git clone [https://huggingface.co/spaces/YOUR_USERNAME/YOUR_SPACE_NAME](https://huggingface.co/spaces/YOUR_USERNAME/YOUR_SPACE_NAME)
cd YOUR_SPACE_NAME

git add Dockerfile
git commit -m "Add Dockerfile"
git push

```
> ⚠️ Do **not** place your project files directly in the Space repository.
> Once the Space is live, upload your project using the File Manager at /files.
> 
Recommended upload path:
```txt
/var/www/localhost/htdocs

```
### Step 3 — Set Environment Variables
On Hugging Face, environment variables are configured in **Space Settings**, not in the command line.
Open your Space:
```txt
Settings → Variables and Secrets

```
Add these variables:
| Variable | Default | Description |
|---|---|---|
| MYSQL_USER | admin | Database username |
| MYSQL_PASSWORD | admin | Database password — change this |
| MYSQL_DATABASE | admin | Default database name |
| SQL_PATH | sql | URL path for phpMyAdmin |
| FILES_PATH | files | URL path for File Manager |
Example:
```env
MYSQL_USER=admin
MYSQL_PASSWORD=strong-password-here
MYSQL_DATABASE=mydb
SQL_PATH=mysecretdb
FILES_PATH=mysecretfiles

```
### Step 4 — Mount Persistent Storage
> 🚨 This step is highly recommended. Without persistent storage, your database and uploaded files may reset when the Space restarts or rebuilds.
> 
Go to:
```txt
Settings → Persistent Storage

```
Add storage with:
| Field | Value |
|---|---|
| **Permission** | Read & Write |
| **Mount path** | /data |
| **Visibility** | Private |
### Step 5 — Done
Hugging Face will build and deploy automatically.
Your live URL:
```txt
https://YOUR_USERNAME-YOUR_SPACE_NAME.hf.space/

```
## 🖥️ Deploy on VPS / Local Machine
### Prerequisites
 * Docker installed
### Step 1 — Clone and Build
```bash
git clone [https://github.com/YOUR_USERNAME/YOUR_REPO_NAME.git](https://github.com/YOUR_USERNAME/YOUR_REPO_NAME.git)
cd YOUR_REPO_NAME

docker build -t php-lamp .

```
### Option A — Docker Run
```bash
docker run -d \
  -p 7860:7860 \
  -v php_lamp_data:/data \
  -e MYSQL_USER=admin \
  -e MYSQL_PASSWORD=yourpassword \
  -e MYSQL_DATABASE=mydb \
  -e SQL_PATH=sql \
  -e FILES_PATH=files \
  --name php-lamp \
  php-lamp

```
### Option B — Docker Compose
```yaml
services:
  php-lamp:
    build: .
    ports:
      - "7860:7860"
    environment:
      MYSQL_USER: admin
      MYSQL_PASSWORD: yourpassword
      MYSQL_DATABASE: mydb
      SQL_PATH: sql
      FILES_PATH: files
    volumes:
      - php_lamp_data:/data
    restart: unless-stopped

volumes:
  php_lamp_data:

```
Run:
```bash
docker compose up -d

```
### Access Your Server
```txt
http://localhost:7860/      → Local machine
http://YOUR_VPS_IP:7860/   → Remote VPS

```
On VPS, open port 7860 in your firewall:
```bash
sudo ufw allow 7860

```
## 🌐 Access URLs
| Tool | Default URL | Env Variable |
|---|---|---|
| 🏠 **Website** | / | — |
| 🗄️ **Database UI** | /sql | SQL_PATH |
| 📁 **File Manager** | /files | FILES_PATH |
Web root directory:
```txt
/var/www/localhost/htdocs

```
All tool paths are customizable via environment variables. For example:
```env
SQL_PATH=x7k2mdb
FILES_PATH=x7k2files

```
Then access:
```txt
/sql       ❌ no longer used
/files     ❌ no longer used
/x7k2mdb   ✅ phpMyAdmin
/x7k2files ✅ File Manager

```
## 🗄️ Database Setup
### Default Credentials
```txt
Username : admin
Password : admin
Database : admin
Host     : 127.0.0.1
Port     : 3306

```
> ⚠️ Change the password before exposing your app publicly.
> 
### How to Change Credentials
**On Hugging Face:**
```txt
Space → Settings → Variables and Secrets

```
**On VPS / Local:**
Update your docker run command or docker-compose.yml.
Example:
```env
MYSQL_USER=myuser
MYSQL_PASSWORD=very-strong-password
MYSQL_DATABASE=myapp

```
### PHP Database Connection Example
```php
<?php

$host = '127.0.0.1';
$db   = getenv('MYSQL_DATABASE') ?: 'admin';
$user = getenv('MYSQL_USER') ?: 'admin';
$pass = getenv('MYSQL_PASSWORD') ?: 'admin';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

echo 'Database connected successfully!';

```
## 💾 Persistent Storage
MariaDB stores its data at:
```txt
/data/mysql

```
Website files are stored at:
```txt
/data/htdocs

```
The container symlinks the Apache web root:
```txt
/var/www/localhost/htdocs → /data/htdocs

```
### Why /data Matters
If /data is not mounted as persistent storage:
 * MariaDB may be reinitialized after restart/rebuild
 * uploaded site files may be lost
 * database tables and records may reset
### Hugging Face Persistent Storage
Set:
| Field | Value |
|---|---|
| **Permission** | Read & Write |
| **Mount path** | /data |
| **Visibility** | Private |
> 🔒 Keep your Persistent Storage bucket private. It may contain your database and uploaded files.
> 
### What the Container Does Automatically
On first boot:
```bash
mariadb-install-db --datadir=/data/mysql --skip-test-db --user=1000

```
Every boot:
```bash
mariadbd --datadir=/data/mysql --bind-address=127.0.0.1

```
You do not need to run these manually. The startup script handles it.
## ⚙️ Environment Variables & .env
You can configure your PHP app using a .env file.
Place it here:
```txt
/var/www/localhost/htdocs/.env

```
The server automatically loads it at startup before Apache starts.
### Supported Server Variables
| Variable | Default | Description |
|---|---|---|
| MYSQL_USER | admin | MariaDB username |
| MYSQL_PASSWORD | admin | MariaDB password |
| MYSQL_DATABASE | admin | MariaDB database name |
| SQL_PATH | sql | phpMyAdmin URL path |
| FILES_PATH | files | File Manager URL path |
### Example .env
```env
# Server/database config
MYSQL_USER=admin
MYSQL_PASSWORD=strong-password
MYSQL_DATABASE=myapp
SQL_PATH=mysecretdb
FILES_PATH=mysecretfiles

# App config
APP_NAME=MyApp
APP_ENV=production
APP_DEBUG=false

# Third-party API keys
STRIPE_KEY=sk_live_xxxxxxxxxxxx
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your@email.com
MAIL_PASSWORD=yourpassword

```
### Accessing Variables in PHP
```php
$appName = getenv('APP_NAME');
$debug   = getenv('APP_DEBUG');

echo $appName;

```
### .env Rules
 * One variable per line
 * Use KEY=value format
 * No spaces around =
 * Use # for full-line comments
 * Avoid inline comments
 * Never commit .env to Git
Correct:
```env
APP_NAME=MyApp
APP_DEBUG=false

```
Incorrect:
```env
APP_NAME = MyApp
APP_DEBUG=false # production mode

```
## 📁 File Manager
The File Manager is available at:
```txt
/files

```
Or at your custom path:
```txt
/${FILES_PATH}

```
Example:
```env
FILES_PATH=mysecretfiles

```
Then open:
```txt
https://YOUR_SPACE_URL/mysecretfiles

```
Recommended upload directory:
```txt
/var/www/localhost/htdocs

```
> 🔒 Security tip: Change FILES_PATH to a hard-to-guess path before exposing your app publicly.
> 
## 💻 Web Terminal
A browser-based terminal is available through the bundled File Manager if terminal support is enabled in that File Manager build.
Open:
```txt
/files

```
Then click the **Terminal** button in the File Manager navigation bar.
### System Monitoring
```bash
free -h
df -h
du -sh *

```
### File Navigation
```bash
pwd
ls -la
cd folder

```
### Developer Commands
```bash
php -v
composer -V
git --version
unzip file.zip
nano file.php

```
### Archive Commands
```bash
zip -r project.zip project/
unzip project.zip
bsdtar -xf archive.tar.gz

```
### Media Commands
```bash
ffmpeg -version
ffmpeg -i input.mp4 output.mp3

```
## 🔀 Custom Domain via Cloudflare Workers
> Serve your Hugging Face Space from your own domain, for example example.com, while proxying requests to the original *.hf.space backend.
> 
### Prerequisites
 * A domain added to Cloudflare
 * DNS managed by Cloudflare
 * A Hugging Face Space already deployed
### Step 1 — Connect Your Domain to Cloudflare
 1. Log in to Cloudflare
 2. Click **Add a Site**
 3. Enter your domain
 4. Choose the Free plan
 5. Review DNS records
 6. Update your nameservers at your domain registrar
 7. Wait for Cloudflare activation
### Step 2 — Create a Worker
 1. Go to **Workers & Pages**
 2. Click **Create application**
 3. Click **Create Worker**
 4. Deploy the default Worker
 5. Click **Edit code**
### Step 3 — Replace Worker Code
Replace the Worker code with:
```js
export default {
  async fetch(request) {
    try {
      const url = new URL(request.url);

      // Replace with your actual Hugging Face Space host.
      // Example: "username-space-name.hf.space"
      const backendHost = "YOUR_USERNAME-YOUR_SPACE_NAME.hf.space";

      const backendUrl = new URL(request.url);
      backendUrl.hostname = backendHost;
      backendUrl.protocol = "https:";

      const newHeaders = new Headers(request.headers);
      newHeaders.set("X-Forwarded-Host", url.hostname);
      newHeaders.set("X-Forwarded-Proto", "https");

      if (newHeaders.has("origin")) {
        newHeaders.set("origin", `https://${backendHost}`);
      }

      if (newHeaders.has("referer")) {
        try {
          const referer = new URL(newHeaders.get("referer"));
          referer.hostname = backendHost;
          referer.protocol = "https:";
          newHeaders.set("referer", referer.toString());
        } catch {}
      }

      const body =
        request.method === "GET" || request.method === "HEAD"
          ? undefined
          : await request.arrayBuffer();

      const response = await fetch(
        new Request(backendUrl.toString(), {
          method: request.method,
          headers: newHeaders,
          body,
          redirect: "manual",
        })
      );

      const headers = new Headers();

      for (const [key, value] of response.headers.entries()) {
        if (key.toLowerCase() === "set-cookie") continue;

        if (key.toLowerCase() === "location") {
          try {
            const loc = new URL(value);
            loc.hostname = url.hostname;
            loc.protocol = url.protocol;
            headers.set("location", loc.toString());
          } catch {
            headers.set("location", value);
          }
          continue;
        }

        headers.set(key, value);
      }

      const setCookies = response.headers.getSetCookie?.() || [];

      for (const cookie of setCookies) {
        headers.append(
          "set-cookie",
          cookie
            .replace(/;\s*Domain=[^;]*/gi, "")
            .replace(/;\s*SameSite=[^;]*/gi, "")
            .concat("; SameSite=Lax")
        );
      }

      headers.delete("x-powered-by");
      headers.delete("server");
      headers.delete("cf-cache-status");
      headers.delete("content-security-policy");

      headers.set("X-Frame-Options", "SAMEORIGIN");
      headers.set("X-Content-Type-Options", "nosniff");
      headers.set("Referrer-Policy", "strict-origin-when-cross-origin");
      headers.set(
        "Strict-Transport-Security",
        "max-age=31536000; includeSubDomains"
      );
      headers.set("X-XSS-Protection", "1; mode=block");

      return new Response(response.body, {
        status: response.status,
        statusText: response.statusText,
        headers,
      });
    } catch (err) {
      return new Response(`Worker Error: ${err.message}`, {
        status: 500,
      });
    }
  },
};

```
Important:
```txt
Replace YOUR_USERNAME-YOUR_SPACE_NAME.hf.space with your real HF Space hostname.
Do not include https://
Do not include a trailing slash

```
Example:
```js
const backendHost = "shkumaraman-backend.hf.space";

```
### Step 4 — Attach Your Domain to the Worker
#### Part A — Custom Domain
 1. Open your Worker
 2. Go to **Settings**
 3. Open **Domains & Routes**
 4. Click **Add**
 5. Select **Custom Domain**
 6. Enter your domain or subdomain
 7. Click **Add domain**
#### Part B — Route Pattern
 1. In **Domains & Routes**, click **Add**
 2. Select **Route**
 3. Add a route pattern:
```txt
[example.com/](https://example.com/)*

```
For all subdomains:
```txt
*[.example.com/](https://.example.com/)*

```
 4. Click **Add route**
### Step 5 — Access Your App
```txt
[https://example.com/](https://example.com/)        → Website
[https://example.com/sql](https://example.com/sql)     → phpMyAdmin
[https://example.com/files](https://example.com/files)   → File Manager

```
If you customized paths:
```txt
[https://example.com/mysecretdb](https://example.com/mysecretdb)     → phpMyAdmin
[https://example.com/mysecretfiles](https://example.com/mysecretfiles)  → File Manager

```
### Troubleshooting
#### Domain not routing to Worker
 * Make sure nameservers point to Cloudflare
 * Check that your route pattern matches your domain
 * Confirm your Worker is deployed
#### Cloudflare error page
 * Check backendHost
 * Make sure your HF Space is running
 * Test the direct *.hf.space URL
#### phpMyAdmin login loop or cookies issue
 * Clear browser cookies for your custom domain
 * Test direct *.hf.space URL
 * If direct URL works but custom domain fails, review Worker cookie rewriting
 * Check that HTTPS is being used
#### Worker only needed on one subdomain
Use:
```txt
[app.example.com/](https://app.example.com/)*

```
Instead of:
```txt
*[.example.com/](https://.example.com/)*

```
## 🔒 Security Notes
Before going public:
 1. Change the default database password.
 2. Change SQL_PATH from /sql to a secret path.
 3. Change FILES_PATH from /files to a secret path.
 4. Keep Hugging Face Persistent Storage private.
 5. Avoid uploading secrets directly into public repositories.
 6. Keep .env out of Git.
 7. Consider adding authentication in front of the File Manager for public deployments.
 8. Do not use admin/admin credentials on a public server.
Recommended environment variables:
```env
MYSQL_USER=admin
MYSQL_PASSWORD=use-a-long-random-password
MYSQL_DATABASE=myapp
SQL_PATH=hidden-db-panel
FILES_PATH=hidden-file-panel

```
## 💡 Pro Tips
 * 📂 **Website Root:** Upload your project files to /var/www/localhost/htdocs
 * 💾 **Persistent Storage:** Mount /data so database and files survive restarts
 * 🗜️ **Fast Deploys:** Upload a .zip through File Manager and extract it on the server
 * ⚡ **OPcache:** Already enabled for better PHP performance
 * 🔒 **Public Hosting:** Change FILES_PATH, SQL_PATH, and MYSQL_PASSWORD
 * 🛠️ **Composer & Git:** Installed for dependency management and repository workflows
 * 🖥️ **No SSH Needed:** Use the File Manager terminal if available
 * 🔄 **mod_rewrite:** Enabled for Laravel, WordPress, and other frameworks
 * 🔀 **Custom Domain:** Use Cloudflare Workers to proxy a custom domain to HF Spaces
 * 🎬 **Media Workflows:** FFmpeg is included for audio/video processing
 * 🖼️ **Image Workflows:** GD and Imagick are included for image processing
## 🤝 Contributing
Contributions, issues, and feature requests are welcome.
 1. Fork the repository
 2. Create your feature branch:
```bash
git checkout -b feature/amazing-feature

```
 3. Commit your changes:
```bash
git commit -m "Add amazing feature"

```
 4. Push to the branch:
```bash
git push origin feature/amazing-feature

```
 5. Open a Pull Request
<div align="center">
**Made with ❤️ for developers who love simplicity**
⭐ **If this helped you, please give it a Star!** ⭐
</div>
