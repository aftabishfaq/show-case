FROM php:8.1-fpm

# Arguments defined in docker-compose.yml
ARG user
ARG uid

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    libjpeg62-turbo-dev \
    curl \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    unzip

# Install PHP extensions
RUN docker-php-ext-configure gd --enable-gd --with-jpeg
RUN docker-php-ext-install -j "$(nproc)" gd
RUN docker-php-ext-install mysqli pdo pdo_mysql mbstring exif pcntl bcmath zip

# Install Node
RUN curl -sL https://deb.nodesource.com/setup_16.x | bash
RUN apt-get install -y nodejs

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user to run Composer and Artisan Commands
RUN useradd -G www-data,root -u $uid -d /home/$user $user
RUN mkdir -p /home/$user/.composer && \
    chown -R $user:$user /home/$user

# Set working directory
WORKDIR /var/www
USER $user