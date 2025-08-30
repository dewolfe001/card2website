<?php

require_once 'vendor/autoload.php';

class WebsiteSubscriptionManager 
{
    private $stripe;
    
    public function __construct($stripeSecretKey) 
    {
        \Stripe\Stripe::setApiKey($stripeSecretKey);
        $this->stripe = new \Stripe\StripeClient($stripeSecretKey);
    }
    
    /**
     * Create or get existing Stripe customer
     */
    public function createOrGetCustomer($userEmail, $userName, $userId = null) 
    {
        try {
            // Try to find existing customer by email
            $customers = $this->stripe->customers->all([
                'email' => $userEmail,
                'limit' => 1
            ]);
            
            if (!empty($customers->data)) {
                return $customers->data[0];
            }
            
            // Create new customer if none exists
            $customer = $this->stripe->customers->create([
                'email' => $userEmail,
                'name' => $userName,
                'metadata' => [
                    'user_id' => $userId
                ]
            ]);
            
            return $customer;
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new Exception("Error creating/getting customer: " . $e->getMessage());
        }
    }
    
    /**
     * Create a subscription for a specific website
     */
    public function createWebsiteSubscription($customerId, $priceId, $websiteId, $websiteDomain) 
    {
        try {
            $subscription = $this->stripe->subscriptions->create([
                'customer' => $customerId,
                'items' => [
                    [
                        'price' => $priceId,
                    ],
                ],
                'metadata' => [
                    'website_id' => $websiteId,
                    'website_domain' => $websiteDomain,
                    'service_type' => 'website_hosting'
                ],
                'expand' => ['latest_invoice.payment_intent'],
            ]);
            
            return $subscription;
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new Exception("Error creating subscription: " . $e->getMessage());
        }
    }
    
    /**
     * Add a new website subscription to existing customer
     */
    public function addWebsiteToCustomer($customerId, $priceId, $websiteId, $websiteDomain) 
    {
        return $this->createWebsiteSubscription($customerId, $priceId, $websiteId, $websiteDomain);
    }
    
    /**
     * Get all subscriptions for a customer
     */
    public function getCustomerSubscriptions($customerId) 
    {
        try {
            $subscriptions = $this->stripe->subscriptions->all([
                'customer' => $customerId,
                'status' => 'all',
                'expand' => ['data.items.data.price.product']
            ]);
            
            return $subscriptions->data;
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new Exception("Error fetching subscriptions: " . $e->getMessage());
        }
    }
    
    /**
     * Cancel a specific website subscription
     */
    public function cancelWebsiteSubscription($subscriptionId, $websiteId) 
    {
        try {
            // Verify the subscription belongs to the website
            $subscription = $this->stripe->subscriptions->retrieve($subscriptionId);
            
            if ($subscription->metadata['website_id'] !== $websiteId) {
                throw new Exception("Subscription does not belong to this website");
            }
            
            $canceledSubscription = $this->stripe->subscriptions->cancel($subscriptionId);
            
            return $canceledSubscription;
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new Exception("Error canceling subscription: " . $e->getMessage());
        }
    }
    
    /**
     * Update subscription (e.g., change plan)
     */
    public function updateWebsiteSubscription($subscriptionId, $newPriceId, $websiteId) 
    {
        try {
            $subscription = $this->stripe->subscriptions->retrieve($subscriptionId);
            
            // Verify ownership
            if ($subscription->metadata['website_id'] !== $websiteId) {
                throw new Exception("Subscription does not belong to this website");
            }
            
            $updated = $this->stripe->subscriptions->update($subscriptionId, [
                'items' => [
                    [
                        'id' => $subscription->items->data[0]->id,
                        'price' => $newPriceId,
                    ]
                ],
                'proration_behavior' => 'create_prorations',
            ]);
            
            return $updated;
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new Exception("Error updating subscription: " . $e->getMessage());
        }
    }
    
    /**
     * Get subscription by website ID
     */
    public function getSubscriptionByWebsiteId($customerId, $websiteId) 
    {
        $subscriptions = $this->getCustomerSubscriptions($customerId);
        
        foreach ($subscriptions as $subscription) {
            if (isset($subscription->metadata['website_id']) && 
                $subscription->metadata['website_id'] === $websiteId) {
                return $subscription;
            }
        }
        
        return null;
    }
    
