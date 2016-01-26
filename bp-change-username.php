<?php
/**
 * Plugin Name: BuddyPress Username Changer
 * Plugin URI: http://buddydev.com/plugins/buddypress-username-changer/
 * Author: Brajesh Singh
 * Author URI: http://buddydev.com/members/sbrajesh
 * Version: 1.1.0
 * License: GPL
 * Last Updated: january 26, 2016
 */
/**
 * allow users to change their username
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 0 );
}

//deprectade BP_USERNAME_CHANGER_SLUG in fafour of BP_USERNAME_CHANGER_SLUG
//settings subtab slug, change it as you please
if ( ! defined( 'BP_USERNAME_CHANGER_SLUG' ) ) {
	define( 'BP_USERNAME_CHANGER_SLUG', 'change-username' );
}

class BP_Username_Change_Helper {
    
    private static $instance = null;
    
    private function __construct() {
        
        $this->setup();
    
    }
    
    public static function get_instance() {
        
        if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
        
        return self::$instance;
        
    }
	
    /**
	 * setup hooks
	 */
    private function setup() {
        
        add_action( 'bp_init', array( $this, 'load_textdomain' ) );
        add_action( 'bp_setup_nav', array( $this, 'nav_setup' ), 11 );
        
    }
    
    /**
    * Load Translation
    */
    public function load_textdomain() {

		load_plugin_textdomain( 'bp-username-changer', false, basename( dirname( __FILE__ ) ) . '/languages/' );
        
    }
    
    /**
    * Add sub menu to the User settings page
    *
    * @return type
    */
    public function nav_setup() {

	//only add if settings component is enabled
        if ( ! bp_is_active( 'settings' ) ) {
			return;
        }
				
        $settings_link = bp_displayed_user_domain() . bp_get_settings_slug() . '/';
        
		bp_core_new_subnav_item( array(
           'name'              => __( 'Change Username', 'bp-username-changer' ),
			'slug'              => BP_USERNAME_CHANGER_SLUG,
			'parent_url'        => $settings_link,
			'parent_slug'       => buddypress()->settings->slug,
			'screen_function'   => array( $this, 'settings_screen' ),
			'position'          => 30,
			'user_has_access'   => apply_filters( 'bp_username_changer_user_has_access', bp_is_my_profile() || is_super_admin() ),
        ));
         
    }

    /**
    * Show/Update Username
    *
    * @global type $wpdb
    * @return type
    */
    public function settings_screen() {

		global $wpdb;
        $bp = buddypress();

		$error          = new WP_Error();
		$is_super_admin = false;
		$user_id        = bp_displayed_user_id();

		if (  ! isset( $_POST['change_username_submit'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'bp-change-username' ) ) {
			$this->load_template();
			exit( 0 );
		} 
	
		//check_admin_referer('bp_settings_change_username');
		$new_user_name = $_POST['new_user_name'];
		// username_exists() references the userlogins object cache, so we must clear
		// it before using the function
		wp_cache_delete( $new_user_name, 'userlogins' );
		wp_cache_delete( $_POST['current_user_name'], 'userlogins' );

		//if the username is empty or invalid
		if ( empty( $new_user_name ) || ! validate_username( $new_user_name ) ) {
			$error->add( 'invalid', __( 'Please enter a valid Username!', 'bp-username-changer' ) );
		} elseif ( $bp->displayed_user->userdata->user_login == $new_user_name ) {
			$error->add('nochange', __( 'Please enter a different Username!', 'bp-username-changer' ) );
		} elseif ( username_exists( $new_user_name ) ) {
			$error->add('exiting_username', sprintf( __( 'The Username %s already exists. Please use a different username!', 'bp-username-changer' ), $new_user_name ) );
		} elseif ( $this->is_reserved_name( $new_user_name ) )  {
			$error->add( 'reserved_usernam', sprintf( __( 'The Username %s is reserved. Please choose a differenet username!' ), $new_user_name ) );
		}

		$error = apply_filters( 'bp_username_changer_validation_errors', $error, $new_user_name );
		//if there was an error
		//show error &redirect
		if ( $error->get_error_code() ) {
			bp_core_add_message( $error->get_error_message(), 'error' );
			bp_core_redirect( bp_displayed_user_domain() . $bp->settings->slug . '/' . BP_USERNAME_CHANGER_SLUG . '/' );

		}

		//if it is multisite, before change the username, revoke the admin capability
		if ( is_multisite() && is_super_admin( $user_id ) ) {

			if ( ! function_exists( 'revoke_super_admin' ) ) {
				   require_once( ABSPATH . 'wp-admin/includes/ms.php' );
			}

			$is_super_admin = true;

			revoke_super_admin( $user_id );
		}
		/* now, update the user_login / user_nicename in the database */
		// this will update user_nicename
		// wp_update_user() doesn't update user_login when updating a user... sucks!
		wp_update_user( array(

			'ID'            => $user_id,
			'user_login'    => $new_user_name,
			'user_nicename' => sanitize_title( $new_user_name )

		) );

		// manually update user_login
		$wpdb->update( $wpdb->users, array( 'user_login' => $new_user_name ), array( 'ID' => $user_id ), array( '%s' ), array( '%d' ) );

		// delete object cache
		clean_user_cache( $user_id );
		wp_cache_delete( $user_id, 'users' );
		wp_cache_delete( 'bp_core_userdata_' . $user_id, 'bp' );
		wp_cache_delete( 'bp_user_username_' . $user_id, 'bp' );
		wp_cache_delete( 'bp_user_domain_' . $user_id, 'bp' );

		// reset auth cookie for new user_login
		// only do this if the current user is attempting to change their own username
		// copies auth cookie logic from wp_update_user()
		if ( bp_is_my_profile() ) {
			wp_clear_auth_cookie();
			// Here we calculate the expiration length of the current auth cookie and compare it to the default expiration.
			// If it's greater than this, then we know the user checked 'Remember Me' when they logged in.
			$logged_in_cookie    = wp_parse_auth_cookie( '', 'logged_in' );

			/** This filter is documented in wp-includes/pluggable.php */
			$default_cookie_life = apply_filters( 'auth_cookie_expiration', ( 2 * DAY_IN_SECONDS ), $user_id, false );
			$remember            = ( ( $logged_in_cookie['expiration'] - time() ) > $default_cookie_life );

			wp_set_auth_cookie( $user_id, $remember );

		}

            //if multisite and the user was super admin, mark him back as super admin
		if ( is_multisite() && $is_super_admin ) {
			grant_super_admin( $user_id );
		}

		// add message
		bp_core_add_message( __( 'Username Changed Successfully!', 'bp-username-changer' ) );

		// fetch the user object just in case plugins altered the user_login
		$user = new WP_User( $user_id );
		// hook for plugins
		do_action( 'bp_username_changed', $new_user_name, $bp->displayed_user->userdata, $user );
		// redirect
		// bp_core_get_user_domain() requires the new user_nicename / user_login
		bp_core_redirect( bp_core_get_user_domain( $user_id, $user->user_nicename, $user->user_login ) . $bp->settings->slug . '/' . BP_USERNAME_CHANGER_SLUG . '/' );

		$this->load_template();
    }
    
	public function load_template() {
		        
        //show title &form
		add_action( 'bp_template_title', array( $this, 'print_title' ) );
		add_action( 'bp_template_content', array( $this, 'print_form' ) );
		
        bp_core_load_template( apply_filters( 'bp_username_changer_template_settings', 'members/single/plugins' ) );
	}
    /**
    * Change Username form
    *
    * @global null $current_user
    */
    public function print_form() {
	
        $bp = buddypress();
    
    ?>
		<form name="username_changer" method="post" class="standard-form">

			<label for="current_user_name"><?php _e("Current User name", "bp-username-changer") ?></label>
			<input type="text" name="current_user_name" id="current_user_name" value="<?php echo esc_attr( $bp->displayed_user->userdata->user_login ); ?>" class="settings-input"  disabled="disabled"/>
			
			<label for="new_user_name"><?php _e("New User name", "bp-username-changer") ?></label>
			<input type="text" name="new_user_name" id="new_user_name" value="" class="settings-input" />
			
			<p><?php _e("Enter the new Username of your choice", "bp-username-changer") ?></p>
			
			<?php wp_nonce_field( 'bp-change-username' ) ;?>
			
			<p class="submit"><input type="submit" id="change_username_submit" name="change_username_submit" class="button" value="<?php _e('Save Changes', 'bp-username-changer') ?>" /></p>

		</form>

    <?php
    
    }

    /**
    * Settings content title
    */
    public function print_title() {

		echo '<h3>' . __( 'Change Username', 'bp-username-changer' ) . '</h3>';

    }

    /**
    * Check if Username is reserved
    *
    * @param type $username
    * @return boolean
    */
    public function is_reserved_name( $username ) {
		
		$is_reserved = false;// assume that it is not reserved and let us check selectively
		
		$reserved = array();

		$admin_names = array( 'admin', 'administrator' );

		if ( is_super_admin() && in_array( $username, $admin_names ) ) {
			return false; //do not prohibit the super admin from any username
		}
		
        //other than that, check for all illigal names
		if ( function_exists( 'bp_core_get_illegal_names' ) ) {
				$reserved = bp_core_get_illegal_names();
		} elseif ( function_exists( 'bp_core_illegal_names' ) ) {
				$reserved = bp_core_illegal_names();
		}

        if ( in_array( $username, (array) $reserved ) ) { 
            $is_reserved =  true;
        }

		return apply_filters( 'bp_username_changer_is_reserved', $is_reserved, $username );
    }

}

BP_Username_Change_Helper::get_instance();
