<?php
/**
 * Plugin Name: DAH Booking Calendar
 * Description: Booking calendar + prepayment summary & form + payment page redirect.
 * Version:     1.1.2
 * Author:      Your Name
 * License:     GPL2
 * Text Domain: dah-booking-calendar
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 0) On init, pull ?order, ?propertyThumbnail, ?depositPercent, ?depositDays
 *    into cookies + $_COOKIE.
 */
add_action( 'init', function(){
    if ( ! empty( $_GET['order'] ) ) {
        $json = urldecode( stripslashes( $_GET['order'] ) );
        setcookie( 'order', $json, time() + HOUR_IN_SECONDS, '/' );
        $_COOKIE['order'] = $json;
    }
    foreach ( [ 'propertyThumbnail', 'depositPercent', 'depositDays' ] as $p ) {
        if ( ! empty( $_GET[ $p ] ) ) {
            $val = urldecode( stripslashes( $_GET[ $p ] ) );
            setcookie( $p, $val, time() + HOUR_IN_SECONDS, '/' );
            $_COOKIE[ $p ] = $val;
        }
    }
});

/**
 * 1) [dah_booking_calendar] shortcode
 */
add_shortcode( 'dah_booking_calendar', function() {
    // enqueue Flatpickr
    wp_enqueue_style(  'flatpickr-css',
        'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
        [], null
    );
    wp_enqueue_script( 'flatpickr-js',
        'https://cdn.jsdelivr.net/npm/flatpickr',
        [], null, true
    );

    // gather data from this post
    $post_id         = get_the_ID();
    $pid             = get_post_meta( $post_id, 'property_id',    true );
    $title           = get_the_title( $post_id );
    $base            = floatval( get_post_meta( $post_id, 'base_price',       true ) );
    $minN            = intval(  get_post_meta( $post_id, 'minnights',       true ) );
    $maxN            = intval(  get_post_meta( $post_id, 'maxnights',       true ) );
    $depPct          = floatval( get_post_meta( $post_id, 'deposit_percent',  true ) );
    $depDays         = intval(  get_post_meta( $post_id, 'deposit_days',     true ) );
    $thumb           = get_the_post_thumbnail_url( $post_id, 'medium' ) ?: '';
    $arrow           = 'https://booking.dublinathome.com/img/date_arrow.png';
    // ────────────────────────────────────────────────────────────────────────────
    // React app’s “prices.securityDepositFee” → our new meta key “security_deposit_fee”
    $securityDeposit = floatval( get_post_meta( $post_id, 'security_deposit_fee', true ) );
    // If you haven’t set it yet, default to 0:
    if ( ! $securityDeposit ) {
        $securityDeposit = 0;
    }

    // enqueue your JS, version-busted
    wp_enqueue_script(
      'dah-booking-js',
      plugin_dir_url( __FILE__ ) . 'js/dah-booking.js',
      [ 'jquery', 'flatpickr-js' ],
      filemtime( plugin_dir_path( __FILE__ ) . 'js/dah-booking.js' ),
      true
    );

    // localize everything (now including paymentUrl)
    wp_localize_script( 'dah-booking-js', 'DAHBooking', [
        'restBase'         => esc_url_raw( rest_url( 'dah/v1' ) ),
        'ajaxUrl'          => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
        'propertyId'       => esc_js( $pid ),
        'propertyName'     => esc_js( $title ),
        'minNights'        => $minN,
        'maxNights'        => $maxN,
        'currency'         => '€',
        'basePrice'        => $base,
        'depositPercent'   => $depPct,
        'depositDays'      => $depDays,
        'propertyThumbnail'=> esc_url( $thumb ),
        'arrowImgUrl'      => esc_url( $arrow ),
        'prepaymentUrl'    => esc_url_raw( home_url( '/prepayment' ) ),
        'paymentUrl'       => esc_url_raw( home_url( '/payment' ) ),
    ] );

    // output the Flatpickr ticket HTML
    ob_start(); ?>
    <div class="Calendar_ticket__qaYVc">
      <div id="dah-calendar"></div>
      <div>
        <div class="Calendar_bookMessage__5Hh2x">
          Book <?php echo esc_html( $title ); ?>
        </div>
        <div class="Calendar_cardText__RA_ob">
          <div class="Calendar_dateLabel__v0z7B">
            Arrival <img class="Calendar_arrow__f19cY" src="<?php echo esc_url($arrow); ?>">
            Departure
          </div>
          <div>
            <div class="Calendar_price__GEKBc">
              €<?php echo number_format( $base, 0 ); ?>
            </div>
            <div class="Calendar_price__Depos"></div>
            <div class="Calendar_normalText__swkEq">
              (Per night. Pick dates for exact price)
            </div>
            <br>
            <form method="POST" data-hs-cf-bound="true">
              <div class="cta-main green booking-btn disabled">
                <span>Pick some dates</span>
                <!-- inline SVG omitted for brevity -->
              </div>
            </form>
          </div>
        </div>









      </div>
    </div>
    <?php
    return ob_get_clean();
});

