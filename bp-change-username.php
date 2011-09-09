<?php
/**
 * Plugin Name: BuddyPress Username Changer
 * Plugin URI: http://buddydev.com/plugins/buddypress-username-changer/
 * Author: Brajesh Singh
 * Author URI: http://buddydev.com/members/sbrajesh
 * Version: 1.0.1
 * License: GPL
 * Last Updated:September 09, 2011
 */
/**
 * allow users to change their username
 */
//settings subtab slug
if(!defined("BPCU_SLUG"))
  define("BPCU_SLUG","change-username");

define("BPCU_PLUGIN_DIR",  plugin_dir_path(__FILE__));
define("BPCU_PLUGIN_NAME","bpcu");

//load textdomain from BPCU_DIR/languages directory
function bpdev_bpcu_load_textdomain() {
        $locale = apply_filters( 'bpdev_bpcu_load_textdomain_get_locale', get_locale() );
	// if load .mo file
        
	if ( !empty( $locale ) ) {
		$mofile_default = sprintf( '%slanguages/%s.mo', BPCU_PLUGIN_DIR,  $locale );
             
		$mofile = apply_filters( 'bpdev_bpcu_load_textdomain_mofile', $mofile_default );
		
                if ( file_exists( $mofile ) ) {
                    // make sure file exists, and load it
			load_textdomain( BPCU_PLUGIN_NAME, $mofile );
		}
	}
}
add_action ( 'bp_init', 'bpdev_bpcu_load_textdomain', 2 );

//setup nav
function bpdev_bpcu_nav_setup(){
     global $bp;
	$settings_link = $bp->loggedin_user->domain . bp_get_settings_slug() . '/';
        bp_core_new_subnav_item( array( 'name' => __( 'Change Username', 'bpcu' ), 'slug' => BPCU_SLUG, 'parent_url' => $settings_link, 'parent_slug' => $bp->settings->slug, 'screen_function' => 'bpdev_bpcu_settings_screen', 'position' => 30, 'user_has_access' => bp_is_my_profile() ) );
	
}
add_action( 'bp_setup_nav', 'bpdev_bpcu_nav_setup',11 );

//update the username or show the forms
function bpdev_bpcu_settings_screen() {
	global $wpdb,$bp,$current_user;

	$error = false;
        $user_id=$current_user->id;
	
        if ( isset($_POST['bpcu_change_username_submit']) ) {
		//check_admin_referer('bp_settings_change_username');

		
               $new_user_name=$_POST['bpcu_new_user_name'];
               //if the username is empty or invalid
               if(empty($new_user_name)||!validate_username($new_user_name)){
                    $error=true;
                    $message=__("Please enter a valid Username!","bpcu");
             } //if the provided name is same as the current username
             else if(!$error&&$current_user->user_login==$new_user_name){
                    $error=true;
                    $message=__("Please enter a differnt Username!","bpcu");
              }
                //else if the username already exists 
             else if(!$error&&username_exists($new_user_name)){
                    $error=true;
                    $message=sprintf(__("The Username %s already exists. Please use a different username!","bpcu"),$new_user_name);
                }
              else if(!$error&&bpdev_bpcu_is_reserved_name($new_user_name)){
                  $error=true;
                  $message=sprintf(__("The Username %s is reserved. Please choose a differenet username!"),$new_user_name);
              }  
            //show error &redirect
                if($error){
                    bp_core_add_message($message,"error");
                    bp_core_redirect(bp_core_get_user_domain($user_id).$bp->settings->slug."/".BPCU_SLUG."/");
                    return;
                }    
                
            //if we are here, there is no error and we can change the username
            
            //update user_nicename, easy way, let the wp_update_user do it for you
            $changed=array("ID"=>$current_user->id,"user_login"=>$new_user_name,"user_nicename"=>  sanitize_title($new_user_name));
           
            wp_update_user($changed);//it will change nicename properly
           
            //update user_login
            $wpdb->update( $wpdb->users, array("user_login"=>$new_user_name), array( 'ID'=>$user_id ),array( '%s' ), array( '%d' ) );
           
            //delete cache
            wp_cache_delete($user_id, 'users');
            wp_cache_delete($user_login, 'userlogins');
            wp_cache_delete('bp_user_username_' . $user_id,'bp' );
            wp_cache_delete( 'bp_user_domain_' . $user_id,'bp');
            
            //reset auth cookie for new user_login
            wp_set_auth_cookie($user_id,true,false);
            //force to reset the global $current_user with the new user details
            $current_user=null;
            wp_set_current_user($user_id);//reset user
            
            $user=wp_get_current_user();
           
            bp_core_add_message(__("Username Changed Successfully!","bpcu"));
           
            do_action('profile_update', $user_id, $current_user);
            bp_core_redirect(bp_core_get_user_domain($user_id,$user->user_nicename,$user->user_login).$bp->settings->slug."/".BPCU_SLUG."/");
            return;
	}
        //show title &form
	add_action( 'bp_template_title', 'bpdev_bpcu_title' );
	add_action( 'bp_template_content', 'bpdev_bpcu_form' );

	bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
}

//Settings->Chaneg Username form
function bpdev_bpcu_form(){
    global  $current_user;
    ?>
    <form name="bpcu_username_changer" method="post" class="standard-form">
            <label for="bpcu_current_user_name"><?php _e( "Current User name", "bpcu" ) ?></label>
            <input type="text" name="bpcu_current_user_name" id="bpcu_current_user_name" value="<?php echo esc_attr( $current_user->user_login ); ?>" class="settings-input"  disabled="disabled"/>
            
            <label for="new_user_name"><?php _e( "New User name", "bpcu" ) ?></label>
            <input type="text" name="bpcu_new_user_name" id="bpcu_new_user_name" value="" class="settings-input" />
	    
            <p><?php _e("Enter the new Username of your choice","bpcu") ?></p>
            <p class="submit"><input type="submit" id="bpcu_change_username_submit" name="bpcu_change_username_submit" class="button" value="<?php _e('Save Changes','bpcu') ?>" /></p>
    </form>
    
    <?php
    
}
//settings page title
function bpdev_bpcu_title(){
    echo "<h3>".__("Change Username","bpcu")."</h3>";
}

//check if a username is reserved
function bpdev_bpcu_is_reserved_name($username){
    $reserved=array();
    $admin_names=array("admin","administrator");
    if(is_super_admin()&&in_array($username,$admin_names))
        return false;//do not prohibit the super admin from any username
   //other than that, check for all illigal names 
    if(function_exists('bp_core_get_illegal_names'))
        $reserved=bp_core_get_illegal_names();
    else if(function_exists('bp_core_illegal_names'))
        $reserved=bp_core_illegal_names();
    
    if(in_array($username,(array)$reserved))
            return true;
}
?>