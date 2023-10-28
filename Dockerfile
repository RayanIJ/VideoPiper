# Use the official PHP 8.0 Apache image from the Docker Hub
FROM php:8.2-apache

# Update package lists and install dependencies
RUN apt-get update && apt-get install -y \
    procps \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    software-properties-common



# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set working directory
WORKDIR /app

# Remove the default Apache index.html file and enable mod_rewrite
RUN rm -rf /var/www/html && a2enmod rewrite

# Copy the current directory contents into the container at /app
COPY . /app

# Install Laravel dependencies
RUN composer install

# Change ownership of /app to www-data
RUN chown -R www-data:www-data /app

# Point Apache DocumentRoot to public Laravel public directory
COPY vhost.conf /etc/apache2/sites-available/000-default.conf

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Update Apache to listen to port 8000
RUN sed -i '/Listen 80/c\Listen 8000' /etc/apache2/ports.conf

RUN chmod -R 755 /app

# Expose port 8000
EXPOSE 8000

# Start Apache service
CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
