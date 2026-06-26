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
RUN addgroup -g 1000 appgroup && \
    adduser -u 1000 -G appgroup -D -s /bin/sh appuser
RUN sed -i 's/^User apache/User appuser/' /etc/apache2/httpd.conf && \
    sed -i 's/^Group apache/Group appgroup/' /etc/apache2/httpd.conf && \
    sed -i 's/Listen 80/Listen 7860/' /etc/apache2/httpd.conf && \
    sed -i 's/^LoadModule mpm_prefork_module/#LoadModule mpm_prefork_module/' /etc/apache2/httpd.conf && \
    sed -i 's/^LoadModule mpm_worker_module/#LoadModule mpm_worker_module/' /etc/apache2/httpd.conf && \
    sed -i '/mod_mpm_event\.so/s/^#//' /etc/apache2/httpd.conf && \
    sed -i '/mod_proxy\.so/s/^#//' /etc/apache2/httpd.conf && \
    sed -i '/mod_proxy_fcgi\.so/s/^#//' /etc/apache2/httpd.conf && \
    sed -i '/mod_rewrite\.so/s/^#//' /etc/apache2/httpd.conf && \
    sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/httpd.conf
RUN cat << 'EOF' >> /etc/apache2/httpd.conf

ServerName localhost
Timeout 60
KeepAlive On
MaxKeepAliveRequests 100
KeepAliveTimeout 2
ProxyTimeout 300
<IfModule mpm_event_module>
    StartServers 1
    MinSpareThreads 25
    MaxSpareThreads 75
    ThreadsPerChild 25
    MaxRequestWorkers 100
    MaxConnectionsPerChild 1000
</IfModule>
<FilesMatch \.php$>
    SetHandler "proxy:fcgi://127.0.0.1:9000/"
</FilesMatch>
<Directory /usr/share/webapps/phpmyadmin>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
<Directory /usr/share/webapps/filemanager>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
<Directory /var/www/localhost/htdocs>
    Options Indexes FollowSymLinks
    IndexOptions FancyIndexing FoldersFirst NameWidth=* DescriptionWidth=* VersionSort
    AllowOverride All
    Require all granted
</Directory>
<Directory /data/htdocs>
    Options Indexes FollowSymLinks
    IndexOptions FancyIndexing FoldersFirst NameWidth=* DescriptionWidth=* VersionSort
    AllowOverride All
    Require all granted
</Directory>
DirectoryIndex index.php index.html
EOF
RUN find /etc/php* -name php-fpm.conf -exec sh -c '\
    sed -i "s|^;*pid = .*|pid = /run/php-fpm/php-fpm.pid|" "$1" && \
    sed -i "s|^;*error_log = .*|error_log = /proc/self/fd/2|" "$1" && \
    sed -i "s|^;*daemonize = .*|daemonize = no|" "$1"' sh {} \; && \
    find /etc/php* -path '*/php-fpm.d/www.conf' -exec sh -c '\
    sed -i "s|^user = .*|user = appuser|" "$1" && \
    sed -i "s|^group = .*|group = appgroup|" "$1" && \
    sed -i "s|^listen = .*|listen = 127.0.0.1:9000|" "$1" && \
    sed -i "s|^;*listen.allowed_clients = .*|listen.allowed_clients = 127.0.0.1|" "$1" && \
    sed -i "s|^pm = .*|pm = dynamic|" "$1" && \
    sed -i "s|^pm.max_children = .*|pm.max_children = 10|" "$1" && \
    sed -i "s|^pm.start_servers = .*|pm.start_servers = 2|" "$1" && \
    sed -i "s|^pm.min_spare_servers = .*|pm.min_spare_servers = 1|" "$1" && \
    sed -i "s|^pm.max_spare_servers = .*|pm.max_spare_servers = 4|" "$1" && \
    sed -i "s|^;*pm.max_requests = .*|pm.max_requests = 300|" "$1" && \
    sed -i "s|^;*request_terminate_timeout = .*|request_terminate_timeout = 300s|" "$1" && \
    sed -i "s|^;*clear_env = .*|clear_env = no|" "$1" && \
    sed -i "s|^;*catch_workers_output = .*|catch_workers_output = yes|" "$1" && \
    sed -i "s|^;*decorate_workers_output = .*|decorate_workers_output = no|" "$1"' sh {} \;
