FROM wordpress:latest

# Install the sockets extension
RUN docker-php-ext-install sockets