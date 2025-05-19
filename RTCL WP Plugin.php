<?php
/*
Plugin Name: OTP for Classified Listing
Description: Use Termii as an OTP provider for the Classified Listing plugin.
Version: 1.0
Author: Oluwatoyin Garber
*/

if (!defined('ABSPATH')) exit;

add_filter('classified_otp_gateways', 'termii_register_gateway');
function termii_register_gateway($gateways) {
    $gateways['termii'] = array(
        'label' => 'Termii',
        'callback' => 'termii_send_otp',
        'verify_callback' => 'termii_verify_otp'
    );
    return $gateways;
}

function termii_send_otp($phone_number) {
    $api_key = get_option('termii_api_key');
    $sender_id = get_option('termii_sender_id');
    $code = rand(100000, 999999);

    $message = "Your OTP is: $code";
    $data = array(
        "to" => $phone_number,
        "from" => $sender_id,
        "sms" => $message,
        "type" => "plain",
        "channel" => "generic",
        "api_key" => $api_key
    );

    $response = wp_remote_post('', array(
        'headers' => array('Content-Type' => 'application/json'),
        'body' => json_encode($data)
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'message' => 'Failed to contact Termii API');
    }

    // Store OTP in transient (for 5 minutes)
    set_transient('termii_otp_' . $phone_number, $code, 300);

    return array('success' => true, 'message' => 'OTP sent successfully');
}

function termii_verify_otp($phone_number, $entered_otp) {
    $stored_otp = get_transient('termii_otp_' . $phone_number);
    if ($stored_otp == $entered_otp) {
        delete_transient('termii_otp_' . $phone_number);
        return array('success' => true, 'message' => 'OTP verified');
    } else {
        return array('success' => false, 'message' => 'Invalid OTP');
    }
}

// Add Termii settings to admin
add_action('admin_menu', function() {
    add_options_page('Termii OTP Settings', 'Termii OTP', 'manage_options', 'termii-otp', 'termii_settings_page');
});

function termii_settings_page() {
    ?>
    <div class="wrap">
        <h2>Termii OTP Settings</h2>
        <form method="post" action="options.php">
            <?php
            settings_fields('termii_settings');
            do_settings_sections('termii-otp');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', function() {
    register_setting('termii_settings', 'termii_api_key');
    register_setting('termii_settings', 'termii_sender_id');

    add_settings_section('termii_section', 'API Credentials', null, 'termii-otp');

    add_settings_field('termii_api_key', 'API Key', function() {
        echo '<input type="text" name="termii_api_key" value="' . esc_attr(get_option('termii_api_key')) . '" class="regular-text">';
    }, 'termii-otp', 'termii_section');

    add_settings_field('termii_sender_id', 'Sender ID', function() {
        echo '<input type="text" name="termii_sender_id" value="' . esc_attr(get_option('termii_sender_id')) . '" class="regular-text">';
    }, 'termii-otp', 'termii_section');
});