RUN cat << 'EOF' > /etc/my.cnf.d/hf.cnf
[mariadb]
max_connections=50
thread_cache_size=16
table_open_cache=512
tmp_table_size=32M
max_heap_table_size=32M
max_allowed_packet=64M
innodb_buffer_pool_size=128M
innodb_log_file_size=64M
innodb_flush_log_at_trx_commit=2
skip-name-resolve
EOF
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
    echo "pdo_mysql.default_socket=\"/run/mysqld/mysqld.sock\"" >> "{}" && \
    echo "mysqli.default_socket=\"/run/mysqld/mysqld.sock\"" >> "{}" && \
    echo "opcache.enable=1" >> "{}" && \
    echo "opcache.memory_consumption=128" >> "{}" && \
    echo "opcache.interned_strings_buffer=16" >> "{}" && \
    echo "opcache.max_accelerated_files=20000" >> "{}" && \
    echo "opcache.validate_timestamps=1" >> "{}" && \
    echo "opcache.revalidate_freq=2" >> "{}" && \
    echo "realpath_cache_size=1024K" >> "{}" && \
    echo "realpath_cache_ttl=300" >> "{}" && \
    echo "session.save_path=\"/data/sessions\"" >> "{}" && \
    echo "sys_temp_dir=\"/tmp\"" >> "{}" && \
    echo "upload_tmp_dir=\"/tmp\"" >> "{}" && \
    echo "upload_max_filesize=512M" >> "{}" && \
    echo "post_max_size=512M" >> "{}" && \
    echo "memory_limit=512M" >> "{}" && \
    echo "max_execution_time=300" >> "{}" && \
    echo "max_input_time=300" >> "{}" && \
    echo "max_file_uploads=100" >> "{}" && \
    echo "log_errors=On" >> "{}" && \
    echo "error_log=/tmp/php-error.log" >> "{}"' \;
RUN mkdir -p \
    /run/mysqld \
    /run/apache2 \
    /run/php-fpm \
    /data/mysql \
    /data/htdocs \
    /data/sessions \
    /var/www/localhost/htdocs \
    /usr/share/webapps/filemanager \
    /etc/apache2/conf.d \
    /var/log/apache2 && \
    ln -sf /dev/stdout /var/log/apache2/access.log && \
    ln -sf /dev/stderr /var/log/apache2/error.log && \
    curl -f -sSL https://raw.githubusercontent.com/shkumaraman/free-web-hosting/main/filemanager/index.php -o /usr/share/webapps/filemanager/index.php && \
    [ -s /usr/share/webapps/filemanager/index.php ] && \
    rm -f /var/www/localhost/htdocs/index.html /var/www/localhost/htdocs/index.php
RUN chown -R appuser:appgroup \
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
USER appuser
RUN cd /usr/share/webapps/filemanager && \
    composer require phpseclib/phpseclib && \
    composer clear-cache
USER root
RUN cat << 'EOF' > /start.sh
#!/bin/sh
MYSQL_PID=""
FPM_PID=""
HTTPD_PID=""
stop_all() {
    CODE="${1:-0}"
    [ -n "$HTTPD_PID" ] && kill "$HTTPD_PID" 2>/dev/null || true
    [ -n "$FPM_PID" ] && kill "$FPM_PID" 2>/dev/null || true
    [ -n "$MYSQL_PID" ] && kill "$MYSQL_PID" 2>/dev/null || true
    wait 2>/dev/null || true
    exit "$CODE"
}
trap 'stop_all 0' INT TERM
rm -f /run/mysqld/mysqld.sock /run/mysqld/mysqld.pid /run/apache2/httpd.pid /run/php-fpm/php-fpm.pid /data/mysql/tc.log 2>/dev/null || true
mkdir -p /data/htdocs /data/sessions /run/php-fpm /run/mysqld /run/apache2 /data/mysql
chown -R appuser:appgroup /data/sessions /data/mysql 2>/dev/null || true
chmod 700 /data/sessions 2>/dev/null || true
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
PHP_FPM_BIN="$(command -v php-fpm || find /usr/sbin /usr/bin -maxdepth 1 -type f -name 'php-fpm*' 2>/dev/null | sort | head -n 1)"
if [ -z "$PHP_FPM_BIN" ]; then
    stop_all 1