/**
 * 2) Proxy Guesty calendar
 */
add_action( 'rest_api_init', function(){
    register_rest_route( 'dah/v1', '/calendar', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function( WP_REST_Request $req ){
            $pid   = sanitize_text_field( $req->get_param('propertyId') );
            $from  = sanitize_text_field( $req->get_param('from') );
            $to    = sanitize_text_field( $req->get_param('to') );
            $url   = add_query_arg(
                [ 'from'=>$from, 'to'=>$to ],
                "https://open-api.guesty.com/v1/listings/{$pid}/calendar"
            );
            $token = glj_get_guesty_token();
            if ( is_wp_error($token) ) {
                return new WP_Error( 'token_error', $token->get_error_message(), [ 'status'=>500 ] );
            }
            $res = wp_remote_get( $url, [
                'headers'=>[
                  'Authorization'=>"Bearer {$token}",
                  'Accept'=>'application/json'
                ],
                'timeout'=>15
            ]);
            if ( is_wp_error($res) ) {
                return new WP_Error( 'api_error', $res->get_error_message(), [ 'status'=>500 ] );
            }
            $data = json_decode( wp_remote_retrieve_body($res), true );
            if ( json_last_error() ) {
                return new WP_Error( 'parse_error', json_last_error_msg(), [ 'status'=>500 ] );
            }
            return rest_ensure_response( $data );
        }
    ]);
});

/**
 * 3) AJAX: save order JSON into a cookie
 */
function dah_save_order_handler() {
    if ( empty( $_POST['order'] ) ) {
        wp_send_json_error( 'No order data', 400 );
    }
    $o = wp_unslash( $_POST['order'] );
    setcookie( 'order', $o, time() + HOUR_IN_SECONDS, '/' );
    wp_send_json_success();
}
add_action( 'wp_ajax_nopriv_dah_save_order', 'dah_save_order_handler' );
add_action( 'wp_ajax_dah_save_order',        'dah_save_order_handler' );

/**
 * 4) [dah_booking_prepayment] shortcode
 *    Renders booking form + details and enqueues JS (with paymentUrl).
 */
