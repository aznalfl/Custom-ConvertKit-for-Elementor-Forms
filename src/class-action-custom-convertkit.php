<?php
namespace LL\CustomConvertKit;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use ElementorPro\Modules\Forms\Classes\Action_Base;

if ( ! defined('ABSPATH') ) exit;

class Action_Custom_ConvertKit extends Action_Base {

	public function get_name()  { return 'll_custom_convertkit'; }
	public function get_label() { return __('Custom ConvertKit', 'll-custom-convertkit'); }

	/**
	 * Controls in the Elementor editor for this action.
	 */
	public function register_settings_section( $widget ) {
		$widget->start_controls_section('ll_ck_section', [
			'label'     => __('Custom ConvertKit', 'll-custom-convertkit'),
			'condition' => [ 'submit_actions' => $this->get_name() ],
		]);

		$widget->add_control('ll_ck_api_key', [
			'label'       => __('API key', 'll-custom-convertkit'),
			'type'        => Controls_Manager::TEXT,
			'placeholder' => __('Leave blank to use Settings → General default', 'll-custom-convertkit'),
			'description' => __('Find in Kit/ConvertKit account settings.', 'll-custom-convertkit'),
		]);

		$widget->add_control('ll_ck_form_id', [
			'label'       => __('Form ID', 'll-custom-convertkit'),
			'type'        => Controls_Manager::NUMBER,
			'description' => __('Numeric Kit/ConvertKit form ID to subscribe to.', 'll-custom-convertkit'),
		]);

		$widget->add_control('ll_ck_tags', [
			'label'       => __('Tag IDs (comma separated)', 'll-custom-convertkit'),
			'type'        => Controls_Manager::TEXT,
			'placeholder' => __('12345,67890', 'll-custom-convertkit'),
			'description' => __('Optional. Tag IDs to apply on subscribe.', 'll-custom-convertkit'),
		]);

		// NEW: allow selecting which Elementor field holds email / first name
		$widget->add_control('ll_ck_email_src', [
			'label'       => __('Email field ID', 'll-custom-convertkit'),
			'type'        => Controls_Manager::TEXT,
			'placeholder' => __('email', 'll-custom-convertkit'),
			'description' => __('Elementor field ID that contains the subscriber email. Default: "email".', 'll-custom-convertkit'),
		]);

		$widget->add_control('ll_ck_fname_src', [
			'label'       => __('First name field ID (optional)', 'll-custom-convertkit'),
			'type'        => Controls_Manager::TEXT,
			'placeholder' => __('first_name', 'll-custom-convertkit'),
			'description' => __('If set, this field’s value is sent as first_name.', 'll-custom-convertkit'),
		]);

		$widget->add_control('ll_ck_auto_include', [
			'label'        => __('Auto-include other fields as custom fields', 'll-custom-convertkit'),
			'type'         => Controls_Manager::SWITCHER,
			'label_on'     => __('Yes', 'll-custom-convertkit'),
			'label_off'    => __('No', 'll-custom-convertkit'),
			'return_value' => 'yes',
			'default'      => 'yes',
			'description'  => __('If on, all fields except reserved ones are sent as custom fields (key = Elementor field ID).', 'll-custom-convertkit'),
		]);

		$rep = new Repeater();
		$rep->add_control('ll_ck_src', [
			'label'       => __('Elementor field ID', 'll-custom-convertkit'),
			'type'        => Controls_Manager::TEXT,
			'placeholder' => __('e.g. phone', 'll-custom-convertkit'),
			'description' => __('Exact field ID from your form (Advanced → ID).', 'll-custom-convertkit'),
		]);
		$rep->add_control('ll_ck_dest', [
			'label'       => __('Kit custom field key', 'll-custom-convertkit'),
			'type'        => Controls_Manager::TEXT,
			'placeholder' => __('e.g. phone', 'll-custom-convertkit'),
			'description' => __('Custom field “key/slug” in Kit. Create fields in Kit first.', 'll-custom-convertkit'),
		]);

		$widget->add_control('ll_ck_mappings', [
			'label'       => __('Field mappings', 'll-custom-convertkit'),
			'type'        => Controls_Manager::REPEATER,
			'fields'      => $rep->get_controls(),
			'title_field' => '{{{ ll_ck_src }}} → {{{ ll_ck_dest }}}',
			'description' => __('Use this to map differently named fields. If empty, rely on Auto-include.', 'll-custom-convertkit'),
		]);

		$widget->end_controls_section();
	}

	public function on_export( $element ) {}

