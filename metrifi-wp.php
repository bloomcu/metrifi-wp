<?php
/*
Plugin Name: MetriFi WP
Description: Create pages from MetriFi
Version: 1.0
Author: MetriFi
*/

// Ensure this plugin only runs if ACF is active
if (!function_exists('acf')) {
    return;
}

// Register the custom REST API endpoint when WordPress initializes the REST API
add_action('rest_api_init', function () {
  register_rest_route('metrifi/v1', '/create-page', array(
      'methods' => 'POST', // Accept POST requests
      'callback' => 'create_page_with_acf', // Function to handle the request
      'permission_callback' => 'custom_page_creation_permissions', // Check permissions
  ));
});

// Permission callback to ensure only authorized users can create pages
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

// Helper function to dynamically sanitize data based on its type
function sanitize_acf_data($data) {
  if (is_array($data)) {
      // Recursively sanitize arrays (e.g., subfields or nested data)
      return array_map('sanitize_acf_data', $data);
  } elseif (is_string($data)) {
      // Sanitize strings as text fields (removes harmful HTML/JS)
      return sanitize_text_field($data);
  } elseif (is_bool($data) || is_numeric($data)) {
      // Return booleans and numbers as-is (no sanitization needed)
      return $data;
  } else {
      // Default to null for unsupported types (e.g., objects), or log for debugging
      error_log('Unsupported data type in sanitize_acf_data: ' . gettype($data));
      return null;
  }
}

// Callback function to create the page and add ACF fields
function create_page_with_acf(WP_REST_Request $request) {
  // Get the request data
  $params = $request->get_json_params();

  // Validate required fields
  if (empty($params['title'])) {
      return new WP_Error('rest_invalid_param', 'Title is required.', array('status' => 400));
  }

  // Create the page
  $page_data = array(
      'post_title'   => sanitize_text_field($params['title']),
      'post_content' => wp_kses_post($params['content'] ?? ''), // Default to empty if not provided
      'post_status'  => 'publish', // Hardcode to publish, or make it configurable
      'post_type'    => 'page',
  );

  $page_id = wp_insert_post($page_data);

  if (is_wp_error($page_id)) {
      return new WP_Error('rest_post_creation_failed', 'Failed to create page.', array('status' => 500));
  }

  // Handle ACF Flexible Content
  if (!empty($params['acf']['content_blocks']) && function_exists('update_field')) {
        $field_key = 'field_5b92ba6a9b055'; // 'content_blocks' field key
        $content_blocks = $params['acf']['content_blocks'];

        // Dynamically sanitize the flexible content data
        $acf_data = array_map(function ($block) {
            // Ensure acf_fc_layout is present and sanitized
            if (!isset($block['acf_fc_layout'])) {
                error_log('Missing acf_fc_layout in block: ' . print_r($block, true));
                return null; // Skip invalid blocks
            }

            // Start with sanitized acf_fc_layout
            $sanitized_block = array(
                'acf_fc_layout' => sanitize_text_field($block['acf_fc_layout']),
            );

            // Remove acf_fc_layout from the original block to avoid double-processing
            unset($block['acf_fc_layout']);

            // Sanitize all remaining subfields dynamically
            foreach ($block as $key => $value) {
                $sanitized_block[$key] = sanitize_acf_data($value);
            }

            return $sanitized_block;
        }, $content_blocks);

        // Filter out any null values (invalid blocks)
        $acf_data = array_filter($acf_data);

        // Save the sanitized ACF data
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

// Instruct ACF to expose all fields (that support REST API integration) 
// in the REST API responses and allow them to be updated via API requests
add_filter('acf/rest_api_field_settings', function ($settings) {
  $settings['show_in_rest'] = true;
  return $settings;
});

// Include ACF fields in the REST API response for 'page' post types
add_filter('acf/rest_api_page_get_fields', '__return_true');

// // Hook into REST API initialization
// add_action('rest_api_init', function () {
//     // Register ACF fields for pages in the REST API
//     if (function_exists('acf_register_rest_api_field')) {
//         acf_register_rest_api_field(
//             'page', // Post type
//             'acf',  // Field name in REST API response
//             array(
//                 'get_callback' => function ($object) { // Retrieves all ACF fields for a page (e.g., content_blocks).
//                     $post_id = $object['id'];
//                     return get_fields($post_id); // Return all ACF fields
//                 },
//                 'update_callback' => function ($value, $object, $field_name) { // Updates the content_blocks field when a POST/PUT request includes
//                     $post_id = $object['id'];
//                     if (!empty($value)) {
//                         update_field('content_blocks', $value['content_blocks'], $post_id); // Update specific field
//                     }
//                 },
//                 'schema' => null, // Optional, can define schema if needed
//             )
//         );
//     }
// });

// // Ensure Flexible Content is properly formatted without extra formatting
// add_filter('acf/format_value_for_rest', function ($value, $post_id, $field) {
//     return $value; // Return raw value for REST API
// }, 10, 3);
