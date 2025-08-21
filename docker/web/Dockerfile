FROM php:8.2-apache
#FROM happytalkteam/docker-happytalk:dev-latest

# Set Env
ENV TZ=Asia/Seoul
ENV PHP_MEMORY_LIMIT=512M

# Install development packages and clean up apt cache
RUN apt-get update
RUN apt-get install -y curl git
RUN apt-get install -y libssl-dev openssl

RUN apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Install docker-php-extention
RUN docker-php-ext-install mysqli
RUN docker-php-ext-install pdo
RUN docker-php-ext-install pdo_mysql
RUN docker-php-ext-install opcache

# 환경 설정
ENV APACHE_DOCUMENT_ROOT /var/www/html

# Xdebug
# RUN yes | pecl install xdebug-2.9.5

# 아파치 Document root 수정
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 아파치 모듈 활성화(rewirte, headers)
RUN a2enmod rewrite
RUN a2enmod headers

# 아파치 데몬 재시작(현재 연결 유지)
RUN apache2ctl -k graceful