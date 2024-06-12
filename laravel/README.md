# Laravel Docker Project

This project sets up a Laravel application environment using Docker. It includes the application, a MariaDB database, phpMyAdmin, and an Nginx web server.

## Prerequisites

- Docker installed on your machine
- Docker Compose installed on your machine

# Services

This Docker Compose file defines four services:

1. **app**: The Laravel application
2. **db**: The MariaDB database
3. **phpmyadmin**: phpMyAdmin interface for managing the database
4. **nginx**: The Nginx web server

### Setup Instructions

### Step 1: Clone the Repository

```bash
git clone <repository-url>
cd <repository-directory>
```

### Step 2: Build and Start the Containers

```bash
docker-compose up --build -d
```

### Step 3: Access the Services

- Laravel Application: [http://localhost](http://localhost)
- phpMyAdmin: [http://localhost:8001](http://localhost:8001)

## Configuration Details

### 1. Laravel Application (app)

- **Dockerfile**: `docker/php/Dockerfile`
- **Image**: `laravel-app-image`
- **Container Name**: `laravel-app`
- **Working Directory**: `/var/www/`
- **Volumes**:
  - Project directory mounted to `/var/www`
  - Custom PHP settings file `uploads.ini` mounted to `/usr/local/etc/php/conf.d/uploads.ini`
- **Network**: `laravelnetwork`

### 2. MariaDB Database (db)

- **Dockerfile**: `docker/mariadb/Dockerfile`
- **Image**: `laravel-db-image`
- **Container Name**: `laravel-db`
- **Environment Variables**:
  - `MYSQL_DATABASE`: `laravel_db`
  - `MYSQL_ROOT_PASSWORD`: `root`
  - `MYSQL_PASSWORD`: `root`
  - `MYSQL_USER`: `laravel_user`
- **Volumes**:
  - Database files mounted to `./db/mariadb`
  - Custom MySQL configuration file `my.cnf` mounted to `/etc/mysql/my.cnf`
- **Network**: `laravelnetwork`

### 3. phpMyAdmin

- **Image**: `phpmyadmin/phpmyadmin`
- **Container Name**: `laravel-phpmyadmin`
- **Ports**: `8001:80`
- **Environment Variables**:
  - `MYSQL_ROOT_PASSWORD`: `root`
  - `MYSQL_PASSWORD`: `root`
  - `MYSQL_USER`: `laravel_user`
  - `UPLOAD_LIMIT`: `300M`
- **Links**: Connects to the `db` service
- **Network**: `laravelnetwork`

### 4. Nginx Web Server (nginx)

- **Dockerfile**: `docker/nginx/Dockerfile`
- **Image**: `laravel-nginx-image`
- **Container Name**: `laravel-nginx`
- **Ports**: `80:80`
- **Volumes**:
  - Project directory mounted to `/var/www`
  - Custom Nginx configuration directory mounted to `/etc/nginx/conf.d`
- **Network**: `laravelnetwork`

## Network

All services are connected through a Docker bridge network named `laravelnetwork`.

## Stopping the Containers

To stop the running containers:

```bash
docker-compose down
```
