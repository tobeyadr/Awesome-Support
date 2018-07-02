<?php

/**
 * Awesome Support Privacy Option.
 *
 * @package   Awesome_Support
 * @author    Naveen Giri <1naveengiri>
 * @license   GPL-2.0+
 * @link      https://getawesomesupport.com
 */
class WPAS_Privacy_Option {
	/**
	 * Instance of this class.
	 *
	 * @since     5.1.1
	 * @var      object
	 */
	protected static $instance = null;
	/**
	 * Store the potential error messages.
	 */
	protected $error_message;

	public function __construct() {
		add_filter( 'wpas_frontend_add_nav_buttons', array( $this, 'frontend_privacy_add_nav_buttons' ) );
		add_filter( 'wp_footer', array( $this, 'print_privacy_popup_temp' ), 101 );
		add_action( 'wp_ajax_wpas_gdpr_open_ticket', array( $this, 'wpas_gdpr_open_ticket' ) );
		add_action( 'wp_ajax_nopriv_wpas_gdpr_open_ticket', array( $this, 'wpas_gdpr_open_ticket' ) );

		/**
		 * Opt in processing
		 */
		add_action( 'wp_ajax_wpas_gdpr_user_opt_in', array( $this, 'wpas_gdpr_user_opt_in' ) );
		add_action( 'wp_ajax_nopriv_wpas_gdpr_user_opt_in', array( $this, 'wpas_gdpr_user_opt_in' ) );

		/**
		 * Opt out processing
		 */
		add_action( 'wp_ajax_wpas_gdpr_user_opt_out', array( $this, 'wpas_gdpr_user_opt_out' ) );
		add_action( 'wp_ajax_nopriv_wpas_gdpr_user_opt_out', array( $this, 'wpas_gdpr_user_opt_out' ) );
		
		add_action( 'wpas_system_tools_after', array( $this, 'wpas_system_tools_after_gdpr_callback' ) );
		
		add_filter( 'wpas_show_done_tool_message', array( $this, 'wpas_show_done_tool_message_gdpr_callback' ), 10, 2 );

		add_filter( 'execute_additional_tools', array( $this, 'execute_additional_tools_gdpr_callback' ), 10, 1 );
		
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'wp_register_asdata_personal_data_eraser' ) );

		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'wp_privacy_personal_asdata_exporters' ), 10, 1 );
	}

	/**
	 * Add or Remove User consent based on action call.
	 */
	function execute_additional_tools_gdpr_callback( $tool ){

		if ( ! isset( $tool ) || ! isset( $_GET['_nonce'] ) ) {
			return false;
		}

		if ( ! wp_verify_nonce( $_GET['_nonce'], 'system_tool' ) ) {
			return false;
		}
		$authors = array();
		if( !empty( $tool ) ){
			// WP_User_Query arguments
			$args = array (
			    'order' => 'ASC',
			    'orderby' => 'display_name',
			);
			// Create the WP_User_Query object
			$wp_user_query = new WP_User_Query($args);

			// Get the results
			$authors = $wp_user_query->get_results();
		}
		switch ( sanitize_text_field( $tool ) ) {

			case 'remove_all_user_consent':

				// Check for results
				if (!empty($authors)) {

				    // loop through each author
				    foreach ($authors as $author) {

				        // get all the user's data
				        if( isset( $author->ID ) && !empty( $author->ID )){
				        	delete_user_option( $author->ID, 'wpas_consent_tracking' );
				        }
				    }
				}
				break;
			case 'add_user_consent':

				$_status = (isset( $_GET['_status'] ) && !empty( isset( $_GET['_status'] ) ))? sanitize_text_field( $_GET['_status'] ): '';
				$consent = ( isset( $_GET['_consent'] ) && !empty( isset( $_GET['_consent'] ) ) )? sanitize_text_field( $_GET['_consent'] ): '';
				if( empty( $_status ) || empty( $consent ) ){
					return false;
				}
				// Check for results
				if (!empty($authors)) {

				    // loop through each author
				    foreach ($authors as $author) {
				    	$opt_type = '';
				        // get all the user's data
				        if( isset( $author->ID ) && !empty( $author->ID )){

							$status 	= ( 'opt-in' === $_status )? true : false;
							$opt_in 	= ! empty ( $status ) ? strtotime( 'NOW' ) : "";
							$opt_out 	= empty ( $opt_in ) ? strtotime( 'NOW' ) : "";
							$opt_type = ( isset( $opt_in ) && !empty( $opt_in ))? 'in' : 'out';
							$args = array( 
								'item' 		=> wpas_get_option( $consent, false ),
								'status' 	=> $status,
								'opt_in' 	=> $opt_in,
								'opt_out' 	=> $opt_out,
								'is_tor'	=> false
							);

							if( 'terms_conditions' === $consent ){
								$args['is_tor'] = true;
							}

							$user_consent = get_user_option( 'wpas_consent_tracking', 
								$author->ID );
							if( !empty( $user_consent )){
								$found_key = array_search( $args['item'], array_column( $user_consent, 'item' ) );	
								// If GDPR option not already enabled, then add it.
								if( false === $found_key ){
									wpas_track_consent( $args , $author->ID, $opt_type );									
								}
								
							} else{

								wpas_track_consent( $args , $author->ID, $opt_type );

							}
				        }
				    }
				}
				break;

		}

	}
	/**
	 * Update data on clean up tool click.
	 */
	function wpas_show_done_tool_message_gdpr_callback( $message, $status ){
		switch( $status ) {

			case 'remove_all_user_consent':
				$message = __( 'User Consent cleared', 'awesome-support' );
				break;

			case 'add_user_consent':
				$message = __( 'Added User Consent', 'awesome-support' );
				break;
		}
		return $message;
	}

	/**
	 * GDPR add consent html in cleanup section.
	 */
	function wpas_system_tools_after_gdpr_callback(){
		?>
		<p><h3><?php _e( 'GDPR/Privacy', 'awesome-support' ); ?></h3></p>
		<table class="widefat wpas-system-tools-table" id="wpas-system-tools-gdpr">
			<thead>
				<tr>
					<th data-override="key" class="row-title"><?php _e( 'GDPR Consent Bulk Action', 'awesome-support' ); ?></th>
					<th data-override="value"></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td class="row-title"><label for="tablecell"><?php _e( 'GDPR Consent', 'awesome-support' ); ?></label></td>
					<td>
						<a href="<?php echo wpas_tool_link( 'remove_all_user_consent' ); ?>" class="button-secondary"><?php _e( 'Remove', 'awesome-support' ); ?></a>
						<span class="wpas-system-tools-desc"><?php _e( 'Clear User Consent data for all Awesome support Users', 'awesome-support' ); ?></span>
					</td>
				</tr>
				<?php 
					$terms = wpas_get_option( 'terms_conditions', '' );
					$gdpr_short_desc_01 = wpas_get_option( 'gdpr_notice_short_desc_01', '' );
					$gdpr_short_desc_02 = wpas_get_option( 'gdpr_notice_short_desc_02', '' );
					$gdpr_short_desc_03 = wpas_get_option( 'gdpr_notice_short_desc_03', '' );

					$consent_array = array(
						'terms_conditions',
						'gdpr_notice_short_desc_01',
						'gdpr_notice_short_desc_02',
						'gdpr_notice_short_desc_03'
					);
					if( !empty( $consent_array ) ){
						foreach ( $consent_array as $key => $consent ) {
							$consent_name = wpas_get_option( $consent, '' );
							if( 'terms_conditions' === $consent ){
								$consent_name = 'Terms';
							}
							if( !empty( $consent_name ) ){
								?>
								<tr>
									<td class="row-title"><label for="tablecell"><?php _e( $consent_name , 'awesome-support' ); ?></label></td>
									<td>
										<?php 
											$opt_in = array(
												'_consent' => $consent,
												'_status' => 'opt-in'
											);
										?>
										<a href="<?php echo wpas_tool_link( 'add_user_consent', $opt_in ); ?>" class="button-secondary"><?php _e( 'OPT-IN', 'awesome-support' ); ?></a>
										<?php 
										$opt_out = array(
											'_consent' => $consent,
											'_status' => 'opt-out'
										);
										?>
										<a href="<?php echo wpas_tool_link( 'add_user_consent', $opt_out ); ?>" class="button-secondary"><?php _e( 'OPT-OUT', 'awesome-support' ); ?></a>
										<span class="wpas-system-tools-desc"><?php _e( 'Set ' . $consent_name . ' Consent status for all Awesome support Users', 'awesome-support' ); ?></span>
									</td>
								</tr>
								<?php 
							}
						}
					}
				?>
			</tbody>
		</table>
		<?php 
	}


	/**
	 * Registers the personal data eraser for Awesome Support data.
	 *
	 * @since  5.1.1
	 *
	 * @param  array $erasers An array of personal data erasers.
	 * @return array $erasers An array of personal data erasers.
	 */
	public function wp_register_asdata_personal_data_eraser( $erasers ){
		$erasers['awesome-support-data'] = array(
			'eraser_friendly_name' => __( 'Awesome Support Data' ),
			'callback'             => array( $this, 'as_users_personal_data_eraser' ),
		);

		return $erasers;
	}

	/**
	 * Erases Awesome Support related personal data associated with an email address.
	 *
	 * @since 4.9.6
	 *
	 * @param  string $email_address The As Users email address.
	 * @param  int    $page          Ticket page.
	 * @return array
	 */
	public function as_users_personal_data_eraser( $email_address, $page = 1 ){
		global $wpdb;

		if ( empty( $email_address ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// Limit us to 500 comments at a time to avoid timing out.
		$number         = 500;
		$page           = (int) $page;
		$items_removed  = false;
		$items_retained = false;
		$author = get_user_by( 'email', $email_address );
		$args = array(
			'post_type'      => array( 'ticket' ),
			'author'         => $author->ID,
			'post_status'    => array_keys( wpas_get_post_status() ),
			'posts_per_page' => $number,
			'paged'          => $page
		);
		/**
		 * Delete ticket data belongs to the mention email id.
		 */
		$ticket_data  = get_posts( $args );
		$messages  = array();
		if( !empty( $ticket_data )){
			foreach ( $ticket_data as $ticket ) {
				if( isset( $ticket->ID ) && !empty( $ticket->ID )){
					$ticket_id = (int) $ticket->ID;
					if ( $ticket_id ) {
						$items_removed = true;
						wp_delete_post( $ticket_id, true );
					}
				}
			}
		} else{
			$messages[] = __( 'No Awesome Support data was found.' );
		}

		$done = count( $ticket_data ) < $number;

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	public function wp_privacy_personal_asdata_exporters( $exporters ){
		$exporters['awesome-support-data-test'] = array(
			'exporter_friendly_name' => __( 'Awesome Support Data' ),
			'callback'               => array( $this, 'as_users_personal_data_exporter' ),
		);

		return $exporters;
	}


	/**
	 * Finds and exports personal Awesome Support data associated with an email address from the post table.
	 *
	 * @since 4.9.6
	 *
	 * @param string $email_address The comment author email address.
	 * @param int    $page          Comment page.
	 * @return array $return An array of personal data.
	 */
	public function as_users_personal_data_exporter( $email_address, $page = 1 ){
		
		$number = 500;
		$page   = (int) $page;
		$data_to_export = array();
		$user_data_to_export = array();
		$done = false;
		$author = get_user_by( 'email', $email_address );
		if ( ! $author ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$instance = WPAS_GDPR_User_Profile::get_instance();
		if( isset( $author->ID ) && !empty( $author->ID )){
			$user_tickets_data = $instance->wpas_gdpr_ticket_data( $author->ID, $number, $page );
			$user_consent_data = $instance->wpas_gdpr_consent_data( $author->ID );

			if( !empty( $user_tickets_data )){
				$name = '';
				$value = '';
				$item_id = "as-{$user->ID}";
				$data_to_export[] = array(
					'group_id'    => 'awesome-support',
					'group_label' => __( 'Awesome Support', 'awesome-support' ),
					'item_id'     => $item_id,
					'data'        => array(),
				);
 				foreach ( $user_tickets_data as $key2 => $ticket ) {
 					
					foreach ( $ticket as $key => $value ) {
						switch ( $key ) {
							case 'ticket_id':
								$item_id = 'as-ticket-{' . $value . '}';
								$name = __( 'Ticket ID', 'awesome-support' );
							break;
							case 'subject':
								$name = __( 'Ticket Subject', 'awesome-support' );
							break;
							case 'description':
								$name = __( 'Ticket Description', 'awesome-support' );
							break;
							case 'replies':

								if( !empty( $value ) && is_array( $value ) ){
									$reply_count = 0;
									foreach ( $value as $reply_key => $reply_data ) {
										$reply_count ++;
										if( isset( $reply_data['content'] ) && !empty( $reply_data['content'] )){
											$name = __( 'Reply ' . $reply_count . ' Content', 'awesome-support' );
											if ( ! empty( $value ) ) {
												$user_data_to_export[] = array(
													'name'  => $name,
													'value' => $reply_data['content'],
												);
											}
										}
									}
								}
								$value = '';
							break;
							case 'ticket_status':
								$name = __( 'Ticket Status', 'awesome-support' );
							break;
							default:
								$value = '';
							break;

						}	
						if ( ! empty( $value ) ) {
							$user_data_to_export[] = array(
								'name'  => $name,
								'value' => $value,
							);
						}
					}

					$data_to_export[] = array(
						'group_id'    => 'ticket_' . $item_id,
						'group_label' => __( $ticket['subject'], 'awesome-support' ),
						'item_id'     => $item_id,
						'data'        => $user_data_to_export,
					);
					$user_data_to_export = array();
				}
			}
			if( !empty( $user_consent_data )){
				$consent_count = 0;
				foreach ( $user_consent_data as $consent_key => $consent_value ) {
					$consent_count ++;
					if( isset( $consent_value['item'] ) && !empty( $consent_value['item'] ) ){
						$user_data_to_export[] = array(
							'name'  => __( 'Item', 'awesome-support' ),
							'value' => $consent_value['item'],
						);
						if( isset( $consent_value['status'] ) && !empty( $consent_value['status'] ) ){
							$user_data_to_export[] = array(
								'name'  => __( 'Status', 'awesome-support' ),
								'value' => $consent_value['status'],
							);
						}
						if( isset( $consent_value['opt_in'] ) && !empty( $consent_value['opt_in'] ) ){
							$user_data_to_export[] = array(
								'name'  => __( 'Opt In', 'awesome-support' ),
								'value' => $consent_value['opt_in'],
							);
						}
						if( isset( $consent_value['opt_out'] ) && !empty( $consent_value['opt_out'] ) ){
							$user_data_to_export[] = array(
								'name'  => __( 'Opt Out', 'awesome-support' ),
								'value' => $consent_value['opt_out'],
							);
						}
					}
				}
				$data_to_export[] = array(
					'group_id'    => 'ticket_consent_' . $consent_count,
					'group_label' => __( 'Consent Data', 'awesome-support' ),
					'item_id'     => $item_id,
					'data'        => $user_data_to_export,
				);
			}
			$done = count( $user_tickets_data ) < $number;
		}
		
		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     5.1.1
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Print Template file for privacy popup container.
	 *
	 * @return void
	 */
	public static function print_privacy_popup_temp() {
		if ( wpas_is_plugin_page() ) { ?>
			<div class="privacy-container-template">
				<div class="entry entry-normal" id="privacy-option-content">
					<div class="wpas-gdpr-loader-background"></div><!-- .wpas-gdpr-loader-background -->
					<a href="#" class="hide-the-content"></a>
					<?php
					$entry_header = wpas_get_option( 'privacy_popup_header', 'Privacy' );
					if ( ! empty( $entry_header ) ) {
						echo '<div class="entry-header">' . $entry_header . '</div>';
					}
					?>
					<div class="entry-content">
						<div class="wpas-gdpr-tab">
							<?php $this->render_tabs(); ?>
						</div>

						<div id="add-remove-consent" class="entry-content-tabs wpas-gdpr-tab-content">
							<?php
								/**
								 * Include tab content for Add/Remove Content data
								 */
								include_once( WPAS_PATH . '/includes/gdpr-integration/tab-content/gdpr-add-remove-consent.php' );
							?>
						</div>
						<div id="delete-existing-data" class="entry-content-tabs wpas-gdpr-tab-content">
							<?php
								/**
								 * Include tab content for Delete my existing data
								 */
								include_once( WPAS_PATH . '/includes/gdpr-integration/tab-content/gdpr-delete-existing-data.php' );
							?>
						</div>
						<div id="export-user-data" class="entry-content-tabs wpas-gdpr-tab-content">
							<?php
								/**
								 * Include tab content for Export tickets and user data
								 */
								include_once( WPAS_PATH . '/includes/gdpr-integration/tab-content/gdpr-export-user-data.php' );
							?>
						</div>					
					</div>
					<?php
					$entry_footer = wpas_get_option( 'privacy_popup_footer', 'Privacy' );
					if ( ! empty( $entry_footer ) ) {
						echo '<div class="entry-footer">' . $entry_footer . '</div>';
					}
					?>
				</div> <!--  .entry entry-regular -->
			</div> <!--  .privacy-container-template -->
		<?php
		}
	}
	
	/**
	 * Render one or more tabs on the privacy popup
	 * * Maybe render the Add/Remove Existing Consent tab
	 * * Maybe render the Export tickets and user data tab
	 * * Maybe render the Delete my existing data tab
	 *
	 * @return void
	 */
	public function render_tabs() {
		
		if ( true === boolval( wpas_get_option( 'privacy_show_consent_tab', true) ) ) {
			?>
			<button class="tablinks wpas-gdpr-tablinks" onclick="wpas_gdpr_open_tab( event, 'add-remove-consent' )" id="wpas-gdpr-tab-default" data-id="add-remove"><?php esc_html_e( 'Add/Remove Existing Consent', 'awesome-support' ); ?></button>
			<?php			
		}
		
		if ( true === boolval( wpas_get_option( 'privacy_show_delete_data_tab', true) ) ) {
			?>
			<button class="tablinks wpas-gdpr-tablinks" onclick="wpas_gdpr_open_tab( event, 'delete-existing-data' )" data-id="delete-existing"><?php esc_html_e( 'Delete my existing data', 'awesome-support' ); ?></button>
			<?php			
		}
		
		if ( true === boolval( wpas_get_option( 'privacy_show_export_tab', true) ) ) {
			?>		
			<button class="tablinks wpas-gdpr-tablinks" onclick="wpas_gdpr_open_tab( event, 'export-user-data' )" data-id="export"><?php esc_html_e( 'Export tickets and user data', 'awesome-support' ); ?></button>
			<?php
		}
		
	}	

	/**
	 * Add GDPR privacy options to
	 * * Add/Remove Existing Consent
	 * * Export tickets and user data
	 * * Delete my existing data
	 *
	 * @return void
	 */
	public function frontend_privacy_add_nav_buttons() {
		
		/* Do not render button if option is turned off */
		if ( ! boolval( wpas_get_option( 'privacy_show_button', true) ) ) {
			return ;
		}
		
		/* Option is on so render the button */
		$button_title = wpas_get_option( 'privacy_button_label', 'Privacy' );
		wpas_make_button(
			$button_title, array(
				'type'  => 'link',
				'link'  => '#',
				'class' => 'wpas-btn wpas-btn-default wpas-link-privacy',
			)
		);
	}

	/**
	 * Ajax based ticket submission
	 * This is only good for 'Official Request: Please Delete My Existing Data ("Right To Be Forgotten")'
	 * ticket from the GDPR popup in 'Delete My Existing Data' tab
	 */
	public function wpas_gdpr_open_ticket() {
		/**
		 * Initialize custom reponse message
		 */
		$response = array(
			'code'    => 403,
			'message' => __( 'Sorry! Something failed', 'awesome-support' ),
		);

		/**
		 * Initiate nonce
		 */
		$nonce = isset( $_POST['data']['nonce'] ) ? $_POST['data']['nonce'] : '';

		/**
		 * Security checking
		 */
		if ( ! empty( $nonce ) && check_ajax_referer( 'wpas-gdpr-nonce', 'security' ) ) {

			/**
			 *  Initiate form data parsing
			 */
			$form_data = array();
			parse_str( $_POST['data']['form-data'], $form_data );

			$subject = isset( $form_data['wpas-gdpr-ded-subject'] ) ? $form_data['wpas-gdpr-ded-subject'] : '';
			$content = isset( $form_data['wpas-gdpr-ded-more-info'] ) && ! empty( $form_data['wpas-gdpr-ded-more-info'] ) ? $form_data['wpas-gdpr-ded-more-info'] : $subject; // Fallback to subject to avoid undefined!

			/**
			 * New ticket submission
			 * *
			 * * NOTE: data sanitization is happening on wpas_open_ticket()
			 * * We can skip doing it here
			 */
			$ticket_id = wpas_open_ticket(
				array(
					'title'   => $subject,
					'message' => $content,
				)
			);

			wpas_log_consent( $form_data['wpas-user'], __( 'Right to be forgotten mail', 'awesome-support' ), __( 'requested', 'awesome-support' ) );
			if ( ! empty( $ticket_id ) ) {
				// send erase data request.
				if ( function_exists( 'wp_create_user_request' )  && function_exists( 'wp_send_user_request' ) ) {
					$current_user = wp_get_current_user();
					if( isset( $current_user->user_email ) && !empty( $current_user->user_email )){
						$request_id = wp_create_user_request( $current_user->user_email, 'remove_personal_data' );
						if( $request_id ) {
							wp_send_user_request( $request_id );
						}
					}
				}
				$response['code']    = 200;
				$response['message'] = __( 'We have received your "Right To Be Forgotten" request!', 'awesome-support' );
			} else {
				$response['message'] = __( 'Something went wrong. Please try again!', 'awesome-support' );
			}
		} else {
			$response['message'] = __( 'Cheating huh?', 'awesome-support' );
		}
		wp_send_json( $response );
		wp_die();
	}

	/**
	 * Ajax based processing user opted in button
	 * The button can be found on GDPR popup in front-end
	 */
	public function wpas_gdpr_user_opt_in() {
		/**
		 * Initialize custom reponse message
		 */
		$response = array(
			'code'    => 403,
			'message' => array(),
		);

		/**
		 * Initiate nonce
		 */
		$nonce = isset( $_POST['data']['nonce'] ) ? $_POST['data']['nonce'] : '';

		/**
		 * Security checking
		 */
		if ( ! empty( $nonce ) && check_ajax_referer( 'wpas-gdpr-nonce', 'security' ) ) {

			$item   	= isset( $_POST['data']['gdpr-data'] ) ? sanitize_text_field( $_POST['data']['gdpr-data'] ) : '';
			$user   	= isset( $_POST['data']['gdpr-user'] ) ? sanitize_text_field( $_POST['data']['gdpr-user'] ) : '';
			$status 	= __( 'Opted-in', 'awesome-support' );
			$opt_in 	= strtotime( 'NOW' );
			$opt_out   	= isset( $_POST['data']['gdpr-optout'] ) ? strtotime( sanitize_text_field( $_POST['data']['gdpr-optout'] ) ) : '';
			$gdpr_id 	= wpas_get_gdpr_data( $item );

			/**
			 * Who is the current user right now?
			 */	
			$logged_user = wp_get_current_user();
			$current_user = isset( $logged_user->data->display_name ) ? $logged_user->data->display_name : __( 'user', 'awesome-support');

			wpas_track_consent(
				array(
					'item'    => $item,
					'status'  => $status,
					'opt_in'  => $opt_in,
					'opt_out' => '',
					'is_tor'  => false,
				), $user, 'in'
			);

			wpas_log_consent( $user, $item, __( 'opted-in', 'awesome-support' ), '', $current_user );
			$response['code']               = 200;
			$response['message']['success'] = __( 'You have successfully opted-in', 'awesome-support' );
			$response['message']['date']    = date( 'm/d/Y', $opt_in );
			$response['message']['status']    = $status;
			/**
			 * return buttons markup based on settings
			 * If can opt-out, then display the button
			 */
			if( wpas_get_option( 'gdpr_notice_opt_out_ok_0' . $gdpr_id, false ) ) {
				$response['message']['button']  = sprintf(
					'<a href="#" class="button button-secondary wpas-button wpas-gdpr-opt-out" data-gdpr="' . $item . '" data-user="' . get_current_user_id() . '">%s</a>',
					__( 'Opt-out', 'awesome-support' )
				);
			} else {
				$response['message']['button']  = '';
			}
		} else {
			$response['message']['error'] = __( 'Cheating huh?', 'awesome-support' );
		}
		wp_send_json( $response );
		wp_die();
	}

	/**
	 * Ajax based processing user opted out button
	 * The button can be found on GDPR popup in front-end
	 */
	public function wpas_gdpr_user_opt_out() {
		/**
		 * Initialize custom reponse message
		 */
		$response = array(
			'code'    => 403,
			'message' => array(),
		);

		/**
		 * Initiate nonce
		 */
		$nonce = isset( $_POST['data']['nonce'] ) ? $_POST['data']['nonce'] : '';

		/**
		 * Security checking
		 */
		if ( ! empty( $nonce ) && check_ajax_referer( 'wpas-gdpr-nonce', 'security' ) ) {

			$item    	= isset( $_POST['data']['gdpr-data'] ) ? sanitize_text_field( $_POST['data']['gdpr-data'] ) : '';
			$user    	= isset( $_POST['data']['gdpr-user'] ) ? sanitize_text_field( $_POST['data']['gdpr-user'] ) : '';
			$status  	= __( 'Opted-Out', 'awesome-support' );
			$opt_out 	= strtotime( 'NOW' );
			$opt_in   	= isset( $_POST['data']['gdpr-optin'] ) ? strtotime( sanitize_text_field( $_POST['data']['gdpr-optin'] ) ) : '';

			/**
			 * Who is the current user right now?
			 */	
			$logged_user = wp_get_current_user();
			$current_user = isset( $logged_user->data->display_name ) ? $logged_user->data->display_name : __( 'user', 'awesome-support');

			wpas_track_consent(
				array(
					'item'    => $item,
					'status'  => $status,
					'opt_in'  => '',
					'opt_out' => $opt_out,
					'is_tor'  => false,
				), $user, 'out'
			);
			wpas_log_consent( $user, $item, __( 'opted-out', 'awesome-support' ), '', $current_user );

			$response['code']               = 200;
			$response['message']['success'] = __( 'You have successfully opted-out', 'awesome-support' );
			$response['message']['date']    = date( 'm/d/Y', $opt_out );
			$response['message']['status']    = $status;
			$response['message']['button']  = sprintf(
				'<a href="#" class="button button-secondary wpas-button wpas-gdpr-opt-in" data-gdpr="' . $item . '" data-user="' . get_current_user_id() . '">%s</a>',
				__( 'Opt-in', 'awesome-support' )
			);
		} else {
			$response['message']['error'] = __( 'Cheating huh?', 'awesome-support' );
		}
		wp_send_json( $response );
		wp_die();
	}


}