add_shortcode( 'dah_booking_prepayment', function() {
    // Enqueue the booking JS and pass paymentUrl
    wp_enqueue_script( 'dah-booking-js' );
    wp_localize_script( 'dah-booking-js', 'DAHBooking', [
        'paymentUrl' => esc_url_raw( home_url( '/payment' ) ),
    ] );

    // 1) Decode “order” from the cookie
    $raw = $_COOKIE['order'] ?? '';
    if ( empty( $raw ) ) {
        return '<p>No booking data found.</p>';
    }
    $json_order = rawurldecode( $raw );
    $o = json_decode( $json_order, true );
    if ( json_last_error() || ! is_array( $o ) ) {
        return '<p>Invalid booking data.</p>';
    }

    // 2) Lookup the real WP post‐ID by matching meta 'property_id' to the raw string
    $passedPropId = sanitize_text_field( $o['propertyId'] );
    $wp_posts = get_posts([
        'post_type'      => 'property',
        'meta_key'       => 'property_id',
        'meta_value'     => $passedPropId,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);
    if ( ! empty( $wp_posts ) ) {
        $realPostId = intval( $wp_posts[0] );
    } else {
        $realPostId = 0;
    }

    // 3) Fetch the 'security_deposit_fee' from that post ID (or default to 0)
    $securityDeposit = 0;
    if ( $realPostId ) {
        $securityDeposit = floatval(
            get_post_meta( $realPostId, 'security_deposit_fee', true )
        );
        if ( ! $securityDeposit ) {
            $securityDeposit = 0;
        }
    }

    // 4) Compute price + deposit amount
    $price  = floatval( $o['price'] );
    $depAmt = $securityDeposit;

    // 5) Fetch thumbnail
    $thumb = rawurldecode( $_COOKIE['propertyThumbnail'] ?? '' );

    // 6) Format dates
    $from = date_i18n( 'D, M j Y', strtotime( $o['arrivalDate'] ) );
    $to   = date_i18n( 'D, M j Y', strtotime( $o['departureDate'] ) );

    // 7) Render output
    ob_start();
    ?>
    <style>
    .d-flex { display: flex!important; }
    .flex-lg-row { flex-direction: row!important; }
    .flex-column { flex-direction: column!important; }
    .row { 
      --bs-gutter-x: 1.5rem; 
      --bs-gutter-y: 0; 
      display: flex; 
      flex-wrap: wrap; 
      flex-direction: row!important; 
      margin-top: calc(-1 * var(--bs-gutter-y)); 
      margin-right: calc(-.5 * var(--bs-gutter-x)); 
      margin-left: calc(-.5 * var(--bs-gutter-x)); 
    }
    .order-lg-2 { order: 2!important; }
    .order-md-1 { order: 1!important; }
    .col-lg-6 { flex: 0 0 auto; width: 50%; }
    .col-md-12 { width: 48%!important; }
    .row > * { 
      flex-shrink: 0; 
      padding-right: calc(var(--bs-gutter-x) * .5); 
      padding-left: calc(var(--bs-gutter-x) * .5); 
      margin-top: var(--bs-gutter-y); 
    }
    #dah-purchase-form { 
      display: flex; 
      flex-direction: column; 
      justify-content: flex-start; 
      align-items: stretch; 
      gap: 12px; 
      flex-wrap: wrap; 
    }
    #dah-purchase-form .row,
    #dah-purchase-form .col,
    #dah-purchase-form .row .col input {
      width: 100%!important;
    }
    #dah-purchase-form .row .col input[type="checkbox"] {
      width: auto!important;
    }
    </style>
    <style>
    .Payment_central__u2At9 { text-align: center; }
    .Payment_submitButton__8EiG9 {
      background-color: #40a594;
      color: #fff;
      padding: .75rem;
      border: none;
      border-radius: 25px;
      font-size: 1rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      margin-top: 20px;
      gap: .5rem;
    }
    .Payment_bookingContainer__Lvu3R {
      background-color: #fff;
      padding: 50px;
      border-radius: 50px;
      margin-top: 40px;
      margin-bottom: 40px;
    }
    .Payment_ticketPic__gjGqX { 
      max-width: 100%; 
      height: auto; 
      border-radius: 50px; 
      width: 100%; 
    }
    .Payment_propertyData__1vbZG { 
      padding-top: 40px; 
      padding-left: 40px; 
    }
    .Payment_propertySubData__SQ1p6 { 
      padding-top: 20px; 
      padding-left: 40px; 
      padding-bottom: 20px; 
    }
    .Payment_propertySubDataRight__clE2m { 
      padding-top: 20px; 
      padding-right: 40px; 
      padding-bottom: 20px; 
    }
    .Payment_propertyData__1vbZG h4 { 
      font-size: 25px; 
      font-weight: lighter; 
    }
    .Payment_propertySubDataRight__clE2m h5, 
    .Payment_propertySubData__SQ1p6 h5 {
      font-size: 20px; 
      line-height: 2rem; 
      color: #818181; 
    }
    .Payment_propertySubDataRight__clE2m h5 {
      text-align: right; 
      padding-right: 40px; 
    }
    .Payment_propertySubData__SQ1p6 p { 
      font-size: 16px; 
      color: #818181; 
    }
    .Payment_bolder__36Yvn { font-weight: bolder; }
    </style>

    <div class="Payment_bookingContainer__Lvu3R container">
      <div class="d-flex flex-column flex-lg-row row">

        <!-- Left pane: property details & deposit summary -->
        <div class="order-md-1 order-lg-2 col-lg-6 col-md-12">
          <?php if ( $thumb ): ?>
            <img class="Payment_ticketPic__gjGqX" src="<?php echo esc_url( $thumb ); ?>" alt="">
          <?php endif; ?>
          <div class="Payment_propertyData__1vbZG">
            <h4><?php echo esc_html( $o['propertyName'] ); ?></h4>
            <h4><?php echo esc_html( "{$from} — {$to}" ); ?></h4>
          </div>
          <div class="Payment_propertySubData__SQ1p6">
            <p>
              <span class="Payment_bolder__36Yvn">Payment Terms:</span>
              You’ll be charged a flat security deposit of €<?php echo number_format( $depAmt, 2 ); ?> today.
            </p>
            <p>
              <span class="Payment_bolder__36Yvn">Cancellation Terms:</span>
              Security deposit is non-refundable.
            </p>
          </div>
          <div class="row">
            <div class="col">
              <div class="Payment_propertySubData__SQ1p6">
                <h5>Total in Euro:</h5>

                <h5>Security deposit due:</h5>

              </div>
            </div>
            <div class="col">
              <div class="Payment_propertySubDataRight__clE2m">
                <h5>€ <?php echo number_format( $price, 2 ); ?></h5>

                <h5>€ <?php echo number_format( $depAmt, 2 ); ?></h5>

              </div>
            </div>
            <?
              echo 'deposit_value='.get_post_meta( $realPostId, 'security_deposit_fee', true ).'</br>';
              
              echo 'realPostId='.$realPostId.'</br>';
              echo 'price='.$price.'</br>';
              echo 'depAmt='.$depAmt.'</br>';
              echo 'secDep='.$securityDeposit.'</br>';

              echo 'img='.$thumb.'</br>';
              
              echo 'from='.$from.'</br>';
              echo 'to='.$to.'</br>';
              ?>

