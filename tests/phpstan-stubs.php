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
}

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {}
}
