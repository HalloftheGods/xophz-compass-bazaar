<?php
/**
 * Tests for the Amazon search barcode scraper and parser.
 *
 * @package Xophz_Compass_Bazaar
 */

require_once dirname( __DIR__ ) . '/support/harness.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xophz-compass-bazaar-barcode-amazon.php';

$A = 'Xophz_Compass_Bazaar_Barcode_Amazon';

// 1. Successful HTML Parsing with title, image, price, and brand
$html_success = '<div data-component-type="s-search-result" class="s-result-item">
  <div class="s-widget-container">
    <div class="s-product-image-container">
      <img class="s-image" src="https://m.media-amazon.com/images/I/61eF3Mbw9nL._AC_UL320_.jpg" alt="Pepsi" />
    </div>
    <h2><span class="a-size-medium a-color-base a-text-normal">Pepsi Soda, 12 fl oz Cans (Pack of 12)</span></h2>
    <span class="a-size-base-plus a-color-base">Pepsi</span>
    <span class="a-price"><span class="a-offscreen">$7.94</span></span>
  </div>
</div>';

$parsed = $A::parse_search( 200, $html_success );
check( 'amazon: status found', $parsed['status'], 'found' );
check( 'amazon: source is amazon', $parsed['source'], 'amazon' );
check( 'amazon: title extracted', $parsed['product']['title'], 'Pepsi Soda, 12 fl oz Cans (Pack of 12)' );
check( 'amazon: image extracted', $parsed['product']['image_url'], 'https://m.media-amazon.com/images/I/61eF3Mbw9nL._AC_UL320_.jpg' );
check( 'amazon: image urls array populated', in_array( 'https://m.media-amazon.com/images/I/61eF3Mbw9nL._AC_UL320_.jpg', $parsed['product']['image_urls'], true ), true );
check( 'amazon: brand extracted', $parsed['product']['brand'], 'Pepsi' );
check( 'amazon: price hint low', $parsed['price_hint']['low'], '7.94' );
check( 'amazon: price hint source', $parsed['price_hint']['source'], 'amazon' );

// 2. Data-cy headline variant
$html_cy = '<div data-component-type="s-search-result">
  <div data-cy="title-recipe-headline"><span>Anker USB C Charger 20W</span></div>
  <img class="s-image" src="https://images-na.ssl-images-amazon.com/images/I/anker.jpg" />
  <span class="a-offscreen">$14.99</span>
</div>';

$parsed_cy = $A::parse_search( 200, $html_cy );
check( 'amazon data-cy: status found', $parsed_cy['status'], 'found' );
check( 'amazon data-cy: title extracted', $parsed_cy['product']['title'], 'Anker USB C Charger 20W' );
check( 'amazon data-cy: price hint', $parsed_cy['price_hint']['low'], '14.99' );

// 3. Bot challenge / CAPTCHA detection
$html_bot = '<html><head><title>Robot Check</title></head><body>
<p>To discuss automated access to Amazon data please contact api-services-support@amazon.com.</p>
<form action="/errors/validateCaptcha">Enter the characters you see below</form>
</body></html>';

$parsed_bot = $A::parse_search( 200, $html_bot );
check( 'amazon bot: detects challenge', $parsed_bot['status'], 'bot_blocked' );
check( 'amazon bot 503: detects status 503', $A::parse_search( 503, '' )['status'], 'bot_blocked' );

// 4. Zero results / 404
check( 'amazon 404: not_found', $A::parse_search( 404, '' )['status'], 'not_found' );

$html_zero = '<div class="s-no-outline"><span class="a-size-medium">No results for 01234567890123.</span></div>';
check( 'amazon empty: not_found', $A::parse_search( 200, $html_zero )['status'], 'not_found' );
