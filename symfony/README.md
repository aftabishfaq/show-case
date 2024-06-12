## Symfony Project
To set up the "Symfony" project using the provided Docker Compose file, please follow these instructions:

1. Install Docker and Docker Compose:
   - Docker: Follow the official Docker installation guide for your operating system: [https://docs.docker.com/get-docker/](https://docs.docker.com/get-docker/)
   - Docker Compose: Follow the official Docker Compose installation guide: [https://docs.docker.com/compose/install/](https://docs.docker.com/compose/install/)

2. Clone the "Symfony" project from GitHub:
   - Open a terminal or command prompt.
   - Run the following command to clone the project:
     ```
     git clone https://github.com/codora-io/symfony.git
     ```
   - Change into the project directory:
     ```
     cd symfony
     ```

3. Configure the project:
   - Open the `docker-compose.yml` file in a text editor.
   - If needed, make any necessary modifications to the Docker Compose file, such as environment variables or volume mappings.
   - Save and close the file.

4. Start the containers:
   - In the terminal, navigate to the root directory of the project where the `docker-compose.yml` file is located.
   - Run the following command to start the containers:
     ```
     docker-compose up -d
     ```
   - Docker Compose will pull the necessary images, build the custom images (if any), and start the containers in the background.

5. Configure Symfony inside the "symfony-app" container:
   - Open a new terminal or command prompt.
   - Run the following command to access the shell of the "symfony-app" container:
     ```
     docker exec -it symfony-app bash
     ```
   - You should now be inside the container.
   - Navigate to the root folder of the Symfony project:
     ```
     cd /var/www
     ```
   - Run any necessary Symfony commands to configure the project. For example, you may need to run the following commands:
     ```
     composer install
     php bin/console lexik:jwt:generate-keypair
     bin/console doctrine:schema:update --force
     bin/console cache:clear
     ```
   - Adjust the commands as per your project requirements.

At this point, the "Symfony" project should be set up and running inside the Docker containers. You can access the application through your browser using the appropriate URL or port, depending on your Docker setup and the configurations in the `docker-compose.yml` file.
## Docker Compose Documentation

This documentation provides an overview and explanation of the Docker Compose configuration file presented. The file is used to define and manage a multi-container Docker application.

### File Details

- **File Name**: docker-compose.yml
- **Version**: 3.7

### Services

The Docker Compose file defines the following services:

#### app

- **Build**:
  - **args**:
    - `user`: Specifies the username for the user inside the container (symfony).
    - `uid`: Specifies the user ID for the user inside the container (1001).
  - **context**: Specifies the build context directory (docker).
  - **dockerfile**: Specifies the Dockerfile to use for building the image (php/Dockerfile).
- **Image**: Specifies the name of the built image (symfony-app-image).
- **Container Name**: Specifies the name of the container (symfony-app).
- **Restart Policy**: Specifies the restart policy for the container (unless-stopped).
- **Working Directory**: Specifies the working directory inside the container (/var/www/).
- **Volumes**:
  - `./:/var/www`: Mounts the current directory on the host to the /var/www directory inside the container.
  - `./docker/php/uploads.ini:/usr/local/etc/php/conf.d/uploads.ini`: Mounts the uploads.ini file on the host to the /usr/local/etc/php/conf.d/uploads.ini file inside the container.
- **Networks**: 
  - `symfonynetwork`: Connects the container to the symfonynetwork network.

#### db

- **Container Name**: Specifies the name of the container (symfony-db).
- **Image**: Specifies the name of the Docker image to use (postgres:latest).
- **Restart Policy**: Specifies the restart policy for the container (unless-stopped).
- **Ports**: Exposes the container's port 5432 to the host's port 5432.
- **Environment**:
  - `POSTGRES_USER=root`: Sets the username for the PostgreSQL database to "root".
  - `POSTGRES_PASSWORD=root`: Sets the password for the PostgreSQL database to "root".
  - `POSTGRES_DB=symfony_db`: Sets the name of the PostgreSQL database to "symfony_db".
- **Networks**: 
  - `symfonynetwork`: Connects the container to the symfonynetwork network.

#### adminer

- **Container Name**: Specifies the name of the container (symfony-adminer).
- **Image**: Specifies the name of the Docker image to use (adminer).
- **Ports**: Exposes the container's port 8080 to the host's port 8080.
- **Depends On**: Specifies that this service depends on the "db" service to be started first.
- **Networks**: 
  - `symfonynetwork`: Connects the container to the symfonynetwork network.

#### nginx

- **Build**:
  - **Context**: Specifies the build context directory (docker).
  - **Dockerfile**: Specifies the Dockerfile to use for building the image (nginx/Dockerfile).
- **Container Name**: Specifies the name of the container (symfony-nginx).
- **Image**: Specifies the name of the built image (symfony-nginx-image).
- **Restart Policy**: Specifies the restart policy for the container (unless-stopped).
- **Ports**: Exposes the container's port 80 to the host's port 80.
- **Volumes**:
  - `./:/var/www`: Mounts the current directory on the host to the /var/www directory inside the container.
  - `./docker/nginx:/etc/nginx/conf.d`: Mounts the nginx directory

 on the host to the /etc/nginx/conf.d directory inside the container.
- **Networks**: 
  - `symfonynetwork`: Connects the container to the symfonynetwork network.

### Networks

The Docker Compose file defines a network called "symfonynetwork" with the following configuration:

- **Driver**: Specifies the network driver to use (bridge).

This network is used to connect the containers defined in the services section, allowing them to communicate with each other using their service names as hostnames.