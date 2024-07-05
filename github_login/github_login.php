<?php
/*
Plugin Name: GitHub Login
Description: A WordPress plugin to enable GitHub login and registration.
Version: 1.0
Author: Yu Luo
*/

if (!defined('ABSPATH')) {
    exit;
}

define('GITHUB_LOGIN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GITHUB_LOGIN_PLUGIN_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, 'github_login_activate');
register_deactivation_hook(__FILE__, 'github_login_deactivate');

add_action('login_form', 'github_login_button');
function github_login_button() {
    echo '<div class="github-login-button">
            <a href="' . esc_url(github_login_get_auth_url()) . '">Login with GitHub</a>
          </div>';
}

// Handle GitHub callback
add_action('init', 'github_login_handle_callback');
function github_login_handle_callback() {
    if (isset($_GET['github_login_callback']) && $_GET['github_login_callback'] == 1) {
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        if ($code) {
            $access_token = github_login_get_access_token($code);
            if ($access_token) {
                $user_data = github_login_get_user_data($access_token);
                if ($user_data) {
                    github_login_register_or_login_user($user_data);
                }
            }
        }
    }
}

// Get GitHub authorization URL
function github_login_get_auth_url() {
    $client_id = 'Your client id';
    $redirect_uri = urlencode(home_url('/wp-login.php?github_login_callback=1'));
    return "https://github.com/login/oauth/authorize?client_id={$client_id}&redirect_uri={$redirect_uri}&scope=user";
}

// Get GitHub access token
function github_login_get_access_token($code) {
    $client_id = 'Your client id';
    $client_secret = 'Your client secret';
    $redirect_uri = home_url('/wp-login.php?github_login_callback=1');
    $response = wp_remote_post('https://github.com/login/oauth/access_token', array(
        'body' => array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'code' => $code,
            'redirect_uri' => $redirect_uri
        ),
        'headers' => array(
            'Accept' => 'application/json'
        )
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return isset($body['access_token']) ? $body['access_token'] : false;
}

// Get GitHub user data
function github_login_get_user_data($access_token) {
    $response = wp_remote_get('https://api.github.com/user', array(
        'headers' => array(
            'Authorization' => 'token ' . $access_token,
            'User-Agent' => 'WordPress GitHub Login'
        )
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body;
}

// Main
function github_login_register_or_login_user($user_data) {
    $user_login = sanitize_user($user_data['login']);
    $user_email = sanitize_email($user_data['email']);
    $user_id = username_exists($user_login);

    if (!$user_id && email_exists($user_email) == false) {
        // Register new user
        $random_password = wp_generate_password(12, false);
        $user_id = wp_create_user($user_login, $random_password, $user_email);
    } else {
        // Login existing user
        $user = get_user_by('login', $user_login);
        if ($user) {
            $user_id = $user->ID;
        }
    }

    if ($user_id) {
        // Update user metadata with GitHub avatar
        update_user_meta($user_id, 'github_avatar', esc_url_raw($user_data['avatar_url']));

        // Set the avatar as the WordPress user avatar
        update_user_meta($user_id, 'wp_user_avatar', esc_url_raw($user_data['avatar_url']));
    }

    wp_set_current_user($user_id, $user_login);
    wp_set_auth_cookie($user_id);
    do_action('wp_login', $user_login);

    wp_redirect(home_url());
    exit;
}

// Add custom avatar filter
add_filter('get_avatar_url', 'github_login_get_avatar_url', 10, 3);
function github_login_get_avatar_url($url, $id_or_email, $args) {
    $user = false;

    if (is_numeric($id_or_email)) {
        $user = get_user_by('id', $id_or_email);
    } elseif (is_string($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
    } elseif (is_object($id_or_email)) {
        if (!empty($id_or_email->user_id)) {
            $user = get_user_by('id', $id_or_email->user_id);
        }
    }

    if ($user && is_object($user)) {
        $github_avatar = get_user_meta($user->ID, 'github_avatar', true);
        if ($github_avatar) {
            return $github_avatar;
        }
    }

    return $url;
}
?>
