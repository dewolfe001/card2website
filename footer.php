<footer class="bg-gray-900 text-gray-300 mt-16">
    <div class="container mx-auto px-4 py-12 grid grid-cols-1 md:grid-cols-4 gap-8">
        <div class="md:col-span-2">
            <div class="flex items-center mb-4">
                <img src="cardbot.png" alt="<?=__('business_card_to_website')?>" class="h-8 mr-2">
                <span class="text-white font-bold text-xl"><?=__('business_card_to_website')?></span>
            </div>
            <p class="mb-4"><?=__('footer_description')?></p>
            <div class="flex space-x-4 text-sm">
                <span>🔒 <?=__('ssl_secured')?></span>
                <span>✅ <?=__('gdpr_compliant')?></span>
            </div>
        </div>
        <div>
            <h3 class="text-white font-semibold mb-2"><?=__('legal')?></h3>
            <ul class="space-y-1">
                <li><a href="terms.php" class="hover:underline"><?=__('terms_and_conditions')?></a></li>
                <li><a href="privacy.php" class="hover:underline"><?=__('privacy_policy')?></a></li>
                <li><a href="https://web321.co/" target="_new" class="hover:underline"><?=__('web321')?></a></li>
            </ul>
        </div>
        <div>
            <h3 class="text-white font-semibold mb-2"><?=__('support')?></h3>
            <ul class="space-y-1">
                <li><a href="contact.php" class="hover:underline"><?=__('contact')?></a></li>
                <li><a href="login.php" class="hover:underline"><?=__('login')?></a></li>
            </ul>
            <h3 class="text-white font-semibold mt-4 mb-2"><?=__('follow_us')?></h3>
            <div class="flex space-x-3 text-sm">
                <a href="#" class="hover:text-white" aria-label="<?=__('twitter')?>"><?=__('twitter')?></a>
                <a href="#" class="hover:text-white" aria-label="<?=__('facebook')?>"><?=__('facebook')?></a>
            </div>
        </div>
    </div>
    <div class="border-t border-gray-700 text-center text-sm text-gray-400 py-4">
        <p>&copy; <?=date('Y')?> <?=__('business_card_to_website')?>. <?=__('all_rights_reserved')?></p>
        <p class="mt-2"><?=__('made_with_love')?></p>
    </div>
</footer>
