FROM php:8.4-fpm-alpine

# 1. Install System Dependencies
# We need rsync for file transfers, openssh for remote commands, 
# and mariadb-client for mysqldump/mysql imports.
RUN apk add --no-cache \
    bash \
    rsync \
    openssh-client \
    mariadb-client \
    postgresql-client \
    git \
    libpng-dev \
    libzip-dev \
    zip \
    unzip

# 2. Install PHP Extensions required by Laravel & Filament
RUN docker-php-ext-install bcmath gd mysqli pdo_mysql zip

# 3. Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. Install WP-CLI (The engine for WordPress operations)
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

# 5. Setup SSH Directory
# This allows the container to store known_hosts and handle keys securely
RUN mkdir -p /root/.ssh && chmod 700 /root/.ssh

# 6. Set Working Directory
WORKDIR /var/www

# 7. (Optional) Create a non-root user for security
# Note: If you use a non-root user, ensure they have permissions to /root/.ssh 
# or change the HOME directory for SSH keys.

CMD ["php-fpm"]