First change
=============

I want to implement a change to the design.
Break generate.php into multiple scripts, redirecting after completion so that any one script doesn't timeout. 
After the generate.php knows the details, it should build the text and images, then push that data to the script that will insert the text, images and styling to a building prompt.
On the preview.php, when the user chooses a layout, I want that layout choice to reference basic HTML / CSS for that layout. The application will pass this to the OpenAI prompt with the instruction to insert the contents into the HTML template: text, links, images, color and font choices.
The layouts will be stored at https://businesscard2website.com/html_templates/ and the database should have a list of available html_templates with a query to make those templates known to the preview and generate scripts.

Here are details on this approach:
----------------------------------

**Pros:**
- Precise structural information
- AI can modify exact classes, IDs, content
- Perfect for programmatic implementation

**Cons:**
- Can overwhelm the prompt with verbose code
- Might focus on implementation rather than design intent

## 3. **URL-Based Analysis** ⚠️ Limited Support

**Current Reality:** Most AI APIs (OpenAI, Anthropic) can't directly fetch and analyze live URLs in a single call.

**Workaround Options:**
- Pre-fetch the HTML yourself and include it in the prompt
- Use web scraping tools to grab the layout, then feed to AI
- Some specialized services might offer this, but it's not standard

## 4. **Structured Schema Approach** ✅ Most Scalable

**Approach:** Create a layout description format
```json
{
  "layout_name": "modern_business",
  "sections": [
    {
      "type": "header", 
      "elements": ["logo", "navigation"],
      "style": "fixed, transparent"
    },
    {
      "type": "hero",
      "elements": ["headline", "subtitle", "cta_button", "background_image"],
      "layout": "centered, full-width"
    },
    {
      "type": "features", 
      "elements": ["icon", "title", "description"],
      "layout": "3-column grid"
    }
  ],
  "color_zones": ["primary", "secondary", "accent"],
  "typography_hierarchy": ["h1", "h2", "body", "caption"]
}

Second change:
==============

When the websites are launched, they need to have associated terms.html and privacy.html files. The HTML pages need to be uploaded to CPanel along with the main content. The HTML needs to have a link to these two pages.
The terms.html is a basic Terms & Conditions page that would be adequate as a document to satisfy the needs for a terms page. It should make reference to the website, its ownerhsip and (if possible) it's contact info.
The privacy.html is a basic Privacy Policty page that would be adequate as a document to satisfy the needs for a privacy page. The website will be information, with a contact form introduced in the footer.
The application should generate an XML sitemap. It should reference the pages: the home page, the terms.html and the privacy.html
The robots.txt page should be added and included in the initial upload of the website. It should have a reference to the XML sitemap's URL.
The llms.txt page should be added and included in the initial upload of the website. It should follow best practices as a LLMS text file for the purpose of encouraging AI citations from the available content.

Third change:
=============

There should be a <script> call in the footer. It should make a request to https://funcs.businesscard2website.com/contactform.js -- that should return Javascript to build a contact form visible to the end user. 
Making the contactform.js is outside of the scope of this change. There should be CORS changes in the deployed website to allow the deployed site to request and execute Javascript from https://funcs.businesscard2website.com/ in the web browser for the end user.