<?php
if ( isset($_COOKIE['order']) ) {
    echo '<div style="background:#f6f8fa;padding:20px;border:1px solid #ccc;margin:20px 0;">';
    echo '<h4>🧾 Order Data (Debug Output)</h4>';
    
    $raw_order = rawurldecode( $_COOKIE['order'] );
    $order = json_decode( $raw_order, true );
    
    if ( json_last_error() === JSON_ERROR_NONE ) {
        echo '<ul style="list-style-type:disc;padding-left:20px;">';
        foreach ( $order as $key => $value ) {
            // Handle arrays/nested values
            if ( is_array($value) ) {
                echo "<li><strong>{$key}:</strong><pre>" . print_r($value, true) . "</pre></li>";
            } else {
                echo "<li><strong>{$key}:</strong> " . esc_html($value) . "</li>";
            }
        }
        echo '</ul>';
    } else {
        echo '<p style="color:red;">Error decoding order JSON: ' . json_last_error_msg() . '</p>';
    }

    echo '</div>';
} else {
    echo '<p>No order cookie found.</p>';
}
?>


<div class="deposit-info">
  <div>Deposit: <span class="deposit_percent">--</span>%</div>
  <div>Total Deposit: <span class="total_deposit">--</span></div>
</div>
          </div>
        </div>

        <!-- Right pane: booking form -->
        <div class="order-md-2 order-lg-1 col-lg-6 col-md-12">
          <form id="dah-purchase-form" method="POST">
            <h3>Your personal details</h3>
            <h6 class="Payment_bookingSubHeader___x_3j">Booking Details</h6>

            <div class="row">
              <div class="col">
                <input name="firstName" placeholder="First Name" required class="form-control">
              </div>
              <div class="col">
                <input name="surname" placeholder="Last Name" required class="form-control">
              </div>
            </div>

            <div class="row">
              <div class="col">
                <input name="email" type="email" placeholder="Your email address" required class="form-control">
              </div>
            </div>

            <div class="row">
              <div class="col">
                <textarea name="address" rows="5" placeholder="Postal address" class="form-control"></textarea>
              </div>
            </div>

            <div class="row">
              <div class="col">
                <input name="phone" placeholder="Phone (incl. international code)" required class="form-control">
              </div>
            </div>

            <div class="row">
              <div class="col">
                <input name="company" placeholder="Company name (if applicable)" class="form-control">
              </div>
            </div>

            <div class="row">
              <div class="col">
                <select name="guests" required class="form-control">
                  <option disabled hidden selected>Number of Guests</option>
                  <?php for ( $i = 1; $i <= 8; $i++ ): ?>
                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                  <?php endfor; ?>
                  <option value="9+">More than 8</option>
                </select>
              </div>
            </div>

            <div class="row">
              <div class="col">
                <input name="requirements" placeholder="Special requirements" class="form-control">
              </div>
            </div>

            <div class="row">
              <div class="col">
                <div class="Payment_checkboxContainer__l7azl">
                  <input name="termsread" type="checkbox" class="form-check-input" required>
                  I agree to the <a href="#">Terms & Conditions</a>.
                </div>
              </div>
            </div>

            <div class="row">
              <div class="col">
                <button
                  type="submit"
                  class="Payment_submitButton__8EiG9 cta-main green"
                  style="width:100%;"
                >
                  <?php if ( $depAmt > 0 ): ?>
                    Pay security deposit of €<?php echo number_format( $depAmt, 2 ); ?>
                  <?php else: ?>
                    Pay full €<?php echo number_format( $price, 2 ); ?>
                  <?php endif; ?>
                </button>
              </div>
            </div>
          </form>
        </div>

      </div><!-- /.row -->
    </div><!-- /.container -->
    <?php
    return ob_get_clean();
});

