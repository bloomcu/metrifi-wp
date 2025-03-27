# MetriFi WP

A WordPress plugin that allows you to create WordPress pages from MetriFi via a REST API endpoint.

## Description

MetriFi WP enables the programmatic creation of WordPress pages with Advanced Custom Fields (ACF) content blocks through a custom REST API endpoint. This plugin is designed to work with ACF Pro and specifically supports flexible content fields.

## Requirements

- WordPress
- Advanced Custom Fields Pro (ACF Pro)

## How It Works

The plugin registers a custom REST API endpoint that accepts POST requests with page data, including title, content, and ACF flexible content blocks. When a valid request is received, the plugin:

1. Validates the request authentication
2. Creates a new WordPress page with the provided title and content
3. Adds the ACF flexible content blocks to the page
4. Returns the created page data, including ID, title, ACF fields, and permalink

## Authentication

The plugin uses WordPress Application Passwords for authentication. To use the API:

1. Generate an application password:
   - Go to your WordPress admin panel
   - Navigate to Users → Your Profile
   - Scroll down to the "Application Passwords" section
   - Enter a name for the application (e.g., "MetriFi Integration")
   - Click "Add New Application Password"
   - Copy the generated password (you won't be able to see it again)

2. Use Basic Authentication with your requests:
   - Username: Your WordPress username
   - Password: The application password you generated

## API Endpoints

### Create Page

```
POST /wp-json/metrifi/v1/create-page
```

#### Headers

```
Authorization: Basic {base64_encoded_credentials}
Content-Type: application/json
```

#### Request Body

```json
{
  "title": "Page Title",
  "content": "Optional HTML content for the main content area",
  "acf": {
    "content_blocks": [
      {
        "acf_fc_layout": "block_type_name",
        "field_1": "value_1",
        "field_2": "value_2"
      },
      {
        "acf_fc_layout": "another_block_type",
        "field_3": "value_3"
      }
    ]
  }
}
```

#### Response

```json
{
  "id": 123,
  "title": "Page Title",
  "acf": {
    "content_blocks": [
      {
        "acf_fc_layout": "block_type_name",
        "field_1": "value_1",
        "field_2": "value_2"
      },
      {
        "acf_fc_layout": "another_block_type",
        "field_3": "value_3"
      }
    ]
  },
  "link": "https://your-site.com/page-title/"
}
```

## Notes

- The plugin requires the ACF Pro plugin to be active
- Pages are created as drafts by default
- The plugin expects a flexible content field named `content_blocks` to exist in your ACF configuration
