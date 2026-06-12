FROM alpine:latest

ENV MYSQL_USER=admin \
    MYSQL_PASSWORD=admin \
    MYSQL_DATABASE=admin \
    SQL_PATH=sql \
    FILES_PATH=files

RUN apk add --no-cache \
    mariadb mariadb-client apache2 apache2-proxy php-fpm \
    php-mysqli php-pdo_mysql php-mbstring php-xml \
    php-gd php-curl php-session phpmyadmin curl \
    zip unzip php-zip php-ctype php-fileinfo \
    php-opcache php-phar php-openssl php-iconv \
    php-intl php-apcu php-redis php-soap php-ldap \
    php-imagick imagemagick \
    php-tokenizer php-simplexml php-dom php-bcmath php-exif \
    php-xmlwriter php-xmlreader php-sockets php-posix \
    php-pdo php-sodium php-ftp php-calendar \
    php-pcntl php-gettext php-shmop php-sysvmsg \
    php-sysvsem php-sysvshm php-tidy php-xsl php-bz2 php-gmp \
    readline wget git composer nano tini ffmpeg libarchive-tools

RUN sed -i 's/Listen 80/Listen 7860/' /etc/apache2/httpd.conf && \
    sed -i 's/^LoadModule mpm_prefork_module/#LoadModule mpm_prefork_module/' /etc/apache2/httpd.conf && \
    sed -i 's/^LoadModule mpm_worker_module/#LoadModule mpm_worker_module/' /etc/apache2/httpd.conf && \
    if grep -q '^#LoadModule mpm_event_module' /etc/apache2/httpd.conf; then sed -i 's/^#LoadModule mpm_event_module/LoadModule mpm_event_module/' /etc/apache2/httpd.conf; elif ! grep -q '^LoadModule mpm_event_module' /etc/apache2/httpd.conf; then printf "\nLoadModule mpm_event_module modules/mod_mpm_event.so\n" >> /etc/apache2/httpd.conf; fi && \
    if grep -q '^#LoadModule proxy_module' /etc/apache2/httpd.conf; then sed -i 's/^#LoadModule proxy_module/LoadModule proxy_module/' /etc/apache2/httpd.conf; elif ! grep -q '^LoadModule proxy_module' /etc/apache2/httpd.conf; then printf "\nLoadModule proxy_module modules/mod_proxy.so\n" >> /etc/apache2/httpd.conf; fi && \
    if grep -q '^#LoadModule proxy_fcgi_module' /etc/apache2/httpd.conf; then sed -i 's/^#LoadModule proxy_fcgi_module/LoadModule proxy_fcgi_module/' /etc/apache2/httpd.conf; elif ! grep -q '^LoadModule proxy_fcgi_module' /etc/apache2/httpd.conf; then printf "\nLoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so\n" >> /etc/apache2/httpd.conf; fi && \
    sed -i 's/#LoadModule rewrite_module/LoadModule rewrite_module/' /etc/apache2/httpd.conf && \
    sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/httpd.conf && \
    printf "\nServerName localhost\n\
<FilesMatch \\.php$>\n\
    SetHandler \"proxy:fcgi://127.0.0.1:9000/\"\n\
</FilesMatch>\n\
<Directory /usr/share/webapps/phpmyadmin>\n\
    Options FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
<Directory /usr/share/webapps/filemanager>\n\
    Options FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
<Directory /var/www/localhost/htdocs>\n\
    Options Indexes FollowSymLinks\n\
    IndexOptions FancyIndexing FoldersFirst NameWidth=* DescriptionWidth=* VersionSort\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
DirectoryIndex index.php index.html\n" >> /etc/apache2/httpd.conf

RUN find /etc/php* -name php-fpm.conf -exec sh -c '\
    sed -i "s|^;*pid = .*|pid = /run/php-fpm/php-fpm.pid|" "$1" && \
    sed -i "s|^;*error_log = .*|error_log = /proc/self/fd/2|" "$1" && \
    sed -i "s|^;*daemonize = .*|daemonize = yes|" "$1"' sh {} \; && \
    find /etc/php* -path '*/php-fpm.d/www.conf' -exec sh -c '\
    sed -i "s|^listen = .*|listen = 127.0.0.1:9000|" "$1" && \
    sed -i "s|^;*clear_env = .*|clear_env = no|" "$1" && \
    sed -i "s|^;*catch_workers_output = .*|catch_workers_output = yes|" "$1" && \
    sed -i "s|^;*decorate_workers_output = .*|decorate_workers_output = no|" "$1"' sh {} \;

