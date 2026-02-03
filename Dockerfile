FROM wordpress:latest

# Install msmtp for mail sending via Mailhog
RUN apt-get update && apt-get install -y \
    msmtp \
    msmtp-mta \
    && rm -rf /var/lib/apt/lists/*

# Configure msmtp for Mailhog
RUN echo "account default\n\
host mailhog\n\
port 1025\n\
auto_from on\n\
maildomain localhost\n\
" > /etc/msmtprc

# Configure PHP to use msmtp as sendmail
RUN echo "sendmail_path = /usr/bin/msmtp -t" > /usr/local/etc/php/conf.d/mailhog.ini

# Enable error logging
RUN echo "log_errors = On\n\
error_log = /var/log/php_errors.log\n\
display_errors = On\n\
error_reporting = E_ALL\n\
" > /usr/local/etc/php/conf.d/error-logging.ini

# Create log file with proper permissions
RUN touch /var/log/php_errors.log && chmod 666 /var/log/php_errors.log

# Install WP-CLI in the WordPress container
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

EXPOSE 80
