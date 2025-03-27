<?php
/*
Plugin Name: MetriFi WP
Description: Create WordPress pages from MetriFi
Version: 1.2
Author: MetriFi
*/

/**
 * Ensure this plugin only runs if ACF is active
 * 
 * @return void
 */
if (!function_exists('acf')) {
    return;
}

/**
 * Register the custom REST API endpoint when WordPress initializes the REST API
 * 
 * @return void
 */
add_action('rest_api_init', function () {
    register_rest_route('metrifi/v1', '/create-page', array(
        'methods' => 'POST', // Accept POST requests
        'callback' => 'create_page_with_acf', // Function to handle the request
        'permission_callback' => 'custom_page_creation_permissions', // Check permissions
    ));

    // Register a new endpoint to check if the plugin is installed
    register_rest_route('metrifi/v1', '/status', array(
        'methods' => 'GET', // Accept GET requests
        'callback' => 'metrifi_plugin_status', // Function to handle the request
        'permission_callback' => '__return_true', // Allow public access
    ));
});

/**
 * Permission callback to ensure only authorized users can create pages
 * 
 * @param WP_REST_Request $request
 * @return bool|WP_Error
 */
function custom_page_creation_permissions(WP_REST_Request $request) {
    // Check if the request includes valid Basic Auth credentials
    $auth_header = $request->get_header('Authorization');
    if (!$auth_header) {
        return new WP_Error('rest_forbidden', 'Authentication required.', array('status' => 401));
    }

    // Extract username and password from Basic Auth
    list($username, $password) = explode(':', base64_decode(substr($auth_header, 6)));
    
    // Verify credentials (Application Password or user login)
    $user = wp_authenticate_application_password(null, $username, $password);
    if (is_wp_error($user) || !user_can($user, 'edit_pages')) {
        return new WP_Error('rest_forbidden', 'You do not have permission to create pages.', array('status' => 403));
    }

    return true; // User is authenticated and has permission
}

/**
 * Helper function to dynamically retrieve the ACF field key (requires ACF Pro)
 * 
 * @param string $field_name
 * @return string|false
 */
function get_acf_field_key($field_name) {
    if (function_exists('acf_get_field')) {
        $field = acf_get_field($field_name);
        if ($field && isset($field['key'])) {
            return $field['key'];
        }
    }
    return false; // Return false if the field key cannot be retrieved
}

/**
 * Callback function to create the page and add ACF fields
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function create_page_with_acf(WP_REST_Request $request) {
    // Get the request data
    $params = $request->get_json_params();

    // Validate required fields
    if (empty($params['title'])) {
        return new WP_Error('rest_invalid_param', 'Title is required.', array('status' => 400));
    }

    // Create the page with minimal sanitization on core fields
    $page_data = array(
        'post_title'   => sanitize_text_field($params['title']), // Basic sanitization for title
        'post_content' => wp_kses_post($params['content'] ?? ''), // Basic sanitization for content
        'post_status'  => 'draft', // Hardcode to publish, or make it configurable
        'post_type'    => 'page',
    );

    $page_id = wp_insert_post($page_data);

    if (is_wp_error($page_id)) {
        return new WP_Error('rest_post_creation_failed', 'Failed to create page.', array('status' => 500));
    }

    // Handle ACF Flexible Content (no sanitization on ACF data)
    if (!empty($params['acf']['content_blocks']) && function_exists('update_field')) {
        // Try to dynamically retrieve the field key, with a fallback to the hardcoded value
        $field_key = get_acf_field_key('content_blocks');
        if (!$field_key) {
            $field_key = 'field_5b92ba6a9b055'; // Fallback to hardcoded value
            error_log('Could not dynamically retrieve ACF field key for "content_blocks". Using hardcoded value: ' . $field_key);
        }

        $content_blocks = $params['acf']['content_blocks'];

        // Basic validation to ensure acf_fc_layout is present, but no sanitization
        $acf_data = array_filter(array_map(function ($block) {
            if (!isset($block['acf_fc_layout'])) {
                error_log('Missing acf_fc_layout in block: ' . print_r($block, true));
                return null; // Skip invalid blocks
            }
            return $block; // Pass the block through unchanged
        }, $content_blocks));

        // Save the ACF data without sanitization
        $result = update_field($field_key, $acf_data, $page_id);

        if (!$result) {
            error_log('Failed to update ACF field for page ID ' . $page_id . ': ' . print_r($acf_data, true));
        }
    }

    // Return the created page data
    $response = array(
        'id'    => $page_id,
        'title' => $params['title'],
        'acf'   => [
            'content_blocks' => get_field('content_blocks', $page_id), // Return the saved ACF data for verification
        ],
        'link'  => get_permalink($page_id),
    );

    return rest_ensure_response($response);
}

/**
 * Callback function to check if the MetriFi plugin is installed and active
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function metrifi_plugin_status(WP_REST_Request $request) {
    $response = array(
        'status' => 'active',
        'message' => 'MetriFi WP plugin is installed and active',
        'version' => '1.2' // Match the version from the plugin header
    );
    
    return rest_ensure_response($response);
}

/**
 * Instruct ACF to expose all fields (that support REST API integration) 
 * in the REST API responses and allow them to be updated via API requests
 * 
 * @param array $settings
 * @return array
 */
add_filter('acf/rest_api_field_settings', function ($settings) {
    $settings['show_in_rest'] = true;
    return $settings;
});

/**
 * Include ACF fields in the REST API response for 'page' post types
 * 
 */
add_filter('acf/rest_api_page_get_fields', '__return_true');