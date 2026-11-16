<?php
// Declarations of optional runtime APIs (WP-CLI, Action Scheduler) for static analysis only.

namespace {
	class WP_CLI {
		public static function add_command( $name, $callable, $args = array() ) {}
		public static function log( $message ) {}
		public static function success( $message ) {}
		public static function warning( $message ) {}
		public static function error( $message, $exit = true ) {}
		public static function halt( $return_code ) {}
		public static function line( $message = '' ) {}
	}
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) { return 0; }
	function as_next_scheduled_action( $hook, $args = null, $group = '' ) { return false; }
	function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {}
	function as_has_scheduled_action( $hook, $args = null, $group = '' ) { return false; }
	function as_get_scheduled_actions( $args = array(), $return_format = OBJECT ) { return array(); }
	class ActionScheduler_Store {
		const STATUS_PENDING = 'pending';
	}
}

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {}
}

// Form Captain contract v1 (optional; src/Integrations/FormCaptain, AFF-SU-01).
namespace FormCaptain\Integrations {
	interface ConnectorInterface {
		public function key(): string;
		public function label(): string;
		public function version(): string;
		public function contract(): string;
		public function capabilities(): array;
		public function requirements(): array;
		public function settings_schema(): array;
		public function register_actions(): void;
	}
}

namespace {
	function form_captain_contract_version() { return '1.1.0'; }
	/** @return true|\WP_Error */
	function form_captain_register_action_v1( $key, array $definition, $callback ) { return true; }
}