fi
"$PHP_FPM_BIN" -F &
FPM_PID="$!"
sleep 2
if ! kill -0 "$FPM_PID" 2>/dev/null; then
    stop_all 1
fi
httpd -D FOREGROUND &
HTTPD_PID="$!"
sleep 2
if ! kill -0 "$HTTPD_PID" 2>/dev/null; then
    stop_all 1
fi
if [ ! -d /data/mysql/mysql ]; then
    mkdir -p /data/mysql
    find /data/mysql -mindepth 1 -delete 2>/dev/null || true
    mariadb-install-db --datadir=/data/mysql --skip-test-db --user=appuser --auth-root-authentication-method=normal
fi
ROOT_PASS=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | head -c 24)
cat << SQL > /tmp/init.sql
FLUSH PRIVILEGES;
ALTER USER 'root'@'localhost' IDENTIFIED BY '${ROOT_PASS}';
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}\`;
CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'%' IDENTIFIED BY '${MYSQL_PASSWORD}';
ALTER USER '${MYSQL_USER}'@'%' IDENTIFIED BY '${MYSQL_PASSWORD}';
GRANT ALL PRIVILEGES ON *.* TO '${MYSQL_USER}'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
mariadbd --datadir=/data/mysql --bind-address=127.0.0.1 --port=3306 --socket=/run/mysqld/mysqld.sock --pid-file=/run/mysqld/mysqld.pid --skip-networking=OFF --innodb-use-native-aio=0 --init-file=/tmp/init.sql &
MYSQL_PID="$!"
TRIES=0
until mariadb-admin ping --socket=/run/mysqld/mysqld.sock -u root -p"${ROOT_PASS}" --silent 2>/dev/null; do
    TRIES=$((TRIES+1))
    if [ "$TRIES" -ge 60 ]; then
        cat /data/mysql/*.err 2>/dev/null || true
        stop_all 1
    fi
    sleep 2
done
rm -f /tmp/init.sql
mariadb --socket=/run/mysqld/mysqld.sock -u root -p"${ROOT_PASS}" << SQL
CREATE DATABASE IF NOT EXISTS phpmyadmin;
SQL
CREATE_TABLES_SQL="$(find /usr/share/webapps/phpmyadmin /usr/share/phpmyadmin /usr/share -name create_tables.sql 2>/dev/null | head -n 1)"
if [ -n "$CREATE_TABLES_SQL" ]; then
    mariadb --socket=/run/mysqld/mysqld.sock -u root -p"${ROOT_PASS}" phpmyadmin -e "SHOW TABLES LIKE 'pma__bookmark';" | grep -q pma__bookmark || mariadb --socket=/run/mysqld/mysqld.sock -u root -p"${ROOT_PASS}" < "$CREATE_TABLES_SQL" || true
fi
mariadb --socket=/run/mysqld/mysqld.sock -u root -p"${ROOT_PASS}" << SQL
GRANT SELECT, INSERT, UPDATE, DELETE ON phpmyadmin.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
chmod -R u+rwX,go+rX /data/htdocs 2>/dev/null || true
while true; do
    if ! kill -0 "$HTTPD_PID" 2>/dev/null; then
        wait "$HTTPD_PID" 2>/dev/null || true
        stop_all 1
    fi
    if ! kill -0 "$FPM_PID" 2>/dev/null; then
        wait "$FPM_PID" 2>/dev/null || true
        stop_all 1
    fi
    if ! kill -0 "$MYSQL_PID" 2>/dev/null; then
        wait "$MYSQL_PID" 2>/dev/null || true
        stop_all 1
    fi
    sleep 2
done
EOF
RUN chmod +x /start.sh && \
    chown appuser:appgroup /start.sh
WORKDIR /var/www/localhost/htdocs
USER appuser
EXPOSE 7860
LABEL huggingface.co/port="7860"
CMD ["tini", "--", "/start.sh"]
