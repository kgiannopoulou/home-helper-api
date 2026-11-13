# PHP with Tesseract (docs/kitchen.md#receipts): runs the Pest suite including
# the real-OCR receipt tests, which are skipped where tesseract isn't installed.
#
#   docker build -t hh-php-ocr ops/ocr
#   docker run --rm --network home-helper-api_sail -v "$PWD:/app" -w /app \
#     -e DB_HOST=mysql -e REDIS_HOST=redis hh-php-ocr php vendor/bin/pest tests/Feature/Kitchen
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-ell tesseract-ocr-eng libzip-dev unzip \
    && docker-php-ext-install pdo_mysql bcmath \
    && rm -rf /var/lib/apt/lists/*

RUN tesseract --version && tesseract --list-langs
