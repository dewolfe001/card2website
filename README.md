# BusinessCard2Website

This repository contains a simple PHP prototype based on the PRD. The app lets users upload a business card image and stores the file in `uploads/` with a database record. A preview page shows the uploaded card and displays text extracted via OCR. AI-based HTML generation will be added later.

## Requirements
- PHP 8+
- MySQL 8
- Tesseract OCR
- (Optional) OpenAI API key for enhanced OCR

## Setup
1. Create a MySQL database and user. Import `schema.sql`.
2. Copy `.env.example` to `.env` and set your DB credentials or configure environment variables `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and `OPENAI_API_KEY` if you want to use OpenAI vision.
3. Serve the PHP files with Apache or PHP's built-in server:
   ```bash
   php -S localhost:8000
   ```
4. Visit `http://localhost:8000/index.php` in your browser.

## Folder Structure
- `index.php` – landing page with upload form
- `upload.php` – processes uploads and saves metadata
- `preview.php` – displays uploaded card
- `uploads/` – uploaded images (ignored in Git)
- `generated_sites/` – future HTML output (ignored in Git)

This is an early scaffold; more features will be implemented following the PRD.