    /**
     * Handle webhook events
     */
    public function handleWebhook($payload, $sigHeader, $webhookSecret) 
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, 
                $sigHeader, 
                $webhookSecret
            );
            
            switch ($event->type) {
                case 'invoice.payment_succeeded':
                    $this->handlePaymentSucceeded($event->data->object);
                    break;
                    
                case 'invoice.payment_failed':
                    $this->handlePaymentFailed($event->data->object);
                    break;
                    
                case 'customer.subscription.deleted':
                    $this->handleSubscriptionCanceled($event->data->object);
                    break;
                    
                default:
                    // Handle other webhook types as needed
                    break;
            }
            
            return ['status' => 'success'];
            
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            throw new Exception("Webhook signature verification failed");
        }
    }
    
    private function handlePaymentSucceeded($invoice) 
    {
        $subscriptionId = $invoice->subscription;
        $subscription = $this->stripe->subscriptions->retrieve($subscriptionId);
        
        if (isset($subscription->metadata['website_id'])) {
            $websiteId = $subscription->metadata['website_id'];
            // Update your database to reflect successful payment for this website
            error_log("Payment succeeded for website: " . $websiteId);
        }
    }
    
    private function handlePaymentFailed($invoice) 
    {
        $subscriptionId = $invoice->subscription;
        $subscription = $this->stripe->subscriptions->retrieve($subscriptionId);
        
        if (isset($subscription->metadata['website_id'])) {
            $websiteId = $subscription->metadata['website_id'];
            // Handle failed payment - maybe suspend the website
            error_log("Payment failed for website: " . $websiteId);
        }
    }
    
    private function handleSubscriptionCanceled($subscription) 
    {
        if (isset($subscription->metadata['website_id'])) {
            $websiteId = $subscription->metadata['website_id'];
            // Handle subscription cancellation - suspend/delete website
            error_log("Subscription canceled for website: " . $websiteId);
        }
    }
}

    /**
     * Create subscriptions for multiple websites at once
     */
    public function createMultipleWebsiteSubscriptions($customerId, $websites) 
    {
        $createdSubscriptions = [];
        $errors = [];
        
        foreach ($websites as $website) {
            try {
                $subscription = $this->createWebsiteSubscription(
                    $customerId,
                    $website['price_id'],
                    $website['website_id'],
                    $website['domain']
                );
                
                $createdSubscriptions[] = [
                    'website_id' => $website['website_id'],
                    'domain' => $website['domain'],
                    'subscription' => $subscription,
                    'status' => 'success'
                ];
                
            } catch (Exception $e) {
                $errors[] = [
                    'website_id' => $website['website_id'],
                    'domain' => $website['domain'],
                    'error' => $e->getMessage(),
                    'status' => 'failed'
                ];
            }
        }
        
        return [
            'successful' => $createdSubscriptions,
            'failed' => $errors,
            'total_processed' => count($websites),
            'success_count' => count($createdSubscriptions),
            'error_count' => count($errors)
        ];
    }
    
    /**
     * Bulk cancel subscriptions by website IDs
     */
    public function cancelMultipleWebsiteSubscriptions($customerId, $websiteIds) 
    {
        $results = [];
        
        foreach ($websiteIds as $websiteId) {
            try {
                $subscription = $this->getSubscriptionByWebsiteId($customerId, $websiteId);
                
                if ($subscription) {
                    $canceled = $this->cancelWebsiteSubscription($subscription->id, $websiteId);
                    $results[] = [
                        'website_id' => $websiteId,
                        'subscription_id' => $subscription->id,
                        'status' => 'canceled',
                        'canceled_at' => $canceled->canceled_at
                    ];
                } else {
                    $results[] = [
                        'website_id' => $websiteId,
                        'status' => 'not_found',
                        'message' => 'No active subscription found'
                    ];
                }
                
            } catch (Exception $e) {
                $results[] = [
                    'website_id' => $websiteId,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
}

// Usage Examples - Scalable for any number of websites
try {
    $subscriptionManager = new WebsiteSubscriptionManager('sk_test_...');
    
    // 1. Create or get customer
    $customer = $subscriptionManager->createOrGetCustomer(
        'user@example.com',
        'John Doe',
        'user_123'
    );
    
    // 2. Define websites dynamically - works with 1, 5, 50, or any number
    $websitesToCreate = [
        [
            'website_id' => 'website_1',
            'domain' => 'example.com',
            'price_id' => 'price_basic_hosting'
        ],
        [
            'website_id' => 'website_2',
            'domain' => 'myblog.net',
            'price_id' => 'price_premium_hosting'
        ],
        [
            'website_id' => 'website_3',
            'domain' => 'company-site.org',
            'price_id' => 'price_enterprise_hosting'
        ],
        // Add as many as needed - the system scales automatically
    ];
    
    // 3. Create all subscriptions in one batch
    $results = $subscriptionManager->createMultipleWebsiteSubscriptions(
        $customer->id, 
        $websitesToCreate
    );
    
    // 4. Report results
    echo "Processing Results:\n";
    echo "Total websites processed: {$results['total_processed']}\n";
    echo "Successfully created: {$results['success_count']}\n";
    echo "Failed: {$results['error_count']}\n\n";
    
    // 5. Display successful subscriptions
    foreach ($results['successful'] as $success) {
        echo "✓ Created subscription for {$success['domain']} (ID: {$success['subscription']->id})\n";
    }
    
    // 6. Display any errors
    foreach ($results['failed'] as $failure) {
        echo "✗ Failed to create subscription for {$failure['domain']}: {$failure['error']}\n";
    }
    
    // 7. Example: Adding more websites later (user adds 5 more sites)
    $additionalWebsites = [
        ['website_id' => 'website_4', 'domain' => 'newsite1.com', 'price_id' => 'price_basic_hosting'],
        ['website_id' => 'website_5', 'domain' => 'newsite2.com', 'price_id' => 'price_basic_hosting'],
        ['website_id' => 'website_6', 'domain' => 'newsite3.com', 'price_id' => 'price_premium_hosting'],
        ['website_id' => 'website_7', 'domain' => 'newsite4.com', 'price_id' => 'price_basic_hosting'],
        ['website_id' => 'website_8', 'domain' => 'newsite5.com', 'price_id' => 'price_enterprise_hosting'],
    ];
    
    $additionalResults = $subscriptionManager->createMultipleWebsiteSubscriptions(
        $customer->id, 
        $additionalWebsites
    );
    
    echo "\nAdded {$additionalResults['success_count']} more website subscriptions\n";
    
    // 8. Get all current subscriptions (now should have 8 total if all succeeded)
    $allSubscriptions = $subscriptionManager->getCustomerSubscriptions($customer->id);
    echo "Customer now has " . count($allSubscriptions) . " active website subscriptions\n";
    
    // 9. Example: Cancel multiple websites at once
    $websitesToCancel = ['website_2', 'website_4', 'website_6'];
    $cancelResults = $subscriptionManager->cancelMultipleWebsiteSubscriptions(
        $customer->id, 
        $websitesToCancel
    );
    
    echo "\nCancellation Results:\n";
    foreach ($cancelResults as $result) {
        echo "Website {$result['website_id']}: {$result['status']}\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Example integration with your application logic
function processUserWebsites($userId, $websiteData) 
{
    $subscriptionManager = new WebsiteSubscriptionManager($_ENV['STRIPE_SECRET_KEY']);
    
    // Get user info from your database
    $user = getUserById($userId); // Your function
    
    // Create or get Stripe customer
    $customer = $subscriptionManager->createOrGetCustomer(
        $user['email'], 
        $user['name'], 
        $userId
    );
    
    // Process however many websites the user has
    $results = $subscriptionManager->createMultipleWebsiteSubscriptions(
        $customer->id, 
        $websiteData
    );
    
    // Update your database with the results
    foreach ($results['successful'] as $success) {
        updateWebsiteSubscription($success['website_id'], $success['subscription']->id);
    }
    
    return $results;
}

// Mock function - replace with your actual database function
function getUserById($userId) {
    return ['email' => 'user@example.com', 'name' => 'John Doe'];
}

function updateWebsiteSubscription($websiteId, $subscriptionId) {
    // Update your database with the Stripe subscription ID
    // UPDATE websites SET stripe_subscription_id = ? WHERE id = ?
}

?>
