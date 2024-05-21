<?php
/*
Plugin Name: CTFd Users Plugin
Description: Fetch and display users along with their scores and ranks from the CTFd platform.
Version: 1.0
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CTFd_Users_Plugin {
    public function __construct() {
        add_action('admin_menu', [$this, 'create_settings_page']);
        add_action('admin_init', [$this, 'setup_sections_and_fields']);
        add_shortcode('ctfd_users', [$this, 'display_users']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    public function create_settings_page() {
        add_menu_page('CTFd Users Settings', 'CTFd Users', 'manage_options', 'ctfd-users-settings', [$this, 'settings_page_content'], 'dashicons-admin-users');
    }

    public function settings_page_content() {
        ?>
        <div class="wrap">
            <h2>CTFd Users Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('ctfd_users_settings');
                do_settings_sections('ctfd-users-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function setup_sections_and_fields() {
        add_settings_section('ctfd_users_section', '', null, 'ctfd-users-settings');
        add_settings_field('ctfd_api_token', 'CTFd API Token', [$this, 'field_callback'], 'ctfd-users-settings', 'ctfd_users_section');
        register_setting('ctfd_users_settings', 'ctfd_api_token');
    }

    public function field_callback() {
        $ctfd_api_token = get_option('ctfd_api_token');
        echo "<input type='text' name='ctfd_api_token' value='" . esc_attr($ctfd_api_token) . "' class='regular-text'>";
    }

    public function enqueue_styles() {
        wp_enqueue_style('ctfd-users-style', plugins_url('style.css', __FILE__));
    }

    function update_user_data() {
        // Fetch the data from the CTFd API
        $api_token = get_option('ctfd_api_token');
        if (!$api_token) {
            return 'CTFd API token is not set.';
        }
    
        // Fetch users
        $users_response = wp_remote_get('http://13.60.63.155/api/v1/users', [
            'headers' => [
                'Authorization' => 'Token '. $api_token,
            ],
        ]);
    
        if (is_wp_error($users_response)) {
            return 'Failed to fetch users: '. $users_response->get_error_message();
        }
    
        $users_body = wp_remote_retrieve_body($users_response);
        $users_data = json_decode($users_body, true);
    
        if (!isset($users_data['success']) ||!$users_data['success']) {
            return 'Failed to fetch users: '. (isset($users_data['message'])? esc_html($users_data['message']) : 'Unknown error');
        }
    
        if (empty($users_data['data'])) {
            return 'No users found';
        }
    
        // Fetch scoreboard
        $scores_response = wp_remote_get('http://13.60.63.155/api/v1/scoreboard/top/30', [
            'headers' => [
                'Authorization' => 'Token '. $api_token,
            ],
        ]);
    
        if (is_wp_error($scores_response)) {
            return 'Failed to fetch scores: '. $scores_response->get_error_message();
        }
    
        $scores_body = wp_remote_retrieve_body($scores_response);
        $scores_data = json_decode($scores_body, true);
    
        if (!isset($scores_data['success']) ||!$scores_data['success']) {
            return 'Failed to fetch scores: '. (isset($scores_data['message'])? esc_html($scores_data['message']) : 'Unknown error');
        }
    
        if (empty($scores_data['data'])) {
            return 'No scores found';
        }
    
        // Map user IDs to scores and ranks
        $user_scores = [];
        foreach ($scores_data['data'] as $score) {
            $user_scores[] = [
                'name' => $score['name'],
                'score' => $score['score'],
                'rank' => array_search($score, $scores_data['data']) + 1
            ];
        }
    
        foreach ($users_data['data'] as $user) {
            $found = false;
            foreach ($user_scores as $user_score) {
                if ($user_score['name'] == $user['name']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $user_scores[] = [
                    'name' => $user['name'],
                    'score' => 'N/A',
                    'rank' => count($user_scores) + 1
                ];
            }
        }
    
        usort($user_scores, function($a, $b) {
            if ($a['score'] == 'N/A' && $b['score']!= 'N/A') {
                return 1;
            } elseif ($a['score']!= 'N/A' && $b['score'] == 'N/A') {
                return -1;
            } else {
                return $b['score'] - $a['score'];
            }
        });
    
        // Update the data in the database
        update_option('ctfd_user_data', $user_scores);
    
        // Schedule the next update
        wp_schedule_event(time() + 60, 'daily', 'update_user_data');
    }
    
    // Schedule the first update
    wp_schedule_event(time(), 'daily', 'update_user_data');
    
    function display_users() {
        $user_scores = get_option('ctfd_user_data');
    
        ob_start();
        echo '<table class="ctfd-users-table">';
        echo '<tr><th>#</th><th>Name</th><th>Score</th><th>Rank</th></tr>';
        $count = 1;
        foreach ($user_scores as $user) {
            echo '<tr>';
            echo '<td>'. $count++. '</td>';
            echo '<td>'. esc_html($user['name']). '</td>';
            echo '<td>'. esc_html($user['score']). '</td>';
            echo '<td>'. esc_html($count - 1). '</td>';
            echo '</tr>';
        }
        echo '</table>';
        return ob_get_clean();
    }

}

new CTFd_Users_Plugin();
