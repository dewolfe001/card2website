**Project Title:** BusinessCard2Website.com

**Purpose:** Enable users to upload a photo of their business card and receive a simple, AI-generated one-page website based on the card content. The backend is built with PHP and MySQL.

**Target Users:**

* Small business owners  
* Freelancers and solopreneurs  
* Networking professionals

**Core Features:**

1. **Landing Page**  
   * Header, Hero Section, CTA: "Upload Your Business Card to Get a Website"  
   * Benefits and how it works section  
   * Example generated sites (gallery)  
   * FAQ and contact info  
2. **Image Upload Module**  
   * Upload field for business card image (JPG, PNG, PDF)  
   * Drag-and-drop and file-picker support  
   * Client-side validation (max size, file type)  
   * Progress indicator  
   * PHP handler to move image to /uploads/ and save metadata in MySQL  
3. **OCR Extraction System**  
   * Server-side PHP script calls Tesseract or Google Cloud Vision API  
   * Parses results into JSON with fields:  
     * name, title, company, phone, email, website, address, tagline, etc.  
   * Provide a confirmation interface where users can review and edit extracted fields before proceeding  
   * Optional text area for users to add more descriptive details or content they'd like included in their site  
   * OCR-to-form UI: Responsive frontend form prefilled with OCR data. Users can correct errors, add missing info, and submit the revised version.  
   * Store OCR output and user-edited data in MySQL  
4. **AI HTML Generator**  
   * PHP-based script formats data into prompts  
   * Calls OpenAI or similar to generate simple HTML with inline CSS  
   * Stores generated HTML in MySQL and as .html file  
   * Uses provided data to enhance SEO and content quality  
   * Adds meta tags (title, description, keywords)  
   * Includes LD-JSON structured data:  
     * Business name, contact info, business type, latitude/longitude (via geocoding API from address), NAICS code (via keyword mapping and AI inference)  
     * Use of schema.org markup with appropriate @type (e.g., LocalBusiness, ProfessionalService, Dentist, RealEstateAgent, etc.)  
   * Adds structured data such as opening hours, contact point, and address using schema.org format  
   * Embeds schema.org microdata relevant to the business type  
   * Ensures all images use descriptive title and alt attributes for accessibility and SEO  
   * Generates LD-JSON automatically with fallback values when key fields are missing; flags missing critical metadata for admin review  
5. **Logo Detection and Cleanup**  
   * System attempts to detect and isolate logo from uploaded card  
   * Uses image processing (e.g., OpenCV or PHP image functions) to enhance clarity and remove backgrounds  
   * Auto-corrects skew, rotation, and borders  
   * Uses this logo prominently in the generated HTML with appropriate image tags and SEO attributes  
6. **User Website Viewer**  
   * Display preview of generated website (iframe or embedded page)  
   * Provide unique URL (e.g., businesscard2website.com/site/12345)  
   * Option to download ZIP of generated site  
7. **Admin Interface**  
   * View all submissions  
   * OCR text correction interface  
   * Re-run AI generation  
   * Delete/flag content  
8. **Email Notifications**  
   * User receives confirmation \+ link to site  
   * Admin notified of new submission  
9. **Future Features (Phase 2+)**  
   * Styling options: theme/color picker  
   * Editable builder UI for simple edits  
   * Payment integration for custom domains  
   * Analytics dashboard for users

**Technical Stack:**

* Frontend: HTML5, TailwindCSS, Alpine.js  
* Backend: PHP 8+, MySQL 8  
* OCR: Tesseract (PHP wrapper) or Google Cloud Vision API  
* AI: OpenAI GPT-4 via PHP SDK or API integration  
* Hosting: Apache/Nginx on Linux, HTTPS with Let's Encrypt

**Database Tables Overview:**

* users (optional, if authentication is added)  
* uploads (id, filename, user\_id, created\_at)  
* ocr\_data (id, upload\_id, json\_data, created\_at)  
* generated\_sites (id, upload\_id, html\_code, public\_url, created\_at)

**Security & Privacy:**

* Limit file size and sanitize uploads  
* Use HTTPS for all requests  
* Clean inputs and outputs to prevent XSS/SQL injection  
* Add user data expiration policies (e.g., auto-delete after 90 days)

**Timeline Estimate (MVP):**

* Week 1: Landing page \+ file upload \+ PHP backend  
* Week 2: OCR \+ MySQL integration \+ text confirmation UI  
* Week 3: AI prompt generation \+ HTML output \+ preview \+ SEO features \+ LD-JSON integration  
* Week 4: Admin tools \+ notification system \+ QA

**Success Criteria:**

* User can upload a business card and receive a preview within 1–2 minutes  
* OCR captures at least 90% of key details correctly  
* Generated websites are valid, mobile-friendly, SEO-optimized, and structured for semantic clarity