RUN BLOWFISH=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | head -c 32) && \
    sed -i "s|'localhost'|'127.0.0.1'|g" /etc/phpmyadmin/config.inc.php && \
    cat << EOF >> /etc/phpmyadmin/config.inc.php
\$cfg['blowfish_secret'] = '${BLOWFISH}';
\$cfg['Servers'][1]['host'] = '127.0.0.1';
\$cfg['Servers'][1]['port'] = '3306';
\$cfg['Servers'][1]['pmadb'] = 'phpmyadmin';
\$cfg['Servers'][1]['bookmarktable'] = 'pma__bookmark';
\$cfg['Servers'][1]['relation'] = 'pma__relation';
\$cfg['Servers'][1]['table_info'] = 'pma__table_info';
\$cfg['Servers'][1]['table_coords'] = 'pma__table_coords';
\$cfg['Servers'][1]['pdf_pages'] = 'pma__pdf_pages';
\$cfg['Servers'][1]['column_info'] = 'pma__column_info';
\$cfg['Servers'][1]['history'] = 'pma__history';
\$cfg['Servers'][1]['table_uiprefs'] = 'pma__table_uiprefs';
\$cfg['Servers'][1]['tracking'] = 'pma__tracking';
\$cfg['Servers'][1]['userconfig'] = 'pma__userconfig';
\$cfg['Servers'][1]['recent'] = 'pma__recent';
\$cfg['Servers'][1]['favorite'] = 'pma__favorite';
\$cfg['Servers'][1]['users'] = 'pma__users';
\$cfg['Servers'][1]['usergroups'] = 'pma__usergroups';
\$cfg['Servers'][1]['navigationhiding'] = 'pma__navigationhiding';
\$cfg['Servers'][1]['savedsearches'] = 'pma__savedsearches';
\$cfg['Servers'][1]['central_columns'] = 'pma__central_columns';
\$cfg['Servers'][1]['designer_settings'] = 'pma__designer_settings';
\$cfg['Servers'][1]['export_templates'] = 'pma__export_templates';
\$cfg['TrustedProxies'] = array('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1');
\$cfg['CookieSameSite'] = 'None';
EOF

RUN find /etc/php* -name php.ini -exec sh -c '\
    echo "session.cookie_secure = On" >> "{}" && \
    echo "session.cookie_samesite = \"None\"" >> "{}" && \
    echo "opcache.enable=1" >> "{}" && \
    echo "session.save_path=\"/tmp\"" >> "{}" && \
    echo "sys_temp_dir=\"/tmp\"" >> "{}" && \
    echo "upload_tmp_dir=\"/tmp\"" >> "{}" && \
    echo "upload_max_filesize=5G" >> "{}" && \
    echo "post_max_size=5G" >> "{}" && \
    echo "memory_limit=2G" >> "{}" && \
    echo "max_execution_time=600" >> "{}" && \
    echo "max_input_time=600" >> "{}" && \
    echo "max_file_uploads=200" >> "{}" && \
    echo "log_errors=On" >> "{}" && \
    echo "error_log=/tmp/php-error.log" >> "{}"' \;

RUN mkdir -p \
    /run/mysqld \
    /run/apache2 \
    /run/php-fpm \
    /data/mysql \
    /data/htdocs \
    /var/www/localhost/htdocs \
    /usr/share/webapps/filemanager \
    /etc/apache2/conf.d \
    /var/log/apache2 && \
    ln -sf /dev/stdout /var/log/apache2/access.log && \
    ln -sf /dev/stderr /var/log/apache2/error.log && \
    curl -fsSL https://raw.githubusercontent.com/shkumaraman/free-web-hosting/main/filemanager/index.php -o /usr/share/webapps/filemanager/index.php && \
    test -s /usr/share/webapps/filemanager/index.php && \
    rm -f /var/www/localhost/htdocs/index.html /var/www/localhost/htdocs/index.php

RUN cd /usr/share/webapps/filemanager && \
    composer require phpseclib/phpseclib && \
    composer clear-cache

RUN chown -R 1000:1000 \
    /run/mysqld \
    /run/apache2 \
    /run/php-fpm \
    /var/www/localhost \
    /var/log/apache2 \
    /etc/phpmyadmin \
    /etc/apache2/conf.d \
    /usr/share/webapps \
    /data && \
    chmod 1777 /tmp && \
    chmod -R u+rwX,go+rX /data /var/www/localhost /usr/share/webapps

