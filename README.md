# BusinessCard2Website

This repository contains a simple PHP prototype based on the PRD. The app lets users upload a business card image and stores the file in `uploads/` with a database record. A preview page shows the uploaded card and displays text extracted via OCR. A basic HTML generator can turn that text into a one-page website which users can view and download. The generator now also infers a NAICS classification via OpenAI to help shape the design.

## Requirements
- PHP 8+
- MySQL 8
- OpenAI API key (for OCR and site generation)

## Setup
1. Create a MySQL database and user. Import `schema.sql`.
2. Copy `.env.example` to `.env` and set your DB credentials along with an `OPENAI_API_KEY`.
   You can optionally set `OPENAI_RETRY_LIMIT` to control how many times API
   requests are retried when a failure occurs (defaults to 3). The `config.php`
   loader will export each variable so it can also be read with `getenv()`.
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
- `website_images` – table storing additional images uploaded for a site along with user and URL metadata

Users can review the extracted text on the preview page, make corrections, and generate the site from their edited version. The edits are stored in the new `ocr_edits` table.

This is an early scaffold; more features will be implemented following the PRD.
The prototype now includes experimental domain suggestions and Namecheap integration for registration.

## Publishing to WHM/cPanel
Set `WHM_HOST`, `WHM_API_TOKEN`, and `WHM_ROOT_USER` in your `.env` file for deployment.
`WHM_HOST` must include the scheme and port (e.g. `https://1.2.3.4:2087`). If your provider issues a cPanel
"cprapid.com" hostname such as `https://192-0-2-123.cprapid.com:2087`, the helper will automatically
convert it back to the underlying IP address to avoid DNS resolution issues.
Use `publish_to_whm.php?upload_id=ID&domain=example.com` to create a new WHM account and upload the generated site to the account's `public_html` directory.

## Billing
The app integrates with Stripe for recurring subscriptions. Set `STRIPE_SECRET_KEY`,
`STRIPE_PRICE_ID_MONTHLY`, and `STRIPE_PRICE_ID_YEARLY` in your `.env` file. After registering and
logging in, users can start a subscription and manage billing through the Stripe customer portal.
Webhook events update the `billing_subscriptions` table.

## Admin Dashboard
Users marked as admins can access `admin_dashboard.php` from their account page. The dashboard
lists all registered users along with their latest subscription status and allows administrators
to delete accounts.

