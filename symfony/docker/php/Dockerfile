FROM php:8.1-fpm

# Arguments defined in docker-compose.yml
ARG user
ARG uid

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    unzip

# Install PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql mbstring exif pcntl bcmath gd zip

RUN apt-get update && \
    apt-get install -y libpq-dev && \
    docker-php-ext-install pdo_pgsql

# Install Node
RUN curl -sL https://deb.nodesource.com/setup_20.x | bash
RUN apt-get install -y nodejs

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user to run Composer and Artisan Commands
RUN useradd -G www-data,root -u $uid -d /home/$user $user
RUN mkdir -p /home/$user/.composer && \
    chown -R $user:$user /home/$user

# Set environment variable
ENV APP_ENV=dev
ENV APP_DEBUG=1
ENV WELCOME_EMAIL="no-reply@symfony.dk"


# Set working directory
WORKDIR /var/www
USER $user


