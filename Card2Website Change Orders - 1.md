## Payment System

- Users need to have multiple payments if they have multiple website plans. See the sample code, “multiiple\_stripe.php” to work from.  
- When the Stripe payment fails, the connected website needs to be suspended by the WHM API  
- There needs to be a recurring report from Stripe. It can run in batches. It needs to inform the main application, so that the main application could send emails and texts to each subscriber. 

## Generation Storage

When the generation creates the page and creates the associated images, it needs to record the image details for later use. Alter the database model, adding tables as required. Store the file names, the file URL, the account the image is associated with. Make this available later use in the Packaging Deployment

## Packaging Deployment

When the package is prepared for deployment, call in and include the images that were generated to be associated with the deployment. Edit the HTML file to make the image references are relative to that HTML page. For example, if the images are in the generated\_images sub directory, use /generated\_images in the img src. Do not use the FQDN or the absolute path to the images. Make sure all of the images, CSS and JS in use are in the deployment package.

## Theming

- All of the pages need to use a consistent theme in terms of color and fonts.   
- There needs to be a header with the logo and navigation in a header menu.  
- There needs to be a footer with additional information, and a footer menu that includes linkage to a terms & conditions page, a privacy policy page, a contact page and a user login page. 


## Process Flow: General changes

The generation flow appears slow in the UI. Instead of redirecting users to pages, change the UI.

- Use iframes and Ajax to embed the process steps into a page that changes infrequently. Use this approach to hide the delays as the UI proceeds through the flow.  
- Have the steps communicate with the progress meter to advance the process.  
- Put in GIF spinners as appropriate to keep people engaged.   
- Put in placeholders for text elements that could be inserted along with the spinners.  

## Process Flow: new website

- The user needs to be walked through these steps with a progress indicator at the top of the page.  
  - The user starts with the information step. The features, benefits and costs.   
  - They upload their business card  
  - They are given a screen to show what they may want to correct  
  - After the site is generated, they can regenerate the site, edit the what has been generated, or proceed to the domain name step.  
  - They can use one of the selected domain names, suggest their own, or use one they have previously registered.  
    - The previous registration is new functionality. The user needs to update their nameservers to point to the hosting.   
  - The user subscribes. They go to Stripe and supply their payment details.  
    - The user may already have an account and in this case, they need to log in and add a new subscription for the new website.  
  - When the user subscribes and pays, the system needs to make an account for them, so that they can return and log in later.   
  - The system will go and set-up their web hosting via the WHM API (publish\_to\_whm.php code)  
  - The system will package the website and populate the new hosting (also done via the publish\_to\_whm code).   
  - The system will report that the website has been installed.   
  - There needs to be language about DNS proliferation, written for lay men. This is new to the project.  
  - There needs to be an alert tool to periodically ping the domain to see when the website is live and let the client know. 

## Process Flow: returning user

- There needs to be a login page for returning users  
- There needs to be a password reset / recovery process for returning users. The reset code will make a one-time use code that redirects a user to reset their password.  
- When the user has logged in, take them to a dashboard page. This is a new page in the project.  
- The dashboard shows the website(s) they own, the registration date, the status of the domain.   
- Each website entry in the dashboard can launch three new options:  
  - Edit website – take them to their page on BusinessCard2Website and allow them to edit the page, add images, and change images. When they commit the changes, the changes will be uploaded to their website via the WHM API.  
  - Visit website – take them to their current hosting web page in a new window.   
  - Cancel website – take them to a flow to cancel their website. They will need to confirm that they want to proceed. When they confirm, it will use the WHM API to delete the CPanel account. It will go to Stripe and discontinue the subscription associated with that website. 