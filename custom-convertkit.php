<?php
/**
 * Plugin Name: Custom ConvertKit for Elementor Forms
 * Description: Adds a reusable Elementor Forms action that maps any form fields to Kit/ConvertKit custom fields.
 * Version:     1.0.0
 * Author:      Luke
 */

if ( ! defined('ABSPATH') ) exit;

add_action('plugins_loaded', function () {
	// Require Elementor Pro (forms action base lives there)
	if ( ! did_action('elementor_pro/init') ) return;

	// Register the action when Elementor Pro forms module is ready
	add_action('elementor_pro/init', function () {
		require_once __DIR__ . '/src/class-action-custom-convertkit.php';
		\ElementorPro\Plugin::instance()
			->modules_manager
			->get_modules('forms')
			->add_form_action(new \LL\CustomConvertKit\Action_Custom_ConvertKit());
	});
});

/**
 * Optional: register a simple option to store a default API key site-wide.
 * You can paste the key per form action too; that overrides this option.
 */
add_action('admin_init', function () {
	register_setting('general', 'll_ck_api_key', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
	add_settings_field(
		'll_ck_api_key',
		'Kit/ConvertKit API key (default)',
		function () {
			printf(
				'<input type="text" id="ll_ck_api_key" name="ll_ck_api_key" value="%s" class="regular-text" />',
				esc_attr(get_option('ll_ck_api_key', ''))
			);
		},
		'general'
	);
});
