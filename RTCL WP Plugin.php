<?php
/*
Plugin Name: Termii OTP Registration
Description: Adds OTP verification to user registration using Termii SMS API.
Version: 1.0
Author: Bolrach
*/

if (!defined('ABSPATH')) exit;

// 1. Add phone number field to registration form
add_action('register_form', function() {
    ?>
    <p>
        <label for="phone">Phone Number<br/>
        <input type="text" name="phone" id="phone" class="input" required></label>
    </p>
    <?php
});

// 2. Validate phone number field
add_filter('registration_errors', function($errors, $sanitized_user_login, $user_email) {
    if (empty($_POST['phone'])) {
        $errors->add('phone_error', '<strong>ERROR</strong>: Phone number is required.');
    }
    return $errors;
}, 10, 3);

// 3. Save phone number and send OTP after registration
add_action('user_register', function($user_id) {
    if (!empty($_POST['phone'])) {
        $phone = sanitize_text_field($_POST['phone']);
        update_user_meta($user_id, 'phone_number', $phone);
        send_termii_otp($phone, $user_id);
        update_user_meta($user_id, 'termii_otp_verified', 'no');
    }
});

// 4. Send OTP via Termii API
function send_termii_otp($phone, $user_id) {
    $api_key = 'YOUR_TERMI_API_KEY';
    $sender_id = 'YourSenderID';

    $data = [
        'api_key' => $api_key,
        'message_type' => 'NUMERIC',
        'to' => $phone,
        'from' => $sender_id,
        'channel' => 'generic',
        'pin_attempts' => 5,
        'pin_time_to_live' => 10,
        'pin_length' => 6,
        'pin_placeholder' => '< 1234 >',
        'message_text' => "Your verification code is < 1234 >"
    ];

    $response = wp_remote_post('https://api.ng.termii.com/api/sms/otp/send', [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode($data),
    ]);

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($body['pinId'])) {
        update_user_meta($user_id, 'termii_pin_id', sanitize_text_field($body['pinId']));
    }
}

// 5. Block login if OTP not verified
add_filter('wp_authenticate_user', function($user, $password) {
    $verified = get_user_meta($user->ID, 'termii_otp_verified', true);
    if ($verified !== 'yes') {
        return new WP_Error('otp_not_verified', 'Please verify your phone number to log in.');
    }
    return $user;
}, 10, 2);

// 6. Shortcode for OTP verification form
add_shortcode('termii_verify_otp', function() {
    if (!is_user_logged_in()) return '<p>You need to log in first.</p>';
    $user_id = get_current_user_id();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_code'])) {
        $otp_code = sanitize_text_field($_POST['otp_code']);
        $pin_id = get_user_meta($user_id, 'termii_pin_id', true);

        if ($pin_id && verify_termii_otp($pin_id, $otp_code)) {
            update_user_meta($user_id, 'termii_otp_verified', 'yes');
            return '<p>Verification successful. You can now log in.</p>';
        } else {
            return '<p>Invalid or expired OTP. Try again.</p>';
        }
    }

    return '<form method="post">
        <label>Enter OTP Code:<br><input type="text" name="otp_code" required></label><br>
        <input type="submit" value="Verify">
    </form>';
});

// 7. Verify OTP with Termii API
function verify_termii_otp($pin_id, $otp_code) {
    $api_key = 'YOUR_TERMI_API_KEY';
    $url = 'https://api.ng.termii.com/api/sms/otp/verify';

    $body = [
        'api_key' => $api_key,
        'pin_id' => $pin_id,
        'pin' => $otp_code,
    ];

    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($body),
    ]);

    $result = json_decode(wp_remote_retrieve_body($response), true);
    return isset($result['verified']) && $result['verified'] === true;
}
