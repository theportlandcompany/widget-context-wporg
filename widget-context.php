<?php
/*
Plugin Name: Widget Context
Plugin URI: http://wordpress.org/extend/plugins/widget-context/
Description: Display widgets in context.
Version: 1.0-alpha.3
Author: Kaspars Dambis
Author URI: http://kaspars.net

For changelog see readme.txt
----------------------------
	
    Copyright 2009  Kaspars Dambis  (email: kaspars@konstruktors.com)
	
    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Go!
widget_context::instance();

class widget_context {
	
	private static $instance;
	private $sidebars_widgets;
	private $options_name = 'widget_logic_options'; // Context settings for widgets (visibility, etc)
	private $settings_name = 'widget_context_settings'; // Widget Context global settings

	var $context_options = array(); // Store visibility settings
	var $context_settings = array(); // Store admin settings
	var $contexts = array();
	var $plugin_path;

	
	static function instance() {

		if ( ! self::$instance )
			self::$instance = new self();

		return self::$instance;

	}

	
	private function widget_context() {

		// Define available widget contexts
		add_action( 'init', array( $this, 'define_widget_contexts' ), 5 );

		// Load plugin settings and show/hide widgets by altering the 
		// $sidebars_widgets global variable
		add_action( 'init', array( $this, 'init_widget_context' ) );
		
		// Append Widget Context settings to widget controls
		add_action( 'in_widget_form', array( $this, 'widget_context_controls' ), 10, 3 );
		
		// Add admin menu for config
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		
		// Save widget context settings, when in admin area
		add_action( 'sidebar_admin_setup', array( $this, 'save_widget_context_settings' ) );

		// Fix legacy context option naming
		add_filter( 'widget_context_options', array( $this, 'fix_legacy_options' ) );

		// Register admin settings menu
		add_action( 'admin_menu', array( $this, 'widget_context_settings_menu' ) );

		// Register admin settings
		add_action( 'admin_init', array( $this, 'widget_context_settings_init' ) );

	}


	function init_widget_context() {

		$this->context_options = apply_filters( 
				'widget_context_options', 
				(array) get_option( $this->options_name, array() ) 
			);

		$this->context_settings = wp_parse_args( 
				(array) get_option( $this->settings_name, array() ), 
				array(
					'contexts' => array()
				) 
			);

		// Hide/show widgets for is_active_sidebar() to work
		add_filter( 'sidebars_widgets', array( $this, 'maybe_unset_widgets_by_context' ), 10 );

	}
		
	
	function admin_scripts() {
		
		wp_enqueue_style( 
			'widget-context-css', 
			plugins_url( 'css/admin.css', plugin_basename( __FILE__ ) ) 
		);

		wp_enqueue_script( 
			'widget-context-js', 
			plugins_url( 'js/widget-context.js', plugin_basename( __FILE__ ) ), 
			array( 'jquery' ) 
		);
	
	}


	function widget_context_controls( $object, $return, $instance ) {

		echo $this->display_widget_context( $object->id );

	}

	
	function save_widget_context_settings() {

		if ( ! current_user_can( 'edit_theme_options' ) || empty( $_POST ) || ! isset( $_POST['sidebar'] ) || empty( $_POST['sidebar'] ) )
			return;
		
		// Delete a widget
		if ( isset( $_POST['delete_widget'] ) && isset( $_POST['the-widget-id'] ) )
			unset( $this->context_options[ $_POST['the-widget-id'] ] );
		
		// Add / Update
		$this->context_options = array_merge( $this->context_options, $_POST['wl'] );

		$sidebars_widgets = wp_get_sidebars_widgets();
		$all_widget_ids = array();

		// Get a lits of all widget IDs
		foreach ( $sidebars_widgets as $widget_area => $widgets )
			foreach ( $widgets as $widget_order => $widget_id )
				$all_widget_ids[] = $widget_id;

		// Remove non-existant widget contexts from the settings
		foreach ( $this->context_options as $widget_id => $widget_context )
			if ( ! in_array( $widget_id, $all_widget_ids ) )
				unset( $this->context_options[ $widget_id ] );

		update_option( $this->options_name, $this->context_options );

	}


	function define_widget_contexts() {

		// Default context
		$default_contexts = array(
			'page' => array(
				'label' => __( 'Page' ),
				'description' => __( 'Based on standard WordPress template tags.', 'widget-context' ),
				'weight' => 10
			),
			'url' => array(
				'label' => __( 'Target by URL' ),
				'description' => __( 'Based on URL patterns.', 'widget-context' ),
				'weight' => 20
			)
		);

		// Add default context controls and checks
		foreach ( $default_contexts as $context_name => $context_desc ) {

			add_filter( 'widget_context_control-' . $context_name, array( $this, 'control_' . $context_name ), 10, 2 );
			add_filter( 'widget_context_check-' . $context_name, array( $this, 'context_check_' . $context_name ), 10, 2 );

		}

		// Enable other plugins and themes to specify their own contexts
		$this->contexts = apply_filters( 'widget_contexts', $default_contexts );

		uasort( $this->contexts, array( $this, 'sort_context_by_weight' ) );

	}


	function sort_context_by_weight( $a, $b ) {

		if ( ! isset( $a['weight'] ) )
			$a['weight'] = 10;

		if ( ! isset( $b['weight'] ) )
			$b['weight'] = 10;

		return ( $a['weight'] < $b['weight'] ) ? -1 : 1;

	}


	function maybe_unset_widgets_by_context( $sidebars_widgets ) {

		// Don't run this on the backend
		if ( is_admin() )
			return $sidebars_widgets;

		// Return from cache if we have done the context checks already
		if ( ! empty( $this->sidebars_widgets ) )
			return $this->sidebars_widgets;

		foreach( $sidebars_widgets as $widget_area => $widget_list ) {

			if ( $widget_area == 'wp_inactive_widgets' || empty( $widget_list ) ) 
				continue;

			foreach( $widget_list as $pos => $widget_id ) {

				if ( ! $this->check_widget_visibility( $widget_id ) )
					unset( $sidebars_widgets[ $widget_area ][ $pos ] );

			}

		}

		// Store in class cache
		$this->sidebars_widgets = $sidebars_widgets;

		return $sidebars_widgets;

	}

	
	function check_widget_visibility( $widget_id ) {

		// Check if this widget even has context set
		if ( ! isset( $this->context_options[ $widget_id ] ) )
			return true;

		$matches = array();

		foreach ( $this->contexts as $context_id => $context_settings ) {

			// This context check has been disabled in the plugin settings
			if ( isset( $this->context_settings['contexts'][ $context_id ] ) && ! $this->context_settings['contexts'][ $context_id ] )
				continue;

			// Make sure that context settings for this widget are defined
			if ( ! isset( $this->context_options[ $widget_id ][ $context_id ] ) )
				$widget_context_args = array();
			else
				$widget_context_args = $this->context_options[ $widget_id ][ $context_id ];

			$matches[ $context_id ] = apply_filters( 
					'widget_context_check-' . $context_id, 
					null, 
					$widget_context_args
				);

		}

		// Get the match rule for this widget (show/hide/selected/notselected)
		$match_rule = $this->context_options[ $widget_id ][ 'incexc' ][ 'condition' ];

		// Force show or hide the widget!
		if ( $match_rule == 'show' )
			return true;
		elseif ( $match_rule == 'hide' )
			return false;

		if ( $match_rule == 'selected' )
			$inc = true;
		else
			$inc = false;

		if ( $inc && in_array( true, $matches ) )
			return true;
		elseif ( ! $inc && ! in_array( true, $matches ) )
			return true;
		else
			return false;

	}


	/**
	 * Default context checks
	 */

	function context_check_incexc( $check, $settings ) {

		return $check;

	}


	function context_check_location( $check, $settings ) {

		$status = array(
				'is_front_page' => is_front_page(),
				'is_home' => is_home(),
				'is_single' => is_single(),
				'is_page' => is_page(),
				'is_attachment' => is_attachment(),
				'is_search' => is_search(),
				'is_404' => is_404(),
				'is_archive' => is_archive(),
				'is_date' => is_date(),
				'is_day' => is_day(),
				'is_month' => is_month(),
				'is_year' => is_year(),
				'is_category' => is_category(),
				'is_tag' => is_tag(),
				'is_author' => is_author()
			);

		$matched = array_intersect_assoc( $settings, $status );

		if ( ! empty( $matched ) )
			return true;

		return $check;

	}


	function context_check_url( $check, $settings ) {

		$urls = trim( $settings['urls'] );

		if ( empty( $urls ) )
			return $check;

		if ( $this->match_path( $urls ) ) 
			return true;

		return $check;

	}

	
	// Thanks to Drupal: http://api.drupal.org/api/function/drupal_match_path/6
	function match_path( $patterns ) {

		global $wp;
		
		$patterns_safe = array();

		// Get the request URI from WP
		$url_request = $wp->request;

		// Append the query string
		if ( ! empty( $_SERVER['QUERY_STRING'] ) )
			$url_request .= '?' . $_SERVER['QUERY_STRING'];

		foreach ( explode( "\n", $patterns ) as $pattern )
			$patterns_safe[] = trim( trim( $pattern ), '/' ); // Trim trailing and leading slashes

		// Remove empty URL patterns
		$patterns_safe = array_filter( $patterns_safe );

		$regexps = '/^('. preg_replace( array( '/(\r\n|\n| )+/', '/\\\\\*/' ), array( '|', '.*' ), preg_quote( implode( "\n", array_filter( $patterns_safe, 'trim' ) ), '/' ) ) .')$/';

		return preg_match( $regexps, $url_request );

	}


	// Dummy function
	function context_check_admin_notes( $check, $widget_id ) {}


	// Dummy function
	function context_check_general( $check, $widget_id ) {}


	/*
		Widget Controls
	 */

	function display_widget_context( $widget_id = null ) {

		$controls = array();
		$options = array();

		if ( empty( $options ) )
			$options[] = array();

		$options['__i__'] = array();

		foreach ( $options as $context_i => $context_options ) {

			$context_elements = array();

			$context_type_dropdown = array(
					'' => sprintf( 
						'<option value="">&mdash; %s &mdash;</option>', 
						__( 'Select Context', 'widget-context' ) 
					)
				);

			foreach ( $this->contexts as $context_name => $context_settings )
				$context_type_dropdown[] = sprintf( 
					'<option value="%s" %s>%s</option>',
					esc_attr( $context_name ),
					checked( 1, 0, false ),
					esc_html( $context_settings['label'] )
				);

			$context_elements[] = sprintf(
					'<div class="context-type">
						<div class="context-type-wrap">
							<select name="wl%s" data-widget-id="%s" data-id="%s">%s</select>
						</div>
						<a href="#" data-widget-id="%s" data-id="%s" class="context-item-delete">%s</a>
					</div>',
					$this->get_field_name( array( $widget_id, 'contexts', $context_i, 'type' ) ),
					esc_attr( $widget_id ),
					esc_attr( $context_i ),
					implode( '', $context_type_dropdown ),
					esc_attr( $widget_id ),
					esc_attr( $context_i ),
					esc_html__( 'Delete Context', 'widget-context' )
				);

			foreach ( $this->contexts as $context_name => $context_settings ) {

				$context_classes = array(
					'context-item',
					sprintf( 'context-item-%s', esc_attr( $context_name ) )
				);

				// Hide this context from the admin UX
				//if ( isset( $this->context_settings['contexts'][ $context_name ] ) && ! $this->context_settings['contexts'][ $context_name ] )
				//	$context_classes[] = 'context-inactive';

				$control_args = array(
					'name' => $context_name,
					'input_prefix' => sprintf( 
						'wl%s',
						$this->get_field_name( array( $widget_id, $context_i, $context_name ) )
					),
					'settings' => $this->get_field_value( array( $widget_id, $context_i, $context_name ) )
				);

				$context_controls = apply_filters( 'widget_context_control-' . $context_name, $control_args );
				$context_classes = apply_filters( 'widget_context_classes-' . $context_name, $context_classes, $control_args );

				if ( ! empty( $context_controls ) && is_string( $context_controls ) ) {
					
					$context_elements[ $context_name ] = sprintf( 
							'<div class="%s">
								<div class="context-group-wrap">
									%s
								</div>
							</div>',
							esc_attr( implode( ' ', $context_classes ) ),
							$context_controls
						);

				}

			}

			$context_items_classes = array(
					'context-selected-items'
				);

			if ( ! is_numeric( $context_i ) && '__i__' == $context_i )
				$context_items_classes[] = 'context-item-placeholder';

			$controls[] = sprintf(
					'<div class="%s" id="%s-%s">
						%s
					</div>',
					esc_attr( implode( ' ', $context_items_classes ) ),
					esc_attr( $widget_id ),
					esc_attr( $context_i ),
					implode( '', $context_elements )
				);

		}


		if ( empty( $controls ) )
			$controls[] = sprintf( '<p class="error">%s</p>', __( 'No settings defined.', 'widget-context' ) );

		// Generate context logic option
		$context_logic = array(
				'show' => __( 'Show widget everywhere', 'widget-context' ), 
				'selected' => __( 'Show widget on selected', 'widget-context' ), 
				'notselected' => __( 'Hide widget on selected', 'widget-context' ), 
				'hide' => __( 'Hide widget everywhere', 'widget-context' )
			);

		$context_logic_dropdown = array();

		foreach ( $context_logic as $item_value => $item_label )
			$context_logic_dropdown[] = sprintf( 
				'<option value="%s" %s>%s</option>',
				esc_attr( $item_value ),
				checked( 1, 0, false ),
				esc_html( $item_label )
			);


		return sprintf( 
				'<div class="widget-context">

					<div class="widget-context-header">
						<h3>%s</h3>
						<!-- <a href="#widget-context-%s" class="toggle-contexts hide-if-no-js">
							<span class="expand">%s</span>
							<span class="collapse">%s</span>
						</a> -->
					</div>
				
					<div class="widget-context-inside" id="widget-context-%s">
						
						<div class="context-logic">
							<div class="context-logic-wrap">
								<select name="wl[%s][context-logic]">%s</select>
							</div>
						</div>

						<h4 class="context-selected-heading">Selected Contexts</h4>

						<div class="context-selected">
							%s
						</div>
						
						<p class="context-actions">
							<input class="button" type="button" value="%s" data-widget-id="%s" />
						</p>

					</div>

				</div>',
				__( 'Widget Context', 'widget-context' ),
				esc_attr( $widget_id ),
				__( 'Expand', 'widget-context' ),
				__( 'Collapse', 'widget-context' ),
				esc_attr( $widget_id ),
				esc_attr( $widget_id ),
				implode( '', $context_logic_dropdown ),
				implode( '', $controls ),
				esc_attr__( 'Add Context', 'widget-context' ),
				esc_attr( $widget_id )
			);

	}


	function control_page( $control_args ) {

		$html = array();

		$page_id_items = array();
		$post_type_items = array();

		$all_pages = get_pages();

		foreach ( $all_pages as $page )
			$page_id_items[ $page->ID ] = $page->post_title;

		$post_types = get_post_types( 
				array( 
					'public' => true, 
					'_builtin' => false, 
					'publicly_queryable' => true 
				),
				'objects'
			);

		foreach ( $post_types as $post_type => $post_type_settings )
			$post_type_items[ $post_type ] = $post_type_settings->label;

		$options = array(
				'is_page_type' => array(
					'label' => __( 'Page Type', 'widget-context' ),
					'items' => array(
						'is_front_page' => __( 'Front page', 'widget-context' ),
						'is_home' => __( 'Blog page', 'widget-context' ),
						'is_singular' => __( 'All posts and pages', 'widget-context' ),
						'is_single' => __( 'All posts', 'widget-context' ),
						'is_page' => __( 'All pages', 'widget-context' ),
						'is_attachment' => __( 'All attachments', 'widget-context' ),
						'is_search' => __( 'Search results', 'widget-context' ),
						'is_404' => __( '404 error page', 'widget-context' ),
					)
				),
				'is_singular-post_type' => array(
					'label' => __( 'Post Type', 'widget-context' ),
					'items' => $post_type_items
				),
				'is_page-id' => array(
					'label' => __( 'Page', 'widget-context' ),
					'items' => $page_id_items
				)
			);

		foreach ( $options as $option_group_id => $option_group ) {

			$opt_group_html = array();

			foreach ( $option_group['items'] as $item_value => $item_label )
				$opt_group_html[] = sprintf( 
					'<option value="%s" %s>%s</option>',
					esc_attr( $item_value ),
					checked( 1, 0, false ),
					esc_html( $item_label )
				);

			$html[] = sprintf(
				'<optgroup label="%s">%s</optgroup>',
				esc_attr( $option_group['label'] ),
				implode( '', $opt_group_html )
			);

		}

		return sprintf( 
				'<select name="%s">%s</select>',
				'',
				implode( '', $html ) 
			);

	}

	
	function control_location( $control_args ) {

		$options = array(
			'archives' => array(
				'label' => __( 'Archives', 'widget-context' ),
				'items' => array(
					'is_archive' => __( 'All archives', 'widget-context' )
				)
			),
			'date' => array(
				'label' => __( 'Date', 'widget-context' ),
				'items' => array(
					'is_date' => __( 'All date archives', 'widget-context' ),
					'is_day' => __( 'Daily archives', 'widget-context' ),
					'is_month' => __( 'Monthly archives', 'widget-context' ),
					'is_year' => __( 'Yearly archives', 'widget-context' ),
				)
			),
			'category' => array(
				'label' => __( 'Category', 'widget-context' ),
				'items' => array(
					'is_category' => __( 'All category archives', 'widget-context' ),
					'is_category-id' => array(
						'label' => 'Category',
						'items' => array(

						)
					)
				)
			),
			'tag' => array(
				'label' => __( 'Tag', 'widget-context' ),
				'items' => array(
					'is_tag' => __( 'All tag archives', 'widget-context' ),
					'is_tag-id' => array(
						'label' => 'Tag',
						'items' => array(
							
						)
					)
				)
			),
			'author' => array(
				'label' => __( 'Author', 'widget-context' ),
				'items' => array(
					'is_author' => __( 'All author archives', 'widget-context' ),
					'is_author-id' => array(
						'label' => 'Author',
						'items' => array(
							
						)
					)
				)
			)
		);



		$context_dropdown = array();
		$context_a = array();


		foreach ( $options as $context_id => $context_options ) {
			
			$select_items = array();

			$context_dropdown[] = sprintf( 
					'<option name="wc[][contexts][type]" value="%s">%s</option>',
					esc_attr( $context_id ),
					esc_html( $context_options['label'] )
				);

			foreach ( $context_options['items'] as $item_id => $item_label ) {

				if ( ! is_string( $item_label ) )
					continue;

				$select_items[] = sprintf( 
						'<option value="%s" %s>%s</option>', 
						esc_attr( $item_id ),
						checked( 0, 1, false ),
						esc_html( $item_label )
					);

			}

			$context_a[ $context_id ] = sprintf( '<select id="">%s</select>', implode( '', $select_items ) );
 
		}

		$context_type_select = sprintf( '<select id="">%s</select>', implode( '', $context_dropdown ) );
		
		return $context_type_select . implode( '', $context_a );

	}


	function control_url( $control_args ) {

		return sprintf( 
				'<div>%s</div>
				<p class="help">%s</p>',
				$this->make_simple_textarea( $control_args, 'urls' ),
				__( 'Enter one location fragment per line. Use <strong>*</strong> character as a wildcard. Example: <code>category/peace/*</code> to target all posts in category <em>peace</em>.', 'widget-context' )
			);

	}


	function control_admin_notes( $control_args ) {

		return sprintf( 
				'<p>%s</p>',
				$this->make_simple_textarea( $control_args, 'notes' )
			);

	}

	

	/**
	 * Widget control helpers
	 */

	
	function make_simple_checkbox( $control_args, $option, $label ) {

		return sprintf(
				'<label class="wc-location-%s"><input type="checkbox" value="1" name="%s[%s]" %s />&nbsp;%s</label>',
				$this->get_field_classname( $option ),
				$control_args['input_prefix'],
				esc_attr( $option ),
				checked( isset( $control_args['settings'][ $option ] ), true, false ),
				esc_html( $label )
			);

	}

	
	function make_simple_textarea( $control_args, $option, $label = null ) {

		if ( isset( $control_args['settings'][ $option ] ) )
			$value = esc_textarea( $control_args['settings'][ $option ] );
		else
			$value = '';
		
		return sprintf(  
				'<label class="wc-%s">
					<strong>%s</strong>
					<textarea name="%s[%s]">%s</textarea>
				</label>',
				$this->get_field_classname( $option ),
				esc_html( $label ),
				$control_args['input_prefix'],
				$option,
				$value
			);

	}


	function make_simple_textfield( $control_args, $option, $label_before = null, $label_after = null) {

		if ( isset( $control_args['settings'][ $option ] ) )
			$value = esc_attr( $control_args['settings'][ $option ] );
		else
			$value = false;

		return sprintf( 
				'<label class="wl-%s">%s <input type="text" name="%s[%s]" value="%s" /> %s</label>',
				$this->get_field_classname( $option ),
				$label_before,
				$control_args['input_prefix'],
				$option,
				esc_attr( $value ),
				esc_html( $label_after )
			);

	}


	function make_simple_dropdown( $control_args, $option, $selection = array(), $label_before = null, $label_after = null ) {

		$options = array();

		if ( isset( $control_args['settings'][ $option ] ) )
			$value = $control_args['settings'][ $option ];
		else
			$value = false;

		if ( empty( $selection ) )
			$options[] = sprintf( 
					'<option value="">%s</option>', 
					esc_html__( 'No options given', 'widget-context' ) 
				);

		foreach ( $selection as $sid => $svalue )
			$options[] = sprintf( 
					'<option value="%s" %s>%s</option>', 
					esc_attr( $sid ), 
					selected( $value, $sid, false ), 
					esc_html( $svalue ) 
				);

		return sprintf( 
				'<label class="wl-%s">
					%s 
					<select name="%s[%s]">
						%s
					</select> 
					%s
				</label>',
				$this->get_field_classname( $option ),
				$label_before, 
				$control_args['input_prefix'], 
				$option,
				implode( '', $options ), 
				$label_after
			);

	}


	/**
	 * Returns [part1][part2][partN] from array( 'part1', 'part2', 'part3' )
	 * @param  array  $parts i.e. array( 'part1', 'part2', 'part3' )
	 * @return string        i.e. [part1][part2][partN]
	 */
	function get_field_name( $parts ) {

		return esc_attr( sprintf( '[%s]', implode( '][', $parts ) ) );

	}

	function get_field_classname( $name ) {

		if ( is_array( $name ) )
			$name = end( $name );

		return sanitize_html_class( str_replace( '_', '-', $name ) );

	}


	/**
	 * Given option keys return its value
	 * @param  array  $parts   i.e. array( 'part1', 'part2', 'part3' )
	 * @param  array  $options i.e. array( 'part1' => array( 'part2' => array( 'part3' => 'VALUE' ) ) )
	 * @return string          Returns option value
	 */
	function get_field_value( $parts, $options = null ) {

		if ( $options == null )
			$options = $this->context_options;

		$value = false;

		if ( empty( $parts ) || ! is_array( $parts ) )
			return false;

		$part = array_shift( $parts );
		
		if ( ! empty( $parts ) && isset( $options[ $part ] ) && is_array( $options[ $part ] ) )
			$value = $this->get_field_value( $parts, $options[ $part ] );
		elseif ( isset( $options[ $part ] ) )
			return $options[ $part ];

		return $value;

	}


	function fix_legacy_options( $options ) {

		if ( empty( $options ) || ! is_array( $options ) )
			return $options;
		
		foreach ( $options as $widget_id => $option ) {

			// We moved from [incexc] = 1/0 to [incexc][condition] = 1/0
			if ( isset( $option['incexc'] ) && ! is_array( $option['incexc'] ) )
				$options[ $widget_id ]['incexc'] = array( 'condition' => $option['incexc'] );
			
			// Move notes from "general" group to "admin_notes"
			if ( isset( $option['general']['notes'] ) ) {
				$options[ $widget_id ]['admin_notes']['notes'] = $option['general']['notes'];
				unset( $option['general']['notes'] );
			}

			// We moved word count out of location context group
			if ( isset( $option['location']['check_wordcount'] ) )
				$options[ $widget_id ]['word_count'] = array(
						'check_wordcount' => true,
						'check_wordcount_type' => $option['location']['check_wordcount_type'],
						'word_count' => $option['location']['word_count']
					);
		
		}

		return $options;

	}



	/**
	 * Admin Settings
	 */


	function widget_context_settings_menu() {

		add_options_page( 
			__( 'Widget Context Settings', 'widget-context' ), 
			__( 'Widget Context', 'widget-context' ), 
			'manage_options', 
			$this->settings_name, 
			array( $this, 'widget_context_admin_view' )
		);

	}


	function widget_context_settings_init() {

		register_setting( $this->settings_name, $this->settings_name );

	}


	function widget_context_admin_view() {

		$context_controls = array();

		foreach ( $this->contexts as $context_id => $context_args ) {
			
			// Hide core modules from being disabled
			if ( isset( $context_args['type'] ) && $context_args['type'] == 'core' )
				continue;

			if ( ! empty( $context_args['description'] ) )
				$context_description = sprintf( 
					'<em class="context-desc">&mdash; %s</em>', 
					esc_html( $context_args['description'] ) 
				);
			else
				$context_description = null;

			// Enable new modules by default
			if ( ! isset( $this->context_settings['contexts'][ $context_id ] ) )
				$this->context_settings['contexts'][ $context_id ] = 1;

			$context_controls[] = sprintf(
					'<div class="context-%s">
						<p>
							<label>
								<input type="hidden" name="%s[contexts][%s]" value="0" />
								<input type="checkbox" name="%s[contexts][%s]" value="1" %s /> %s
							</label> 
							%s
						</p>
					</div>',
					esc_attr( $context_id ),
					$this->settings_name,
					esc_attr( $context_id ),
					$this->settings_name,
					esc_attr( $context_id ),
					checked( $this->context_settings['contexts'][ $context_id ], 1, false ),
					esc_html( $context_args['label'] ),
					$context_description
				);

		}

		?>
		<div class="wrap wrap-widget-context">
			<h2><?php esc_html_e( 'Widget Context Settings', 'widget-context' ); ?></h2>

			<div class="widget-context-settings-wrap">

				<div class="widget-context-form">
					<form method="post" action="options.php">
						<?php
							settings_fields( $this->settings_name );
							do_settings_sections( $this->settings_name );
						?>

						<table class="form-table">
							<?php
								printf( 
									'<tr class="settings-section settings-section-modules">
										<th>%s</th>
										<td>
											%s
										</td>
									</div>',
									esc_html__( 'Enabled Modules', 'widget-context' ),
									implode( '', $context_controls )
								);
							?>
						</table>
							
						<?php
							submit_button();
						?>
					</form>
				</div>

				<div class="widget-context-sidebar">
					<p><strong>Widget Context</strong> is created and maintained by <a href="http://kaspars.net">Kaspars Dambis</a>.</p>
				</div>

			</div>
		</div>
		<?php

	}


}


/**
 * Load core modules
 */

// Word Count
include plugin_dir_path( __FILE__ ) . '/modules/word-count/word-count.php';

// Custom Post Types and Taxonomies
include plugin_dir_path( __FILE__ ) . '/modules/custom-post-types-taxonomies/custom-cpt-tax.php';


