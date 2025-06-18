# BusinessCard2Website

This repository contains a simple PHP prototype based on the PRD. The app lets users upload a business card image and stores the file in `uploads/` with a database record. A preview page shows the uploaded card and displays text extracted via OCR. A basic HTML generator can turn that text into a one-page website which users can view and download. The generator now also infers a NAICS classification via OpenAI to help shape the design.

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
- `preview.php` – displays uploaded card and lets you edit extracted text
- `generate.php` – creates a simple HTML site from the reviewed text
- `view_site.php` – preview of generated site with download link
- `download.php` – download the generated HTML file
- `uploads/` – uploaded images (ignored in Git)
- `generated_sites/` – generated HTML output (ignored in Git)
- `naics_classifications` – table storing NAICS code results

Users can review the extracted text on the preview page, make corrections, and generate the site from their edited version. The edits are stored in the new `ocr_edits` table.

This is an early scaffold; more features will be implemented following the PRD.
The prototype now includes experimental domain suggestions and Namecheap integration for registration.

## Publishing to WHM/cPanel
Set `WHM_HOST`, `WHM_API_TOKEN`, and `WHM_ROOT_USER` in your `.env` file for deployment.
Use `publish_to_whm.php?upload_id=ID&domain=example.com` to create a new WHM account and upload the generated site to the account's `public_html` directory.

## Billing
The app integrates with Stripe for recurring subscriptions. Set `STRIPE_SECRET_KEY` and
`STRIPE_PRICE_ID` in your `.env` file. After registering and logging in, users can start a
subscription and manage billing through the Stripe customer portal. Webhook events update the
`billing_subscriptions` table.