**KPIs:**

* Conversion rate (upload to preview completion)  
* Accuracy rate of OCR fields  
* Bounce rate on generated sites  
* Time to first byte / site generation speed  
* SEO scoring metrics (structured data, meta tags, image attributes)

**Modular Approach: Product Requirements Document (PRD)**

---

**Project Title:** BusinessCard2Website.com (Modular Architecture)

**Purpose:**  
 Enable users to upload a photo of their business card and receive a simple, AI-generated one-page website based on the card content. This system is decomposed into modular PHP applications for OCR, NAICS inference, domain availability, billing, and publishing.

**Target Users:**

* Small business owners

* Freelancers and solopreneurs

* Networking professionals

---

## **Sub-Project 1: OCR Microservice**

**Purpose:**  
 Receive uploaded images of business cards and extract structured text.

**Features:**

* PHP-based Tesseract or Google Vision OCR processing

* Normalize data: name, title, company, phone, email, website, address

* Respond with structured JSON

* Optional: basic logo detection and image enhancement

* Stores image and extracted data in MySQL

## **Sub-Project 2: NAICS Code Inference Service**

**Purpose:**  
 Accept structured business data and return the best-matching NAICS code.

**Features:**

* PHP backend integrates with OpenAI to map data to NAICS codes

* Result includes NAICS code, title, and description

* Writes to MySQL table: `naics_classifications`

* Accepts REST API payload from OCR service or main app

## **Sub-Project 3: Domain Name Discovery & Availability Check**

**Purpose:**  
 Suggest and validate domain names.

**Features:**

* Receives OCR and NAICS data

* Brainstorms potential brand and domain names via AI

* Integrates with Namecheap API to check availability

* Returns available domains with metadata (e.g., cost, TLD)

## **Sub-Project 4: Domain Registration API**

**Purpose:**  
 Purchase domains from Namecheap upon user approval.

**Features:**

* Accepts user confirmation and selected domain name

* Sends purchase request to Namecheap API using secure credentials

* Handles responses (success/failure)

* Stores ownership data in `domain_registrations` table

## **Sub-Project 5: Billing System**

**Purpose:**  
 Charge customers via Stripe and manage subscriptions.

**Features:**

* PHP integration with Stripe Billing API

* Supports monthly/annual recurring plans

* Generates Stripe Checkout Sessions

* Stores user Stripe ID, subscription status in `billing_subscriptions`

* Webhooks for cancellation, failure, and renewal

## **Sub-Project 6: Website Publishing Service**

**Purpose:**  
 Generate and deploy static websites using business data.

**Features:**

* Accepts structured input (OCR, NAICS, user-edited data)

* Generates HTML, CSS, and JS using AI prompt to HTML generator

* Includes:

  * Meta tags

  * LD-JSON structured data (NAICS, address, businessType, etc.)

  * schema.org markup

  * Alt/title on all images

* Uploads to static hosting (e.g., separate server, FTP)

* Assigns final domain/subdomain

* Logs publishing status in MySQL

## **Shared Services and Core System**

**Database Tables:**

* uploads (id, filename, user\_id, created\_at)

* ocr\_data (upload\_id, json\_data, created\_at)

* naics\_classifications (upload\_id, code, description)

* domain\_suggestions (upload\_id, suggestion, availability, checked\_at)

* domain\_registrations (domain, registrar\_id, purchase\_date, user\_id)

* billing\_subscriptions (user\_id, stripe\_id, plan\_type, active\_status)

* published\_sites (upload\_id, html\_url, domain, status, published\_at)

**Security & Compliance:**

* All API calls authenticated with secure tokens

* HTTPS enforced on all endpoints

* Validate and sanitize input/output

* Payment and personal data handled according to PCI and privacy standards

**Deployment Strategy:**

* Each sub-project as a standalone PHP app with RESTful interface

* Internal API Gateway to orchestrate processes

* MySQL as shared data layer

**Success Criteria:**

* Seamless handoff between sub-projects via REST APIs

* OCR accuracy \>= 80%, NAICS mapping \>= 90% relevance

* Domain check latency \< 3s, registration success \>= 95%

* Billing setup time \< 1 minute

* Page deployment & DNS prop within 2–5 minutes

**Timeline Breakdown:**

* Week 1: OCR App \+ Upload Handling

* Week 2: NAICS App \+ Text Review Interface

* Week 3: Domain Brainstorming \+ Namecheap Integration

* Week 4: Stripe Billing Integration

* Week 5: HTML Generator \+ Web Publisher \+ DNS Manager

* Week 6: QA, Security, and Launch

---

Each sub-project is modular and documented for separate development tracks with integration tests between them. Documentation and OpenAPI specs should be generated for each API.