RUN cat << 'EOF' > /start.sh
#!/bin/sh
rm -f /run/mysqld/mysqld.sock /run/mysqld/mysqld.pid /run/apache2/httpd.pid /run/php-fpm/php-fpm.pid /data/mysql/tc.log 2>/dev/null || true

if [ ! -d /data/htdocs ]; then
    mkdir -p /data/htdocs
fi

mkdir -p /run/php-fpm

chown -R 1000:1000 /data /var/www/localhost /tmp /usr/share/webapps /run/php-fpm 2>/dev/null || true

if [ ! -L /var/www/localhost/htdocs ]; then
    cp -a /var/www/localhost/htdocs/. /data/htdocs/ 2>/dev/null || true
    rm -rf /var/www/localhost/htdocs 2>/dev/null || true
    ln -sfn /data/htdocs /var/www/localhost/htdocs
fi

if [ -f /var/www/localhost/htdocs/.env ]; then
    set -a
    . /var/www/localhost/htdocs/.env || true
    set +a
fi

cat > /etc/apache2/conf.d/tool-aliases.conf << APACHECONF
Alias /${SQL_PATH:-sql} /usr/share/webapps/phpmyadmin
Alias /${FILES_PATH:-files} /usr/share/webapps/filemanager
APACHECONF

if [ ! -d /data/mysql/mysql ]; then
    find /data/mysql -mindepth 1 -delete 2>/dev/null || true
    mariadb-install-db --datadir=/data/mysql --skip-test-db --user=1000 --auth-root-authentication-method=normal
fi

cat << SQL > /tmp/init.sql
FLUSH PRIVILEGES;
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}\`;
CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'%' IDENTIFIED BY '${MYSQL_PASSWORD}';
ALTER USER '${MYSQL_USER}'@'%' IDENTIFIED BY '${MYSQL_PASSWORD}';
GRANT ALL PRIVILEGES ON *.* TO '${MYSQL_USER}'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

mariadbd --datadir=/data/mysql --bind-address=127.0.0.1 --port=3306 --socket=/run/mysqld/mysqld.sock --pid-file=/run/mysqld/mysqld.pid --skip-networking=OFF --innodb-use-native-aio=0 --init-file=/tmp/init.sql &

TRIES=0
until mariadb-admin ping --socket=/run/mysqld/mysqld.sock -u root --silent 2>/dev/null; do
    TRIES=$((TRIES+1))
    if [ "$TRIES" -ge 30 ]; then
        cat /data/mysql/*.err 2>/dev/null || true
        exit 1
    fi
    sleep 2
done

rm -f /tmp/init.sql

mariadb --socket=/run/mysqld/mysqld.sock -u root << SQL
CREATE DATABASE IF NOT EXISTS phpmyadmin;
SQL

CREATE_TABLES_SQL="$(find /usr/share/webapps/phpmyadmin /usr/share/phpmyadmin /usr/share -name create_tables.sql 2>/dev/null | head -n 1)"

if [ -n "$CREATE_TABLES_SQL" ]; then
    mariadb --socket=/run/mysqld/mysqld.sock -u root phpmyadmin -e "SHOW TABLES LIKE 'pma__bookmark';" | grep -q pma__bookmark || mariadb --socket=/run/mysqld/mysqld.sock -u root < "$CREATE_TABLES_SQL" || true
fi

mariadb --socket=/run/mysqld/mysqld.sock -u root << SQL
GRANT SELECT, INSERT, UPDATE, DELETE ON phpmyadmin.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL

chmod -R u+rwX,go+rX /data/htdocs 2>/dev/null || true

PHP_FPM_BIN="$(command -v php-fpm || find /usr/sbin /usr/bin -maxdepth 1 -type f -name 'php-fpm*' 2>/dev/null | sort | head -n 1)"

if [ -z "$PHP_FPM_BIN" ]; then
    exit 1
fi

"$PHP_FPM_BIN" -D

exec httpd -D FOREGROUND
EOF

RUN chmod +x /start.sh && \
    chown 1000:1000 /start.sh

WORKDIR /var/www/localhost/htdocs
USER 1000
EXPOSE 7860
CMD ["tini", "--", "/start.sh"]