	/**
	 * Called on submit.
	 */
	public function run( $record, $ajax_handler ) {
		$settings = $record->get('form_settings');
		$fields   = $record->get('fields');

		$RESERVED = ['email', 'first_name'];

		// 1) Resolve which Elementor field holds email / first name
		$email_id = trim($settings['ll_ck_email_src'] ?? '') ?: 'email';
		$email    = $this->value_of($fields, $email_id);
		if ( $email === '' ) {
			$ajax_handler->add_error_message(__('Email is required for ConvertKit.', 'll-custom-convertkit'));
			return;
		}

		$fname_id   = trim($settings['ll_ck_fname_src'] ?? '');
		$first_name = $fname_id ? $this->value_of($fields, $fname_id) : $this->value_of($fields, 'first_name');

		// 2) Resolve credentials and destination
		$api_key = trim($settings['ll_ck_api_key'] ?? '');
		if ( $api_key === '' ) {
			$api_key = (string) get_option('ll_ck_api_key', '');
		}
		if ( $api_key === '' ) {
			$ajax_handler->add_error_message(__('Missing API key.', 'll-custom-convertkit'));
			return;
		}

		$form_id = absint($settings['ll_ck_form_id'] ?? 0);
		if ( ! $form_id ) {
			$ajax_handler->add_error_message(__('Missing ConvertKit form ID.', 'll-custom-convertkit'));
			return;
		}

		// 3) Build payload (keep reserved fields only at top level)
		$payload = [
			'api_key' => $api_key,
			'email'   => $email,
		];
		if ( $first_name !== '' ) {
			$payload['first_name'] = $first_name;
		}

		// 4) Custom fields by explicit mapping (skip reserved keys)
		$custom = [];
		if ( ! empty($settings['ll_ck_mappings']) && is_array($settings['ll_ck_mappings']) ) {
			foreach ( $settings['ll_ck_mappings'] as $map ) {
				$src  = isset($map['ll_ck_src'])  ? trim((string) $map['ll_ck_src'])  : '';
				$dest = isset($map['ll_ck_dest']) ? trim((string) $map['ll_ck_dest']) : '';
				if ( $src === '' || $dest === '' ) continue;
				if ( in_array($dest, $RESERVED, true) ) continue; // never send reserved inside fields{}

				$val = $this->value_of($fields, $src);
				if ( $val !== '' ) $custom[$dest] = $val;
			}
		}

		// 5) Auto-include remaining fields (key = Elementor field ID), excluding reserved and email/fname source IDs
		$auto = (isset($settings['ll_ck_auto_include']) && $settings['ll_ck_auto_include'] === 'yes');
		if ( $auto && is_array($fields) ) {
			foreach ( $fields as $id => $data ) {
				// skip reserved names and the actual IDs used for email/first name
				if ( in_array($id, $RESERVED, true) ) continue;
				if ( $id === $email_id || ($fname_id && $id === $fname_id) ) continue;

				// don’t overwrite explicit mappings
				if ( array_key_exists($id, $custom) ) continue;

				$val = $this->normalise_value($data['value'] ?? '');
				if ( $val !== '' ) $custom[$id] = $val;
			}
		}

		if ( ! empty($custom) ) {
			$payload['fields'] = $custom;
		}

		// 6) Tags
		$tags_csv = (string) ($settings['ll_ck_tags'] ?? '');
		if ( $tags_csv !== '' ) {
			$tags = array_filter(array_map('intval', array_map('trim', explode(',', $tags_csv))));
			if ( $tags ) $payload['tags'] = array_values($tags);
		}

		// 7) POST to ConvertKit (use filterable base for Kit/ConvertKit host)
		$base = apply_filters('ll_ck/api_base', 'https://api.convertkit.com/v3');
		$url  = trailingslashit($base) . 'forms/' . $form_id . '/subscribe';

		$response = wp_remote_post($url, [
			'timeout' => 12,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode($payload),
		]);

		// Transport error
		if ( is_wp_error($response) ) {
			error_log('[Custom ConvertKit] transport error: ' . $response->get_error_message());
			$ajax_handler->add_error_message(__('Could not reach ConvertKit. Please try again later.', 'll-custom-convertkit'));
			return;
		}

		// Get code/body before logging
		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);

		// Optional debug logging (mask API key)
		if ( defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) {
			$masked = $payload;
			if ( isset($masked['api_key']) ) $masked['api_key'] = substr($masked['api_key'], 0, 4) . '…';
			error_log('[Custom ConvertKit] URL: ' . $url);
			error_log('[Custom ConvertKit] Payload: ' . wp_json_encode($masked));
			error_log('[Custom ConvertKit] HTTP ' . $code . ' body: ' . $body);
		}

		if ( $code < 200 || $code > 299 ) {
			error_log('[Custom ConvertKit] HTTP ' . $code . ' response: ' . $body);
			$ajax_handler->add_error_message(__('ConvertKit returned an error. Please try again.', 'll-custom-convertkit'));
			return;
		}

		// Success → Elementor handles success message/actions chain.
	}

	/**
	 * Helpers
	 */
	private function value_of(array $fields, string $id): string {
		if ( ! isset($fields[$id]) ) return '';
		$val = $fields[$id]['value'] ?? '';
		return $this->normalise_value($val);
	}

	private function normalise_value($val): string {
		if ( is_array($val) ) {
			// Checkbox/multi-select → comma separated
			$val = implode(', ', array_map('trim', $val));
		}
		$val = trim((string) $val);
		return $val;
	}
}