/**
 * 5) [dah_payment] shortcode
 *    Renders Stripe payment form UI + summary.
 */
add_shortcode( 'dah_payment', function(){
    $raw = $_COOKIE['order'] ?? '';
    if ( ! $raw && ! empty($_GET['order']) ) {
        $raw = stripslashes( $_GET['order'] );
    }
    if ( ! $raw ) {
        return '<p>No booking data found.</p>';
    }
    $d = json_decode( urldecode($raw), true );
    if ( ! is_array($d) ) {
        return '<p>Invalid booking data.</p>';
    }

    $thumb   = $_COOKIE['propertyThumbnail'] ?? '';
    $thumb   = $thumb ?: ( ! empty($_GET['propertyThumbnail']) ? urldecode(stripslashes($_GET['propertyThumbnail'])) : '' );
    $pct     = floatval( $_COOKIE['depositPercent'] ?? ( $d['depositPercent'] ?? 0 ) );
    $depAmt  = round( ($pct/100) * $d['price'], 2 );
    $price   = floatval( $d['price'] );
    $from    = date_i18n( 'D, M j Y', strtotime($d['arrivalDate']) );
    $to      = date_i18n( 'D, M j Y', strtotime($d['departureDate']) );

    ob_start(); ?>
    <div class="Payment_formRow__dUXHU row">
      <div class="col">
        <h3>Payment Details</h3>
        <div class="Payment_ccList__1dgDK"><img src="/img/cc_group.png" alt=""></div>
        <h5 class="Payment_bookingSmallSubHeader__K32XQ">
          Pay in confidence with Stripe payments. Enter your details below.
        </h5>
        <div class="Payment_cardSurround__HADgB">
          <form id="payment-form" data-hs-cf-bound="true">
            <div id="card-element" class="StripeElement StripeElement--empty"></div>
            <div class="Payment_submitButton__8EiG9">
              <button type="submit" class="cta-main green">
                Pay
                <?php echo ' €' . number_format( $pct>0 ? $depAmt : $price, 2 ) . ' Now'; ?>
                <!-- inline SVG omitted -->
              </button>
            </div>
          </form>
        </div>
      </div>

      <div class="colRightCol col">
        <?php if ( $thumb ): ?>
          <img class="Payment_ticketPic__gjGqX" src="<?php echo esc_url($thumb); ?>" alt="">
        <?php endif; ?>
        <div class="Payment_propertyData__1vbZG">
          <h4><?php echo esc_html( $d['propertyName'] ); ?></h4>
          <h4><?php echo esc_html("{$from} — {$to}"); ?></h4>
        </div>
        <hr class="Payment_greyLine__ns_6X">
        <div class="Payment_propertySubData__SQ1p6"><h5>Deposit Due Today:</h5></div>
        <div class="Payment_propertySubDataRight__clE2m">
          <h5>€ <?php echo number_format( $depAmt, 2 ); ?></h5>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
});

add_action( 'rest_api_init', function(){
  register_rest_route( 'dah/v1', '/paymentpolicy', [
    'methods'             => 'GET',
    'permission_callback' => '__return_true',
    'callback'            => function( WP_REST_Request $req ){
      $pid   = sanitize_text_field( $req->get_param('propertyId') );
      $url   = "https://open-api.guesty.com/v1/listings/{$pid}/paymentPolicy"; 
      $token = glj_get_guesty_token(); 
      $res   = wp_remote_get( $url, [
        'headers'=>[
          'Authorization'=>"Bearer {$token}",
          'Accept'=>'application/json'
        ],
        'timeout'=>15
      ]);
      if ( is_wp_error($res) ) {
        return new WP_Error( 'api_error', $res->get_error_message(), [ 'status'=>500 ] );
      }
      $data = json_decode( wp_remote_retrieve_body($res), true );
      return rest_ensure_response( $data );
    }
  ]);
});